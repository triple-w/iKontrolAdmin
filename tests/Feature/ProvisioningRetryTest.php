<?php

namespace Tests\Feature;

use App\Enums\InstallationStatus;
use App\Models\{Client, IkontrolVersion};
use App\Services\{AuditService, CpanelService, IkontrolDeploymentService, IkontrolInstanceConnectionService, InstanceFilesystemService, InstanceProvisioningService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProvisioningRetryTest extends TestCase
{
    use RefreshDatabase;

    public static function failingSteps(): array
    {
        return [
            'validating'=>[InstallationStatus::Validating,0], 'folder'=>[InstallationStatus::CreatingFolder,1],
            'database'=>[InstallationStatus::CreatingDatabase,2], 'database user'=>[InstallationStatus::AssigningDatabaseUser,3],
            'code'=>[InstallationStatus::DeployingCode,4], 'env'=>[InstallationStatus::CreatingEnv,5],
            'dependencies'=>[InstallationStatus::InstallingDependencies,6], 'key'=>[InstallationStatus::GeneratingKey,7],
            'migrations'=>[InstallationStatus::RunningMigrations,8], 'optimizing'=>[InstallationStatus::Optimizing,9],
            'connection'=>[InstallationStatus::TestingConnection,10],
        ];
    }

    #[DataProvider('failingSteps')]
    public function test_each_provisioning_stage_records_its_failure(InstallationStatus $status, int $index): void
    {
        config(['ikontrol.db.prefix'=>'tws001_ik_','ikontrol.instances_root'=>'C:\\safe','ikontrol.folder_suffix'=>'.ikontrol.solutions']);
        $client=Client::create(['name'=>'Client '.$index,'active'=>true]);
        $version=IkontrolVersion::create(['version'=>'1.0.'.$index,'name'=>'Base','source_type'=>'archive','source_reference'=>'base.zip','active'=>true]);
        $instance=\App\Models\IkontrolInstance::create(['client_id'=>$client->id,'ikontrol_version_id'=>$version->id,'name'=>'Prueba','slug'=>'prueba'.$index,'folder_name'=>'prueba'.$index.'.ikontrol.solutions','absolute_path'=>'C:\\safe\\prueba'.$index.'.ikontrol.solutions','db_host'=>'localhost','db_port'=>3306,'db_name'=>'test_'.$index,'installation_status'=>InstallationStatus::Pending]);
        $fs=Mockery::mock(InstanceFilesystemService::class)->shouldIgnoreMissing();
        $cpanel=Mockery::mock(CpanelService::class)->shouldIgnoreMissing();
        $connection=Mockery::mock(IkontrolInstanceConnectionService::class)->shouldIgnoreMissing();
        $deployment=Mockery::mock(IkontrolDeploymentService::class)->shouldIgnoreMissing();
        $audit=Mockery::mock(AuditService::class)->shouldIgnoreMissing();
        if ($status === InstallationStatus::Validating) { $fs->shouldReceive('folderName')->andReturn($instance->folder_name); $fs->shouldReceive('path')->andReturn($instance->absolute_path); $fs->shouldReceive('validateSlug')->andThrow(new RuntimeException('stage failed')); }
        if ($status === InstallationStatus::CreatingFolder) { $fs->shouldReceive('folderExists')->andReturnFalse(); $fs->shouldReceive('createFolder')->andThrow(new RuntimeException('stage failed')); }
        if ($status === InstallationStatus::CreatingDatabase) { $cpanel->shouldReceive('databaseExists')->andReturnFalse(); $cpanel->shouldReceive('createDatabase')->andThrow(new RuntimeException('stage failed')); }
        if ($status === InstallationStatus::AssigningDatabaseUser) $cpanel->shouldReceive('assignUserToDatabase')->andThrow(new RuntimeException('stage failed'));
        if ($status === InstallationStatus::DeployingCode) $deployment->shouldReceive('deployCode')->andThrow(new RuntimeException('stage failed'));
        if ($status === InstallationStatus::CreatingEnv) $deployment->shouldReceive('createEnvironment')->andThrow(new RuntimeException('stage failed'));
        if ($status === InstallationStatus::InstallingDependencies) $deployment->shouldReceive('verifyDependencies')->andThrow(new RuntimeException('stage failed'));
        if ($status === InstallationStatus::GeneratingKey) $deployment->shouldReceive('run')->with($instance, 'key:generate', ['--force'])->andReturn(['exit_code'=>1,'output'=>'stage failed']);
        if ($status === InstallationStatus::RunningMigrations) $deployment->shouldReceive('run')->with($instance, 'migrate', ['--force'])->andReturn(['exit_code'=>1,'output'=>'stage failed']);
        if ($status === InstallationStatus::Optimizing) $deployment->shouldReceive('run')->with($instance, 'optimize:clear', [])->andReturn(['exit_code'=>1,'output'=>'stage failed']);
        if ($status === InstallationStatus::TestingConnection) $connection->shouldReceive('test')->andReturn(['success'=>false]);
        $service=new InstanceProvisioningService($cpanel,$fs,$connection,$audit,$deployment);
        $method=new \ReflectionMethod($service,'run');
        $result=$method->invoke($service,$instance,$version,$index);

        $this->assertSame(InstallationStatus::Failed,$result->installation_status);
        $this->assertDatabaseHas('instance_installation_logs',['instance_id'=>$instance->id,'step'=>$status->value,'status'=>'FAILED']);
    }

    public function test_failure_is_logged_without_cleanup_and_retry_resumes_failed_step(): void
    {
        config(['ikontrol.db.prefix'=>'tws001_ik_','ikontrol.db.host'=>'localhost','ikontrol.db.port'=>3306,'ikontrol.instances_root'=>'C:\\safe','ikontrol.folder_suffix'=>'.ikontrol.solutions','ikontrol.db.password'=>'super-secret-value']);
        $client = Client::create(['name'=>'PRUEBA IKONTROL','active'=>true]);
        $version = IkontrolVersion::create(['version'=>'1.0.0','name'=>'Base','source_type'=>'archive','source_reference'=>'base.zip','active'=>true,'is_default'=>true]);
        $fs = Mockery::mock(InstanceFilesystemService::class);
        $fs->shouldReceive('validateSlug')->andReturnTrue();
        $fs->shouldReceive('folderName')->andReturn('prueba.ikontrol.solutions');
        $fs->shouldReceive('path')->andReturn('C:\\safe\\prueba.ikontrol.solutions');
        $fs->shouldReceive('folderExists')->twice()->andReturnFalse();
        $fs->shouldReceive('rootWritable')->once()->andReturnTrue();
        $fs->shouldReceive('createFolder')->once();
        $fs->shouldNotReceive('removeEmptyFolder');
        $cpanel = Mockery::mock(CpanelService::class);
        $cpanel->shouldReceive('listDatabases')->once()->andReturn([]);
        $cpanel->shouldReceive('databaseExists')->once()->andReturnFalse();
        $cpanel->shouldReceive('createDatabase')->once();
        $cpanel->shouldReceive('assignUserToDatabase')->once();
        $connection = Mockery::mock(IkontrolInstanceConnectionService::class);
        $connection->shouldReceive('testGlobalConnection')->once()->andReturn(['success'=>true]);
        $connection->shouldReceive('test')->once()->andReturn(['success'=>true]);
        $audit = Mockery::mock(AuditService::class);
        $audit->shouldReceive('record')->once();
        $failedDeployment = Mockery::mock(IkontrolDeploymentService::class);
        $failedDeployment->shouldReceive('deployCode')->once()->andThrow(new RuntimeException('deploy failed password=super-secret-value'));
        $service = new InstanceProvisioningService($cpanel, $fs, $connection, $audit, $failedDeployment);

        $instance = $service->provision(['client_mode'=>'existing','client_id'=>$client->id,'name'=>'Prueba','slug'=>'prueba','ikontrol_version_id'=>$version->id]);

        $this->assertSame(InstallationStatus::Failed, $instance->installation_status);
        $this->assertDatabaseHas('instance_installation_logs', ['instance_id'=>$instance->id,'step'=>'DEPLOYING_CODE','status'=>'FAILED','message'=>'deploy failed password=[REDACTED]']);

        $retryDeployment = Mockery::mock(IkontrolDeploymentService::class);
        $retryDeployment->shouldReceive('deployCode')->once();
        $retryDeployment->shouldReceive('createEnvironment')->once();
        $retryDeployment->shouldReceive('verifyDependencies')->once();
        $retryDeployment->shouldReceive('run')->times(7)->andReturn(['exit_code'=>0,'output'=>'','duration_ms'=>1]);
        $retry = new InstanceProvisioningService($cpanel, $fs, $connection, $audit, $retryDeployment);

        $result = $retry->retry($instance);

        $this->assertSame(InstallationStatus::ReadyForDomain, $result->installation_status);
        $this->assertSame('1.0.0', $result->installed_version);
        $this->assertNotNull($result->installed_at);
        $this->assertSame(1, $result->installationLogs()->where('step','CREATING_DATABASE')->where('status','SUCCESS')->count());
        $this->assertSame(2, $result->installationLogs()->where('step','DEPLOYING_CODE')->where('status','STARTED')->count());
    }
}
