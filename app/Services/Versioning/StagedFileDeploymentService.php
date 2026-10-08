<?php

namespace App\Services\Versioning;

use App\Models\{IkontrolInstance, InstanceUpdateRun};
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class StagedFileDeploymentService
{
    public function deploy(IkontrolInstance $instance, InstanceUpdateRun $run, array $plan): array
    {
        if (($plan['status'] ?? null) !== 'DEPLOYMENT_READY') throw new RuntimeException('El plan no autoriza el despliegue.');
        $root = $this->instanceRoot($instance); $stage = realpath((string) ($plan['staging_path'] ?? ''));
        if ($stage === false || ! $this->inside($stage, $this->managedRoot('staging_root'))) throw new RuntimeException('El staging no pertenece a la raíz administrada.');
        $backup = $this->backupPath($run); File::ensureDirectoryExists($backup, 0750);
        $manifest = ['source_version' => $plan['source_version'], 'target_version' => $plan['target_version'], 'replaced' => [], 'created' => []];
        foreach ($plan['files_to_replace'] as $file) {
            $target = $this->target($root, $file['path']);
            if (! is_file($target) || ! hash_equals($file['current_sha256'], hash_file('sha256', $target))) throw new RuntimeException('Un archivo cambió después del dry-run: '.$file['path']);
            $copy = $backup.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file['path']); File::ensureDirectoryExists(dirname($copy), 0750);
            if (! File::copy($target, $copy) || ! hash_equals($file['current_sha256'], hash_file('sha256', $copy))) throw new RuntimeException('No fue posible verificar el backup: '.$file['path']);
            $manifest['replaced'][] = ['path' => $file['path'], 'sha256' => $file['current_sha256'], 'deployed_sha256' => $file['target_sha256']];
        }
        foreach ($plan['files_to_create'] as $file) $manifest['created'][] = ['path' => $file['path'], 'deployed_sha256' => $file['target_sha256']];
        $run->update(['status' => 'backup', 'backup_reference' => $backup, 'backup_manifest' => $manifest]);

        $installed = [];
        try {
            $run->update(['status' => 'deploying']);
            foreach (array_merge($plan['files_to_replace'], $plan['files_to_create']) as $file) {
                $source = $this->target($stage, $file['path']); $target = $this->target($root, $file['path']);
                if (! is_file($source) || ! hash_equals($file['target_sha256'], hash_file('sha256', $source))) throw new RuntimeException('El staging cambió después del dry-run.');
                File::ensureDirectoryExists(dirname($target), 0750);
                $temporary = $target.'.ikontrol-new-'.$run->id; $previous = $target.'.ikontrol-old-'.$run->id;
                if (! File::copy($source, $temporary) || ! hash_equals($file['target_sha256'], hash_file('sha256', $temporary))) throw new RuntimeException('Falló la copia verificada de '.$file['path']);
                if (is_file($target) && ! File::move($target, $previous)) throw new RuntimeException('No fue posible apartar el archivo anterior.');
                if (! File::move($temporary, $target)) { if (is_file($previous)) File::move($previous, $target); throw new RuntimeException('No fue posible activar el archivo nuevo.'); }
                if (is_file($previous)) File::delete($previous);
                $installed[] = $file['path'];
            }
            return $manifest;
        } catch (Throwable $e) {
            $rollback = $this->rollback($instance, $run, $installed);
            if ($rollback !== 'CODE_ROLLBACK_AVAILABLE') $run->update(['status' => 'MANUAL_REVIEW_REQUIRED']);
            throw $e;
        }
    }

    public function rollback(IkontrolInstance $instance, InstanceUpdateRun $run, ?array $only = null): string
    {
        $root = $this->instanceRoot($instance); $backup = realpath((string) $run->backup_reference); $manifest = (array) $run->backup_manifest; $ok = true;
        $selected = $only === null ? null : array_flip($only);
        foreach (array_reverse($manifest['replaced'] ?? []) as $file) {
            if ($selected !== null && ! isset($selected[$file['path']])) continue;
            $target = $this->target($root, $file['path']); $source = $backup === false ? '' : $this->target($backup, $file['path']);
            if (! is_file($target) || ! hash_equals($file['deployed_sha256'], hash_file('sha256', $target)) || ! is_file($source) || ! hash_equals($file['sha256'], hash_file('sha256', $source))) { $ok = false; continue; }
            $temporary = $target.'.ikontrol-rollback-'.$run->id; $previous = $target.'.ikontrol-failed-'.$run->id;
            if (! File::copy($source, $temporary) || ! hash_equals($file['sha256'], hash_file('sha256', $temporary))) { $ok = false; continue; }
            if (is_file($target) && ! File::move($target, $previous)) { File::delete($temporary); $ok = false; continue; }
            if (! File::move($temporary, $target)) { if (is_file($previous)) File::move($previous, $target); $ok = false; continue; }
            if (is_file($previous)) File::delete($previous);
        }
        foreach (array_reverse($manifest['created'] ?? []) as $file) {
            if ($selected !== null && ! isset($selected[$file['path']])) continue;
            $target = $this->target($root, $file['path']);
            if (is_file($target) && hash_equals($file['deployed_sha256'], hash_file('sha256', $target))) File::delete($target); elseif (file_exists($target)) $ok = false;
        }
        $status = $ok ? 'CODE_ROLLBACK_AVAILABLE' : 'MANUAL_REVIEW_REQUIRED';
        $run->update(['rollback_status' => $status]);
        return $status;
    }

    private function backupPath(InstanceUpdateRun $run): string { $root = $this->managedRoot('backup_root'); $path = $root.DIRECTORY_SEPARATOR.'run-'.$run->id; if (file_exists($path)) throw new RuntimeException('Ya existe un backup para esta ejecución.'); return $path; }
    private function instanceRoot(IkontrolInstance $instance): string { $root = realpath((string) config('ikontrol.instances_root')); $path = realpath((string) $instance->absolute_path); if ($root === false || $path === false || is_link((string)$instance->absolute_path) || ! $this->inside($path, $root)) throw new RuntimeException('La ruta de instancia no está confinada.'); return $path; }
    private function managedRoot(string $key): string { $path = (string) config('ikontrol.release_deployment.'.$key); File::ensureDirectoryExists($path, 0750); $real = realpath($path); if ($real === false) throw new RuntimeException('La raíz administrada no está disponible.'); return $real; }
    private function target(string $root, string $relative): string { if (! preg_match('/\A[a-zA-Z0-9._-]+(?:\/[a-zA-Z0-9._ -]+)*\z/', $relative) || str_contains($relative, '..')) throw new RuntimeException('Ruta relativa inválida.'); $target = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative); if (! $this->inside($target, $root)) throw new RuntimeException('Ruta fuera de la raíz permitida.'); $cursor=$target; while(strlen($cursor)>strlen($root)){if(is_link($cursor))throw new RuntimeException('La ruta contiene un symlink.');$cursor=dirname($cursor);} return $target; }
    private function inside(string $path, string $root): bool { return str_starts_with(strtolower(str_replace('\\','/',$path)), strtolower(rtrim(str_replace('\\','/',$root),'/')).'/'); }
}
