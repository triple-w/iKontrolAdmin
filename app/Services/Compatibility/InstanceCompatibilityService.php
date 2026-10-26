<?php

namespace App\Services\Compatibility;

use App\Models\{IkontrolInstance, IkontrolRelease, InstanceCompatibilityEvaluation};
use Illuminate\Contracts\Auth\Authenticatable;
use RuntimeException;
use Throwable;

class InstanceCompatibilityService
{
    private const BEGIN = 'IKONTROL_COMPATIBILITY_JSON_BEGIN';
    private const END = 'IKONTROL_COMPATIBILITY_JSON_END';

    public function __construct(
        private readonly CompatibilityInspectorBundle $bundles,
        private readonly ReadOnlyDatabaseCredentialVerifier $credentials,
        private readonly CompatibilityInspectorProcessRunner $processes,
    ) {}

    public function targets(): array
    {
        return array_keys((array) config('ikontrol.compatibility_inspector.targets', []));
    }

    public function evaluate(IkontrolInstance $instance, string $targetVersion, ?int $invoiceId, ?Authenticatable $user): InstanceCompatibilityEvaluation
    {
        if (! in_array($targetVersion, $this->targets(), true)) throw new RuntimeException('INSPECTOR_TARGET_NOT_ALLOWED');
        $configuration = (array) config('ikontrol.compatibility_inspector.targets.'.$targetVersion);
        $base = [
            'instance_id' => $instance->id,
            'evaluated_by' => $user?->getAuthIdentifier(),
            'source_version' => $configuration['source_version'] ?? null,
            'target_version' => $targetVersion,
            'source_commit_sha' => $configuration['source_commit'] ?? null,
            'status' => 'NOT_VERIFIED',
            'bundle_trust' => 'DEVELOPMENT_VERIFIED',
            'authorizes_deployment' => false,
            'evaluated_at' => now(),
        ];
        $bundle = null;
        try {
            $bundle = $this->bundles->stage($targetVersion);
            $contract = (array) $bundle['contract'];
            $base += [
                'source_tree_sha256' => data_get($contract, 'source.tree_sha256'),
                'contract_sha256' => $bundle['contract_sha256'],
                'bundle_sha256' => $bundle['manifest_sha256'],
            ];
            $credentialEvidence = $this->credentials->verify($instance);
            if (($credentialEvidence['verified'] ?? false) !== true) throw new RuntimeException('READ_ONLY_CREDENTIALS_NOT_VERIFIED');
            $process = $this->processes->run($bundle, $instance, $invoiceId, $this->credentials->environment($instance));
            if (($process['timed_out'] ?? false) === true) throw new RuntimeException('INSPECTOR_TIMEOUT');
            $result = $this->decode((string) ($process['stdout'] ?? ''));
            $this->assertResult($result, $targetVersion, $contract);
            if (in_array($result['status'], ['COMPATIBLE', 'REVIEW_REQUIRED'], true) && ($process['exit_code'] ?? null) !== 0) throw new RuntimeException('INSPECTOR_EXIT_STATUS_MISMATCH');
            $summary = $this->summary($result);
            $summary['execution'] = [
                'execution_id' => $process['execution_id'] ?? null,
                'exit_code' => $process['exit_code'] ?? null,
                'timed_out' => false,
                'stderr_present' => ($process['stderr_present'] ?? false) === true,
            ];
            $unresolved = $instance->updateRuns()->whereIn('status', ['MANUAL_REVIEW_REQUIRED', 'CODE_ROLLED_BACK_DB_ADVANCED', 'PARTIAL_CODE_DEPLOYMENT'])->first();
            $latestRun = $instance->updateRuns()->first();
            $summary['admin_prerequisites'] = [
                'latest_deployment_status' => $latestRun?->status,
                'recovery_verified' => $latestRun === null || in_array($latestRun->status, ['completed', 'RECOVERY_VERIFIED_SOURCE', 'RECOVERY_VERIFIED_TARGET'], true),
                'deployment_blocker' => $unresolved !== null,
            ];
            $status = (string) $result['status'];
            if ($unresolved) {
                $status = 'BLOCKED';
                $summary['blockers'][] = 'ADMIN_DEPLOYMENT_RECOVERY_REQUIRED';
                $summary['blockers'] = array_values(array_unique($summary['blockers']));
            }
            $release = IkontrolRelease::where('version', $targetVersion)->where('status', 'validated')->first();
            $releaseEvidence = is_array($bundle['release'] ?? null) ? $bundle['release'] : [];
            $official = ($bundle['trust'] ?? null) === 'RELEASE_VERIFIED' && $release
                && $release->artifact_verification_status === 'verified'
                && (int) ($releaseEvidence['id'] ?? 0) === (int) $release->id
                && hash_equals(strtolower((string) $release->commit_sha), strtolower((string) ($releaseEvidence['commit_sha'] ?? '')))
                && hash_equals(strtolower((string) $release->artifact_sha256), strtolower((string) ($releaseEvidence['artifact_sha256'] ?? '')));
            return InstanceCompatibilityEvaluation::create(array_replace($base, [
                'release_id' => $official ? $release->id : null,
                'evaluated_release_commit_sha' => $official ? strtolower((string) $release->commit_sha) : null,
                'evaluated_release_artifact_sha256' => $official ? strtolower((string) $release->artifact_sha256) : null,
                'status' => $status,
                'authorizes_deployment' => $official && $status === 'COMPATIBLE',
                'files_fingerprint_sha256' => hash('sha256', $this->stableJson($summary['files'])),
                'schema_fingerprint_sha256' => data_get($summary, 'schema.fingerprint_sha256'),
                'summary' => $summary,
                'error_evidence' => null,
            ]));
        } catch (Throwable $e) {
            return InstanceCompatibilityEvaluation::create(array_replace($base, [
                'summary' => ['blockers' => [], 'review_required' => [], 'not_verified' => [$this->errorCode($e)]],
                'error_evidence' => ['code' => $this->errorCode($e)],
            ]));
        } finally {
            if (is_array($bundle)) $this->bundles->remove($bundle);
        }
    }

