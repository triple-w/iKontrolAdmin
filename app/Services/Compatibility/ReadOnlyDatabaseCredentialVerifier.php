<?php

namespace App\Services\Compatibility;

use App\Models\IkontrolInstance;
use PDO;
use RuntimeException;

class ReadOnlyDatabaseCredentialVerifier
{
    public function verify(IkontrolInstance $instance): array
    {
        $username = (string) config('ikontrol.compatibility_inspector.readonly_username');
        $password = (string) config('ikontrol.compatibility_inspector.readonly_password');
        if ($username === '' || $password === '') throw new RuntimeException('READ_ONLY_CREDENTIALS_NOT_CONFIGURED');
        if (! preg_match('/\A[A-Za-z0-9_]+\z/', (string) $instance->db_name)) throw new RuntimeException('INSTANCE_DATABASE_UNSAFE');
        $host = (string) ($instance->db_host ?: config('ikontrol.db.host'));
        $port = (int) ($instance->db_port ?: config('ikontrol.db.port'));
        $pdo = new PDO('mysql:host='.$host.';port='.$port.';dbname='.$instance->db_name.';charset=utf8mb4', $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM,
        ]);
        $grants = array_map(static fn (array $row) => (string) ($row[0] ?? ''), $pdo->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll());
        return ['verified' => true, 'grants_fingerprint_sha256' => $this->assertReadOnlyGrants($grants)];
    }

    public function assertReadOnlyGrants(array $grants): string
    {
        $grants = array_map(static fn (mixed $grant) => strtoupper(trim((string) $grant)), $grants);
        if ($grants === [] || ! collect($grants)->contains(fn (string $grant) => preg_match('/\AGRANT [A-Z ,]*SELECT[A-Z ,]* ON /', $grant))) throw new RuntimeException('READ_ONLY_SELECT_GRANT_MISSING');
        foreach ($grants as $grant) {
            if (str_contains($grant, 'WITH GRANT OPTION') || ! preg_match('/\AGRANT (?:USAGE|(?:(?:SELECT|SHOW VIEW)(?:, )?)+) ON /', $grant)) throw new RuntimeException('READ_ONLY_CREDENTIAL_HAS_WRITE_PRIVILEGES');
        }
        return hash('sha256', implode(chr(10), $grants));
    }

    public function environment(IkontrolInstance $instance): array
    {
        return [
            'database.default.hostname' => (string) ($instance->db_host ?: config('ikontrol.db.host')),
            'database.default.database' => (string) $instance->db_name,
            'database.default.username' => (string) config('ikontrol.compatibility_inspector.readonly_username'),
            'database.default.password' => (string) config('ikontrol.compatibility_inspector.readonly_password'),
            'database.default.DBDriver' => 'MySQLi',
            'database.default.DBPrefix' => (string) config('ikontrol.db.table_prefix', 'ikontrol_'),
            'database.default.port' => (string) ($instance->db_port ?: config('ikontrol.db.port')),
            'CI_ENVIRONMENT' => 'production',
        ];
    }
}
