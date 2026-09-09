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
        $deployment = Mockery::mock(IkontrolDeploymentService::class);
        $deployment->shouldReceive('installOperationalCommandsFor')->once();
        $deployment->shouldReceive('runTemplateCommand')->once()->with($this->instance, 'ikontrol:log-check')->andReturn(['exit_code' => 0, 'output' => '{"status":"SUCCESS","written":true}']);
        $result = (new InstanceRuntimeDiagnosticService($deployment, app(AuditService::class)))->generateTestLog($this->instance);
        $this->assertTrue($result['written']);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'instance_log_check']);
    }

    public function test_admin_and_dashboard_diagnostics_are_structured_and_audited(): void
    {
        $deployment = Mockery::mock(IkontrolDeploymentService::class);
        $deployment->shouldReceive('installOperationalCommandsFor')->twice();
        $deployment->shouldReceive('runDiagnosticCommand')->once()->with($this->instance, 'ikontrol:admin-diagnose', 'admin@example.test')->andReturn(['exit_code' => 0, 'duration_ms' => 3, 'output' => '{"status":"WARNING","user_exists":true,"role_ok":true,"profile_ok":false}']);
        $deployment->shouldReceive('runDiagnosticCommand')->once()->with($this->instance, 'ikontrol:dashboard-check', 'admin@example.test')->andReturn(['exit_code' => 0, 'duration_ms' => 4, 'output' => '{"status":"FAILED","checks":{"settings_table":false}}']);
        $service = new InstanceRuntimeDiagnosticService($deployment, app(AuditService::class));
        $this->assertSame('WARNING', $service->diagnoseAdmin($this->instance, 'ADMIN@example.test')['status']);
        $this->assertSame('FAILED', $service->diagnoseDashboard($this->instance, 'admin@example.test')['status']);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'instance_admin_diagnose']);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'instance_dashboard_diagnose']);
    }

    public function test_malformed_or_failed_diagnostic_is_not_exposed(): void
    {
        $deployment = Mockery::mock(IkontrolDeploymentService::class);
        $deployment->shouldReceive('installOperationalCommandsFor')->once();
        $deployment->shouldReceive('runDiagnosticCommand')->andReturn(['exit_code' => 1, 'output' => 'password=secret']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no pudo completarse');
        (new InstanceRuntimeDiagnosticService($deployment, app(AuditService::class)))->diagnoseAdmin($this->instance, 'admin@example.test');
    }
}
