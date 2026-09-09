<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class AllowedSparkRunner
{
    private const COMMANDS = ['key:generate' => ['--force'], 'cache:clear' => [], 'logs:clear' => ['--force'], 'migrate' => [], 'ikontrol:database-check' => [], 'ikontrol:stamps-status' => [], 'ikontrol:logging-status' => [], 'ikontrol:log-check' => []];

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

    public function runWithInput(string $path, string $command, array $arguments, string $input): array
    {
        if (! in_array($command, ['ikontrol:admin-provision', 'ikontrol:admin-password-set'], true)) throw new RuntimeException('Comando Spark sensible no permitido.');
        if (($command === 'ikontrol:admin-provision' && count($arguments) !== 2) || ($command === 'ikontrol:admin-password-set' && count($arguments) !== 1)) throw new RuntimeException('Argumentos de comando no permitidos.');
        foreach ($arguments as $argument) if (! is_string($argument) || str_contains($argument, "\0") || str_contains($argument, "\n")) throw new RuntimeException('Argumento de comando no permitido.');
        $root=realpath((string)config('ikontrol.instances_root')); $resolved=realpath($path);
        if($root===false||$resolved===false||!str_starts_with($resolved,$root.DIRECTORY_SEPARATOR)||is_link($resolved))throw new RuntimeException('El directorio de ejecución no es seguro.');
        $started=microtime(true); $process=new Process(array_merge(['php','spark',$command],$arguments),$resolved,['CI_ENVIRONMENT'=>'production']);
        $process->setInput($input.PHP_EOL); $process->setTimeout((int)config('ikontrol.deployment.command_timeout',300)); $process->run();
        $output=$process->getOutput().$process->getErrorOutput(); foreach([$input,(string)config('ikontrol.db.password'),(string)config('ikontrol.cpanel.token')] as $secret)if($secret!=='')$output=str_replace($secret,'[REDACTED]',$output);
        return ['exit_code'=>$process->getExitCode(),'duration_ms'=>(int)((microtime(true)-$started)*1000),'output'=>mb_substr(trim($output),0,2000)];
    }

    public function runStampMovement(string $path, string $action, int $quantity, string $reason, string $requestId): array
    {
        if (! in_array($action,['credit','debit'],true) || $quantity < 1 || $quantity > 1000000 || ! preg_match('/\A[a-f0-9]{32}\z/',$requestId)) throw new RuntimeException('Movimiento de timbres inválido.');
        $reason=trim($reason); if($reason===''||mb_strlen($reason)>500||str_contains($reason,"\0")||preg_match('/[\r\n]/',$reason))throw new RuntimeException('Motivo inválido.');
        return $this->execute($path,'ikontrol:stamps-adjust',[$action,(string)$quantity,$requestId,$reason]);
    }

    public function runDiagnostic(string $path, string $command, string $email): array
    {
        if (! in_array($command, ['ikontrol:admin-diagnose', 'ikontrol:dashboard-check'], true) || ! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            throw new RuntimeException('Diagnóstico o correo no permitido.');
        }

        return $this->execute($path, $command, [strtolower($email)]);
    }

    private function execute(string $path,string $command,array $arguments):array
    {
        $root=realpath((string)config('ikontrol.instances_root'));$resolved=realpath($path);if($root===false||$resolved===false||!str_starts_with($resolved,$root.DIRECTORY_SEPARATOR)||is_link($resolved))throw new RuntimeException('El directorio de ejecución no es seguro.');
        $started=microtime(true);$process=new Process(array_merge(['php','spark',$command],$arguments),$resolved,['CI_ENVIRONMENT'=>'production']);$process->setTimeout((int)config('ikontrol.deployment.command_timeout',300));$process->run();$output=$process->getOutput().$process->getErrorOutput();foreach([(string)config('ikontrol.db.password'),(string)config('ikontrol.cpanel.token')]as$secret)if($secret!=='')$output=str_replace($secret,'[REDACTED]',$output);return['exit_code'=>$process->getExitCode(),'duration_ms'=>(int)((microtime(true)-$started)*1000),'output'=>mb_substr(trim($output),0,20000)];
    }
}
