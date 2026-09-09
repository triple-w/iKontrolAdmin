<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class AllowedSparkRunner
{
    private const COMMANDS = [
        'list' => [], 'migrate:status' => [], 'key:generate' => ['--force'],
        'cache:clear' => [], 'logs:clear' => ['--force'], 'migrate' => [],
        'ikontrol:database-check' => [], 'ikontrol:stamps-status' => [],
        'ikontrol:logging-status' => [], 'ikontrol:log-check' => [],
        'ikontrol:settings-baseline' => [],
    ];

    public function run(string $path, string $command, array $arguments = []): array
    {
        if (! array_key_exists($command, self::COMMANDS) || $arguments !== self::COMMANDS[$command]) throw new RuntimeException('Comando Spark no permitido.');
        return $this->execute($path, $command, $arguments, null, 20000);
    }

    public function runWithInput(string $path, string $command, array $arguments, string $input): array
    {
        if (! in_array($command, ['ikontrol:admin-provision', 'ikontrol:admin-password-set'], true)) throw new RuntimeException('Comando Spark sensible no permitido.');
        if (($command === 'ikontrol:admin-provision' && count($arguments) !== 2) || ($command === 'ikontrol:admin-password-set' && count($arguments) !== 1)) throw new RuntimeException('Argumentos de comando no permitidos.');
        foreach ($arguments as $argument) if (! is_string($argument) || str_contains($argument, "\0") || str_contains($argument, "\n")) throw new RuntimeException('Argumento de comando no permitido.');
        return $this->execute($path, $command, $arguments, $input.PHP_EOL, 4000, [$input]);
    }

    public function runStampMovement(string $path, string $action, int $quantity, string $reason, string $requestId): array
    {
        if (! in_array($action, ['credit', 'debit'], true) || $quantity < 1 || $quantity > 1000000 || ! preg_match('/\A[a-f0-9]{32}\z/', $requestId)) throw new RuntimeException('Movimiento de timbres inválido.');
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500 || str_contains($reason, "\0") || preg_match('/[\r\n]/', $reason)) throw new RuntimeException('Motivo inválido.');
        return $this->execute($path, 'ikontrol:stamps-adjust', [$action, (string) $quantity, $requestId, $reason], null, 20000);
    }

    public function runDiagnostic(string $path, string $command, string $email): array
    {
        if (! in_array($command, ['ikontrol:admin-diagnose', 'ikontrol:dashboard-check'], true) || ! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) throw new RuntimeException('Diagnóstico o correo no permitido.');
        return $this->execute($path, $command, [strtolower($email)], null, 20000);
    }

    private function execute(string $path, string $command, array $arguments, ?string $input = null, int $limit = 4000, array $extraSecrets = []): array
    {
        $root = realpath((string) config('ikontrol.instances_root')); $cwd = realpath($path);
        if ($root === false || $cwd === false || ! str_starts_with($cwd, $root.DIRECTORY_SEPARATOR) || is_link($cwd)) throw new RuntimeException('El directorio de ejecución no es seguro.');
        $binary = (string) config('ikontrol.deployment.php_binary', PHP_BINARY);
        if ($binary === '' || str_contains($binary, "\0") || str_contains($binary, "\n")) throw new RuntimeException('El binario PHP configurado no es válido.');
        $executionId = strtoupper(bin2hex(random_bytes(4))); $started = microtime(true);
        $process = new Process(array_merge([$binary, 'spark', $command], $arguments), $cwd, ['CI_ENVIRONMENT' => 'production']);
        if ($input !== null) $process->setInput($input);
        $process->setTimeout((int) config('ikontrol.deployment.command_timeout', 300)); $process->run();
        $stdout = $this->redact($process->getOutput(), $extraSecrets); $stderr = $this->redact($process->getErrorOutput(), $extraSecrets);

        return ['execution_id'=>$executionId, 'exit_code'=>$process->getExitCode(), 'duration_ms'=>(int)((microtime(true)-$started)*1000), 'output'=>mb_substr(trim($stdout!==''?$stdout:$stderr),0,$limit), 'stdout'=>mb_substr(trim($stdout),0,$limit), 'stderr'=>mb_substr(trim($stderr),0,$limit), 'stdout_tail'=>mb_substr(trim($stdout),-$limit), 'stderr_tail'=>mb_substr(trim($stderr),-$limit), 'cwd'=>$cwd, 'php_binary'=>$binary];
    }

    private function redact(string $value, array $extraSecrets = []): string
    {
        foreach (array_merge($extraSecrets, [(string) config('ikontrol.db.password'), (string) config('ikontrol.cpanel.token')]) as $secret) if ($secret !== '') $value = str_replace($secret, '[REDACTED]', $value);
        return preg_replace(['/(password|token|secret|authorization)\s*[=:]\s*[^\s,;]+/i', '/bearer\s+[^\s]+/i'], ['$1=[REDACTED]', 'Bearer [REDACTED]'], $value) ?? '';
    }
}
