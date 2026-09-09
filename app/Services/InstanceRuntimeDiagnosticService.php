<?php

namespace App\Services;

use App\Models\IkontrolInstance;
use RuntimeException;

class InstanceRuntimeDiagnosticService
{
    public function __construct(
        private IkontrolDeploymentService $deployment,
        private AuditService $audit,
    ) {}

    public function loggingStatus(IkontrolInstance $instance): array
    {
        return $this->runJson($instance, 'ikontrol:logging-status');
    }

    public function generateTestLog(IkontrolInstance $instance): array
    {
        $result = $this->runJson($instance, 'ikontrol:log-check');
        $this->audit->record('instance_log_check', 'Prueba controlada del logger ejecutada.', $instance, [
            'success' => ($result['status'] ?? null) === 'SUCCESS',
        ]);

        return $result;
    }

    public function diagnoseAdmin(IkontrolInstance $instance, string $email): array
    {
        return $this->diagnose($instance, 'ikontrol:admin-diagnose', $email, 'instance_admin_diagnose');
    }

    public function diagnoseDashboard(IkontrolInstance $instance, string $email): array
    {
        return $this->diagnose($instance, 'ikontrol:dashboard-check', $email, 'instance_dashboard_diagnose');
    }

    private function diagnose(IkontrolInstance $instance, string $command, string $email, string $action): array
    {
        $this->deployment->installOperationalCommandsFor($instance);
        $process = $this->deployment->runDiagnosticCommand($instance, $command, strtolower(trim($email)));
        $result = $this->decode($process);
        $this->audit->record($action, 'Diagnóstico de solo lectura ejecutado.', $instance, [
            'status' => $result['status'] ?? 'FAILED',
            'duration_ms' => $process['duration_ms'] ?? 0,
        ]);

        return $result + ['duration_ms' => $process['duration_ms'] ?? 0];
    }

    private function runJson(IkontrolInstance $instance, string $command): array
    {
        return $this->decode($this->deployment->runTemplateCommand($instance, $command));
    }

    private function decode(array $process): array
    {
        $decoded = json_decode(trim((string) ($process['output'] ?? '')), true);
        if (($process['exit_code'] ?? 1) !== 0 || ! is_array($decoded)) {
            throw new RuntimeException('El diagnóstico de la instancia no pudo completarse.');
        }

        return $decoded;
    }
}
