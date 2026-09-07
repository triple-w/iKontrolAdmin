<?php

namespace Tests\Feature;

use App\Enums\InstallationStatus as S;
use App\Models\{AdminUser, Client, IkontrolInstance, IkontrolTemplate};
use App\Services\{AllowedArtisanRunner, AuditService, CpanelService, IkontrolDatabaseTemplateService, IkontrolDeploymentService, IkontrolInstanceConnectionService, InstanceFilesystemService, InstanceProvisioningService, VersionSourceManager};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{File, Http};
use Mockery;
use Tests\TestCase;

class TemplateEnvironmentAndRepairTest extends TestCase
{
    use RefreshDatabase;
    private string $root;

    protected function setUp(): void
    {
        parent::setUp(); $this->root=storage_path('framework/testing/ci-env-'.bin2hex(random_bytes(3))); File::ensureDirectoryExists($this->root.'/ikontrol.ikontrol.solutions/app');
        config(['ikontrol.instances_root'=>$this->root,'ikontrol.folder_suffix'=>'.ikontrol.solutions','ikontrol.db.host'=>'localhost','ikontrol.db.port'=>3307,'ikontrol.db.username'=>'global_user','ikontrol.db.password'=>'global-secret','ikontrol.db.table_prefix'=>'ikontrol_']);
    }

    protected function tearDown(): void { File::deleteDirectory($this->root); parent::tearDown(); }

    public function test_template_environment_uses_codeigniter_format_and_root_document_root(): void
    {
        $instance=$this->makeInstance(); $deployment=$this->deployment(); $result=$deployment->createTemplateEnvironment($instance); $env=File::get($instance->absolute_path.'/.env');
        foreach (["CI_ENVIRONMENT = production","app.baseURL = 'https://ikontrol.ikontrol.solutions/'","database.default.hostname = 'localhost'","database.default.database = 'test_ikontrol'","database.default.username = 'global_user'","database.default.password = 'global-secret'","database.default.DBDriver = MySQLi","database.default.DBPrefix = 'ikontrol_'","database.default.port = 3307"] as $line) $this->assertStringContainsString($line,$env);
        foreach (['APP_ENV','APP_DEBUG','APP_URL','APP_KEY','DB_CONNECTION','DB_HOST','DB_DATABASE','DB_USERNAME','DB_PASSWORD'] as $legacy) $this->assertStringNotContainsString($legacy,$env);
        $this->assertSame(realpath($instance->absolute_path),$result['document_root']); $this->assertSame(realpath($instance->absolute_path),$deployment->templateDocumentRoot($instance));
        if (PHP_OS_FAMILY !== 'Windows') $this->assertSame(0600,fileperms($instance->absolute_path.'/.env') & 0777); else $this->assertFileExists($instance->absolute_path.'/.env');
        $this->assertFileExists($instance->absolute_path.'/app/Commands/IkontrolDatabaseCheck.php');
    }

    public function test_existing_encryption_key_is_preserved_and_not_generated_again(): void
    {
        $instance=$this->makeInstance(); File::put($instance->absolute_path.'/.env',"encryption.key = 'existing-key'\nDB_PASSWORD=old\n"); $deployment=$this->deployment(); $deployment->createTemplateEnvironment($instance);
        $this->assertTrue($deployment->templateHasEncryptionKey($instance)); $env=File::get($instance->absolute_path.'/.env'); $this->assertStringContainsString("encryption.key = 'existing-key'",$env); $this->assertStringNotContainsString('DB_PASSWORD',$env);
    }

    public function test_repair_checks_database_without_touching_database_or_template_sql(): void
    {
        $instance=$this->makeInstance(); $deployment=Mockery::mock(IkontrolDeploymentService::class);
        $deployment->shouldReceive('createTemplateEnvironment')->once()->with($instance)->andReturn(['encryption_key_present'=>true]); $deployment->shouldReceive('templateHasEncryptionKey')->once()->andReturn(true); $deployment->shouldReceive('runTemplateCommand')->once()->with($instance,'ikontrol:database-check',[])->andReturn(['exit_code'=>0,'output'=>'CONNECTED']);
        $cpanel=Mockery::mock(CpanelService::class); $cpanel->shouldNotReceive('createDatabase'); $cpanel->shouldNotReceive('assignUserToDatabase');
        $database=Mockery::mock(IkontrolDatabaseTemplateService::class); $database->shouldNotReceive('import');
        $service=new InstanceProvisioningService($cpanel,Mockery::mock(InstanceFilesystemService::class),Mockery::mock(IkontrolInstanceConnectionService::class),app(AuditService::class),$deployment,null,$database);
        $result=$service->regenerateTemplateConfiguration($instance);
        $this->assertSame(S::ReadyForDomain,$result->installation_status); $this->assertDatabaseHas('admin_audit_logs',['action'=>'regenerate_instance_configuration']);
    }

