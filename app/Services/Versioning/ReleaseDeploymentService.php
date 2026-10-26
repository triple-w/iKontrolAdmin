<?php

namespace App\Services\Versioning;

use App\Models\{IkontrolInstance, IkontrolRelease, InstanceUpdateRun};
use App\Services\{AllowedSparkRunner, ManagedCommandJsonProtocol};
use App\Services\Compatibility\CompatibilityDeploymentGate;
use App\Services\Upgrade\InstanceVersionManagementService;
use RuntimeException;
use Throwable;

final class ReleaseDeploymentService
{
    private const REVIEW_STATES = ['MANUAL_REVIEW_REQUIRED', 'CODE_ROLLED_BACK_DB_ADVANCED', 'PARTIAL_CODE_DEPLOYMENT'];

    public function __construct(
        private readonly ReleaseArtifactService $artifacts,
        private readonly DeploymentPlanService $plans,
        private readonly StagedFileDeploymentService $files,
        private readonly ReleaseCompatibilityService $compatibility,
        private readonly ReleasePromotionService $promotions,
        private readonly AllowedSparkRunner $runner,
        private readonly ManagedCommandJsonProtocol $protocol,
        private readonly InstanceVersionManagementService $versions,
        private readonly ?CompatibilityDeploymentGate $compatibilityGate = null,
    ) {}

