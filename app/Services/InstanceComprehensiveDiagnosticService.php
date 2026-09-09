<?php

namespace App\Services;

use App\Models\{IkontrolInstance, InstanceDiagnosticSnapshot};
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class InstanceComprehensiveDiagnosticService
{
    private const ENV_KEYS = ['CI_ENVIRONMENT', 'app.baseURL', 'database.default.hostname', 'database.default.database', 'database.default.username', 'database.default.password', 'database.default.DBDriver', 'database.default.DBPrefix', 'database.default.port', 'encryption.key'];
    private const MANAGED_COMMANDS = ['ikontrol:database-check', 'ikontrol:log-check', 'ikontrol:admin-diagnose', 'ikontrol:dashboard-check'];

    public function __construct(
        private IkontrolInstanceConnectionService $connections,
        private IkontrolDeploymentService $deployment,
        private AuditService $audit,
    ) {}

    public function diagnose(IkontrolInstance $instance, ?string $email = null): InstanceDiagnosticSnapshot
    {
        $started = microtime(true);
        $filesystem = $this->filesystem($instance);
        $environment = $this->environment($instance, $filesystem['safe_path'] ?? null);
        $commands = $this->commands($instance);
        $database = $this->database($instance);
        $applicationDatabase = $this->command($instance, 'ikontrol:database-check');
        $logging = $this->command($instance, 'ikontrol:logging-status');
        $migrations = $this->migrations($instance);
        $http = $this->http($instance);
        $admin = $email ? $this->diagnosticCommand($instance, 'ikontrol:admin-diagnose', $email) : ['status' => 'NOT_CHECKED'];
        $dashboard = $email ? $this->diagnosticCommand($instance, 'ikontrol:dashboard-check', $email) : ['status' => 'NOT_CHECKED'];
        unset($filesystem['safe_path']);

        $checks = compact('filesystem', 'environment', 'commands', 'database', 'applicationDatabase', 'logging', 'migrations', 'http', 'admin', 'dashboard');
        $recommendations = $this->recommendations($checks);
        $status = $this->overallStatus($checks);
        $snapshot = InstanceDiagnosticSnapshot::create([
            'instance_id' => $instance->id,
            'status' => $status,
            'checks' => $this->sanitize($checks),
            'recommendations' => $recommendations,
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'checked_at' => now(),
        ]);
        $this->audit->record('instance_comprehensive_diagnostic', 'Diagnóstico integral ejecutado.', $instance, ['status' => $status, 'duration_ms' => $snapshot->duration_ms]);

        return $snapshot;
    }

    public function installTools(IkontrolInstance $instance): void
    {
        $this->deployment->installOperationalCommandsFor($instance);
        $this->audit->record('install_instance_diagnostic_tools', 'Herramientas administradas de diagnóstico instaladas.', $instance, ['version' => config('ikontrol.deployment.diagnostic_tools_version')]);
    }

    public function repairWritable(IkontrolInstance $instance): array
    {
        $path = $this->safePath($instance);
        $results = [];
        foreach (['writable', 'writable/cache', 'writable/logs', 'writable/session', 'writable/uploads'] as $relative) {
            $target = $path.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (is_link($target)) throw new RuntimeException('Se detectó un symlink no permitido dentro de writable.');
            if (! is_dir($target) && ! mkdir($target, 0775, true) && ! is_dir($target)) throw new RuntimeException('No fue posible preparar writable.');
            $results[$relative] = chmod($target, 0775) && is_writable($target);
        }
        $this->audit->record('repair_instance_writable', 'Permisos writable reparados de forma controlada.', $instance, ['success' => ! in_array(false, $results, true)]);
        return $results;
    }

    private function filesystem(IkontrolInstance $instance): array
    {
        try {$path = $this->safePath($instance);} catch (Throwable) {return ['status' => 'FAILED', 'path_exists' => false];}
        $checks = ['path_exists' => true];
        foreach (['index.php', 'spark', 'app', 'system', 'writable', 'writable/logs', 'writable/session', '.env'] as $relative) {
            $target = $path.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $checks[str_replace(['/', '.'], '_', $relative)] = ! is_link($target) && ($relative === 'index.php' || $relative === 'spark' || $relative === '.env' ? is_file($target) : is_dir($target));
        }
        $checks['env_readable'] = is_readable($path.DIRECTORY_SEPARATOR.'.env');
        $checks['logs_writable'] = is_writable($path.DIRECTORY_SEPARATOR.'writable'.DIRECTORY_SEPARATOR.'logs');
        $checks['session_writable'] = is_writable($path.DIRECTORY_SEPARATOR.'writable'.DIRECTORY_SEPARATOR.'session');
        return ['status' => in_array(false, $checks, true) ? 'FAILED' : 'OK', 'safe_path' => $path] + $checks;
    }

    private function environment(IkontrolInstance $instance, ?string $path): array
    {
        if (! $path || ! is_readable($path.DIRECTORY_SEPARATOR.'.env')) return ['status' => 'FAILED', 'fields' => []];
        $values = $this->parseEnv((string) file_get_contents($path.DIRECTORY_SEPARATOR.'.env'));
        $fields = [];
        foreach (self::ENV_KEYS as $key) $fields[$key] = ['present' => isset($values[$key]) && $values[$key] !== ''];
        foreach (['CI_ENVIRONMENT', 'app.baseURL', 'database.default.database', 'database.default.DBDriver', 'database.default.DBPrefix', 'database.default.port'] as $key) $fields[$key]['value'] = $values[$key] ?? null;
        $expectedUrl = rtrim((string) ($instance->url ?: 'https://'.$instance->slug.'.ikontrol.solutions'), '/').'/';
        $compare = [
            'database' => $this->match($instance->db_name, $values['database.default.database'] ?? null),
            'username' => $this->match((string) config('ikontrol.db.username'), $values['database.default.username'] ?? null),
            'base_url' => $this->match($expectedUrl, isset($values['app.baseURL']) ? rtrim($values['app.baseURL'], '/').'/' : null),
        ];
        return ['status' => in_array(false, array_column($fields, 'present'), true) ? 'FAILED' : (in_array('MISMATCH', $compare, true) ? 'WARNING' : 'OK'), 'fields' => $fields, 'comparison' => $compare];
    }

    private function commands(IkontrolInstance $instance): array
    {
        $result = $this->rawCommand($instance, 'list');
        if (! $result['success']) return ['status' => 'FAILED', 'managed' => array_fill_keys(self::MANAGED_COMMANDS, 'UNKNOWN')];
        $managed = [];
        foreach (self::MANAGED_COMMANDS as $command) $managed[$command] = str_contains($result['output'], $command) ? 'AVAILABLE' : 'MISSING_MANAGED_COMMAND';
        return ['status' => in_array('MISSING_MANAGED_COMMAND', $managed, true) ? 'WARNING' : 'OK', 'managed' => $managed];
    }

    private function database(IkontrolInstance $instance): array
    {
        $connection = $this->connections->test($instance);
        if (! $connection['success']) return ['status' => 'FAILED', 'connection' => 'FAILED', 'table_count' => null];
        try {
            $tables = $this->connections->withInstanceConnection($instance, fn ($db) => array_map(fn ($row) => array_values((array) $row)[0], $db->select('SHOW TABLES')));
            $logicalTables = (array) config('ikontrol.deployment.schema_tables.'.($instance->template?->schema_version ?? $instance->schema_version), []);
            $tablePrefix = (string) config('ikontrol.db.table_prefix', 'ikontrol_');
            $expected = array_map(fn ($table) => $tablePrefix.$table, $logicalTables);
            $missing = $expected ? array_values(array_diff($expected, $tables)) : [];
            return ['status' => $missing ? 'WARNING' : 'OK', 'connection' => 'OK', 'table_count' => count($tables), 'expected_contract' => $expected ? 'DEFINED' : 'NOT_DEFINED', 'critical_missing' => $missing];
        } catch (Throwable) {return ['status' => 'FAILED', 'connection' => 'FAILED', 'table_count' => null];}
    }

    private function migrations(IkontrolInstance $instance): array
    {
        $result = $this->rawCommand($instance, 'migrate:status');
        if (! $result['success']) return ['status' => 'FAILED', 'total' => null, 'executed' => null, 'pending' => null];
        $lines = preg_split('/\R/', $result['output']) ?: [];
        $rows = array_values(array_filter($lines, fn ($line) => preg_match('/\b(up|down)\b/i', $line)));
        $pending = count(array_filter($rows, fn ($line) => preg_match('/\bdown\b/i', $line)));
        return ['status' => $pending ? 'WARNING' : 'OK', 'total' => count($rows), 'executed' => count($rows) - $pending, 'pending' => $pending];
    }

    private function http(IkontrolInstance $instance): array
    {
        $url = $instance->url ?: 'https://'.$instance->slug.'.ikontrol.solutions/';
        $started = microtime(true);
        try {
            $response = Http::timeout(15)->connectTimeout(8)->withOptions(['allow_redirects' => ['max' => 5, 'track_redirects' => true]])->get($url);
            $history = $response->header('X-Guzzle-Redirect-Status-History');
            return ['status' => $response->serverError() ? 'FAILED' : ($response->successful() || $response->redirect() ? 'OK' : 'WARNING'), 'initial_status' => $history ? (int) explode(',', $history)[0] : $response->status(), 'final_status' => $response->status(), 'final_url' => (string) $response->effectiveUri(), 'duration_ms' => (int) ((microtime(true) - $started) * 1000), 'content_type' => mb_substr((string) $response->header('Content-Type'), 0, 100)];
        } catch (ConnectionException) {return ['status' => 'FAILED', 'initial_status' => null, 'final_status' => null, 'error' => 'DNS_SSL_CONNECTION_ERROR', 'duration_ms' => (int) ((microtime(true) - $started) * 1000)];}
    }

    private function command(IkontrolInstance $instance, string $command): array
    {
        $result = $this->rawCommand($instance, $command);
        if (! $result['success']) return ['status' => 'FAILED'];
        $json = json_decode(trim($result['output']), true);
        return is_array($json) ? $this->sanitize($json) : ['status' => 'OK'];
    }

    private function diagnosticCommand(IkontrolInstance $instance, string $command, string $email): array
    {
        try {
            $result = $this->deployment->runInstalledDiagnosticCommand($instance, $command, strtolower(trim($email)));
            $json = json_decode(trim((string) ($result['output'] ?? '')), true);
            return ($result['exit_code'] ?? 1) === 0 && is_array($json) ? $this->sanitize($json) : ['status' => 'FAILED'];
        } catch (Throwable) {return ['status' => 'FAILED'];}
    }

    private function rawCommand(IkontrolInstance $instance, string $command): array
    {
        try {$result = $this->deployment->runTemplateCommand($instance, $command);return ['success' => ($result['exit_code'] ?? 1) === 0, 'output' => (string) ($result['output'] ?? '')];} catch (Throwable) {return ['success' => false, 'output' => ''];}
    }

    private function recommendations(array $checks): array
    {
        $items = [];
        if (in_array('MISMATCH', $checks['environment']['comparison'] ?? [], true)) $items[] = ['code' => 'ENV_CONFIGURATION_MISMATCH', 'action' => 'regenerate_configuration'];
        if (($checks['database']['connection'] ?? null) !== 'OK') $items[] = ['code' => 'MYSQL_CONNECTION_FAILED', 'action' => 'reassign_database_user'];
        if (($checks['commands']['status'] ?? null) !== 'OK') $items[] = ['code' => 'MANAGED_COMMANDS_MISSING', 'action' => 'install_diagnostic_tools'];
        if (($checks['migrations']['pending'] ?? 0) > 0) $items[] = ['code' => 'MIGRATIONS_PENDING', 'action' => 'run_migrations'];
        if (! ($checks['filesystem']['logs_writable'] ?? false) || ! ($checks['filesystem']['session_writable'] ?? false)) $items[] = ['code' => 'WRITABLE_PERMISSIONS', 'action' => 'repair_writable'];
        if (($checks['logging']['threshold'] ?? $checks['logging']['logger_threshold'] ?? null) === 0) $items[] = ['code' => 'LOGGER_DISABLED', 'action' => 'review_logger_configuration'];
        if (($checks['admin']['profile_ok'] ?? true) === false) $items[] = ['code' => 'ADMIN_RELATION_MISSING', 'action' => 'review_admin'];
        if (($checks['http']['final_status'] ?? null) === 500) $items[] = ['code' => 'HTTP_500', 'action' => 'review_logs_dashboard'];
        return $items;
    }

    private function overallStatus(array $checks): string
    {
        foreach (['filesystem', 'environment', 'database', 'applicationDatabase', 'http'] as $key) if (($checks[$key]['status'] ?? 'FAILED') === 'FAILED') return 'FAILED';
        foreach ($checks as $check) if (in_array($check['status'] ?? null, ['WARNING', 'FAILED'], true)) return 'WARNING';
        return 'HEALTHY';
    }

    private function safePath(IkontrolInstance $instance): string
    {
        $root = realpath((string) config('ikontrol.instances_root')); $path = realpath((string) $instance->absolute_path);
        if ($root === false || $path === false || is_link($path) || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || basename($path) !== $instance->slug.config('ikontrol.folder_suffix')) throw new RuntimeException('La ruta administrada no es segura.');
        return $path;
    }

    private function parseEnv(string $contents): array
    {
        $values = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) if (preg_match('/^\s*([A-Za-z][A-Za-z0-9_.]*)\s*=\s*(.*?)\s*$/', $line, $match)) $values[$match[1]] = trim($match[2], " \t\"'");
        return $values;
    }

    private function match(string $expected, ?string $actual): string {return $actual !== null && hash_equals($expected, $actual) ? 'MATCH' : 'MISMATCH';}
    private function sanitize(array $data): array {$json = json_encode($data);foreach ([(string) config('ikontrol.db.password'), (string) config('ikontrol.cpanel.token')] as $secret) if ($secret !== '') $json = str_replace($secret, '[REDACTED]', $json);$json = preg_replace('/("?(?:password|token|secret|authorization|encryption\.key)"?\s*:\s*)"[^"]*"/i', '$1"[REDACTED]"', $json) ?? '{}';return json_decode($json, true) ?: [];}
}
