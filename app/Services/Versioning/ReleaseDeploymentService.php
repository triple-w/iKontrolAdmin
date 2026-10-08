<?php

namespace App\Services\Versioning;

use App\Models\{IkontrolInstance, IkontrolRelease, InstanceUpdateRun};
use App\Services\{AllowedSparkRunner, ManagedCommandJsonProtocol};
use App\Services\Upgrade\InstanceVersionManagementService;
use RuntimeException;
use Throwable;

final class ReleaseDeploymentService
{
    public function __construct(private readonly ReleaseArtifactService $artifacts, private readonly DeploymentPlanService $plans, private readonly StagedFileDeploymentService $files, private readonly ReleaseCompatibilityService $compatibility, private readonly AllowedSparkRunner $runner, private readonly ManagedCommandJsonProtocol $protocol, private readonly InstanceVersionManagementService $versions) {}

    public function prepare(IkontrolInstance $instance, IkontrolRelease $release): InstanceUpdateRun
    {
        $canonical = $this->versions->canonicalVersion();
        if ($release->version !== $canonical || $release->status !== 'validated') throw new RuntimeException('Sólo el release canónico validado puede prepararse.');
        if (! $this->compatibility->isCompatible($instance, $release)) throw new RuntimeException('El release no es compatible con la versión o canal de la instancia.');
        $run = InstanceUpdateRun::create(['instance_id' => $instance->id, 'release_id' => $release->id, 'from_version' => $instance->detected_version ?: $instance->current_version, 'to_version' => $release->version, 'status' => 'preflight', 'started_at' => now()]);
        try {
            $staged = $this->artifacts->stage($release, $run); $plan = $this->plans->build($instance, $release, $staged);
            $plan['backup_path'] = rtrim((string) config('ikontrol.release_deployment.backup_root'), '/\\').DIRECTORY_SEPARATOR.'run-'.$run->id;
            $run->update(['status' => $plan['status'], 'deployment_plan' => $plan, 'finished_at' => now()]);
            return $run->fresh();
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'error_message' => $this->safeError($e), 'finished_at' => now()]); throw $e;
        }
    }

    public function execute(IkontrolInstance $instance, InstanceUpdateRun $run): array
    {
        $run->loadMissing('release'); $release = $run->release;
        if ($run->instance_id !== $instance->id || $run->status !== 'DEPLOYMENT_READY' || ! $release || $release->version !== $this->versions->canonicalVersion() || $run->to_version !== $release->version) throw new RuntimeException('El plan de despliegue no autoriza esta operación.');
        $originalPlan = $run->deployment_plan; $freshPlan = $this->plans->build($instance, $release, ['path' => $originalPlan['staging_path'], 'manifest' => $release->artifact_manifest_json]);
        if ($freshPlan['status'] !== 'DEPLOYMENT_READY' || $freshPlan['files_to_create'] !== $originalPlan['files_to_create'] || $freshPlan['files_to_replace'] !== $originalPlan['files_to_replace']) throw new RuntimeException('La instancia cambió después del dry-run.');
        $databaseAttempted = false;
        try {
            $this->files->deploy($instance, $run, $freshPlan);
            $versionAfterCode = $this->decode($this->runner->inspectVersion((string) $instance->absolute_path));
            if (($versionAfterCode['canonical_version'] ?? null) !== $release->version) throw new RuntimeException('El código desplegado no informa la versión canónica esperada.');
            $database = $this->decode($this->runner->run((string) $instance->absolute_path, 'ikontrol:database-check'));
            if (! $this->ready($database)) throw new RuntimeException('database-check bloqueó la actualización.');
            $plan = $this->decode($this->runner->runUpgradePlan((string) $instance->absolute_path, $release->version));
            if (! $this->compatible($plan)) throw new RuntimeException('El plan dirigido bloqueó la actualización.');
            $run->update(['status' => 'migrating']); $databaseAttempted = true;
            $upgrade = $this->decode($this->runner->executeUpgrade((string) $instance->absolute_path, $release->version));
            if (! $this->successful($upgrade)) throw new RuntimeException('El upgrade dirigido reportó fallo.');
            $inspection = $this->versions->inspect($instance);
            if (($inspection['current_version'] ?? null) !== $release->version || ($inspection['status'] ?? null) !== 'CURRENT') throw new RuntimeException('La reinspección no confirmó la versión objetivo.');
            $run->update(['status' => 'completed', 'finished_at' => now(), 'log' => json_encode($this->sanitize(['version_after_code' => $versionAfterCode, 'database' => $database, 'plan' => $plan, 'upgrade' => $upgrade, 'inspection' => $inspection]), JSON_THROW_ON_ERROR)]);
            $instance->update(['current_commit_sha' => $release->commit_sha, 'last_update_at' => now(), 'last_update_status' => 'COMPLETED']);
            return ['run' => $run->fresh(), 'inspection' => $inspection];
        } catch (Throwable $e) {
            $run->refresh();
            $rollback = $run->rollback_status ?: ($run->backup_manifest ? $this->files->rollback($instance, $run) : null);
            $review = $databaseAttempted || $rollback === 'MANUAL_REVIEW_REQUIRED';
            $run->update(['status' => $review ? 'MANUAL_REVIEW_REQUIRED' : 'failed', 'database_review_required' => $databaseAttempted, 'rollback_status' => $rollback, 'error_message' => $this->safeError($e), 'finished_at' => now()]);
            $instance->update(['upgrade_status' => 'BLOCKED', 'last_update_at' => now(), 'last_update_status' => $review ? 'MANUAL_REVIEW_REQUIRED' : 'FAILED']);
            throw $e;
        }
    }

    private function decode(array $result): array { return $this->protocol->decodeProcess($result); }
    private function ready(array $result): bool { return ($result['ready'] ?? false) === true || in_array(strtoupper((string)($result['status'] ?? '')), ['READY','OK','SUCCESS'], true); }
    private function compatible(array $result): bool { return ($result['compatible'] ?? false) === true || in_array(strtoupper((string)($result['status'] ?? '')), ['READY','COMPATIBLE'], true); }
    private function successful(array $result): bool { return $this->ready($result) || strtoupper((string)($result['status'] ?? '')) === 'COMPLETED'; }
    private function sanitize(array $data): array { foreach ($data as $key => $value) { if (preg_match('/password|secret|token|credential|authorization/i',(string)$key)) $data[$key]='[REDACTED]'; elseif(is_array($value)) $data[$key]=$this->sanitize($value); } return $data; }
    private function safeError(Throwable $e): string { return mb_substr(preg_replace('/(?:token|bearer|password|secret)\s*[:=]?\s*[^\s,;]+/i','[REDACTED]',$e->getMessage()) ?: 'La actualización no se completó.',0,1000); }
}
