<?php

namespace App\Services\Compatibility;

use App\Models\IkontrolInstance;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class CompatibilityInspectorProcessRunner
{
    public function run(array $bundle, IkontrolInstance $instance, ?int $invoiceId, array $environment): array
    {
        $instancesRoot = realpath((string) config('ikontrol.instances_root'));
        $instanceRoot = realpath((string) $instance->absolute_path);
        $entrypoint = realpath((string) ($bundle['entrypoint'] ?? ''));
        $bundleRoot = realpath((string) ($bundle['path'] ?? ''));
        if ($instancesRoot === false || $instanceRoot === false || $bundleRoot === false || $entrypoint === false || is_link((string) $instance->absolute_path) || ! $this->inside($instanceRoot, $instancesRoot) || ! $this->inside($entrypoint, $bundleRoot)) throw new RuntimeException('INSPECTOR_EXECUTION_PATH_UNSAFE');
        if ($invoiceId !== null && ($invoiceId !== 1410 || ! $this->isSmartfree($instance))) throw new RuntimeException('INVOICE_DIAGNOSTIC_NOT_ALLOWED');
        $binary = (string) config('ikontrol.deployment.php_binary', PHP_BINARY);
        if ($binary === '' || str_contains($binary, chr(0)) || preg_match('/[\r\n]/', $binary)) throw new RuntimeException('PHP_BINARY_INVALID');
        $argv = [$binary, $entrypoint, '--root='.$instanceRoot];
        if ($invoiceId !== null) $argv[] = '--invoice-id=1410';
        $process = new Process($argv, $bundleRoot, $environment);
        $timeout = (int) config('ikontrol.compatibility_inspector.timeout', 120);
        $process->setTimeout($timeout);
        $timedOut = false;
        try { $process->run(); } catch (ProcessTimedOutException) { $timedOut = true; }
        return [
            'execution_id' => strtoupper(bin2hex(random_bytes(4))),
            'exit_code' => $process->getExitCode(),
            'timed_out' => $timedOut,
            'timeout_seconds' => $timeout,
            'stdout' => mb_substr($process->getOutput(), 0, 1048576),
            'stderr_present' => trim($process->getErrorOutput()) !== '',
        ];
    }

    private function inside(string $path, string $root): bool
    {
        return str_starts_with(strtolower(str_replace('\\', '/', $path)), strtolower(rtrim(str_replace('\\', '/', $root), '/').'/'));
    }

    private function isSmartfree(IkontrolInstance $instance): bool
    {
        return str_contains(strtolower(implode(' ', [$instance->name, $instance->slug, $instance->domain, $instance->url])), 'smartfree');
    }
}
