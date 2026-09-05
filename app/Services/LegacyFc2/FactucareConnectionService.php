<?php

namespace App\Services\LegacyFc2;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class FactucareConnectionService
{
    public function isConfigured(): bool
    {
        return filled(config('fc2.host'))
            && filled(config('fc2.database'))
            && filled(config('fc2.username'))
            && config('fc2.password') !== null;
    }

    public function testConnection(): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'status' => 'NOT_CONFIGURED', 'message' => 'La conexión FactuCare no está configurada.', 'response_time_ms' => 0];
        }

        $started = microtime(true);
        try {
            $connection = DB::connection(config('fc2.connection'));
            $connection->select('SELECT 1');
            $users = $connection->getSchemaBuilder()->hasTable('users') ? $connection->table('users')->count() : null;

            return [
                'success' => true,
                'status' => 'CONNECTED',
                'message' => $users === null ? 'Conexión FactuCare correcta; la tabla users no está disponible.' : "Conexión FactuCare correcta; {$users} usuarios detectados.",
                'response_time_ms' => (int) ((microtime(true) - $started) * 1000),
                'users_count' => $users,
            ];
        } catch (Throwable $exception) {
            return [
                'success' => false,
                'status' => 'ERROR',
                'message' => $this->safeError($exception),
                'response_time_ms' => (int) ((microtime(true) - $started) * 1000),
            ];
        } finally {
            $name = config('fc2.connection');
            if (config("database.connections.$name.driver") !== 'sqlite') DB::disconnect($name);
        }
    }

    public function safeError(Throwable $exception): string
    {
        $sqlState = $exception instanceof QueryException ? ($exception->errorInfo[0] ?? null) : null;

        return 'No fue posible conectar con FactuCare.'
            .($sqlState ? " SQLSTATE {$sqlState}." : '')
            .' Revise host, base, usuario y permisos.';
    }
}
