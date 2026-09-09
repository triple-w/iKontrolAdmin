<?php

namespace Tests\Unit;

use App\Services\ManagedCommandJsonProtocol;
use RuntimeException;
use Tests\TestCase;

class ManagedCommandJsonProtocolTest extends TestCase
{
    public function test_extracts_marked_nested_json_from_codeigniter_banner(): void
    {
        $result = $this->protocol()->decodeProcess($this->process("CodeIgniter v4.6.1 Command Line Tool - Server Time: 2026-09-09\nIKONTROL_JSON_BEGIN\n{\"status\":\"READY\",\"foo\":\"bar\",\"checks\":{\"nested\":true}}\nIKONTROL_JSON_END"));
        $this->assertSame('READY', $result['status']);
        $this->assertSame('bar', $result['foo']);
        $this->assertTrue($result['checks']['nested']);
        $this->assertSame('MARKED_OUTPUT', $result['_output_protocol']);
    }

    public function test_supports_banner_and_legacy_json_temporarily(): void
    {
        $result = $this->protocol()->decodeProcess($this->process("CodeIgniter banner\n{\"status\":\"READY\",\"checks\":{\"role\":true}}\nDone"));
        $this->assertSame('READY', $result['status']);
        $this->assertSame('LEGACY_OUTPUT', $result['_output_protocol']);
    }

    public function test_stderr_warning_does_not_corrupt_valid_stdout(): void
    {
        $result = $this->protocol()->decodeProcess($this->process("IKONTROL_JSON_BEGIN\n{\"status\":\"READY\"}\nIKONTROL_JSON_END", 0, 'PHP warning'));
        $this->assertSame('READY', $result['status']);
    }

    public function test_nonzero_exit_code_wins_over_partial_json(): void
    {
        $this->expectExceptionMessage('COMMAND_EXECUTION_FAILED');
        $this->protocol()->decodeProcess($this->process('{"status":"READY"}', 1, 'failed'));
    }

    public function test_begin_without_end_is_invalid(): void
    {
        $this->expectExceptionMessage('stage=JSON_PROTOCOL reason=INVALID_JSON');
        $this->protocol()->decodeProcess($this->process("IKONTROL_JSON_BEGIN\nnot-json"));
    }

    public function test_output_without_json_has_sanitized_context_and_execution_id(): void
    {
        config(['ikontrol.db.password' => 'TopSecretPassword']);
        try {
            $this->protocol()->decodeProcess($this->process('banner password=TopSecretPassword'));
            $this->fail('Debió fallar el protocolo JSON.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('execution_id=TEST1234', $exception->getMessage());
            $this->assertStringContainsString('[REDACTED]', $exception->getMessage());
            $this->assertStringNotContainsString('TopSecretPassword', $exception->getMessage());
        }
    }

    private function protocol(): ManagedCommandJsonProtocol
    {
        return new ManagedCommandJsonProtocol();
    }

    private function process(string $stdout, int $exitCode = 0, string $stderr = ''): array
    {
        return ['execution_id'=>'TEST1234', 'exit_code'=>$exitCode, 'stdout'=>$stdout, 'stdout_tail'=>$stdout, 'stderr'=>$stderr, 'stderr_tail'=>$stderr];
    }
}
