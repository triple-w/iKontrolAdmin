<?php

namespace App\Services\Compatibility;

use App\Models\{IkontrolInstance, IkontrolRelease};
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class CompatibilityDeploymentGate
{
    public function __construct(private readonly InstanceCompatibilityService $inspector) {}

    public function assertAllowed(IkontrolInstance $instance, IkontrolRelease $release): void
    {
        $target = config('ikontrol.compatibility_inspector.targets.'.$release->version);
        if (! is_array($target)) return;
        $evaluation = $this->inspector->evaluate($instance, $release->version, null, Auth::user());
        if (! $evaluation || ! $evaluation->authorizes_deployment || $evaluation->status !== 'COMPATIBLE'
            || $evaluation->bundle_trust !== 'RELEASE_VERIFIED' || $evaluation->release_id !== $release->id
            || ! hash_equals(strtolower((string) ($target['source_commit'] ?? '')), strtolower((string) $evaluation->source_commit_sha))
            || ! hash_equals(strtolower((string) $release->commit_sha), strtolower((string) $evaluation->evaluated_release_commit_sha))
            || ! hash_equals(strtolower((string) $release->artifact_sha256), strtolower((string) $evaluation->evaluated_release_artifact_sha256))) {
            throw new RuntimeException('La compatibilidad fiscal exacta de esta release no autoriza el despliegue.');
        }
    }
}
