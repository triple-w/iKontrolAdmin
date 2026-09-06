<?php

namespace Tests\Unit;

use App\Enums\InstallationStatus as S;
use App\Models\{Client, IkontrolInstance, IkontrolTemplate, InstanceInstallationLog};
use App\Services\{AuditService, CpanelService, IkontrolDatabaseTemplateService, IkontrolDeploymentService, IkontrolInstanceConnectionService, IkontrolTemplateValidationService, InstanceFilesystemService, InstanceProvisioningService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class TemplateProvisioningSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_dry_run_has_no_side_effects_and_detects_existing_database(): void
    {
        config(['ikontrol.instances_root' => 'C:\\safe', 'ikontrol.db.prefix' => 'test_']);
        $template = new IkontrolTemplate(['version'=>'1.0.0','app_version'=>'1.0.0','schema_version'=>'1','active'=>true]);
        $validator = Mockery::mock(IkontrolTemplateValidationService::class); $validator->shouldReceive('validate')->once()->with($template)->andReturn([]);
        $fs = Mockery::mock(InstanceFilesystemService::class);
        $fs->shouldReceive('validateSlug')->andReturn(true); $fs->shouldReceive('folderName')->andReturn('prueba.ikontrol.solutions'); $fs->shouldReceive('path')->andReturn('C:\\safe\\prueba.ikontrol.solutions'); $fs->shouldReceive('folderExists')->once()->andReturn(false); $fs->shouldReceive('rootWritable')->once()->andReturn(true); $fs->shouldNotReceive('createFolder'); $fs->shouldNotReceive('removeEmptyFolder');
        $cpanel = Mockery::mock(CpanelService::class); $cpanel->shouldReceive('listDatabases')->once()->andReturn(['test_prueba']); $cpanel->shouldNotReceive('createDatabase'); $cpanel->shouldNotReceive('assignUserToDatabase');
        $connection = Mockery::mock(IkontrolInstanceConnectionService::class); $connection->shouldReceive('testGlobalConnection')->once()->andReturn(['success'=>true]);
        $service = new InstanceProvisioningService($cpanel, $fs, $connection, Mockery::mock(AuditService::class), null, $validator);

        $result = $service->dryRun('prueba', $template);

        $this->assertTrue($result['dry_run']); $this->assertFalse($result['checks']['database_not_existing']); $this->assertFalse($result['success']);
    }

    public function test_failed_sql_import_is_logged_without_cleanup_or_secrets(): void
    {
        config(['ikontrol.db.password'=>'very-secret-password']);
        $client = Client::create(['name'=>'Prueba','slug'=>'prueba','active'=>true]);
        $template = IkontrolTemplate::create(['version'=>'1.0.0','name'=>'Base','app_version'=>'1.0.0','schema_version'=>'1','archive_path'=>'1.0.0/base.zip','database_dump_path'=>'1.0.0/base.sql','archive_sha256'=>str_repeat('a',64),'database_sha256'=>str_repeat('b',64),'active'=>true]);
        $instance = IkontrolInstance::create(['client_id'=>$client->id,'ikontrol_template_id'=>$template->id,'name'=>'Prueba','slug'=>'prueba','folder_name'=>'prueba.ikontrol.solutions','db_name'=>'test_prueba','installation_status'=>S::Failed]);
        InstanceInstallationLog::create(['instance_id'=>$instance->id,'step'=>S::ImportingDatabaseTemplate->value,'status'=>'FAILED','message'=>'fallo','created_at'=>now()]);
        $database = Mockery::mock(IkontrolDatabaseTemplateService::class); $database->shouldReceive('import')->once()->andThrow(new RuntimeException('password=very-secret-password import failed'));
        $fs = Mockery::mock(InstanceFilesystemService::class); $fs->shouldNotReceive('removeEmptyFolder');
        $cpanel = Mockery::mock(CpanelService::class); $connection = Mockery::mock(IkontrolInstanceConnectionService::class);
        $service = new InstanceProvisioningService($cpanel, $fs, $connection, Mockery::mock(AuditService::class), Mockery::mock(IkontrolDeploymentService::class), null, $database);

        $result = $service->retry($instance);

        $this->assertSame(S::Failed, $result->installation_status);
        $message = $result->installationLogs()->where('status','FAILED')->latest('id')->value('message');
        $this->assertStringNotContainsString('very-secret-password', $message); $this->assertStringContainsString('[REDACTED]', $message);
    }
}
