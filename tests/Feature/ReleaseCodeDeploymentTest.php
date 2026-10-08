<?php

namespace Tests\Feature;

use App\Enums\InstallationStatus;
use App\Models\{AdminUser, Client, IkontrolInstance, IkontrolRelease, InstanceUpdateRun};
use App\Services\{AllowedSparkRunner, ManagedCommandJsonProtocol};
use App\Services\Upgrade\InstanceVersionManagementService;
use App\Services\Versioning\{DeploymentPlanService, ReleaseArtifactService, ReleaseCompatibilityService, ReleaseDeploymentService, StagedFileDeploymentService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class ReleaseCodeDeploymentTest extends TestCase
{
    use RefreshDatabase;

    private string $root;
    private string $artifacts;

    protected function setUp(): void
    {
        parent::setUp();
        $base = storage_path('framework/testing/release-deployment-'.bin2hex(random_bytes(5)));
        $this->root = $base.'/instances'; $this->artifacts = $base.'/artifacts';
        foreach ([$this->root, $this->artifacts, $base.'/staging', $base.'/backups'] as $path) File::ensureDirectoryExists($path);
        config(['ikontrol.instances_root' => $this->root, 'ikontrol.upgrade.canonical_version' => '1.1.5', 'ikontrol.release_deployment.artifact_root' => $this->artifacts, 'ikontrol.release_deployment.staging_root' => $base.'/staging', 'ikontrol.release_deployment.backup_root' => $base.'/backups']);
    }

    public function test_correct_artifact_is_verified_and_staged(): void
    {
        [$instance, $release] = $this->fixture(); $run = $this->updateRun($instance, $release);
        $staged = app(ReleaseArtifactService::class)->stage($release, $run);
        $this->assertFileExists($staged['path'].'/app/Test.php'); $this->assertSame('verified', $release->fresh()->artifact_verification_status);
    }

    public function test_incorrect_artifact_checksum_blocks(): void
    {
        [$instance, $release] = $this->fixture(); $release->update(['artifact_sha256' => str_repeat('0', 64)]);
        $this->expectException(RuntimeException::class); app(ReleaseArtifactService::class)->stage($release, $this->updateRun($instance, $release));
    }

    public function test_archive_path_traversal_is_blocked(): void
    {
        [$instance, $release] = $this->fixture(['../escape.php' => 'bad']);
        $this->expectException(RuntimeException::class); app(ReleaseArtifactService::class)->stage($release, $this->updateRun($instance, $release));
    }

    public function test_archive_symlink_is_blocked(): void
    {
        [$instance, $release, $archive] = $this->fixture(); $zip = new ZipArchive(); $zip->open($archive); $zip->addFromString('package/link', 'app/Test.php'); $zip->setExternalAttributesName('package/link', ZipArchive::OPSYS_UNIX, 0120777 << 16); $zip->close(); $release->update(['artifact_sha256' => hash_file('sha256', $archive)]);
        $this->expectException(RuntimeException::class); app(ReleaseArtifactService::class)->stage($release, $this->updateRun($instance, $release));
    }

    public function test_protected_files_are_rejected_by_manifest_and_never_modified(): void
    {
        [$instance, $release] = $this->fixture([], ['.env' => 'APP_KEY=evil']); File::put($instance->absolute_path.'/.env', 'APP_KEY=safe');
        try { app(ReleaseArtifactService::class)->stage($release, $this->updateRun($instance, $release)); $this->fail('Protected manifest accepted.'); } catch (RuntimeException) {}
        $this->assertSame('APP_KEY=safe', File::get($instance->absolute_path.'/.env'));
    }

    public function test_dry_run_does_not_write_instance_tree(): void
    {
        [$instance, $release] = $this->fixture(); $before = $this->snapshot($instance->absolute_path);
        $run = $this->deployment($this->runner())->prepare($instance, $release);
        $this->assertSame('DEPLOYMENT_READY', $run->status); $this->assertNotEmpty($run->deployment_plan['staging_path']); $this->assertNotEmpty($run->deployment_plan['backup_path']); $this->assertSame($before, $this->snapshot($instance->absolute_path));
    }

    public function test_plan_detects_new_file(): void { [$instance, $release] = $this->fixture(); $plan = $this->preparedPlan($instance, $release); $this->assertContains('new.php', array_column($plan['files_to_create'], 'path')); }
    public function test_plan_detects_replaceable_file(): void { [$instance, $release] = $this->fixture(); $plan = $this->preparedPlan($instance, $release); $this->assertContains('app/Test.php', array_column($plan['files_to_replace'], 'path')); }

    public function test_target_manifest_can_supply_verified_source_hashes(): void
    {
        [$instance, $release] = $this->fixture(); IkontrolRelease::where('version','1.1.4')->delete();
        $plan=$this->preparedPlan($instance,$release); $this->assertSame('DEPLOYMENT_READY',$plan['status']); $this->assertContains('app/Test.php',array_column($plan['files_to_replace'],'path'));
    }

    public function test_plan_blocks_local_conflict(): void
    {
        [$instance, $release] = $this->fixture(); File::put($instance->absolute_path.'/app/Test.php', 'customer change');
        $plan = $this->preparedPlan($instance, $release); $this->assertSame('DEPLOYMENT_CONFLICT', $plan['status']); $this->assertSame('LOCAL_MODIFICATION', $plan['local_conflicts'][0]['reason']);
    }

    public function test_database_preflight_not_ready_blocks_deployment(): void
    {
        [$instance,$release]=$this->fixture(); $instance->update(['database_status'=>'FAIL']);
        $plan=$this->preparedPlan($instance,$release); $this->assertSame('DEPLOYMENT_BLOCKED',$plan['status']); $this->assertSame('BLOCKED',$plan['database_preflight']);
    }

    public function test_deploy_replaces_and_creates_files(): void
    {
        [$instance, $release] = $this->fixture(); $run = $this->deployment($this->runner())->prepare($instance, $release);
        app(StagedFileDeploymentService::class)->deploy($instance, $run, $run->deployment_plan);
        $this->assertSame('target', File::get($instance->absolute_path.'/app/Test.php')); $this->assertSame('new', File::get($instance->absolute_path.'/new.php'));
    }

    public function test_intermediate_failure_rolls_back_installed_code(): void
    {
        [$instance, $release] = $this->fixture([], ['z/new.php' => 'new']); $run = $this->deployment($this->runner())->prepare($instance, $release); File::put($instance->absolute_path.'/z', 'blocks directory');
        try { app(StagedFileDeploymentService::class)->deploy($instance, $run, $run->deployment_plan); $this->fail('Deployment should fail.'); } catch (\Throwable) {}
        $this->assertSame('base', File::get($instance->absolute_path.'/app/Test.php')); $this->assertSame('CODE_ROLLBACK_AVAILABLE', $run->fresh()->rollback_status);
    }

    public function test_post_deploy_runs_version_plan_upgrade_and_accepts_failed_baseline(): void
    {
        [$instance, $release] = $this->fixture(); $runner = $this->runner(); $service = $this->deployment($runner); $run = $service->prepare($instance, $release);
        $this->successfulRunner($runner, $instance);
        $service->execute($instance, $run);
        $this->assertSame('completed', $run->fresh()->status); $this->assertSame('CURRENT', $instance->fresh()->upgrade_status); $this->assertSame('FAIL', $instance->fresh()->baseline_status);
    }

    public function test_exact_slug_confirmation_is_required(): void
    {
        [$instance, $release] = $this->fixture(); $run = $this->deployment($this->runner())->prepare($instance, $release); $admin = AdminUser::create(['name'=>'Admin','email'=>'deploy@example.test','password'=>'password-long-enough','active'=>true]);
        $this->actingAs($admin)->post(route('instances.update-runs.execute',[$instance,$run]),['confirmation'=>'wrong'])->assertSessionHasErrors('confirmation');
        $this->assertSame('DEPLOYMENT_READY', $run->fresh()->status);
    }

    public function test_release_different_from_canonical_is_blocked(): void
    {
        [$instance, $release] = $this->fixture(); $release->update(['version'=>'1.1.6']);
        $this->expectException(RuntimeException::class); $this->deployment($this->runner())->prepare($instance, $release);
    }

    public function test_deployment_is_idempotent_after_files_match(): void
    {
        [$instance, $release] = $this->fixture(); $service = $this->deployment($this->runner()); $run = $service->prepare($instance,$release); app(StagedFileDeploymentService::class)->deploy($instance,$run,$run->deployment_plan);
        $second = $service->prepare($instance,$release); $this->assertSame([], $second->deployment_plan['files_to_create']); $this->assertSame([], $second->deployment_plan['files_to_replace']);
    }

    public function test_prepare_endpoint_records_complete_audit(): void
    {
        [$instance, $release] = $this->fixture(); $admin=AdminUser::create(['name'=>'Admin','email'=>'audit-deploy@example.test','password'=>'password-long-enough','active'=>true]);
        $this->actingAs($admin)->post(route('instances.releases.prepare',[$instance,$release]))->assertRedirect();
        $this->assertDatabaseHas('admin_audit_logs',['action'=>'INSTANCE_RELEASE_PREPARE_STARTED','entity_id'=>$instance->id]); $this->assertDatabaseHas('admin_audit_logs',['action'=>'INSTANCE_RELEASE_PREPARE_COMPLETED','entity_id'=>$instance->id]);
    }

    public function test_successful_execution_records_start_and_finish_audit(): void
    {
        [$instance, $release] = $this->fixture(); $prepareRunner=$this->runner(); $run=$this->deployment($prepareRunner)->prepare($instance,$release);
        $runner=$this->runner(); $this->successfulRunner($runner,$instance); $this->app->instance(AllowedSparkRunner::class,$runner);
        $admin=AdminUser::create(['name'=>'Admin','email'=>'execute-audit@example.test','password'=>'password-long-enough','active'=>true]);
        $this->actingAs($admin)->post(route('instances.update-runs.execute',[$instance,$run]),['confirmation'=>$instance->slug])->assertRedirect(route('instances.show',[$instance,'tab'=>'upgrade']));
        $this->assertDatabaseHas('admin_audit_logs',['action'=>'INSTANCE_RELEASE_DEPLOYMENT_STARTED','entity_id'=>$instance->id]); $this->assertDatabaseHas('admin_audit_logs',['action'=>'INSTANCE_RELEASE_DEPLOYMENT_COMPLETED','entity_id'=>$instance->id]);
    }

    public function test_deployment_code_does_not_use_spark_migrate_or_git_pull(): void
    {
        $source = File::get(app_path('Services/Versioning/ReleaseDeploymentService.php')).File::get(app_path('Services/Versioning/StagedFileDeploymentService.php'));
        $this->assertStringNotContainsString('spark migrate', strtolower($source)); $this->assertStringNotContainsString('git pull', strtolower($source));
    }

    private function fixture(array $extraEntries = [], array $extraManaged = []): array
    {
        $client=Client::create(['name'=>'Release Client']); $path=$this->root.'/golden'; File::ensureDirectoryExists($path.'/app'); File::put($path.'/app/Test.php','base'); File::put($path.'/spark','spark'); File::put($path.'/index.php','index');
        $instance=IkontrolInstance::create(['client_id'=>$client->id,'name'=>'Golden','slug'=>'golden','folder_name'=>'golden','absolute_path'=>$path,'db_name'=>'golden_db','detected_version'=>'1.1.4','current_version'=>'1.1.4','canonical_version'=>'1.1.5','database_status'=>'READY','upgrade_status'=>'UPDATE_AVAILABLE','installation_origin'=>'EXISTING','installation_status'=>InstallationStatus::Ready,'active'=>true]);
        IkontrolRelease::create(['version'=>'1.1.4','release_identifier'=>'ikontrol-1.1.4','channel'=>'stable','git_tag'=>'v1.1.4','source_ref'=>'v1.1.4','commit_sha'=>str_repeat('a',40),'source_repository'=>'triple-w/ikontrol-platform','manifest_json'=>['from_versions'=>['1.1.3']],'artifact_manifest_json'=>['files'=>[['path'=>'app/Test.php','sha256'=>hash('sha256','base'),'size'=>4],['path'=>'spark','sha256'=>hash('sha256','spark'),'size'=>5],['path'=>'index.php','sha256'=>hash('sha256','index'),'size'=>5]]],'status'=>'validated']);
        $managed=array_merge(['app/Test.php'=>'target','spark'=>'spark','index.php'=>'index','new.php'=>'new'],$extraManaged); $commit=str_repeat('b',40); $releaseId='ikontrol-1.1.5-canonical-stamp-wallet-resolution';
        $sourceHashes=['app/Test.php'=>hash('sha256','base'),'spark'=>hash('sha256','spark'),'index.php'=>hash('sha256','index')]; $manifest=['schema_version'=>1,'product'=>'ikontrol','version'=>'1.1.5','release_id'=>$releaseId,'commit_sha'=>$commit,'files'=>[]]; foreach($managed as $name=>$body)$manifest['files'][]=['path'=>$name,'sha256'=>hash('sha256',$body),'size'=>strlen($body)]+(isset($sourceHashes[$name])?['from_sha256'=>['1.1.4'=>$sourceHashes[$name]]]:[]);
        $archive=$this->artifacts.'/1.1.5.zip'; $zip=new ZipArchive(); $zip->open($archive,ZipArchive::CREATE|ZipArchive::OVERWRITE); foreach($managed as$name=>$body)$zip->addFromString('package/'.$name,$body); $zip->addFromString('package/updates/1.1.5/deployment-manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR)); foreach($extraEntries as$name=>$body)$zip->addFromString($name,$body); $zip->close();
        $release=IkontrolRelease::create(['version'=>'1.1.5','release_identifier'=>$releaseId,'channel'=>'stable','git_tag'=>'v1.1.5','source_ref'=>'v1.1.5','commit_sha'=>$commit,'source_repository'=>'triple-w/ikontrol-platform','artifact_path'=>'1.1.5.zip','artifact_sha256'=>hash_file('sha256',$archive),'artifact_verification_status'=>'unverified','manifest_json'=>['from_versions'=>['1.1.4']],'status'=>'validated']);
        return [$instance,$release,$archive];
    }

    private function updateRun(IkontrolInstance $instance, IkontrolRelease $release): InstanceUpdateRun { return InstanceUpdateRun::create(['instance_id'=>$instance->id,'release_id'=>$release->id,'from_version'=>'1.1.4','to_version'=>'1.1.5','status'=>'preflight']); }
    private function preparedPlan(IkontrolInstance $instance, IkontrolRelease $release): array { return $this->deployment($this->runner())->prepare($instance,$release)->deployment_plan; }
    private function runner() { return Mockery::mock(AllowedSparkRunner::class); }
    private function deployment($runner): ReleaseDeploymentService { $versions=new InstanceVersionManagementService($runner,app(ManagedCommandJsonProtocol::class)); return new ReleaseDeploymentService(app(ReleaseArtifactService::class),app(DeploymentPlanService::class),app(StagedFileDeploymentService::class),app(ReleaseCompatibilityService::class),$runner,app(ManagedCommandJsonProtocol::class),$versions); }
    private function process(array $payload): array { return ['exit_code'=>0,'stdout'=>"IKONTROL_JSON_BEGIN\n".json_encode($payload,JSON_THROW_ON_ERROR)."\nIKONTROL_JSON_END",'stderr'=>'']; }
    private function successfulRunner($runner, IkontrolInstance $instance): void { $runner->shouldReceive('inspectVersion')->once()->ordered()->andReturn($this->process(['status'=>'READY','current_version'=>'1.1.4','canonical_version'=>'1.1.5'])); $runner->shouldReceive('run')->once()->ordered()->with($instance->absolute_path,'ikontrol:database-check')->andReturn($this->process(['status'=>'READY'])); $runner->shouldReceive('runUpgradePlan')->once()->ordered()->with($instance->absolute_path,'1.1.5')->andReturn($this->process(['status'=>'READY','compatible'=>true])); $runner->shouldReceive('executeUpgrade')->once()->ordered()->with($instance->absolute_path,'1.1.5')->andReturn($this->process(['status'=>'COMPLETED'])); $runner->shouldReceive('inspectVersion')->once()->ordered()->andReturn($this->process(['status'=>'READY','current_version'=>'1.1.5','canonical_version'=>'1.1.5'])); $runner->shouldReceive('run')->once()->ordered()->with($instance->absolute_path,'ikontrol:database-check')->andReturn($this->process(['status'=>'READY'])); $runner->shouldReceive('run')->once()->ordered()->with($instance->absolute_path,'ikontrol:baseline-check')->andReturn($this->process(['status'=>'FAIL'])); }
    private function snapshot(string $path): array { $result=[]; foreach(File::allFiles($path) as$file)$result[str_replace('\\','/',$file->getRelativePathname())]=hash_file('sha256',$file->getPathname()); ksort($result); return $result; }
}
