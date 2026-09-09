<?php

namespace Tests\Feature;

use App\Enums\InstallationStatus;
use App\Models\{Client, IkontrolInstance, IkontrolTemplate};
use App\Services\{AuditService, IkontrolDeploymentService, InstanceLogService, InstanceRuntimeDiagnosticService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class InstanceRuntimeDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    private string $root;
    private IkontrolInstance $instance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/runtime-'.uniqid());
        config(['ikontrol.instances_root' => $this->root, 'ikontrol.folder_suffix' => '.ikontrol.solutions']);
        $client = Client::create(['name' => 'Runtime', 'active' => true]);
        $template = IkontrolTemplate::create(['version' => uniqid(), 'name' => 'Base', 'app_version' => '2', 'schema_version' => '1', 'archive_path' => 'a', 'database_dump_path' => 'b', 'archive_sha256' => str_repeat('a', 64), 'database_sha256' => str_repeat('b', 64), 'active' => true]);
        $path = $this->root.'/runtime.ikontrol.solutions';
        File::ensureDirectoryExists($path.'/writable/logs');
        $this->instance = IkontrolInstance::create(['client_id' => $client->id, 'ikontrol_template_id' => $template->id, 'name' => 'Runtime', 'slug' => 'runtime', 'folder_name' => 'runtime.ikontrol.solutions', 'absolute_path' => $path, 'db_name' => 'test_runtime', 'installation_status' => InstallationStatus::Failed]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_logging_filesystem_status_handles_writable_and_missing_directory(): void
    {
        $service = new InstanceLogService(app(AuditService::class), Mockery::mock(IkontrolDeploymentService::class));
        $this->assertTrue($service->filesystemStatus($this->instance)['writable']);
        File::deleteDirectory($this->instance->absolute_path.'/writable/logs');
        $this->assertFalse($service->filesystemStatus($this->instance)['directory_exists']);
    }

    public function test_log_check_uses_fixed_allowlisted_command_and_audits(): void
    {
        File::put($this->instance->absolute_path.'/writable/logs/log-test.log', "ERROR - IKONTROL_ADMIN_LOG_CHECK\n");
        $deployment = Mockery::mock(IkontrolDeploymentService::class);
        $deployment->shouldReceive('runTemplateCommand')->once()->with($this->instance, 'ikontrol:log-check')->andReturn(['exit_code' => 0, 'stdout' => '{"status":"SUCCESS","written":true,"test_file":"log-test.log"}']);
        $result = (new InstanceRuntimeDiagnosticService($deployment, app(AuditService::class)))->generateTestLog($this->instance);
        $this->assertTrue($result['written']);
        $this->assertTrue($result['admin_visible']);
        $this->assertTrue($result['marker_found']);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'instance_log_check']);
    }

    public function test_admin_and_dashboard_diagnostics_are_structured_and_audited(): void
    {
        $deployment = Mockery::mock(IkontrolDeploymentService::class);
        $deployment->shouldReceive('installOperationalCommandsFor')->twice();
        $deployment->shouldReceive('runInstalledDiagnosticCommand')->once()->with($this->instance, 'ikontrol:admin-diagnose', 'admin@example.test')->andReturn(['exit_code' => 0, 'duration_ms' => 3, 'stdout' => '{"status":"WARNING","reason":"TEAM_MEMBER_INFO_MISSING","user_exists":true,"role_ok":true,"profile_ok":false}', 'stderr' => 'harmless warning']);
        $deployment->shouldReceive('runInstalledDiagnosticCommand')->once()->with($this->instance, 'ikontrol:dashboard-check', 'admin@example.test')->andReturn(['exit_code' => 0, 'duration_ms' => 4, 'stdout' => '{"status":"FAILED","reason":"SETTING_MISSING","checks":{"settings_table":false}}']);
        $service = new InstanceRuntimeDiagnosticService($deployment, app(AuditService::class));
        $admin = $service->diagnoseAdmin($this->instance, 'ADMIN@example.test');
        $dashboard = $service->diagnoseDashboard($this->instance, 'admin@example.test');
        $this->assertSame('TEAM_MEMBER_INFO_MISSING', $admin['reason']);
        $this->assertSame('SETTING_MISSING', $dashboard['reason']);
        $this->assertFalse($dashboard['checks']['settings_table']);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'instance_admin_diagnose']);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'instance_dashboard_diagnose']);
    }

    public function test_malformed_or_failed_diagnostic_is_not_exposed(): void
    {
        $deployment = Mockery::mock(IkontrolDeploymentService::class);
        $deployment->shouldReceive('installOperationalCommandsFor')->once();
        $deployment->shouldReceive('runInstalledDiagnosticCommand')->andReturn(['exit_code' => 1, 'stderr_tail' => 'command failed']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('COMMAND_EXECUTION_FAILED');
        (new InstanceRuntimeDiagnosticService($deployment, app(AuditService::class)))->diagnoseAdmin($this->instance, 'admin@example.test');
    }

    public function test_log_check_reports_disabled_logger_without_false_success(): void
    {
        $deployment = Mockery::mock(IkontrolDeploymentService::class);
        $deployment->shouldReceive('runTemplateCommand')->once()->andReturn(['exit_code' => 0, 'stdout' => '{"status":"FAILED","logger_threshold":0,"test_file_created":false}']);
        $this->expectExceptionMessage('LOGGER_DISABLED');
        (new InstanceRuntimeDiagnosticService($deployment, app(AuditService::class)))->generateTestLog($this->instance);
    }

    public function test_invalid_json_is_reported_even_when_command_exits_successfully(): void
    {
        $deployment = Mockery::mock(IkontrolDeploymentService::class);
        $deployment->shouldReceive('installOperationalCommandsFor')->once();
        $deployment->shouldReceive('runInstalledDiagnosticCommand')->once()->andReturn(['exit_code' => 0, 'stdout' => 'not-json', 'stderr' => 'warning']);
        $this->expectExceptionMessage('INVALID_JSON');
        (new InstanceRuntimeDiagnosticService($deployment, app(AuditService::class)))->diagnoseDashboard($this->instance, 'admin@example.test');
    }
}
