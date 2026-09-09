<?php

namespace Tests\Feature;

use App\Enums\InstallationStatus;
use App\Models\{Client, IkontrolTemplate};
use App\Services\{AllowedArtisanRunner, AllowedSparkRunner, AuditService, CpanelService, IkontrolDatabaseTemplateService, IkontrolDeploymentService, IkontrolInstanceConnectionService, IkontrolInstanceOperationsService, IkontrolTemplateValidationService, InstanceComprehensiveDiagnosticService, InstanceFilesystemService, InstanceLogService, InstanceProvisioningService, VersionSourceManager};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{File, Http};
use Mockery;
use Tests\TestCase;
use ZipArchive;

class InstanceRecoveryEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private string $root;
    private string $templates;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/recovery-'.uniqid());
        $this->templates = $this->root.'/templates';
        File::ensureDirectoryExists($this->root);
        config(['ikontrol.instances_root' => $this->root, 'ikontrol.templates.root' => $this->templates, 'ikontrol.folder_suffix' => '.ikontrol.solutions', 'ikontrol.db.host' => 'localhost', 'ikontrol.db.port' => 3306, 'ikontrol.db.username' => 'sandbox_user', 'ikontrol.db.password' => 'SandboxSecret!', 'ikontrol.db.prefix' => 'sandbox_', 'ikontrol.db.table_prefix' => 'ikontrol_', 'ikontrol.deployment.schema_tables.sandbox-schema' => []]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_instance_can_be_provisioned_diagnosed_and_recovered_end_to_end(): void
    {
        $this->withoutExceptionHandling();
        [$template, $client] = $this->fixture();
        $cpanel = Mockery::mock(CpanelService::class);
        $cpanel->shouldReceive('listDatabases')->twice()->andReturn([]);
        $cpanel->shouldReceive('databaseExists')->once()->with('sandbox_sandbox')->andReturn(false);
        $cpanel->shouldReceive('createDatabase')->once()->with('sandbox_sandbox');
        $cpanel->shouldReceive('assignUserToDatabase')->once()->with('sandbox_sandbox');
        $connection = Mockery::mock(IkontrolInstanceConnectionService::class);
        $connection->shouldReceive('testGlobalConnection')->twice()->andReturn(['success' => true]);
        $connection->shouldReceive('test')->andReturn(['success' => true, 'status' => 'CONNECTED']);
        $connection->shouldReceive('withInstanceConnection')->andReturn(['ikontrol_users', 'ikontrol_roles', 'ikontrol_team', 'ikontrol_settings', 'ikontrol_dashboards', 'ikontrol_custom_widgets']);
        $database = Mockery::mock(IkontrolDatabaseTemplateService::class);
        $database->shouldReceive('import')->once();
        $deployment = $this->deployment();
        app(IkontrolTemplateValidationService::class)->validate($template);
        $provisioning = new InstanceProvisioningService($cpanel, app(InstanceFilesystemService::class), $connection, app(AuditService::class), $deployment, app(IkontrolTemplateValidationService::class), $database);
        $preflight=$provisioning->preflight('sandbox',$template);
        $this->assertTrue($preflight['success'],json_encode($preflight['checks']));
        $instance = $provisioning->provision(['client_mode' => 'existing', 'client_id' => $client->id, 'name' => 'Sandbox', 'slug' => 'sandbox', 'ikontrol_template_id' => $template->id, 'is_test' => true]);
        $this->assertSame(InstallationStatus::ReadyForDomain, $instance->installation_status, json_encode($instance->installationLogs()->get(['step','status','message'])->toArray()));

        Http::fake(['*' => Http::response('', 200, ['Content-Type' => 'text/html'])]);
        $diagnostics = new InstanceComprehensiveDiagnosticService($connection, $deployment, app(AuditService::class));
        $before = $diagnostics->diagnose($instance);
        $this->assertSame('WARNING', $before->status);
        $this->assertContains('MANAGED_COMMANDS_MISSING', array_column($before->recommendations, 'code'));

        $tools = $diagnostics->installTools($instance);
        $this->assertSame('SPARK_DISCOVERY_AND_JSON_PROTOCOL_OK', $tools['verification_result']);
        $operations = new IkontrolInstanceOperationsService($deployment, $connection, $cpanel, app(AuditService::class), app(InstanceFilesystemService::class), $provisioning);
        $admin = $operations->provisionAdmin($instance, 'Sandbox Admin', 'admin@sandbox.test', 'SafeSandboxPassword!');
        $this->assertSame('ADMIN_DIAGNOSE_READY', $admin['verification_result']);

        $log = app(InstanceLogService::class);
        $runtime = app(\App\Services\InstanceRuntimeDiagnosticService::class);
        $this->assertSame('READY', $runtime->generateTestLog($instance)['status']);
        $this->assertNotEmpty($log->listing($instance));
        $this->assertStringContainsString('IKONTROL_ADMIN_LOG_CHECK', $log->read($instance, $log->listing($instance)[0]['name'])['content']);

        $after = $diagnostics->diagnose($instance, 'admin@sandbox.test');
        $this->assertSame('HEALTHY', $after->status);
        $this->assertSame('READY', data_get($after->checks, 'admin.status'));
        $this->assertSame('READY', data_get($after->checks, 'dashboard.status'));
    }

    private function fixture(): array
    {
        $directory = $this->templates.'/0.0.1'; File::ensureDirectoryExists($directory);
        $archive = $directory.'/sandbox.zip'; $sql = $directory.'/sandbox.sql'; File::put($sql, '-- sandbox baseline');
        $zip = new ZipArchive(); $zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('index.php', '<?php'); $zip->addFromString('.env.example', 'CI_ENVIRONMENT = production');
        $zip->addEmptyDir('app'); $zip->addEmptyDir('system'); $zip->addEmptyDir('writable'); $zip->addEmptyDir('writable/logs'); $zip->addEmptyDir('writable/session');
        $zip->addFromString('spark', $this->sparkHarness()); $zip->close();
        $template = IkontrolTemplate::create(['version' => '0.0.1', 'name' => 'Sandbox', 'app_version' => '0.0.1', 'schema_version' => 'sandbox-schema', 'archive_path' => '0.0.1/sandbox.zip', 'database_dump_path' => '0.0.1/sandbox.sql', 'archive_sha256' => hash_file('sha256', $archive), 'database_sha256' => hash_file('sha256', $sql), 'active' => true, 'is_default' => true]);
        return [$template, Client::create(['name' => 'Sandbox', 'active' => true])];
    }

    private function deployment(): IkontrolDeploymentService
    {
        return new IkontrolDeploymentService(app(VersionSourceManager::class), app(AllowedArtisanRunner::class), app(IkontrolTemplateValidationService::class), new AllowedSparkRunner());
    }

    private function sparkHarness(): string
    {
        return <<<'PHP'
<?php
$command=$argv[1]??'list';$root=__DIR__;
echo "CodeIgniter v4.6.1 Command Line Tool - Server Time: 2026-09-09 12:00:00 UTC\n";
$json=function(array$data){echo "IKONTROL_JSON_BEGIN\n".json_encode($data)."\nIKONTROL_JSON_END\n";};
if($command==='list'){foreach(glob($root.'/app/Commands/*.php')?:[]as$file){preg_match_all('/ikontrol:[a-z:-]+/',file_get_contents($file),$m);foreach(array_unique($m[0])as$name)echo $name.PHP_EOL;}exit(0);}
if($command==='key:generate'){file_put_contents($root.'/.env',"encryption.key = 'sandbox-key'\n",FILE_APPEND);echo "KEY_GENERATED\n";exit(0);}
if(in_array($command,['cache:clear','migrate'],true)){echo 'OK';exit(0);}
if($command==='ikontrol:database-check'){$json(['status'=>'READY']);exit(0);}
if($command==='migrate:status'){echo "Migration  Batch  Status\n001  1  up\n";exit(0);}
if($command==='ikontrol:logging-status'){$json(['status'=>'OK','threshold'=>4,'writable'=>true]);exit(0);}
if($command==='ikontrol:log-check'){$dir=$root.'/writable/logs';@mkdir($dir,0775,true);$file=$dir.'/log-sandbox.log';file_put_contents($file,"ERROR - IKONTROL_ADMIN_LOG_CHECK\n",FILE_APPEND);$json(['status'=>'READY','test_file_created'=>true,'test_file'=>'log-sandbox.log','logger_threshold'=>4]);exit(0);}
if($command==='ikontrol:admin-provision'){$password=trim(stream_get_contents(STDIN));if(strlen($password)<12)exit(2);file_put_contents($root.'/writable/admin.json',json_encode(['email'=>strtolower($argv[3]),'profile'=>true]));echo 'ADMIN_CREATED';exit(0);}
if($command==='ikontrol:admin-diagnose'){$admin=is_file($root.'/writable/admin.json')?json_decode(file_get_contents($root.'/writable/admin.json'),true):null;$ready=$admin&&$admin['email']===strtolower($argv[2])&&$admin['profile'];$json(['status'=>$ready?'READY':'FAILED','user_exists'=>(bool)$admin,'role_ok'=>$ready,'profile_ok'=>$ready]);exit(0);}
if($command==='ikontrol:dashboard-check'){$admin=is_file($root.'/writable/admin.json');$json(['status'=>$admin?'READY':'FAILED','checks'=>['settings_table'=>true,'dashboard_baseline'=>$admin]]);exit(0);}
exit(1);
PHP;
    }
}
