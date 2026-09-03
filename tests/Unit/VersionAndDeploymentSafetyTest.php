<?php

namespace Tests\Unit;

use App\Models\IkontrolVersion;
use App\Services\{AllowedArtisanRunner, IkontrolDeploymentService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
}
