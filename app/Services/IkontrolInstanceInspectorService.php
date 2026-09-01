<?php

namespace App\Services;

use App\Models\IkontrolInstance;
use App\Models\InstanceInspectionSnapshot;
use Carbon\Carbon;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use Throwable;

class IkontrolInstanceInspectorService
{
    private const ENTITY_TABLES = [
        'users' => ['label' => 'Usuarios', 'tables' => ['users']],
        'clients' => ['label' => 'Clientes', 'tables' => ['clients', 'clientes']],
        'suppliers' => ['label' => 'Proveedores', 'tables' => ['suppliers', 'proveedores']],
        'products' => ['label' => 'Productos', 'tables' => ['products', 'productos']],
        'quotes' => ['label' => 'Cotizaciones / propuestas', 'tables' => ['quotes', 'quotations', 'proposals', 'cotizaciones', 'propuestas']],
        'sales' => ['label' => 'Ventas', 'tables' => ['sales', 'ventas']],
        'invoices' => ['label' => 'Facturas / documentos fiscales', 'tables' => ['invoices', 'facturas', 'fiscal_documents', 'documentos_fiscales']],
        'payments' => ['label' => 'Pagos', 'tables' => ['payments', 'pagos']],
        'payment_complements' => ['label' => 'Complementos de pago', 'tables' => ['payment_complements', 'complementos_pago']],
        'credit_notes' => ['label' => 'Notas de crédito', 'tables' => ['credit_notes', 'notas_credito']],
    ];

    public function __construct(private IkontrolInstanceConnectionService $connections) {}

    public function inspect(IkontrolInstance $instance): array
    {
        $started = microtime(true);

        try {
            $result = $this->connections->withInstanceConnection($instance, fn (Connection $connection) => $this->read($connection, $instance));
            $result['success'] = true;
            $result['duration_ms'] = (int) ((microtime(true) - $started) * 1000);
            $result['inspected_at'] = now();
        } catch (Throwable) {
            $result = [
                'success' => false,
                'schema_status' => 'ERROR',
                'schema_error' => 'No fue posible inspeccionar la instancia.',
                'app_version' => null,
                'schema_version' => null,
                'company_name' => null,
                'legal_name' => null,
                'rfc' => null,
                'url' => null,
                'database_size' => null,
                'last_activity_at' => null,
                'counts' => [],
                'technical_metadata' => ['host' => config('ikontrol.db.host'), 'port' => config('ikontrol.db.port'), 'database' => $instance->db_name, 'table_count' => 0, 'migration_count' => 0, 'last_migration' => null],
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                'inspected_at' => now(),
            ];
        }

        return $this->storeSnapshot($instance, $result);
    }

    private function read(Connection $connection, IkontrolInstance $instance): array
    {
        $tableRows = $connection->select(
            'SELECT TABLE_NAME, COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0) AS TABLE_SIZE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?',
            [$instance->db_name, 'BASE TABLE']
        );
        $columnRows = $connection->select(
            'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?',
            [$instance->db_name]
        );
        $tables = [];
        $databaseSize = 0;
        foreach ($tableRows as $row) {
            $name = (string) $row->TABLE_NAME;
            if ($this->validIdentifier($name)) {
                $tables[$name] = true;
                $databaseSize += (int) $row->TABLE_SIZE;
            }
        }
        $columns = [];
        foreach ($columnRows as $row) {
            $table = (string) $row->TABLE_NAME;
            $column = (string) $row->COLUMN_NAME;
            if ($this->validIdentifier($table) && $this->validIdentifier($column) && isset($tables[$table])) {
                $columns[$table][$column] = true;
            }
        }

        [$migrationCount, $lastMigration] = $this->migrations($connection, $tables, $columns);
        $counts = $this->counts($connection, $tables);
        $company = $this->company($connection, $tables, $columns);
        $appVersion = $this->appVersion($connection, $tables, $columns) ?? $instance->app_version;
        $lastActivity = $this->lastActivity($connection, $tables, $columns);
        $status = $this->schemaStatus(array_keys($tables), $migrationCount, $counts);

        return [
            'schema_status' => $status,
            'schema_error' => null,
            'app_version' => $appVersion,
            'schema_version' => $lastMigration ?? $instance->schema_version,
            'company_name' => $company['company_name'] ?? null,
            'legal_name' => $company['legal_name'] ?? null,
            'rfc' => $company['rfc'] ?? null,
            'url' => $company['url'] ?? $instance->url,
            'database_size' => $databaseSize,
            'last_activity_at' => $lastActivity,
            'counts' => $counts,
            'technical_metadata' => [
                'host' => config('ikontrol.db.host'),
                'port' => config('ikontrol.db.port'),
                'database' => $instance->db_name,
                'table_count' => count($tables),
                'migration_count' => $migrationCount,
                'last_migration' => $lastMigration,
            ],
        ];
    }

    private function migrations(Connection $connection, array $tables, array $columns): array
    {
        if (! isset($tables['migrations'], $columns['migrations']['migration'])) {
            return [0, null];
        }
        $rows = $connection->select('SELECT `migration`, `batch` FROM `migrations` ORDER BY `batch` DESC, `migration` DESC');

        return [count($rows), isset($rows[0]) ? (string) $rows[0]->migration : null];
    }

