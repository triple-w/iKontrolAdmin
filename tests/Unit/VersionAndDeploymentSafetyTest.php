<?php

namespace Tests\Unit;

use App\Enums\InstallationStatus;
use App\Models\{Client, IkontrolInstance, IkontrolVersion};
use App\Services\{AllowedArtisanRunner, IkontrolDeploymentService, VersionSourceManager};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class VersionAndDeploymentSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_default_version_is_available_through_scope(): void
    {
        IkontrolVersion::create(['version' => '1.0.0', 'name' => 'Base 1.0.0', 'source_type' => 'archive', 'source_reference' => 'base.zip', 'active' => true, 'is_default' => true]);
        IkontrolVersion::create(['version' => '0.9.0', 'name' => 'Old', 'source_type' => 'archive', 'source_reference' => 'old.zip', 'active' => false, 'is_default' => true]);

        $this->assertSame('1.0.0', IkontrolVersion::default()->sole()->version);
    }

    public function test_deployment_rejects_database_names_with_sql_characters(): void
    {
        $service = app(IkontrolDeploymentService::class);

        $this->expectException(RuntimeException::class);
        $service->assertDatabaseNotExisting('safe_name; DROP DATABASE x');
    }

    public function test_artisan_runner_rejects_arbitrary_commands(): void
    {
        $root = storage_path('framework/testing/'.Str::random(12));
        mkdir($root, 0750, true);
        config(['ikontrol.instances_root' => $root]);
        $runner = app(AllowedArtisanRunner::class);

        try {
            $this->expectException(RuntimeException::class);
            $runner->run($root, 'shell:arbitrary', []);
        } finally {
            @rmdir($root);
        }
    }

    public function test_deployment_rejects_path_outside_generated_instance_folder(): void
    {
        $root = storage_path('framework/testing/'.Str::random(12));
        File::ensureDirectoryExists($root);
        config(['ikontrol.instances_root' => $root, 'ikontrol.folder_suffix' => '.ikontrol.solutions']);
        $instance = $this->makeInstance(['absolute_path' => dirname($root).DIRECTORY_SEPARATOR.'outside', 'folder_name' => 'prueba.ikontrol.solutions']);
        $service = new IkontrolDeploymentService(Mockery::mock(VersionSourceManager::class), Mockery::mock(AllowedArtisanRunner::class));

        try {
            $this->expectException(RuntimeException::class);
            $service->createEnvironment($instance);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_deployment_rejects_an_occupied_unmanaged_folder(): void
    {
        $root = storage_path('framework/testing/'.Str::random(12));
        $path = $root.DIRECTORY_SEPARATOR.'prueba.ikontrol.solutions';
        File::ensureDirectoryExists($path);
        File::put($path.DIRECTORY_SEPARATOR.'customer-file.txt', 'do not overwrite');
        config(['ikontrol.instances_root' => $root, 'ikontrol.folder_suffix' => '.ikontrol.solutions']);
        $instance = $this->makeInstance(['absolute_path' => $path, 'folder_name' => 'prueba.ikontrol.solutions']);
        $version = IkontrolVersion::create(['version'=>'1.2.3','name'=>'Base','source_type'=>'archive','source_reference'=>'base.zip','active'=>true]);
        $service = new IkontrolDeploymentService(Mockery::mock(VersionSourceManager::class), Mockery::mock(AllowedArtisanRunner::class));

        try {
            $this->expectException(RuntimeException::class);
            $service->deployCode($instance, $version);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_environment_is_created_atomically_with_production_settings(): void
    {
        $root = storage_path('framework/testing/'.Str::random(12));
        $path = $root.DIRECTORY_SEPARATOR.'prueba.ikontrol.solutions';
        File::ensureDirectoryExists($path);
        config(['ikontrol.instances_root'=>$root,'ikontrol.folder_suffix'=>'.ikontrol.solutions','ikontrol.db.username'=>'mysql_user','ikontrol.db.password'=>'pa$$ word']);
        $instance = $this->makeInstance(['absolute_path'=>$path,'folder_name'=>'prueba.ikontrol.solutions','db_name'=>'tws001_ik_prueba']);
        $service = new IkontrolDeploymentService(Mockery::mock(VersionSourceManager::class), Mockery::mock(AllowedArtisanRunner::class));

        try {
            $service->createEnvironment($instance);
            $contents = File::get($path.DIRECTORY_SEPARATOR.'.env');
            $this->assertStringContainsString('APP_ENV=production', $contents);
            $this->assertStringContainsString('APP_DEBUG=false', $contents);
            $this->assertStringContainsString('DB_DATABASE="tws001_ik_prueba"', $contents);
            $this->assertStringContainsString('DB_PASSWORD="pa\\$\\$ word"', $contents);
            $this->assertFileDoesNotExist($path.DIRECTORY_SEPARATOR.'.env.tmp');
        } finally {
            File::deleteDirectory($root);
        }
    }

    private function makeInstance(array $attributes = []): IkontrolInstance
    {
        $client = Client::create(['name' => Str::random(8), 'active' => true]);
        return IkontrolInstance::create(array_merge(['client_id'=>$client->id,'name'=>'Prueba','slug'=>'prueba','folder_name'=>'prueba.ikontrol.solutions','db_host'=>'localhost','db_port'=>3306,'db_name'=>'test_'.Str::random(8),'installation_status'=>InstallationStatus::Pending], $attributes));
    }
}
