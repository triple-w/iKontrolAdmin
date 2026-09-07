<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class AllowedSparkRunner
{
    private const COMMANDS = ['key:generate' => ['--force'], 'cache:clear' => [], 'ikontrol:database-check' => []];

    public function run(string $path, string $command, array $arguments = []): array
    {
        $root = realpath((string) config('ikontrol.instances_root')); $resolved = realpath($path);
        if ($root === false || $resolved === false || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR) || is_link($resolved)) throw new RuntimeException('El directorio de ejecución no es seguro.');
        if (! array_key_exists($command, self::COMMANDS) || $arguments !== self::COMMANDS[$command]) throw new RuntimeException('Comando Spark no permitido.');
        $started = microtime(true); $process = new Process(array_merge(['php', 'spark', $command], $arguments), $resolved, ['CI_ENVIRONMENT'=>'production']);
        $process->setTimeout((int) config('ikontrol.deployment.command_timeout', 300)); $process->run();
        $output = $process->getOutput().$process->getErrorOutput();
        foreach ([(string) config('ikontrol.db.password'), (string) config('ikontrol.cpanel.token')] as $secret) if ($secret !== '') $output = str_replace($secret, '[REDACTED]', $output);
        return ['exit_code'=>$process->getExitCode(), 'duration_ms'=>(int) ((microtime(true)-$started)*1000), 'output'=>mb_substr(trim($output), 0, 2000)];
    }
}