    private function decode(string $stdout): array
    {
        $begin = strrpos($stdout, self::BEGIN);
        if ($begin === false) throw new RuntimeException('INSPECTOR_OUTPUT_MISSING');
        $begin += strlen(self::BEGIN);
        $end = strpos($stdout, self::END, $begin);
        if ($end === false) throw new RuntimeException('INSPECTOR_OUTPUT_INCOMPLETE');
        $decoded = json_decode(trim(substr($stdout, $begin, $end - $begin)), true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) throw new RuntimeException('INSPECTOR_OUTPUT_INVALID');
        return $decoded;
    }

    private function assertResult(array $result, string $targetVersion, array $contract): void
    {
        if (! in_array($result['status'] ?? null, InstanceCompatibilityEvaluation::STATUSES, true) || ($result['schema_version'] ?? null) !== 1 || ($result['read_only'] ?? null) !== true) throw new RuntimeException('INSPECTOR_RESULT_INVALID');
        if (($result['target_version'] ?? null) !== $targetVersion || data_get($result, 'versions.expected_source_version') !== data_get($contract, 'source.version') || data_get($result, 'versions.expected_source_commit') !== data_get($contract, 'source.commit') || data_get($result, 'files.origin_tree_sha256') !== data_get($contract, 'source.tree_sha256')) throw new RuntimeException('INSPECTOR_ORIGIN_MISMATCH');
        if (($result['privacy']['contains_secrets'] ?? null) !== false) throw new RuntimeException('INSPECTOR_PRIVACY_NOT_VERIFIED');
    }

    private function summary(array $result): array
    {
        return [
            'versions' => $this->only((array) ($result['versions'] ?? []), ['status', 'canonical', 'registered', 'expected_source_version', 'expected_source_commit', 'match']),
            'files' => $this->only((array) ($result['files'] ?? []), ['status', 'expected_count', 'matched_count', 'modified', 'missing', 'extra_in_scope', 'origin_tree_sha256']),
            'schema' => $this->only((array) ($result['schema'] ?? []), ['status', 'fingerprint_sha256', 'tables', 'missing_or_incomplete_tables', 'invalid_index_tables']),
            'runtime' => $this->only((array) ($result['runtime'] ?? []), ['status', 'runtime_mode', 'logical_environment', 'transport_environment', 'adapter', 'fiscal_enabled', 'stamping_enabled', 'real_pac_opt_in', 'production_transport_opt_in', 'credential_present', 'endpoint_allowed', 'production_guard_satisfied', 'coherent', 'operational']),
            'data' => $this->sanitizeArray((array) ($result['data'] ?? [])),
            'blockers' => $this->codes($result['blockers'] ?? []),
            'review_required' => $this->codes($result['review_required'] ?? []),
            'not_verified' => $this->codes($result['not_verified'] ?? []),
        ];
    }

    private function only(array $source, array $keys): array
    {
        return $this->sanitizeArray(array_intersect_key($source, array_flip($keys)));
    }

    private function sanitizeArray(array $value, int $depth = 0): array
    {
        if ($depth > 8) return [];
        $clean = [];
        foreach (array_slice($value, 0, 500, true) as $key => $item) {
            if (is_string($key) && preg_match('/password|secret|token|authorization|api.?key|private.?key|certificate|xml|rfc|reason.?social|endpoint.?url/i', $key)) continue;
            if (is_array($item)) $clean[$key] = $this->sanitizeArray($item, $depth + 1);
            elseif (is_bool($item) || is_int($item) || is_float($item) || $item === null) $clean[$key] = $item;
            elseif (is_string($item)) $clean[$key] = $this->sanitizeString($item);
        }
        return $clean;
    }

    private function codes(mixed $codes): array
    {
        if (! is_array($codes)) return [];
        return array_values(array_filter(array_slice($codes, 0, 100), fn ($code) => is_string($code) && preg_match('/\A[A-Z0-9_:-]{1,100}\z/', $code)));
    }

    private function errorCode(Throwable $e): string
    {
        $code = strtoupper(trim($e->getMessage()));
        return preg_match('/\A[A-Z0-9_:-]{1,100}\z/', $code) ? $code : 'INSPECTION_FAILED';
    }

    private function sanitizeString(string $value): string
    {
        foreach ([
            (string) config('ikontrol.compatibility_inspector.readonly_password'),
            (string) config('ikontrol.db.password'),
            (string) config('ikontrol.releases.github_token'),
            (string) config('ikontrol.cpanel.token'),
        ] as $secret) if ($secret !== '') $value = str_replace($secret, '[REDACTED]', $value);
        $value = preg_replace([
            '/(password|token|secret|authorization|api.?key|private.?key|certificate|csd_password)\s*[=:]\s*[^\s,;]+/i',
            '/bearer\s+[^\s]+/i',
            '/-----BEGIN [^-]*(?:PRIVATE KEY|CERTIFICATE)-----.*?-----END [^-]*(?:PRIVATE KEY|CERTIFICATE)-----/is',
        ], ['$1=[REDACTED]', 'Bearer [REDACTED]', '[REDACTED PEM]'], $value) ?? '';
        return mb_substr($value, 0, 500);
    }

    private function stableJson(array $value): string
    {
        $sort = function (&$item) use (&$sort): void { if (! is_array($item)) return; foreach ($item as &$child) $sort($child); unset($child); if (! array_is_list($item)) ksort($item); };
        $sort($value);
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
