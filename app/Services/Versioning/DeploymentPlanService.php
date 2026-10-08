<?php

namespace App\Services\Versioning;

use App\Models\{IkontrolInstance, IkontrolRelease};
use RuntimeException;

final class DeploymentPlanService
{
    public function build(IkontrolInstance $instance, IkontrolRelease $release, array $staged): array
    {
        $root = $this->instanceRoot($instance); $currentVersion = $instance->detected_version ?: $instance->current_version; $base = $this->baseManifest($instance);
        $create = []; $replace = []; $conflicts = []; $protected = []; $bytes = 0;
        foreach ($staged['manifest']['files'] as $file) {
            $relative = $file['path']; $bytes += $file['size'];
            if ($this->protected($relative, $instance)) { $protected[] = $relative; continue; }
            $target = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (is_link($target) || $this->hasSymlinkParent($target, $root)) { $conflicts[] = ['path' => $relative, 'reason' => 'SYMLINK']; continue; }
            if (! file_exists($target)) { $create[] = ['path' => $relative, 'target_sha256' => $file['sha256']]; continue; }
            if (! is_file($target)) { $conflicts[] = ['path' => $relative, 'reason' => 'NOT_A_FILE']; continue; }
            $actual = hash_file('sha256', $target);
            if (hash_equals($file['sha256'], $actual)) continue;
            $expected = $base[$relative] ?? ($file['from_sha256'][$currentVersion] ?? null);
            if ($expected === null || ! hash_equals($expected, $actual)) { $conflicts[] = ['path' => $relative, 'reason' => 'LOCAL_MODIFICATION', 'actual_sha256' => $actual, 'expected_sha256' => $expected]; continue; }
            $replace[] = ['path' => $relative, 'current_sha256' => $actual, 'target_sha256' => $file['sha256']];
        }
        $free = @disk_free_space($root); $required = max($bytes * 3, 10485760);
        $databasePreflight = $instance->database_status === 'READY' ? 'READY' : 'BLOCKED';
        $state = $protected || $databasePreflight !== 'READY' || ($free !== false && $free < $required) ? 'DEPLOYMENT_BLOCKED' : ($conflicts ? 'DEPLOYMENT_CONFLICT' : 'DEPLOYMENT_READY');
        return ['status' => $state, 'source_version' => $instance->detected_version ?: $instance->current_version, 'target_version' => $release->version, 'commit_sha' => $release->commit_sha, 'files_to_create' => $create, 'files_to_replace' => $replace, 'protected_files' => $protected, 'local_conflicts' => $conflicts, 'database_preflight' => $databasePreflight, 'required_disk_space' => $required, 'available_disk_space' => $free, 'staging_path' => $staged['path'], 'backup_path' => null];
    }

    private function baseManifest(IkontrolInstance $instance): array
    {
        $release = IkontrolRelease::where('version', $instance->detected_version ?: $instance->current_version)->first(); $map = [];
        foreach (($release?->artifact_manifest_json['files'] ?? []) as $file) if (isset($file['path'], $file['sha256'])) $map[$file['path']] = $file['sha256'];
        return $map;
    }
    private function instanceRoot(IkontrolInstance $instance): string { $configured = realpath((string) config('ikontrol.instances_root')); $path = realpath((string) $instance->absolute_path); if ($configured === false || $path === false || is_link((string) $instance->absolute_path) || ! str_starts_with(strtolower(str_replace('\\','/',$path)), strtolower(rtrim(str_replace('\\','/',$configured),'/')).'/')) throw new RuntimeException('La ruta de instancia no está confinada o es un symlink.'); return $path; }
    private function protected(string $path, IkontrolInstance $instance): bool { $list = array_merge((array) config('ikontrol.release_deployment.protected_paths'), (array) $instance->deployment_overrides); $path = strtolower(trim(str_replace('\\','/',$path),'/')); foreach ($list as $item) { $item = strtolower(trim(str_replace('\\','/',(string)$item),'/')); if ($item !== '' && ($path === $item || str_starts_with($path, $item.'/'))) return true; } return false; }
    private function hasSymlinkParent(string $path, string $root): bool { $parent = dirname($path); while (strlen($parent) >= strlen($root)) { if (is_link($parent)) return true; if ($parent === $root) break; $parent = dirname($parent); } return false; }
}
