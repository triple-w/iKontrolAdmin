<?php

namespace App\Services;

use Symfony\Component\Process\Process;
use RuntimeException;

class AllowedArtisanRunner
{
    private const COMMANDS = [
        'key:generate' => [['--force']],
        'migrate' => [['--force']],
        'optimize:clear' => [[]],
        'config:cache' => [[]],
        'route:cache' => [[]],
        'view:cache' => [[]],
        'about' => [['--only=environment,cache,drivers']],
    ];

    public function run(string $path, string $command, array $arguments = []): array
    {
        $root = realpath((string) config('ikontrol.instances_root'));
        $resolved = realpath($path);
        if ($root === false || $resolved === false || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('El directorio de ejecución está fuera de IKONTROL_INSTANCES_ROOT.');
        }
        if (! array_key_exists($command, self::COMMANDS) || $arguments !== self::COMMANDS[$command][0]) {
            throw new RuntimeException('Comando Artisan no permitido.');
        }
        $started = microtime(true);
        $process = new Process(array_merge(['php', 'artisan', $command], $arguments), $resolved, ['APP_DEBUG' => 'false']);
        $process->setTimeout((int) config('ikontrol.deployment.command_timeout', 300));
        $process->run();
        return ['exit_code' => $process->getExitCode(), 'duration_ms' => (int) ((microtime(true) - $started) * 1000), 'output' => $this->sanitize($process->getOutput().$process->getErrorOutput())];
    }

    private function sanitize(string $output): string
    {
        foreach ([(string) config('ikontrol.db.password'), (string) config('ikontrol.cpanel.token')] as $secret) {
            if ($secret !== '') $output = str_replace($secret, '[REDACTED]', $output);
        }
        return mb_substr(trim($output), 0, 2000);
    }
}
