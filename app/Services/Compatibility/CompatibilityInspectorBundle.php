<?php

namespace App\Services\Compatibility;

use Illuminate\Support\Facades\File;
use RuntimeException;

class CompatibilityInspectorBundle
{
    public function stage(string $targetVersion): array
    {
        $configuration = config('ikontrol.compatibility_inspector.targets.'.$targetVersion);
        if (! is_array($configuration)) throw new RuntimeException('INSPECTOR_TARGET_NOT_ALLOWED');
        $source = (string) ($configuration['bundle_path'] ?? '');
        $expectedManifestHash = strtolower((string) ($configuration['bundle_manifest_sha256'] ?? ''));
        if ($source === '' || ! preg_match('/\A[a-f0-9]{64}\z/', $expectedManifestHash)) throw new RuntimeException('DEVELOPMENT_BUNDLE_NOT_CONFIGURED');
        $source = realpath($source);
        if ($source === false || ! is_dir($source) || is_link($source)) throw new RuntimeException('INSPECTOR_BUNDLE_UNSAFE');

        $manifestPath = $source.DIRECTORY_SEPARATOR.'bundle-manifest.json';
        if (! is_file($manifestPath) || ! hash_equals($expectedManifestHash, $this->hash($manifestPath, 'text-lf-sha256'))) throw new RuntimeException('INSPECTOR_MANIFEST_HASH_MISMATCH');
        $manifest = $this->json($manifestPath);
        if (($manifest['schema_version'] ?? null) !== 1 || ($manifest['target_version'] ?? null) !== $targetVersion || ($manifest['source_commit'] ?? null) !== ($configuration['source_commit'] ?? null) || ! is_array($manifest['files'] ?? null)) throw new RuntimeException('INSPECTOR_MANIFEST_INVALID');
        $entrypoint = (string) ($manifest['entrypoint'] ?? '');
        if ($entrypoint !== 'inspect.php' || ! isset($manifest['files'][$entrypoint], $manifest['files']['InstanceCompatibilityInspector.php'], $manifest['files']['contract-1.1.6.json'])) throw new RuntimeException('INSPECTOR_ENTRYPOINT_INVALID');

        foreach ($manifest['files'] as $name => $definition) {
            if (! is_string($name) || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $name) || ! is_array($definition)) throw new RuntimeException('INSPECTOR_FILE_INVALID');
            $file = $source.DIRECTORY_SEPARATOR.$name;
            $expected = strtolower((string) ($definition['sha256'] ?? ''));
            $mode = (string) ($definition['hash_mode'] ?? '');
            if (! is_file($file) || is_link($file) || ! preg_match('/\A[a-f0-9]{64}\z/', $expected) || ! in_array($mode, ['sha256', 'text-lf-sha256'], true) || ! hash_equals($expected, $this->hash($file, $mode))) throw new RuntimeException('INSPECTOR_FILE_HASH_MISMATCH');
        }

        $contract = $this->json($source.DIRECTORY_SEPARATOR.'contract-1.1.6.json');
        if (($contract['target_version'] ?? null) !== $targetVersion || ($contract['source']['version'] ?? null) !== ($configuration['source_version'] ?? null) || ($contract['source']['commit'] ?? null) !== ($configuration['source_commit'] ?? null)) throw new RuntimeException('INSPECTOR_CONTRACT_INVALID');

        $root = storage_path('app/compatibility-inspector/runtime');
        File::ensureDirectoryExists($root, 0750);
        $temporary = $root.DIRECTORY_SEPARATOR.'inspection-'.bin2hex(random_bytes(12));
        File::ensureDirectoryExists($temporary, 0750);
        try {
            foreach ($manifest['files'] as $name => $definition) {
                $staged = $temporary.DIRECTORY_SEPARATOR.$name;
                if (! File::copy($source.DIRECTORY_SEPARATOR.$name, $staged)) throw new RuntimeException('INSPECTOR_STAGING_FAILED');
                @chmod($staged, 0640);
                if (! hash_equals(strtolower((string) $definition['sha256']), $this->hash($staged, (string) $definition['hash_mode']))) throw new RuntimeException('INSPECTOR_STAGED_FILE_HASH_MISMATCH');
            }
            if (! File::copy($manifestPath, $temporary.DIRECTORY_SEPARATOR.'bundle-manifest.json')) throw new RuntimeException('INSPECTOR_STAGING_FAILED');
            @chmod($temporary.DIRECTORY_SEPARATOR.'bundle-manifest.json', 0640);
            if (! hash_equals($expectedManifestHash, $this->hash($temporary.DIRECTORY_SEPARATOR.'bundle-manifest.json', 'text-lf-sha256'))) throw new RuntimeException('INSPECTOR_STAGED_MANIFEST_HASH_MISMATCH');
        } catch (\Throwable $e) {
            File::deleteDirectory($temporary);
            throw $e;
        }

        return [
            'path' => $temporary,
            'entrypoint' => $temporary.DIRECTORY_SEPARATOR.$entrypoint,
            'manifest_sha256' => $expectedManifestHash,
            'contract_sha256' => $this->hash($source.DIRECTORY_SEPARATOR.'contract-1.1.6.json', 'text-lf-sha256'),
            'contract' => $contract,
            'trust' => 'DEVELOPMENT_VERIFIED',
        ];
    }

    public function remove(array $bundle): void
    {
        $root = realpath(storage_path('app/compatibility-inspector/runtime'));
        $path = realpath((string) ($bundle['path'] ?? ''));
        if ($root !== false && $path !== false && str_starts_with(strtolower(str_replace('\\', '/', $path)), strtolower(rtrim(str_replace('\\', '/', $root), '/').'/'))) File::deleteDirectory($path);
    }

    private function json(string $path): array
    {
        if (! is_file($path) || is_link($path) || filesize($path) > 2097152) throw new RuntimeException('INSPECTOR_JSON_INVALID');
        $decoded = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) throw new RuntimeException('INSPECTOR_JSON_INVALID');
        return $decoded;
    }

    private function hash(string $path, string $mode): string
    {
        $contents = (string) file_get_contents($path);
        if ($mode === 'text-lf-sha256') $contents = str_replace([chr(13).chr(10), chr(13)], chr(10), $contents);
        return hash('sha256', $contents);
    }
}
