<?php

namespace App\Services\Versioning;

use App\Models\IkontrolRelease;
use Illuminate\Support\Carbon;
use Throwable;

class ReleaseSyncService
{
    public function __construct(
        private readonly GitHubReleaseService $github,
        private readonly ReleaseManifestValidator $validator,
    ) {}

    public function sync(): array
    {
        $summary = ['discovered' => 0, 'imported' => 0, 'updated' => 0, 'invalid' => 0];
        foreach ($this->github->discover() as $remote) {
            $summary['discovered']++;
            $tag = is_string($remote['tag_name'] ?? null) ? $remote['tag_name'] : '';
            $version = $this->safeVersion($tag);
            if ($version === null) { $summary['invalid']++; continue; }

            $attributes = [
                'release_identifier' => $this->releaseIdentifier($remote, $tag),
                'channel' => ($remote['prerelease'] ?? false) ? 'canary' : 'stable',
                'git_tag' => $tag,
                'source_ref' => $tag,
                'commit_sha' => str_repeat('0', 40),
                'source_repository' => $this->github->repository(),
                'manifest_hash' => null,
                'manifest_json' => null,
                'validation_errors' => null,
                'published_at' => $this->date($remote['published_at'] ?? null),
                'status' => 'discovered',
            ];
            if ($asset = $this->artifactAsset($remote, $version)) {
                $attributes['artifact_url'] = $asset['browser_download_url'];
                if (preg_match('/\Asha256:([a-f0-9]{64})\z/i', (string) ($asset['digest'] ?? ''), $digest)) $attributes['artifact_sha256'] = strtolower($digest[1]);
            }

            try {
                $attributes['commit_sha'] = $this->github->commitSha($tag);
                $rawManifest = $this->github->manifest($version, $tag);
                $attributes['manifest_hash'] = hash('sha256', $rawManifest);
                $attributes['manifest_json'] = $this->validator->parse($rawManifest);
                if ($attributes['manifest_json']['version'] !== $version) throw new \InvalidArgumentException('La versión del tag no coincide con el manifest.');
                if ($attributes['manifest_json']['channel'] !== $attributes['channel']) throw new \InvalidArgumentException('El canal del release GitHub no coincide con el manifest.');
                $attributes['channel'] = $attributes['manifest_json']['channel'];
                $attributes['validation_errors'] = null;
                $attributes['status'] = 'validated';
            } catch (Throwable $e) {
                $attributes['validation_errors'] = [$this->safeError($e->getMessage())];
                $attributes['status'] = 'invalid';
                $summary['invalid']++;
            }

            $release = IkontrolRelease::firstOrNew(['version' => $version]);
            if (! $release->exists) $attributes['discovered_at'] = now();
            $release->fill($attributes);
            $changed = $release->isDirty();
            $wasNew = ! $release->exists;
            if ($changed) $release->save();
            if ($wasNew) $summary['imported']++;
            elseif ($changed) $summary['updated']++;
        }
        return $summary;
    }

    private function safeVersion(string $tag): ?string
    {
        try { return $this->validator->versionFromTag($tag); } catch (Throwable) { return null; }
    }

    private function date(mixed $value): ?Carbon
    {
        try { return is_string($value) ? Carbon::parse($value) : null; } catch (Throwable) { return null; }
    }

    private function safeError(string $message): string
    {
        return mb_substr(preg_replace('/(?:token|bearer|password|secret)\s*[:=]?\s*[^\s,;]+/i', '[REDACTED]', $message) ?? 'Validación fallida.', 0, 1000);
    }

    private function releaseIdentifier(array $remote, string $tag): string
    {
        $value = trim((string) ($remote['name'] ?? $tag));
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,159}\z/', $value) ? $value : $tag;
    }

    private function artifactAsset(array $remote, string $version): ?array
    {
        $version = strtolower($version); $allowed = ['ikontrol-'.$version.'.zip', 'ikontrol-platform-'.$version.'.zip'];
        foreach (($remote['assets'] ?? []) as $asset) {
            if (is_array($asset) && in_array(strtolower((string) ($asset['name'] ?? '')), $allowed, true) && filter_var($asset['browser_download_url'] ?? null, FILTER_VALIDATE_URL)) return $asset;
        }
        return null;
    }
}
