<?php

namespace App\Services;

use RuntimeException;

final class ManagedCommandJsonProtocol
{
    public const BEGIN = 'IKONTROL_JSON_BEGIN';
    public const END = 'IKONTROL_JSON_END';

    public function decodeProcess(array $process): array
    {
        $executionId = (string) ($process['execution_id'] ?? strtoupper(bin2hex(random_bytes(4))));
        if (($process['exit_code'] ?? 1) !== 0) {
            throw new RuntimeException('stage=COMMAND_EXECUTION reason=COMMAND_EXECUTION_FAILED exit_code='.(string) ($process['exit_code'] ?? 'unknown').' execution_id='.$executionId.' stderr_tail='.$this->tail($process['stderr_tail'] ?? $process['stderr'] ?? ''));
        }

        $stdout = (string) ($process['stdout'] ?? $process['output'] ?? '');
        $marked = $this->betweenMarkers($stdout);
        if ($marked !== null) {
            $decoded = json_decode(trim($marked), true);
            if (is_array($decoded)) return $decoded + ['_output_protocol' => 'MARKED_OUTPUT'];
            $this->invalid($process, $executionId);
        }
        if (str_contains($stdout, self::BEGIN) || str_contains($stdout, self::END)) $this->invalid($process, $executionId);

        $legacy = $this->lastJsonObject($stdout);
        if ($legacy !== null) return $legacy + ['_output_protocol' => 'LEGACY_OUTPUT'];

        $this->invalid($process, $executionId);
    }

    private function betweenMarkers(string $stdout): ?string
    {
        $begin = strrpos($stdout, self::BEGIN);
        if ($begin === false) return null;
        $begin += strlen(self::BEGIN);
        $end = strpos($stdout, self::END, $begin);
        if ($end === false) return null;

        return substr($stdout, $begin, $end - $begin);
    }

    private function lastJsonObject(string $stdout): ?array
    {
        $last = null;
        $length = strlen($stdout);
        for ($start = 0; $start < $length; $start++) {
            if ($stdout[$start] !== '{') continue;
            $depth = 0; $quoted = false; $escaped = false;
            for ($end = $start; $end < $length; $end++) {
                $character = $stdout[$end];
                if ($quoted) {
                    if ($escaped) {$escaped = false; continue;}
                    if ($character === '\\') {$escaped = true; continue;}
                    if ($character === '"') $quoted = false;
                    continue;
                }
                if ($character === '"') {$quoted = true; continue;}
                if ($character === '{') $depth++;
                if ($character === '}' && --$depth === 0) {
                    $decoded = json_decode(substr($stdout, $start, $end - $start + 1), true);
                    if (is_array($decoded)) {
                        $last = $decoded;
                        $start = $end;
                    }
                    break;
                }
            }
        }

        return $last;
    }

    private function invalid(array $process, string $executionId): never
    {
        throw new RuntimeException('stage=JSON_PROTOCOL reason=INVALID_JSON exit_code='.(string) ($process['exit_code'] ?? 'unknown').' execution_id='.$executionId.' stdout_tail='.$this->tail($process['stdout_tail'] ?? $process['stdout'] ?? $process['output'] ?? '').' stderr_tail='.$this->tail($process['stderr_tail'] ?? $process['stderr'] ?? ''));
    }

    private function tail(mixed $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim((string) $value)) ?? '';
        foreach ([(string) config('ikontrol.db.password'), (string) config('ikontrol.cpanel.token')] as $secret) {
            if ($secret !== '') $value = str_replace($secret, '[REDACTED]', $value);
        }
        $value = preg_replace(['/(password|token|secret|authorization)\s*[=:]\s*[^\s,;]+/i', '/bearer\s+[^\s]+/i'], ['$1=[REDACTED]', 'Bearer [REDACTED]'], $value) ?? '';
        return mb_substr($value, -300);
    }
}