    private function counts(Connection $connection, array $tables): array
    {
        $counts = [];
        foreach (self::ENTITY_TABLES as $key => $definition) {
            $table = collect($definition['tables'])->first(fn (string $candidate) => isset($tables[$candidate]));
            if ($table === null) {
                continue;
            }
            $row = $connection->selectOne('SELECT COUNT(*) AS aggregate FROM '.$this->quoteIdentifier($table));
            $counts[$key] = ['label' => $definition['label'], 'value' => (int) ($row->aggregate ?? 0), 'table' => $table];
        }

        return $counts;
    }

    private function company(Connection $connection, array $tables, array $columns): array
    {
        foreach (['companies', 'company', 'empresas', 'empresa'] as $table) {
            if (! isset($tables[$table])) {
                continue;
            }
            $mapping = [
                'company_name' => $this->firstColumn($columns[$table] ?? [], ['name', 'company_name', 'nombre']),
                'legal_name' => $this->firstColumn($columns[$table] ?? [], ['legal_name', 'business_name', 'razon_social']),
                'rfc' => $this->firstColumn($columns[$table] ?? [], ['rfc', 'tax_id']),
                'url' => $this->firstColumn($columns[$table] ?? [], ['url', 'website']),
            ];
            $selected = array_filter($mapping);
            if ($selected === []) {
                continue;
            }
            $sql = collect($selected)->map(fn (string $column, string $alias) => $this->quoteIdentifier($column).' AS '.$this->quoteIdentifier($alias))->implode(', ');
            $row = $connection->selectOne('SELECT '.$sql.' FROM '.$this->quoteIdentifier($table).' LIMIT 1');

            return $row ? array_intersect_key((array) $row, $selected) : [];
        }

        return [];
    }

    private function appVersion(Connection $connection, array $tables, array $columns): ?string
    {
        foreach (['settings', 'configurations'] as $table) {
            $key = $this->firstColumn($columns[$table] ?? [], ['key', 'name']);
            $value = $this->firstColumn($columns[$table] ?? [], ['value']);
            if (! isset($tables[$table]) || ! $key || ! $value) {
                continue;
            }
            $rows = $connection->select(
                'SELECT '.$this->quoteIdentifier($key).' AS `setting_key`, '.$this->quoteIdentifier($value).' AS `setting_value` FROM '.$this->quoteIdentifier($table).' WHERE '.$this->quoteIdentifier($key).' IN (?, ?, ?)',
                ['app_version', 'ikontrol_version', 'version']
            );
            if (isset($rows[0]) && is_scalar($rows[0]->setting_value)) {
                return mb_substr((string) $rows[0]->setting_value, 0, 255);
            }
        }

        return null;
    }

    private function lastActivity(Connection $connection, array $tables, array $columns): ?Carbon
    {
        $latest = null;
        foreach (array_keys($tables) as $table) {
            if (! isset($columns[$table]['updated_at'])) {
                continue;
            }
            $row = $connection->selectOne('SELECT MAX(`updated_at`) AS last_activity FROM '.$this->quoteIdentifier($table));
            if (! empty($row->last_activity)) {
                try {
                    $candidate = Carbon::parse($row->last_activity);
                    $latest = $latest === null || $candidate->greaterThan($latest) ? $candidate : $latest;
                } catch (Throwable) {
                }
            }
        }

        return $latest;
    }

    private function schemaStatus(array $tables, int $migrationCount, array $counts): string
    {
        if ($migrationCount > 0 && count($counts) >= 3) {
            return 'SCHEMA_RECOGNIZED';
        }
        if ($migrationCount > 0 || $counts !== []) {
            return 'SCHEMA_PARTIAL';
        }

        return $tables === [] ? 'CONNECTED' : 'SCHEMA_UNKNOWN';
    }

    private function storeSnapshot(IkontrolInstance $instance, array $result): array
    {
        $safeError = $result['schema_error'] ? mb_substr($result['schema_error'], 0, 1000) : null;
        $snapshot = InstanceInspectionSnapshot::create([
            'instance_id' => $instance->id,
            'schema_status' => $result['schema_status'],
            'app_version' => $result['app_version'],
            'schema_version' => $result['schema_version'],
            'company_name' => $result['company_name'],
            'legal_name' => $result['legal_name'],
            'rfc' => $result['rfc'],
            'url' => $result['url'],
            'database_size' => $result['database_size'],
            'last_activity_at' => $result['last_activity_at'],
            'counts' => $this->sanitize($result['counts']),
            'technical_metadata' => $this->sanitize($result['technical_metadata'] + ['duration_ms' => $result['duration_ms']]),
            'schema_error' => $safeError,
            'inspected_at' => $result['inspected_at'],
        ]);
        $instance->update([
            'app_version' => $result['app_version'],
            'schema_version' => $result['schema_version'],
            'schema_status' => $result['schema_status'],
            'schema_checked_at' => $result['inspected_at'],
            'schema_error' => $safeError,
        ]);

        return $result + ['snapshot' => $snapshot];
    }

    private function firstColumn(array $available, array $candidates): ?string
    {
        return collect($candidates)->first(fn (string $column) => isset($available[$column]));
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (! $this->validIdentifier($identifier)) {
            throw new InvalidArgumentException('Identificador de schema inválido.');
        }

        return '`'.$identifier.'`';
    }

    private function validIdentifier(string $identifier): bool
    {
        return (bool) preg_match('/\A[a-zA-Z0-9_]+\z/', $identifier);
    }

    private function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (preg_match('/password|secret|token|credential|authorization/i', (string) $key)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            }
        }

        return $data;
    }
}