    public function prepare(IkontrolInstance $instance, IkontrolRelease $release): InstanceUpdateRun
    {
        $this->assertNoUnresolvedRun($instance);
        $this->promotions->assertDeploymentAllowed($instance, $release);
        $canonical = $this->versions->canonicalVersion();
        if ($release->version !== $canonical || $release->status !== 'validated') throw new RuntimeException('Sólo el release canónico validado puede prepararse.');
        if (! $this->compatibility->isCompatible($instance, $release)) throw new RuntimeException('El release no es compatible con la versión o canal de la instancia.');
        ($this->compatibilityGate ?? app(CompatibilityDeploymentGate::class))->assertAllowed($instance, $release);
        $run = InstanceUpdateRun::create(['instance_id' => $instance->id, 'release_id' => $release->id, 'release_commit_sha' => strtolower($release->commit_sha), 'release_artifact_sha256' => strtolower((string) $release->artifact_sha256), 'from_version' => $instance->detected_version ?: $instance->current_version, 'to_version' => $release->version, 'status' => 'preflight', 'started_at' => now()]);
        try {
            $staged = $this->artifacts->stage($release, $run);
            $plan = $this->plans->build($instance, $release, $staged);
            $plan['backup_path'] = rtrim((string) config('ikontrol.release_deployment.backup_root'), '/\\').DIRECTORY_SEPARATOR.'run-'.$run->id;
            $run->update(['status' => $plan['status'], 'deployment_plan' => $plan, 'finished_at' => now()]);
            return $run->fresh();
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'error_message' => $this->safeErrorWithStage('PREPARE', $e), 'finished_at' => now()]);
            throw $e;
        }
    }

    public function execute(IkontrolInstance $instance, InstanceUpdateRun $run): array
    {
        $run->loadMissing('release'); $release = $run->release;
        if ($run->instance_id !== $instance->id || $run->status !== 'DEPLOYMENT_READY' || ! $release || $release->version !== $this->versions->canonicalVersion() || $run->to_version !== $release->version) throw new RuntimeException('El plan de despliegue no autoriza esta operación.');
        $this->assertNoUnresolvedRun($instance, $run);
        $this->promotions->assertDeploymentAllowed($instance, $release);
        $this->promotions->assertRunMatchesRelease($run, $release);
        ($this->compatibilityGate ?? app(CompatibilityDeploymentGate::class))->assertAllowed($instance, $release);
        if ($release->artifact_verification_status !== 'verified' || ! is_array($release->artifact_manifest_json)) throw new RuntimeException('La release carece de integridad local verificable.');

        $originalPlan = (array) $run->deployment_plan;
        $freshPlan = $this->plans->build($instance, $release, ['path' => $originalPlan['staging_path'], 'manifest' => $release->artifact_manifest_json]);
        if ($freshPlan['status'] !== 'DEPLOYMENT_READY' || $freshPlan['files_to_create'] !== $originalPlan['files_to_create'] || $freshPlan['files_to_replace'] !== $originalPlan['files_to_replace']) throw new RuntimeException('La instancia cambió después del dry-run.');

        $databaseAttempted = false; $codeTouched = false; $stage = 'PRE_DEPLOY_STATE'; $evidence = $this->evidence($run);
        try {
            $before = $this->inspectState($instance, $evidence, 'before_deployment');
            $this->assertState($before, (string) $run->from_version, (string) $run->from_version, 'El código o la base de datos no corresponden a la versión origen declarada.');
            $this->storeEvidence($run, $evidence, $stage);

            $stage = 'FILE_DEPLOYMENT';
            $this->files->deploy($instance, $run, $freshPlan);
            $codeTouched = true; $evidence = array_replace_recursive($evidence, $this->evidence($run));

            $stage = 'POST_CODE_STATE';
            $versionAfterCode = $this->inspectState($instance, $evidence, 'after_code_deployment');
            $this->assertState($versionAfterCode, $release->version, (string) $run->from_version, 'El estado posterior al despliegue de código no coincide con el esperado.');
            $databaseProcess = $this->runner->run((string) $instance->absolute_path, 'ikontrol:database-check');
            $this->recordProcess($evidence, 'database_check_before_upgrade', $databaseProcess);
            $database = $this->decode($databaseProcess);
            if (! $this->ready($database)) throw new RuntimeException('database-check bloqueó la actualización.');

            $stage = 'DIRECTED_UPGRADE_PLAN';
            $planProcess = $this->runner->runUpgradePlan((string) $instance->absolute_path, $release->version);
            $this->recordProcess($evidence, 'directed_upgrade_plan', $planProcess);
            $plan = $this->decode($planProcess);
            if (! $this->compatible($plan)) throw new RuntimeException('El plan dirigido bloqueó la actualización.');

            $stage = 'DIRECTED_UPGRADE';
            $run->update(['status' => 'migrating']); $databaseAttempted = true;
            $upgradeProcess = $this->runner->executeUpgrade((string) $instance->absolute_path, $release->version);
            $this->recordProcess($evidence, 'directed_upgrade', $upgradeProcess);
            $this->storeEvidence($run, $evidence, $stage);
            $upgrade = $this->decode($upgradeProcess);
            if (! $this->successful($upgrade)) throw new RuntimeException('stage=DIRECTED_UPGRADE reason=UPGRADE_REPORTED_FAILURE status='.(string) ($upgrade['status'] ?? 'unknown').' message='.(string) ($upgrade['message'] ?? $upgrade['error'] ?? 'sin detalle'));

            $stage = 'FINAL_STATE_VALIDATION';
            $finalState = $this->inspectState($instance, $evidence, 'after_directed_upgrade');
            $this->assertState($finalState, $release->version, $release->version, 'La verificación final detectó divergencia entre código y base de datos.');
            $targetFiles = $this->files->verifyInstalledState($instance, $run, 'target');
            if (! $targetFiles['verified']) throw new RuntimeException('La verificación final detectó archivos distintos del artefacto objetivo.');
            $inspection = $this->versions->inspect($instance);
            if (($inspection['current_version'] ?? null) !== $release->version || ($inspection['canonical_version'] ?? null) !== $release->version || ($inspection['status'] ?? null) !== 'CURRENT') throw new RuntimeException('La reinspección no confirmó la versión objetivo.');

            $evidence['results'] = $this->sanitize(['version_after_code' => $versionAfterCode, 'database' => $database, 'plan' => $plan, 'upgrade' => $upgrade, 'final_state' => $finalState, 'target_files' => $targetFiles, 'inspection' => $inspection]);
            $evidence['outcome'] = 'DEPLOYMENT_COMPLETED';
            $run->update(['status' => 'completed', 'finished_at' => now(), 'log' => json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
            $instance->update(['current_commit_sha' => $release->commit_sha, 'last_update_at' => now(), 'last_update_status' => 'COMPLETED']);
            return ['run' => $run->fresh(), 'inspection' => $inspection];
        } catch (Throwable $e) {
            $run->refresh(); $evidence = array_replace_recursive($evidence, $this->evidence($run));
            $evidence['failure'] = ['stage' => $stage, 'exception' => $this->safeError($e)];
            $installed = (array) data_get($run->backup_manifest, 'installed', []); $codeTouched = $codeTouched || $installed !== [];
            $beforeRollback = ($codeTouched || $databaseAttempted) ? $this->tryInspectState($instance, $evidence, 'failure_before_rollback') : null;
            $rollbackEvidence = null;
            if ($run->backup_manifest) {
                if (in_array($run->rollback_status, ['CODE_ROLLBACK_VERIFIED', 'CODE_ROLLBACK_INCOMPLETE'], true)) $rollbackEvidence = data_get($this->evidence($run), 'rollback');
                else $rollbackEvidence = $this->files->rollbackWithEvidence($instance, $run);
            }
            $run->refresh(); $evidence = array_replace_recursive($evidence, $this->evidence($run));
            $afterRollback = ($codeTouched || $databaseAttempted) ? $this->tryInspectState($instance, $evidence, 'after_rollback') : null;
            $databaseAdvanced = $this->databaseVersion($beforeRollback) === $release->version || $this->databaseVersion($afterRollback) === $release->version;
            $codeRestored = $run->rollback_status === 'CODE_ROLLBACK_VERIFIED' && $this->codeVersion($afterRollback) === (string) $run->from_version;
            $preState = data_get($evidence, 'version_states.before_deployment');
            $preexistingInconsistency = is_array($preState)
                && (($preState['code_version'] ?? null) !== (string) $run->from_version
                    || ($preState['database_version'] ?? null) !== (string) $run->from_version);

            $status = 'failed';
            if ($run->rollback_status === 'CODE_ROLLBACK_INCOMPLETE') $status = 'PARTIAL_CODE_DEPLOYMENT';
            elseif ($codeRestored && $databaseAdvanced) $status = 'CODE_ROLLED_BACK_DB_ADVANCED';
            elseif ($databaseAttempted || $preexistingInconsistency || ($codeTouched && ! $codeRestored)) $status = 'MANUAL_REVIEW_REQUIRED';
            $review = $status !== 'failed';
            $evidence['recovery'] = ['code_restored' => $codeRestored, 'database_advanced' => $databaseAdvanced, 'preexisting_inconsistency' => $preexistingInconsistency, 'rollback' => $rollbackEvidence, 'retry_safe' => false, 'outcome' => $status];
            $run->update(['status' => $status, 'database_review_required' => $databaseAttempted || $databaseAdvanced || $preexistingInconsistency, 'rollback_status' => $run->rollback_status, 'error_message' => $this->safeErrorWithStage($stage, $e), 'log' => json_encode($this->sanitize($evidence), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'finished_at' => now()]);
            $instance->update(['upgrade_status' => $review ? 'BLOCKED' : $instance->upgrade_status, 'last_update_at' => now(), 'last_update_status' => $status]);
            throw $e;
        }
    }

    public function verifyRecovery(IkontrolInstance $instance, InstanceUpdateRun $run, string $expected): InstanceUpdateRun
    {
        if ($run->instance_id !== $instance->id || ! in_array($run->status, self::REVIEW_STATES, true)) throw new RuntimeException('El deployment no está pendiente de recuperación verificable.');
        if (! in_array($expected, ['source', 'target'], true)) throw new RuntimeException('Estado de recuperación inválido.');
        $evidence = $this->evidence($run); $previousStatus = $run->status;
        try {
            $state = $this->inspectState($instance, $evidence, 'recovery_verification');
            $version = $expected === 'source' ? (string) $run->from_version : (string) $run->to_version;
            $this->assertState($state, $version, $version, 'La recuperación no dejó código y base de datos en la misma versión esperada.');
            $files = $this->files->verifyInstalledState($instance, $run, $expected);
            if (! $files['verified']) throw new RuntimeException('Los hashes instalados no corresponden al estado de recuperación declarado.');
            $status = $expected === 'source' ? 'RECOVERY_VERIFIED_SOURCE' : 'RECOVERY_VERIFIED_TARGET';
            $evidence['recovery_resolution'] = ['verified_at' => now()->toIso8601String(), 'expected' => $expected, 'version' => $version, 'state' => $this->state($state), 'files' => $files, 'previous_status' => $previousStatus];
            $run->update(['status' => $status, 'log' => json_encode($this->sanitize($evidence), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'finished_at' => now()]);
            $instance->update(['upgrade_status' => $expected === 'source' ? 'UPDATE_AVAILABLE' : 'CURRENT', 'current_version' => $version, 'detected_version' => $version, 'canonical_version' => $version, 'last_update_status' => $status, 'last_update_at' => now()]);
            return $run->fresh();
        } catch (Throwable $e) {
            $evidence['recovery_verification_failure'] = ['attempted_at' => now()->toIso8601String(), 'expected' => $expected, 'error' => $this->safeError($e)];
            $run->update(['log' => json_encode($this->sanitize($evidence), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
            throw $e;
        }
    }

    private function assertNoUnresolvedRun(IkontrolInstance $instance, ?InstanceUpdateRun $current = null): void
    {
        $query = InstanceUpdateRun::where('instance_id', $instance->id)->whereIn('status', self::REVIEW_STATES);
        if ($current) $query->whereKeyNot($current->id);
        if ($query->exists()) throw new RuntimeException('Existe un deployment anterior que requiere revisión manual.');
    }

    private function inspectState(IkontrolInstance $instance, array &$evidence, string $key): array
    {
        $process = $this->runner->inspectVersion((string) $instance->absolute_path);
        $this->recordProcess($evidence, $key, $process);
        $state = $this->decode($process); $evidence['version_states'][$key] = $this->state($state);
        return $state;
    }

    private function tryInspectState(IkontrolInstance $instance, array &$evidence, string $key): ?array
    {
        try { return $this->inspectState($instance, $evidence, $key); }
        catch (Throwable $e) { $evidence['version_states'][$key] = ['inspection_error' => $this->safeError($e)]; return null; }
    }

    private function assertState(array $state, string $code, string $database, string $message): void
    {
        if ($this->codeVersion($state) !== $code || $this->databaseVersion($state) !== $database) throw new RuntimeException($message.' expected_code='.$code.' actual_code='.($this->codeVersion($state) ?? 'unknown').' expected_db='.$database.' actual_db='.($this->databaseVersion($state) ?? 'unknown'));
    }

    private function state(array $state): array { return ['code_version' => $this->codeVersion($state), 'database_version' => $this->databaseVersion($state), 'compatible' => $state['compatible'] ?? null]; }
    private function codeVersion(?array $state): ?string { $value = $state['canonical_version'] ?? null; return is_string($value) && $value !== '' ? $value : null; }
    private function databaseVersion(?array $state): ?string { $value = $state['current_version'] ?? null; return is_string($value) && $value !== '' ? $value : null; }

    private function recordProcess(array &$evidence, string $key, array $process): void
    {
        $evidence['processes'][$key] = $this->sanitize(['execution_id' => $process['execution_id'] ?? null, 'command' => $process['command'] ?? null, 'exit_code' => $process['exit_code'] ?? null, 'duration_ms' => $process['duration_ms'] ?? null, 'timed_out' => $process['timed_out'] ?? false, 'timeout_seconds' => $process['timeout_seconds'] ?? null, 'exception' => $process['exception'] ?? null, 'stdout_tail' => $process['stdout_tail'] ?? $process['stdout'] ?? null, 'stderr_tail' => $process['stderr_tail'] ?? $process['stderr'] ?? null]);
    }

    private function evidence(InstanceUpdateRun $run): array { $value = json_decode((string) $run->log, true); return is_array($value) ? $value : []; }
    private function storeEvidence(InstanceUpdateRun $run, array $evidence, string $stage): void { $evidence['last_stage'] = $stage; $run->update(['log' => json_encode($this->sanitize($evidence), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]); }
    private function decode(array $result): array { return $this->protocol->decodeProcess($result); }
    private function ready(array $result): bool { return ($result['ready'] ?? false) === true || in_array(strtoupper((string) ($result['status'] ?? '')), ['READY', 'OK', 'SUCCESS'], true); }
    private function compatible(array $result): bool { return ($result['compatible'] ?? false) === true || in_array(strtoupper((string) ($result['status'] ?? '')), ['READY', 'COMPATIBLE'], true); }
    private function successful(array $result): bool { return $this->ready($result) || strtoupper((string) ($result['status'] ?? '')) === 'COMPLETED'; }

    private function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (preg_match('/password|secret|token|credential|authorization|app_key|private_key|certificate|csd|\.env|fiscal/i', (string) $key)) $data[$key] = '[REDACTED]';
            elseif (is_array($value)) $data[$key] = $this->sanitize($value);
            elseif (is_string($value)) $data[$key] = $this->sanitizeString($value);
        }
        return $data;
    }

    private function sanitizeString(string $value): string
    {
        foreach ([(string) config('ikontrol.db.password'), (string) config('ikontrol.cpanel.token')] as $secret) if ($secret !== '') $value = str_replace($secret, '[REDACTED]', $value);
        $value = preg_replace(['/(password|token|secret|authorization|app_key|encryption\.key|private_key|certificate|csd_password)\s*[=:]\s*[^\s,;]+/i', '/bearer\s+[^\s]+/i', '/-----BEGIN [^-]*(?:PRIVATE KEY|CERTIFICATE)-----.*?-----END [^-]*(?:PRIVATE KEY|CERTIFICATE)-----/is'], ['$1=[REDACTED]', 'Bearer [REDACTED]', '[REDACTED PEM]'], $value) ?? '';
        return mb_substr($value, 0, 4000);
    }

    private function safeError(Throwable $e): string { return mb_substr($this->sanitizeString($e->getMessage()) ?: 'La actualización no se completó.', 0, 1000); }
    private function safeErrorWithStage(string $stage, Throwable $e): string { return mb_substr('stage='.$stage.' '.$this->safeError($e), 0, 1000); }
}