    public function test_repair_fails_when_codeigniter_database_check_fails(): void
    {
        $instance=$this->makeInstance(); $deployment=Mockery::mock(IkontrolDeploymentService::class);
        $deployment->shouldReceive('createTemplateEnvironment')->andReturn([]); $deployment->shouldReceive('templateHasEncryptionKey')->andReturn(true); $deployment->shouldReceive('runTemplateCommand')->with($instance,'ikontrol:database-check',[])->andReturn(['exit_code'=>1,'output'=>'ERROR']);
        $service=new InstanceProvisioningService(Mockery::mock(CpanelService::class),Mockery::mock(InstanceFilesystemService::class),Mockery::mock(IkontrolInstanceConnectionService::class),app(AuditService::class),$deployment);
        try { $service->regenerateTemplateConfiguration($instance); $this->fail('Debió fallar.'); } catch (\RuntimeException) { $this->assertSame(S::Failed,$instance->fresh()->installation_status); }
    }

    public function test_confirm_domain_accepts_200_and_followed_redirect(): void
    {
        foreach ([['ikontrol',Http::response('ok',200)],['redirected',Http::response('login',200,['X-Guzzle-Redirect-History'=>'https://redirected.ikontrol.solutions/login'])]] as [$slug,$response]) {
            $instance=$this->makeInstance($slug); Http::fake(['*'=>$response]); $this->service()->confirmDomain($instance); $this->assertSame(S::Ready,$instance->fresh()->installation_status);
        }
    }

    public function test_confirm_domain_404_and_500_are_controlled_by_controller(): void
    {
        $admin=AdminUser::create(['name'=>'Admin','email'=>'domain@test.local','password'=>'password','active'=>true]);
        Http::fake(fn ($request) => Http::response('', str_contains($request->url(),'status500') ? 500 : 404));
        foreach ([404=>'HTTP 404',500=>'HTTP 500'] as $status=>$message) { $instance=$this->makeInstance('status'.$status); $response=$this->actingAs($admin)->post(route('provisioning.confirm-domain',$instance));$response->assertRedirect()->assertSessionHas('error');$this->assertStringContainsString($message,(string)$response->getSession()->get('error')); $this->assertSame(S::ReadyForDomain,$instance->fresh()->installation_status); }
    }

    private function deployment(): IkontrolDeploymentService { return new IkontrolDeploymentService(Mockery::mock(VersionSourceManager::class),Mockery::mock(AllowedArtisanRunner::class)); }
    private function service(): InstanceProvisioningService { return new InstanceProvisioningService(Mockery::mock(CpanelService::class),Mockery::mock(InstanceFilesystemService::class),Mockery::mock(IkontrolInstanceConnectionService::class),app(AuditService::class)); }
    private function makeInstance(string $slug='ikontrol'): IkontrolInstance { $client=Client::firstOrCreate(['name'=>'Test'],['slug'=>'test','active'=>true]);$template=IkontrolTemplate::firstOrCreate(['version'=>'2.0.0'],['name'=>'Base','app_version'=>'2.0.0','schema_version'=>'1','archive_path'=>'2.0.0/a.zip','database_dump_path'=>'2.0.0/a.sql','archive_sha256'=>str_repeat('a',64),'database_sha256'=>str_repeat('b',64),'active'=>true]);$path=$this->root.'/'.$slug.'.ikontrol.solutions';File::ensureDirectoryExists($path.'/app');return IkontrolInstance::create(['client_id'=>$client->id,'ikontrol_template_id'=>$template->id,'name'=>'iKontrol','slug'=>$slug,'folder_name'=>$slug.'.ikontrol.solutions','absolute_path'=>$path,'db_name'=>'test_'.$slug,'installation_status'=>S::ReadyForDomain]); }
}
