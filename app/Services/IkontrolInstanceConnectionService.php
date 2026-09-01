<?php

namespace App\Services;

use App\Models\IkontrolInstance;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

class IkontrolInstanceConnectionService
{
    public function testGlobalConnection(): array
    {
        if (! $this->globalConfigurationIsComplete()) {
            return ['success' => false, 'status' => 'NOT_CONFIGURED', 'message' => 'La conexión MySQL global no está configurada.', 'response_time_ms' => 0];
        }

        return $this->attempt('ikontrol_global_connection', 'information_schema');
    }

    public function test(IkontrolInstance $instance): array
    {
        $result = $this->attempt($this->connectionName($instance), $instance->db_name);
        $instance->update([
            'last_connection_at' => now(),
            'last_connection_status' => $result['success'] ? 'CONNECTED' : 'ERROR',
            'last_connection_error' => $result['success'] ? null : $result['message'],
        ]);

        return $result;
    }

    public function withInstanceConnection(IkontrolInstance $instance, Closure $callback): mixed
    {
        $name = $this->connectionName($instance);
        $this->configure($name, $instance->db_name);
        DB::purge($name);

        try {
            return $callback(DB::connection($name));
        } finally {
            DB::disconnect($name);
        }
    }

    private function attempt(string $connection, string $database): array
    {
        $this->configure($connection, $database);
        $started = microtime(true);

        try {
            DB::purge($connection);
            DB::connection($connection)->select('SELECT 1');

            return ['success' => true, 'status' => 'CONNECTED', 'message' => 'Conexión MySQL correcta.', 'response_time_ms' => (int) ((microtime(true) - $started) * 1000)];
        } catch (Throwable) {
            return ['success' => false, 'status' => 'ERROR', 'message' => 'No fue posible conectar con MySQL. Revise host, puerto, usuario, password y permisos.', 'response_time_ms' => (int) ((microtime(true) - $started) * 1000)];
        } finally {
            DB::disconnect($connection);
        }
    }

    private function configure(string $name, string $database): void
    {
        config(["database.connections.$name" => [
            'driver' => 'mysql',
            'host' => config('ikontrol.db.host'),
            'port' => config('ikontrol.db.port'),
            'database' => $database,
            'username' => config('ikontrol.db.username'),
            'password' => config('ikontrol.db.password'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'options' => extension_loaded('pdo_mysql') ? array_filter([\PDO::ATTR_TIMEOUT => 10]) : [],
        ]]);
    }

    private function connectionName(IkontrolInstance $instance): string
    {
        return 'instance_'.$instance->getKey();
    }

    private function globalConfigurationIsComplete(): bool
    {
        return filled(config('ikontrol.db.host'))
            && filled(config('ikontrol.db.port'))
            && filled(config('ikontrol.db.username'))
            && config('ikontrol.db.password') !== null;
    }
}
