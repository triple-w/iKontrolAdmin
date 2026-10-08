<?php

namespace App\Services\Upgrade;

use App\Models\{IkontrolInstance, IkontrolUpgradeAudit};
use App\Services\{AllowedSparkRunner, ManagedCommandJsonProtocol};
use RuntimeException;
use Throwable;

final class InstanceVersionManagementService
{
    public const STATES = ['CURRENT', 'UPDATE_AVAILABLE', 'LEGACY_ADOPTABLE', 'BLOCKED', 'UNREACHABLE'];

    public function __construct(
        private readonly AllowedSparkRunner $runner,
        private readonly ManagedCommandJsonProtocol $protocol,
    ) {}

    public function inspect(IkontrolInstance $instance): array
    {
        $canonical = $this->canonicalVersion();
        try {
            $version = $this->decode($this->runner->inspectVersion($this->path($instance)));
        } catch (Throwable) {
            $instance->update([
                'canonical_version' => $canonical,
                'target_version' => $canonical,
                'upgrade_status' => 'UNREACHABLE',
                'database_status' => null,
                'baseline_status' => null,
                'last_upgrade_audit_at' => now(),
                'last_connection_at' => now(),
                'last_connection_status' => 'ERROR',
                'last_connection_error' => 'No fue posible ejecutar ikontrol:version.',
            ]);
            return ['status' => 'UNREACHABLE', 'current_version' => null, 'canonical_version' => $canonical, 'database' => null, 'baseline' => null];
        }

        $current = $this->currentVersion($version);
        $database = $this->safeCheck(fn () => $this->runner->run($this->path($instance), 'ikontrol:database-check'));
        $baseline = $this->safeCheck(fn () => $this->runner->run($this->path($instance), 'ikontrol:baseline-check'));
        $adoption = null;

        if ($current === null) {
            $adoption = $this->safeCheck(fn () => $this->runner->runAdoptBaseline($this->path($instance)));
            $state = $this->ready($database) && $this->compatible($adoption) ? 'LEGACY_ADOPTABLE' : 'BLOCKED';
        } elseif (! $this->ready($database) || ! $this->ready($baseline)) {
            $state = 'BLOCKED';
        } elseif (version_compare($current, $canonical, '==')) {
            $state = 'CURRENT';
        } elseif (version_compare($current, $canonical, '<')) {
            $state = 'UPDATE_AVAILABLE';
        } else {
            $state = 'BLOCKED';
        }

        $instance->update([
            'current_version' => $current,
            'detected_version' => $current,
            'canonical_version' => $canonical,
            'target_version' => $canonical,
            'installation_origin' => $current === null ? 'LEGACY' : $instance->installation_origin,
            'upgrade_status' => $state,
            'database_status' => $this->resultStatus($database),
            'baseline_status' => $this->resultStatus($baseline),
            'last_upgrade_audit_at' => now(),
            'last_connection_at' => now(),
            'last_connection_status' => 'CONNECTED',
            'last_connection_error' => null,
        ]);

        return compact('state', 'current', 'canonical', 'version', 'database', 'baseline', 'adoption') + [
            'status' => $state,
            'current_version' => $current,
            'canonical_version' => $canonical,
        ];
    }

    public function plan(IkontrolInstance $instance): IkontrolUpgradeAudit
    {
        if ($instance->upgrade_status !== 'UPDATE_AVAILABLE') throw new RuntimeException('La instancia no tiene una actualización disponible.');
        $target = $this->canonicalVersion();
        $audit = $this->newAudit($instance, $target, 'PLANNING');
        try {
            $plan = $this->decode($this->runner->runUpgradePlan($this->path($instance), $target));
            $compatible = $this->compatible($plan);
            $audit->update([
                'status' => $compatible ? 'PLAN_READY' : 'PLAN_BLOCKED',
                'compatibility' => $compatible ? 'COMPATIBLE' : 'BLOCKED',
                'finished_at' => now(),
                'summary_json' => ['source' => $instance->detected_version, 'target' => $target],
                'plan_json' => $this->sanitize($plan),
            ]);
            $instance->update(['target_version' => $target, 'upgrade_status' => $compatible ? 'UPDATE_AVAILABLE' : 'BLOCKED', 'last_upgrade_audit_at' => now()]);
            return $audit->fresh();
        } catch (Throwable $e) {
            $this->failAudit($audit, $instance, 'No fue posible generar el plan.');
            throw $e;
        }
    }

