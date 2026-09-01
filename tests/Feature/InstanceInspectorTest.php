<?php

namespace Tests\Feature;

use App\Enums\InstallationStatus;
use App\Models\AdminUser;
use App\Models\Client;
use App\Models\IkontrolInstance;
use App\Models\InstanceInspectionSnapshot;
use App\Services\IkontrolInstanceConnectionService;
use App\Services\IkontrolInstanceInspectorService;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class InstanceInspectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_recognized_instance_creates_snapshot_using_only_selects(): void
    {
        $instance = $this->makeInstance();
        $executed = [];
        $connection = $this->connection(
            ['migrations' => 100, 'users' => 200, 'clients' => 300, 'products' => 400],
            ['migrations' => ['migration', 'batch'], 'users' => ['id'], 'clients' => ['id', 'updated_at'], 'products' => ['id']],
            ['users' => 3, 'clients' => 125, 'products' => 489],
            [['migration' => '2026_01_01_final', 'batch' => 4]],
            '2026-08-01 10:00:00',
            $executed,
        );
        $service = $this->serviceWith($connection);

        $result = $service->inspect($instance);

        $this->assertTrue($result['success']);
        $this->assertSame('SCHEMA_RECOGNIZED', $result['schema_status']);
        $this->assertSame(125, $result['counts']['clients']['value']);
        $this->assertDatabaseHas('instance_inspection_snapshots', ['instance_id' => $instance->id, 'schema_status' => 'SCHEMA_RECOGNIZED']);
        foreach ($executed as $sql) {
            $this->assertMatchesRegularExpression('/\ASELECT\b/i', ltrim($sql));
            $this->assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|TRUNCATE)\b/i', $sql);
        }
    }

    public function test_missing_tables_are_handled_without_error(): void
    {
        $result = $this->serviceWith($this->connection([], [], []))->inspect($this->makeInstance());

        $this->assertTrue($result['success']);
        $this->assertSame('CONNECTED', $result['schema_status']);
        $this->assertSame([], $result['counts']);
    }

    public function test_partial_schema_is_reported(): void
    {
        $connection = $this->connection(['clients' => 10], ['clients' => ['id']], ['clients' => 8]);

        $result = $this->serviceWith($connection)->inspect($this->makeInstance());

        $this->assertSame('SCHEMA_PARTIAL', $result['schema_status']);
    }

    public function test_unknown_schema_is_reported(): void
    {
        $connection = $this->connection(['legacy_records' => 10], ['legacy_records' => ['id']], []);

        $result = $this->serviceWith($connection)->inspect($this->makeInstance());

        $this->assertSame('SCHEMA_UNKNOWN', $result['schema_status']);
    }

    public function test_connection_error_is_snapshotted_safely(): void
    {
        $connections = Mockery::mock(IkontrolInstanceConnectionService::class);
        $connections->shouldReceive('withInstanceConnection')->once()->andThrow(new RuntimeException('password=production-secret'));
        $result = (new IkontrolInstanceInspectorService($connections))->inspect($this->makeInstance());

        $this->assertFalse($result['success']);
        $this->assertSame('ERROR', $result['schema_status']);
        $this->assertStringNotContainsString('production-secret', $result['schema_error']);
        $this->assertDatabaseHas('instance_inspection_snapshots', ['schema_status' => 'ERROR', 'schema_error' => 'No fue posible inspeccionar la instancia.']);
    }

    public function test_sensitive_configuration_is_not_stored(): void
    {
        config(['ikontrol.db.password' => 'mysql-super-secret', 'ikontrol.cpanel.token' => 'cpanel-super-secret']);
        $this->serviceWith($this->connection([], [], []))->inspect($this->makeInstance());
        $stored = InstanceInspectionSnapshot::firstOrFail()->toArray();

        $this->assertStringNotContainsString('mysql-super-secret', json_encode($stored));
        $this->assertStringNotContainsString('cpanel-super-secret', json_encode($stored));
    }

    public function test_ui_renders_when_snapshot_has_no_recognized_tables(): void
    {
        $admin = AdminUser::create(['name' => 'Admin', 'email' => 'inspector@example.test', 'password' => 'test-password', 'active' => true]);
        $instance = $this->makeInstance();
        InstanceInspectionSnapshot::create(['instance_id' => $instance->id, 'schema_status' => 'SCHEMA_UNKNOWN', 'counts' => [], 'technical_metadata' => ['table_count' => 1], 'inspected_at' => now()]);

        $this->actingAs($admin)->get(route('instances.show', $instance))
            ->assertOk()
            ->assertSee('Información de la instancia')
            ->assertSee('No se identificaron contadores');
    }

    public function test_manual_inspection_endpoint_records_sanitized_audit_metadata(): void
    {
        $admin = AdminUser::create(['name' => 'Admin', 'email' => 'audit-inspector@example.test', 'password' => 'test-password', 'active' => true]);
        $instance = $this->makeInstance();
        $inspector = Mockery::mock(IkontrolInstanceInspectorService::class);
        $inspector->shouldReceive('inspect')->once()->withArgs(fn ($received) => $received->is($instance))->andReturn([
            'success' => true,
            'schema_status' => 'SCHEMA_PARTIAL',
            'duration_ms' => 42,
        ]);
        $this->app->instance(IkontrolInstanceInspectorService::class, $inspector);

        $this->actingAs($admin)->post(route('instances.inspect', $instance))->assertRedirect();

        $audit = \App\Models\AdminAuditLog::where('action', 'inspect_instance')->firstOrFail();
        $this->assertSame($instance->id, $audit->entity_id);
        $this->assertSame(['success' => true, 'schema_status' => 'SCHEMA_PARTIAL', 'duration_ms' => 42], $audit->metadata_json);
    }

    private function makeInstance(): IkontrolInstance
    {
        $client = Client::create(['name' => 'NAVIKA', 'active' => true]);

        return IkontrolInstance::create(['client_id' => $client->id, 'name' => 'DOLD', 'slug' => 'dold-'.uniqid(), 'folder_name' => uniqid().'.ikontrol.solutions', 'db_host' => 'localhost', 'db_port' => 3306, 'db_name' => 'test_'.uniqid(), 'installation_status' => InstallationStatus::Ready, 'active' => true]);
    }

    private function serviceWith(Connection $connection): IkontrolInstanceInspectorService
    {
        $connections = Mockery::mock(IkontrolInstanceConnectionService::class);
        $connections->shouldReceive('withInstanceConnection')->once()->andReturnUsing(fn ($instance, $callback) => $callback($connection));

        return new IkontrolInstanceInspectorService($connections);
    }

    private function connection(array $tables, array $columns, array $counts, array $migrations = [], ?string $activity = null, ?array &$executed = null): Connection
    {
        $executed ??= [];
        $connection = Mockery::mock(Connection::class);
        $tableRows = collect($tables)->map(fn ($size, $name) => (object) ['TABLE_NAME' => $name, 'TABLE_SIZE' => $size])->values()->all();
        $columnRows = collect($columns)->flatMap(fn ($names, $table) => collect($names)->map(fn ($name) => (object) ['TABLE_NAME' => $table, 'COLUMN_NAME' => $name]))->values()->all();
        $connection->shouldReceive('select')->andReturnUsing(function (string $sql) use (&$executed, $tableRows, $columnRows, $migrations) {
            $executed[] = $sql;
            if (str_contains($sql, 'information_schema.TABLES')) return $tableRows;
            if (str_contains($sql, 'information_schema.COLUMNS')) return $columnRows;
            if (str_contains($sql, 'FROM `migrations`')) return array_map(fn ($row) => (object) $row, $migrations);
            return [];
        });
        $connection->shouldReceive('selectOne')->andReturnUsing(function (string $sql) use (&$executed, $counts, $activity) {
            $executed[] = $sql;
            if (preg_match('/COUNT\(\*\).*FROM `([^`]+)`/i', $sql, $match)) return (object) ['aggregate' => $counts[$match[1]] ?? 0];
            if (str_contains($sql, 'MAX(`updated_at`)')) return (object) ['last_activity' => $activity];
            return null;
        });

        return $connection;
    }
}
