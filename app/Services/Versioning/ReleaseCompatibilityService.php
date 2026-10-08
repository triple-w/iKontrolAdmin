<?php

namespace App\Services\Versioning;

use App\Models\{IkontrolInstance, IkontrolRelease};

class ReleaseCompatibilityService
{
    public function isCompatible(IkontrolInstance $instance, IkontrolRelease $release): bool
    {
        $current = $instance->current_version ?: $instance->installed_version ?: $instance->detected_version ?: $instance->app_version;
        if (! is_string($current) || $current === '' || $release->status !== 'validated') return false;
        if (($instance->update_channel ?: 'stable') === 'stable' && $release->channel !== 'stable') return false;
        if (version_compare($release->version, $current, '<=')) return false;
        return in_array($current, $release->manifest_json['from_versions'] ?? [], true);
    }

    public function latestCompatible(IkontrolInstance $instance, iterable $releases): ?IkontrolRelease
    {
        $compatible = null;
        foreach ($releases as $release) {
            if ($this->isCompatible($instance, $release) && ($compatible === null || version_compare($release->version, $compatible->version, '>'))) $compatible = $release;
        }
        return $compatible;
    }
}