    public function adoptionPlan(IkontrolInstance $instance): IkontrolUpgradeAudit
    {
        if ($instance->upgrade_status !== 'LEGACY_ADOPTABLE') throw new RuntimeException('La instancia no está clasificada como legacy adoptable.');
        $target = $this->canonicalVersion();
        $audit = $this->newAudit($instance, $target, 'ADOPTION_PLANNING');
        try {
            $plan = $this->decode($this->runner->runAdoptBaseline($this->path($instance)));
            $compatible = $this->compatible($plan);
            $audit->update([
                'status' => $compatible ? 'ADOPTION_READY' : 'ADOPTION_BLOCKED',
                'compatibility' => $compatible ? 'COMPATIBLE' : 'BLOCKED',
                'finished_at' => now(),
                'summary_json' => ['source' => null, 'target' => $target, 'operation' => 'ADOPT_BASELINE'],
                'plan_json' => $this->sanitize($plan),
            ]);
            if (! $compatible) $instance->update(['upgrade_status' => 'BLOCKED']);
            $instance->update(['last_upgrade_audit_at' => now()]);
            return $audit->fresh();
        } catch (Throwable $e) {
            $this->failAudit($audit, $instance, 'No fue posible evaluar la adopción.');
            throw $e;
        }
    }

    public function executeUpgrade(IkontrolInstance $instance, IkontrolUpgradeAudit $audit): array
    {
        $this->assertAudit($instance, $audit, 'PLAN_READY');
        $target = $this->canonicalVersion();
        if ($audit->target_version !== $target || $instance->upgrade_status !== 'UPDATE_AVAILABLE') throw new RuntimeException('El plan ya no corresponde al estado actual de la instancia.');
        try {
            $recheck = $this->decode($this->runner->runUpgradePlan($this->path($instance), $target));
            if (! $this->compatible($recheck)) throw new RuntimeException('El preflight actualizado bloqueó la actualización.');
            $execution = $this->decode($this->runner->executeUpgrade($this->path($instance), $target));
            if (! $this->successful($execution)) throw new RuntimeException('El comando de actualización reportó fallo.');
            $post = $this->inspect($instance);
            if (($post['status'] ?? null) !== 'CURRENT') throw new RuntimeException('La reinspección no confirmó la versión canónica.');
            $audit->update(['status' => 'COMPLETED', 'compatibility' => 'COMPATIBLE', 'finished_at' => now(), 'summary_json' => $this->sanitize(['preflight' => $recheck, 'execution' => $execution, 'post_inspection' => $post])]);
            $instance->update(['last_update_at' => now(), 'last_update_status' => 'COMPLETED']);
            return ['execution' => $execution, 'inspection' => $post];
        } catch (Throwable $e) {
            $audit->update(['status' => 'FAILED', 'finished_at' => now(), 'summary_json' => ['error' => 'La actualización no se completó.']]);
            $instance->update(['upgrade_status' => 'BLOCKED', 'last_update_at' => now(), 'last_update_status' => 'FAILED']);
            throw $e;
        }
    }

    public function executeAdoption(IkontrolInstance $instance, IkontrolUpgradeAudit $audit): array
    {
        $this->assertAudit($instance, $audit, 'ADOPTION_READY');
        if ($instance->upgrade_status !== 'LEGACY_ADOPTABLE') throw new RuntimeException('La instancia ya no está lista para adopción.');
        try {
            $recheck = $this->decode($this->runner->runAdoptBaseline($this->path($instance)));
            if (! $this->compatible($recheck)) throw new RuntimeException('El dry-run actualizado bloqueó la adopción.');
            $execution = $this->decode($this->runner->runAdoptBaseline($this->path($instance), true));
            if (! $this->successful($execution)) throw new RuntimeException('El comando de adopción reportó fallo.');
            $instance->update(['installation_origin' => 'ADOPTED']);
            $post = $this->inspect($instance);
            if (in_array($post['status'] ?? null, ['LEGACY_ADOPTABLE', 'UNREACHABLE', 'BLOCKED'], true)) throw new RuntimeException('La reinspección no confirmó la adopción.');
            $audit->update(['status' => 'ADOPTED', 'compatibility' => 'COMPATIBLE', 'finished_at' => now(), 'summary_json' => $this->sanitize(['dry_run' => $recheck, 'execution' => $execution, 'post_inspection' => $post])]);
            return ['execution' => $execution, 'inspection' => $post];
        } catch (Throwable $e) {
            $audit->update(['status' => 'FAILED', 'finished_at' => now(), 'summary_json' => ['error' => 'La adopción no se completó.']]);
            $instance->update(['upgrade_status' => 'BLOCKED']);
            throw $e;
        }
    }

