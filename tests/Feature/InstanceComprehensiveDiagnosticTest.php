<?php

namespace Tests\Feature;

use App\Enums\InstallationStatus;
use App\Models\{Client, IkontrolInstance, IkontrolTemplate};
use App\Services\{AuditService, IkontrolDeploymentService, IkontrolInstanceConnectionService, InstanceComprehensiveDiagnosticService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{File, Http};
use Mockery;
use RuntimeException;
use Tests\TestCase;

class InstanceComprehensiveDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    private string $root;
    private IkontrolInstance $instance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/comprehensive-'.uniqid());
        config(['ikontrol.instances_root' => $this->root, 'ikontrol.folder_suffix' => '.ikontrol.solutions', 'ikontrol.db.username' => 'global_user', 'ikontrol.db.password' => 'NeverPersistThisSecret']);
        $template = IkontrolTemplate::create(['version' => uniqid(), 'name' => 'Base', 'app_version' => '2', 'schema_version' => 'schema-1', 'archive_path' => 'a', 'database_dump_path' => 'b', 'archive_sha256' => str_repeat('a', 64), 'database_sha256' => str_repeat('b', 64), 'active' => true]);
        $client = Client::create(['name' => 'Test', 'active' => true]);
        $path = $this->root.'/general.ikontrol.solutions';
        foreach (['app', 'system', 'writable/logs', 'writable/session'] as $directory) File::ensureDirectoryExists($path.'/'.$directory);
        foreach (['index.php', 'spark'] as $file) File::put($path.'/'.$file, 'ok');
        $this->instance = IkontrolInstance::create(['client_id' => $client->id, 'ikontrol_template_id' => $template->id, 'name' => 'General', 'slug' => 'general', 'folder_name' => 'general.ikontrol.solutions', 'absolute_path' => $path, 'url' => 'https://general.ikontrol.solutions/', 'db_name' => 'general_db', 'installation_status' => InstallationStatus::Failed]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_correct_environment_and_healthy_checks_create_sanitized_snapshot(): void
    {
        $this->writeEnv('general_db', 'global_user', 'https://general.ikontrol.solutions/');
        $service = $this->service(200, "Migration  Batch  Status\n001  1  up", true);
        $snapshot = $service->diagnose($this->instance);
        $this->assertSame('HEALTHY', $snapshot->status);
        $this->assertSame('MATCH', data_get($snapshot->checks, 'environment.comparison.database'));
        $this->assertStringNotContainsString('NeverPersistThisSecret', json_encode($snapshot->toArray()));
    }

    public function test_env_mismatch_missing_commands_http_500_and_pending_migration_create_plan(): void
    {
        $this->writeEnv('wrong_db', 'wrong_user', 'https://wrong.example/');
        $snapshot = $this->service(500, "Migration  Batch  Status\n001  ---  down", false)->diagnose($this->instance, 'admin@example.test');
        $this->assertSame('FAILED', $snapshot->status);
        $codes = array_column($snapshot->recommendations, 'code');
        $this->assertContains('ENV_CONFIGURATION_MISMATCH', $codes);
        $this->assertContains('MANAGED_COMMANDS_MISSING', $codes);
        $this->assertContains('MIGRATIONS_PENDING', $codes);
        $this->assertContains('HTTP_500', $codes);
        $this->assertSame(500, data_get($snapshot->checks, 'http.final_status'));
    }

    public function test_install_tools_and_repair_writable_are_controlled_and_audited(): void
    {
        File::deleteDirectory($this->instance->absolute_path.'/writable/logs');
        $deployment = Mockery::mock(IkontrolDeploymentService::class);
        $deployment->shouldReceive('installOperationalCommandsFor')->once()->with($this->instance);
        $service = new InstanceComprehensiveDiagnosticService(Mockery::mock(IkontrolInstanceConnectionService::class), $deployment, app(AuditService::class));
        $service->installTools($this->instance);
        $result = $service->repairWritable($this->instance);
        $this->assertTrue($result['writable/logs']);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'install_instance_diagnostic_tools']);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'repair_instance_writable']);
    }

    public function test_installed_tools_have_versioned_hash_manifest_and_tampering_is_rejected(): void
    {
        $deployment = app(IkontrolDeploymentService::class);
        $deployment->installOperationalCommandsFor($this->instance);
        $manifest = json_decode(File::get($this->instance->absolute_path.'/.ikontroladmin-diagnostic-tools.json'), true);
        $this->assertSame('iKontrolAdmin', $manifest['managed_by']);
        $this->assertArrayHasKey('IkontrolLogCheck.php', $manifest['files']);
        File::append($this->instance->absolute_path.'/app/Commands/IkontrolLogCheck.php', "\n// tampered");
        $this->expectException(RuntimeException::class);
        $deployment->installOperationalCommandsFor($this->instance);
    }

    public function test_writable_repair_rejects_instance_path_outside_root(): void
    {
        $this->instance->absolute_path = dirname($this->root);
        $this->expectException(RuntimeException::class);
        (new InstanceComprehensiveDiagnosticService(Mockery::mock(IkontrolInstanceConnectionService::class), Mockery::mock(IkontrolDeploymentService::class), app(AuditService::class)))->repairWritable($this->instance);
    }

    private function service(int $httpStatus, string $migrationOutput, bool $commandsAvailable): InstanceComprehensiveDiagnosticService
    {
        Http::fake(['*' => Http::response('', $httpStatus, ['Content-Type' => 'text/html'])]);
        $connection = Mockery::mock(IkontrolInstanceConnectionService::class);
        $connection->shouldReceive('test')->once()->andReturn(['success' => true, 'status' => 'CONNECTED']);
        $connection->shouldReceive('withInstanceConnection')->once()->andReturn(['ikontrol_users', 'ikontrol_settings']);
        $deployment = Mockery::mock(IkontrolDeploymentService::class);
        $list = $commandsAvailable ? implode("\n", ['ikontrol:database-check', 'ikontrol:log-check', 'ikontrol:admin-diagnose', 'ikontrol:dashboard-check']) : 'CodeIgniter commands';
        $deployment->shouldReceive('runTemplateCommand')->with($this->instance, 'list')->andReturn(['exit_code' => 0, 'output' => $list]);
        $deployment->shouldReceive('runTemplateCommand')->with($this->instance, 'ikontrol:database-check')->andReturn(['exit_code' => 0, 'output' => '{"status":"OK"}']);
        $deployment->shouldReceive('runTemplateCommand')->with($this->instance, 'ikontrol:logging-status')->andReturn(['exit_code' => 0, 'output' => '{"status":"OK","threshold":4,"writable":true}']);
        $deployment->shouldReceive('runTemplateCommand')->with($this->instance, 'migrate:status')->andReturn(['exit_code' => 0, 'output' => $migrationOutput]);
        $deployment->shouldReceive('runInstalledDiagnosticCommand')->zeroOrMoreTimes()->andReturn(['exit_code' => 0, 'output' => '{"status":"READY","profile_ok":true,"checks":{}}']);
        return new InstanceComprehensiveDiagnosticService($connection, $deployment, app(AuditService::class));
    }

    private function writeEnv(string $database, string $username, string $url): void
    {
        File::put($this->instance->absolute_path.'/.env', implode("\n", [
            'CI_ENVIRONMENT = production', "app.baseURL = '{$url}'", 'database.default.hostname = localhost', "database.default.database = '{$database}'", "database.default.username = '{$username}'", "database.default.password = 'NeverPersistThisSecret'", 'database.default.DBDriver = MySQLi', 'database.default.DBPrefix = ikontrol_', 'database.default.port = 3306', "encryption.key = 'hidden'",
        ]));
    }
}
