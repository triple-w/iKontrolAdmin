<?php

namespace App\Services;

use App\Models\IkontrolInstance;
use RuntimeException;

class InstanceRuntimeDiagnosticService
{
    public function __construct(
        private IkontrolDeploymentService $deployment,
        private AuditService $audit,
        private ?ManagedCommandJsonProtocol $jsonProtocol = null,
    ) {}

    public function loggingStatus(IkontrolInstance $instance): array
    {
        return $this->runJson($instance, 'ikontrol:logging-status');
    }

    public function generateTestLog(IkontrolInstance $instance): array
    {
        $result = $this->runJson($instance, 'ikontrol:log-check');
        if (! in_array($result['status'] ?? null, ['READY', 'SUCCESS'], true) || ! ($result['test_file_created'] ?? $result['written'] ?? false)) {
            $reason=$result['reason']??(($result['logger_threshold']??null)===0?'LOGGER_DISABLED':'FILE_NOT_CREATED');throw new RuntimeException($reason.': el logger no produjo evidencia de escritura.');
        }
        $file=(string)($result['test_file']??'');$logs=app(InstanceLogService::class);$visible=$file!==''&&in_array($file,array_column($logs->listing($instance),'name'),true);
        if(!$visible)throw new RuntimeException('LOG_NOT_VISIBLE_TO_ADMIN: el archivo generado no aparece en writable/logs.');
        $content=$logs->read($instance,$file,100)['content']??'';if(!str_contains($content,'IKONTROL_ADMIN_LOG_CHECK'))throw new RuntimeException('MARKER_NOT_FOUND: el archivo no contiene el marcador esperado.');
        $result['admin_visible']=true;$result['marker_found']=true;
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
        $process = $this->deployment->runInstalledDiagnosticCommand($instance, $command, strtolower(trim($email)));
        $result = $this->decode($process);
        $this->audit->record($action, 'Diagnóstico de solo lectura ejecutado.', $instance, [
            'status' => $result['status'] ?? 'FAILED',
            'duration_ms' => $process['duration_ms'] ?? 0,
        ]);

        return $result + [
            'command_discovered' => true,
            'execution' => 'READY',
            'reason' => $result['reason'] ?? null,
            'duration_ms' => $process['duration_ms'] ?? 0,
            'runner' => $this->runnerContext($process),
        ];
    }

    private function runJson(IkontrolInstance $instance, string $command): array
    {
        return $this->decode($this->deployment->runTemplateCommand($instance, $command));
    }

    private function decode(array $process): array
    {
        return ($this->jsonProtocol ?? app(ManagedCommandJsonProtocol::class))->decodeProcess($process);
    }

    private function runnerContext(array $process): array
    {
        return [
            'cwd' => $process['cwd'] ?? null,
            'php_binary' => $process['php_binary'] ?? null,
            'exit_code' => $process['exit_code'] ?? null,
            'stdout_tail' => $process['stdout_tail'] ?? null,
            'stderr_tail' => $process['stderr_tail'] ?? null,
        ];
    }
}