    public function canonicalVersion(): string
    {
        $version = (string) config('ikontrol.upgrade.canonical_version', '1.1.4');
        if (! preg_match('/\A\d+\.\d+\.\d+(?:-[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?\z/', $version)) throw new RuntimeException('La versión canónica configurada no es válida.');
        return $version;
    }

    private function newAudit(IkontrolInstance $instance, string $target, string $status): IkontrolUpgradeAudit
    {
        return IkontrolUpgradeAudit::create(['ikontrol_instance_id' => $instance->id, 'source_version' => $instance->detected_version, 'target_version' => $target, 'status' => $status, 'started_at' => now()]);
    }

    private function failAudit(IkontrolUpgradeAudit $audit, IkontrolInstance $instance, string $message): void
    {
        $audit->update(['status' => 'FAILED', 'finished_at' => now(), 'summary_json' => ['error' => $message]]);
        $instance->update(['upgrade_status' => 'BLOCKED', 'last_upgrade_audit_at' => now()]);
    }

    private function assertAudit(IkontrolInstance $instance, IkontrolUpgradeAudit $audit, string $status): void
    {
        if ($audit->ikontrol_instance_id !== $instance->id || $audit->status !== $status || $audit->compatibility !== 'COMPATIBLE') throw new RuntimeException('La auditoría no autoriza esta operación.');
    }

    private function decode(array $process): array
    {
        return $this->protocol->decodeProcess($process);
    }

    private function safeCheck(callable $callback): array
    {
        try { return $this->decode($callback()); } catch (Throwable) { return ['status' => 'FAILED']; }
    }

    private function currentVersion(array $result): ?string
    {
        $value = data_get($result, 'current_version', data_get($result, 'version.current', data_get($result, 'version')));
        if ($value === null || $value === '') return null;
        if (! is_string($value) || ! preg_match('/\A\d+\.\d+\.\d+(?:-[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?\z/', $value)) return null;
        return $value;
    }

    private function ready(?array $result): bool
    {
        if (! is_array($result)) return false;
        if (($result['success'] ?? null) === true || ($result['ready'] ?? null) === true) return true;
        return in_array(strtoupper((string) ($result['status'] ?? '')), ['READY', 'SUCCESS', 'OK', 'HEALTHY', 'PASSED'], true);
    }

    private function compatible(?array $result): bool
    {
        if (! is_array($result)) return false;
        if (($result['compatible'] ?? null) === true || ($result['adoptable'] ?? null) === true || ($result['can_execute'] ?? null) === true) return true;
        return in_array(strtoupper((string) ($result['compatibility'] ?? $result['status'] ?? '')), ['COMPATIBLE', 'ADOPTABLE', 'READY'], true);
    }

    private function successful(array $result): bool
    {
        return $this->ready($result) || in_array(strtoupper((string) ($result['status'] ?? '')), ['COMPLETED', 'ADOPTED'], true);
    }

    private function resultStatus(?array $result): string
    {
        return $this->ready($result) ? 'READY' : strtoupper((string) ($result['status'] ?? 'FAILED'));
    }

    private function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (preg_match('/password|secret|token|credential|authorization/i', (string) $key)) $data[$key] = '[REDACTED]';
            elseif (is_array($value)) $data[$key] = $this->sanitize($value);
        }
        return $data;
    }

    private function path(IkontrolInstance $instance): string
    {
        return (string) $instance->absolute_path;
    }
}
