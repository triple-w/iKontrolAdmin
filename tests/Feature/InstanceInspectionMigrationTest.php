<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InstanceInspectionMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('instance_inspection_snapshots');
        Schema::dropIfExists('ikontrol_instances');
        Schema::enableForeignKeyConstraints();

        Schema::create('ikontrol_instances', function (Blueprint $table) {
            $table->id();
            $table->string('schema_version')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('instance_inspection_snapshots');
        Schema::dropIfExists('ikontrol_instances');
        Schema::enableForeignKeyConstraints();

        parent::tearDown();
    }

    public function test_it_runs_on_a_clean_database(): void
    {
        $this->migration()->up();

        $this->assertInspectionSchemaExists();
    }

    public function test_it_completes_when_only_schema_status_already_exists(): void
    {
        Schema::table('ikontrol_instances', fn (Blueprint $table) => $table->string('schema_status', 30)->nullable());

        $this->migration()->up();

        $this->assertInspectionSchemaExists();
    }

    public function test_it_accepts_all_three_instance_columns_already_existing(): void
    {
        Schema::table('ikontrol_instances', function (Blueprint $table) {
            $table->string('schema_status', 30)->nullable();
            $table->dateTime('schema_checked_at')->nullable();
            $table->text('schema_error')->nullable();
        });

        $this->migration()->up();

        $this->assertInspectionSchemaExists();
    }

    public function test_it_does_not_recreate_an_existing_snapshot_table(): void
    {
        Schema::create('instance_inspection_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('existing_marker');
        });

        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn('instance_inspection_snapshots', 'existing_marker'));
    }

    public function test_it_can_run_twice_without_error(): void
    {
        $migration = $this->migration();

        $migration->up();
        $migration->up();

        $this->assertInspectionSchemaExists();
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_09_01_000006_create_instance_inspection_snapshots_table.php');
    }

    private function assertInspectionSchemaExists(): void
    {
        $this->assertTrue(Schema::hasColumn('ikontrol_instances', 'schema_status'));
        $this->assertTrue(Schema::hasColumn('ikontrol_instances', 'schema_checked_at'));
        $this->assertTrue(Schema::hasColumn('ikontrol_instances', 'schema_error'));
        $this->assertTrue(Schema::hasTable('instance_inspection_snapshots'));
        $this->assertTrue(Schema::hasColumn('instance_inspection_snapshots', 'inspected_at'));
    }
}
