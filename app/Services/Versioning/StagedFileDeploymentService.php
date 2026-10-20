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
        $manifest = ['source_version' => $plan['source_version'], 'target_version' => $plan['target_version'], 'replaced' => [], 'created' => [], 'installed' => []];
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
                $manifest['installed'] = $installed;
                $run->update(['backup_manifest' => $manifest]);
            }
            $this->recordEvidence($run, 'file_deployment', ['installed' => $installed, 'status' => 'TARGET_HASHES_VERIFIED']);
            return $manifest;
        } catch (Throwable $e) {
            $rollback = $this->rollbackWithEvidence($instance, $run, $installed);
            if ($rollback['status'] !== 'CODE_ROLLBACK_VERIFIED') $run->update(['status' => 'PARTIAL_CODE_DEPLOYMENT']);
            throw $e;
        }
    }

    public function rollback(IkontrolInstance $instance, InstanceUpdateRun $run, ?array $only = null): string
    {
        return $this->rollbackWithEvidence($instance, $run, $only)['status'];
    }

    public function rollbackWithEvidence(IkontrolInstance $instance, InstanceUpdateRun $run, ?array $only = null): array
    {
        $root = $this->instanceRoot($instance); $backup = realpath((string) $run->backup_reference); $manifest = (array) $run->backup_manifest;
        $selected = $only === null ? null : array_flip($only);
        $evidence = ['restored' => [], 'removed' => [], 'unchanged' => [], 'failed' => []];
        foreach (array_reverse($manifest['replaced'] ?? []) as $file) {
            $target = $this->target($root, $file['path']); $source = $backup === false ? '' : $this->target($backup, $file['path']);
            if (is_file($target) && hash_equals($file['sha256'], hash_file('sha256', $target))) { $evidence['unchanged'][] = $file['path']; continue; }
            if ($selected !== null && ! isset($selected[$file['path']])) { $evidence['failed'][] = ['path' => $file['path'], 'reason' => 'UNEXPECTED_HASH_NOT_INSTALLED_BY_RUN']; continue; }
            if (! is_file($target) || ! hash_equals($file['deployed_sha256'], hash_file('sha256', $target)) || ! is_file($source) || ! hash_equals($file['sha256'], hash_file('sha256', $source))) { $evidence['failed'][] = ['path' => $file['path'], 'reason' => 'ROLLBACK_PRECONDITION_FAILED']; continue; }
            $temporary = $target.'.ikontrol-rollback-'.$run->id; $previous = $target.'.ikontrol-failed-'.$run->id;
            if (! File::copy($source, $temporary) || ! hash_equals($file['sha256'], hash_file('sha256', $temporary))) { $evidence['failed'][] = ['path' => $file['path'], 'reason' => 'ROLLBACK_COPY_FAILED']; continue; }
            if (is_file($target) && ! File::move($target, $previous)) { File::delete($temporary); $evidence['failed'][] = ['path' => $file['path'], 'reason' => 'FAILED_CODE_COULD_NOT_BE_MOVED']; continue; }
            if (! File::move($temporary, $target)) { if (is_file($previous)) File::move($previous, $target); $evidence['failed'][] = ['path' => $file['path'], 'reason' => 'BACKUP_COULD_NOT_BE_ACTIVATED']; continue; }
            if (is_file($previous)) File::delete($previous);
            if (! is_file($target) || ! hash_equals($file['sha256'], hash_file('sha256', $target))) { $evidence['failed'][] = ['path' => $file['path'], 'reason' => 'RESTORED_HASH_MISMATCH']; continue; }
            $evidence['restored'][] = $file['path'];
        }
        foreach (array_reverse($manifest['created'] ?? []) as $file) {
            $target = $this->target($root, $file['path']);
            if (! file_exists($target)) { $evidence['unchanged'][] = $file['path']; continue; }
            if ($selected !== null && ! isset($selected[$file['path']])) { $evidence['failed'][] = ['path' => $file['path'], 'reason' => 'UNEXPECTED_CREATED_FILE']; continue; }
            if (is_file($target) && hash_equals($file['deployed_sha256'], hash_file('sha256', $target)) && File::delete($target) && ! file_exists($target)) $evidence['removed'][] = $file['path'];
            else $evidence['failed'][] = ['path' => $file['path'], 'reason' => 'CREATED_FILE_NOT_REMOVED'];
        }
        $status = $evidence['failed'] === [] ? 'CODE_ROLLBACK_VERIFIED' : 'CODE_ROLLBACK_INCOMPLETE';
        $evidence['status'] = $status;
        $run->update(['rollback_status' => $status]);
        $this->recordEvidence($run, 'rollback', $evidence);
        return $evidence;
    }

    public function verifyInstalledState(IkontrolInstance $instance, InstanceUpdateRun $run, string $expected): array
    {
        if (! in_array($expected, ['source', 'target'], true)) throw new RuntimeException('Estado de recuperación inválido.');
        $root = $this->instanceRoot($instance); $manifest = (array) $run->backup_manifest;
        if (! isset($manifest['replaced'], $manifest['created'])) throw new RuntimeException('El deployment no conserva un manifiesto de respaldo verificable.');
        $result = ['expected' => $expected, 'matched' => [], 'absent' => [], 'failed' => []];
        foreach ($manifest['replaced'] as $file) {
            $target = $this->target($root, $file['path']); $sha = $expected === 'source' ? $file['sha256'] : $file['deployed_sha256'];
            if (is_file($target) && hash_equals($sha, hash_file('sha256', $target))) $result['matched'][] = $file['path'];
            else $result['failed'][] = ['path' => $file['path'], 'reason' => 'HASH_MISMATCH_OR_MISSING'];
        }
        foreach ($manifest['created'] as $file) {
            $target = $this->target($root, $file['path']);
            if ($expected === 'source' && ! file_exists($target)) $result['absent'][] = $file['path'];
            elseif ($expected === 'target' && is_file($target) && hash_equals($file['deployed_sha256'], hash_file('sha256', $target))) $result['matched'][] = $file['path'];
            else $result['failed'][] = ['path' => $file['path'], 'reason' => $expected === 'source' ? 'EXPECTED_ABSENT' : 'HASH_MISMATCH_OR_MISSING'];
        }
        $result['verified'] = $result['failed'] === [];
        return $result;
    }

    private function recordEvidence(InstanceUpdateRun $run, string $key, array $evidence): void
    {
        $log = json_decode((string) $run->fresh()->log, true); if (! is_array($log)) $log = [];
        $log[$key] = $evidence;
        $run->update(['log' => json_encode($log, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
    }

    private function backupPath(InstanceUpdateRun $run): string { $root = $this->managedRoot('backup_root'); $path = $root.DIRECTORY_SEPARATOR.'run-'.$run->id; if (file_exists($path)) throw new RuntimeException('Ya existe un backup para esta ejecución.'); return $path; }
    private function instanceRoot(IkontrolInstance $instance): string { $root = realpath((string) config('ikontrol.instances_root')); $path = realpath((string) $instance->absolute_path); if ($root === false || $path === false || is_link((string)$instance->absolute_path) || ! $this->inside($path, $root)) throw new RuntimeException('La ruta de instancia no está confinada.'); return $path; }
    private function managedRoot(string $key): string { $path = (string) config('ikontrol.release_deployment.'.$key); File::ensureDirectoryExists($path, 0750); $real = realpath($path); if ($real === false) throw new RuntimeException('La raíz administrada no está disponible.'); return $real; }
    private function target(string $root, string $relative): string { if (! preg_match('/\A[a-zA-Z0-9._-]+(?:\/[a-zA-Z0-9._ -]+)*\z/', $relative) || str_contains($relative, '..')) throw new RuntimeException('Ruta relativa inválida.'); $target = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative); if (! $this->inside($target, $root)) throw new RuntimeException('Ruta fuera de la raíz permitida.'); $cursor=$target; while(strlen($cursor)>strlen($root)){if(is_link($cursor))throw new RuntimeException('La ruta contiene un symlink.');$cursor=dirname($cursor);} return $target; }
    private function inside(string $path, string $root): bool { return str_starts_with(strtolower(str_replace('\\','/',$path)), strtolower(rtrim(str_replace('\\','/',$root),'/')).'/'); }
}
