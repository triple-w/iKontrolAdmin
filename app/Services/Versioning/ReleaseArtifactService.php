<?php

namespace App\Services\Versioning;

use App\Models\{IkontrolRelease, InstanceUpdateRun};
use Illuminate\Support\Facades\{File, Http};
use RuntimeException;
use ZipArchive;

final class ReleaseArtifactService
{
    public function stage(IkontrolRelease $release, InstanceUpdateRun $run): array
    {
        $this->assertRelease($release);
        $archive = $this->artifact($release);
        if (filesize($archive) > (int) config('ikontrol.release_deployment.max_archive_bytes')) throw new RuntimeException('El artefacto excede el tamaño permitido.');
        if (! hash_equals(strtolower((string) $release->artifact_sha256), hash_file('sha256', $archive))) {
            $release->update(['artifact_verification_status' => 'checksum_failed']);
            throw new RuntimeException('El checksum SHA-256 del artefacto no coincide.');
        }

        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) throw new RuntimeException('No fue posible abrir el artefacto ZIP.');
        try {
            [$entries, $total] = $this->inspectArchive($zip);
            $manifestEntry = $this->manifestEntry($entries, $release->version);
            $manifest = $this->validateManifest((string) $zip->getFromName($manifestEntry), $release);
            $prefix = substr($manifestEntry, 0, -strlen($this->manifestRelativePath($release->version)));
            $stage = $this->newStagePath($run);
            File::ensureDirectoryExists($stage, 0750);
            foreach ($manifest['files'] as $file) {
                $entry = $prefix.$file['path'];
                if (! isset($entries[$entry]) || $entries[$entry]['directory']) throw new RuntimeException('El manifest referencia un archivo ausente: '.$file['path']);
                $contents = $zip->getFromName($entry);
                if (! is_string($contents) || strlen($contents) !== $file['size'] || ! hash_equals($file['sha256'], hash('sha256', $contents))) {
                    throw new RuntimeException('El checksum de un archivo del artefacto no coincide: '.$file['path']);
                }
                $target = $stage.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file['path']);
                File::ensureDirectoryExists(dirname($target), 0750);
                if (File::put($target, $contents) === false) throw new RuntimeException('No fue posible materializar el staging.');
            }
            $release->update(['artifact_verification_status' => 'verified', 'artifact_manifest_json' => $manifest]);
            $run->update(['staging_reference' => $stage]);
            return ['path' => $stage, 'manifest' => $manifest, 'archive_bytes' => filesize($archive), 'extracted_bytes' => $total];
        } catch (\Throwable $e) {
            if ($release->artifact_verification_status !== 'checksum_failed') $release->update(['artifact_verification_status' => 'invalid']);
            throw $e;
        } finally {
            $zip->close();
        }
    }

    private function artifact(IkontrolRelease $release): string
    {
        $root = $this->root('artifact_root');
        $relative = (string) $release->artifact_path;
        if ($relative !== '') {
            $candidate = realpath($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative));
            if ($candidate === false || ! $this->inside($candidate, $root) || ! is_file($candidate)) throw new RuntimeException('El artefacto registrado no está disponible dentro de la raíz administrada.');
            return $candidate;
        }
        $url = (string) $release->artifact_url; $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! str_starts_with($url, 'https://') || ! in_array($host, ['github.com', 'api.github.com'], true)) throw new RuntimeException('El release no tiene una fuente de artefacto segura.');
        File::ensureDirectoryExists($root, 0750);
        $relative = $release->version.'-'.$release->commit_sha.'.zip';
        $target = $root.DIRECTORY_SEPARATOR.$relative;
        $temporary = $target.'.part';
        $request = Http::timeout(120)->connectTimeout(15); $token = (string) config('ikontrol.releases.github_token'); if ($token !== '') $request = $request->withToken($token);
        $request->sink($temporary)->get($url)->throw();
        if (filesize($temporary) > (int) config('ikontrol.release_deployment.max_archive_bytes')) { File::delete($temporary); throw new RuntimeException('El artefacto excede el tamaño permitido.'); }
        File::move($temporary, $target);
        $release->update(['artifact_path' => $relative]);
        return $target;
    }

    private function inspectArchive(ZipArchive $zip): array
    {
        if ($zip->numFiles < 1 || $zip->numFiles > (int) config('ikontrol.release_deployment.max_files')) throw new RuntimeException('El número de archivos del artefacto no es aceptable.');
        $entries = []; $names = []; $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i); $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            $zip->getExternalAttributesIndex($i, $opsys, $attributes);
            $symlink = $opsys === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000;
            if ($name === '' || $symlink || str_starts_with($name, '/') || preg_match('/(^|\/)\.\.($|\/)/', $name) || preg_match('/\A[A-Za-z]:/', $name) || str_contains($name, "\0")) throw new RuntimeException('El artefacto contiene una ruta o symlink inseguro.');
            $key = strtolower(rtrim($name, '/')); if (isset($names[$key])) throw new RuntimeException('El artefacto contiene rutas duplicadas.'); $names[$key] = true;
            $directory = str_ends_with($name, '/'); $size = $directory ? 0 : (int) ($stat['size'] ?? 0); $compressed = (int) ($stat['comp_size'] ?? $size); $total += $size;
            if ($size > 10485760 && $compressed > 0 && $size / $compressed > 1000) throw new RuntimeException('El artefacto contiene una entrada con compresión no razonable.');
            if ($total > (int) config('ikontrol.release_deployment.max_extracted_bytes')) throw new RuntimeException('El artefacto excede el tamaño extraído permitido.');
            $entries[rtrim($name, '/')] = ['directory' => $directory];
        }
        return [$entries, $total];
    }

    private function validateManifest(string $json, IkontrolRelease $release): array
    {
        if ($json === '' || strlen($json) > (int) config('ikontrol.releases.max_manifest_bytes', 262144)) throw new RuntimeException('El manifest de archivos está vacío o excede el límite.');
        try { $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new RuntimeException('El manifest de archivos no contiene JSON válido.'); }
        if (! is_array($data) || ($data['schema_version'] ?? null) !== 1 || ($data['product'] ?? null) !== 'ikontrol' || ($data['version'] ?? null) !== $release->version || strtolower((string) ($data['commit_sha'] ?? '')) !== strtolower($release->commit_sha) || ($data['release_id'] ?? null) !== $release->release_identifier || ! is_array($data['files'] ?? null) || ! array_is_list($data['files'])) throw new RuntimeException('El manifest de archivos no corresponde al release seleccionado.');
        $seen = [];
        foreach ($data['files'] as &$file) {
            if (! is_array($file) || array_diff(array_keys($file), ['path', 'sha256', 'size', 'from_sha256']) || ! is_string($file['path'] ?? null) || ! preg_match('/\A[a-zA-Z0-9._-]+(?:\/[a-zA-Z0-9._ -]+)*\z/', $file['path']) || preg_match('/(^|\/)\.\.($|\/)/', $file['path']) || ! preg_match('/\A[a-f0-9]{64}\z/i', (string) ($file['sha256'] ?? '')) || ! is_int($file['size'] ?? null) || $file['size'] < 0 || isset($seen[strtolower($file['path'])]) || $this->protected($file['path'])) throw new RuntimeException('El manifest contiene un archivo inválido, duplicado o protegido.');
            if (isset($file['from_sha256']) && (! is_array($file['from_sha256']) || array_is_list($file['from_sha256']))) throw new RuntimeException('from_sha256 debe ser un objeto de versiones y hashes.');
            foreach (($file['from_sha256'] ?? []) as $version => &$sha) { if (! is_string($version) || ! in_array($version, $release->manifest_json['from_versions'] ?? [], true) || ! is_string($sha) || ! preg_match('/\A[a-f0-9]{64}\z/i', $sha)) throw new RuntimeException('El manifest contiene un checksum de origen inválido.'); $sha = strtolower($sha); } unset($sha);
            $file['sha256'] = strtolower($file['sha256']); $seen[strtolower($file['path'])] = true;
        }
        unset($file);
        if (! isset($seen['spark'], $seen['index.php'])) throw new RuntimeException('El manifest no contiene la aplicación mínima requerida.');
        return $data;
    }

    private function manifestEntry(array $entries, string $version): string
    {
        $suffix = $this->manifestRelativePath($version); $matches = array_values(array_filter(array_keys($entries), fn ($entry) => $entry === $suffix || str_ends_with($entry, '/'.$suffix)));
        if (count($matches) !== 1) throw new RuntimeException('El artefacto debe contener exactamente un manifest de despliegue.');
        return $matches[0];
    }

    private function manifestRelativePath(string $version): string { return 'updates/'.$version.'/deployment-manifest.json'; }
    private function protected(string $path): bool { $path = strtolower(trim(str_replace('\\', '/', $path), '/')); foreach ((array) config('ikontrol.release_deployment.protected_paths') as $protected) { $protected = strtolower(trim($protected, '/')); if ($path === $protected || str_starts_with($path, $protected.'/')) return true; } return false; }
    private function newStagePath(InstanceUpdateRun $run): string { $root = $this->root('staging_root'); File::ensureDirectoryExists($root, 0750); return $root.DIRECTORY_SEPARATOR.'run-'.$run->id.'-'.bin2hex(random_bytes(6)); }
    private function root(string $key): string { $configured = (string) config('ikontrol.release_deployment.'.$key); File::ensureDirectoryExists($configured, 0750); $root = realpath($configured); if ($root === false) throw new RuntimeException('La raíz administrada no está disponible.'); return $root; }
    private function inside(string $path, string $root): bool { $path = str_replace('\\', '/', $path); $root = rtrim(str_replace('\\', '/', $root), '/'); return str_starts_with(strtolower($path), strtolower($root).'/'); }
    private function assertRelease(IkontrolRelease $release): void { if ($release->status !== 'validated' || $release->source_repository !== config('ikontrol.releases.repository') || $release->source_ref !== $release->git_tag || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,159}\z/', (string) $release->release_identifier) || ! preg_match('/\A\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?\z/', $release->version) || ! preg_match('/\A[a-f0-9]{40}\z/i', $release->commit_sha) || ! preg_match('/\A[a-f0-9]{64}\z/i', (string) $release->artifact_sha256)) throw new RuntimeException('El release no tiene metadata inmutable y validada.'); }
}
