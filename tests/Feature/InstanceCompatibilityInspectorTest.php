<?php

namespace Tests\Feature;

use App\Enums\InstallationStatus;
use App\Models\{AdminUser, Client, IkontrolInstance, IkontrolRelease, InstanceCompatibilityEvaluation, InstanceUpdateRun};
use App\Services\Compatibility\{CompatibilityDeploymentGate, CompatibilityInspectorBundle, CompatibilityInspectorProcessRunner, InstanceCompatibilityService, ReadOnlyDatabaseCredentialVerifier};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class InstanceCompatibilityInspectorTest extends TestCase
{
    use RefreshDatabase;
    private string $root;
    private IkontrolInstance $instance;
    private AdminUser $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/compatibility-'.bin2hex(random_bytes(5)));
        File::ensureDirectoryExists($this->root.'/golden');
        config(['ikontrol.instances_root' => $this->root, 'ikontrol.compatibility_inspector.targets.1.1.6' => [
            'bundle_path' => null, 'bundle_manifest_sha256' => null, 'source_version' => '1.1.5',
            'source_commit' => '3c4a65d00d833743378ce526da7e5891da7509f9', 'trust' => 'DEVELOPMENT_VERIFIED',
        ]]);
        $client = Client::create(['name' => 'Test', 'active' => true]);
        $this->instance = IkontrolInstance::create(['client_id' => $client->id, 'name' => 'Golden', 'slug' => 'golden', 'folder_name' => 'golden', 'absolute_path' => $this->root.'/golden', 'db_name' => 'golden_db', 'current_version' => '1.1.5', 'installation_status' => InstallationStatus::Ready, 'active' => true]);
        $this->user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password-for-tests', 'active' => true]);
    }

    public function test_verified_development_bundle_is_staged_in_admin_runtime_and_removed(): void
    {
        $source = $this->bundleFixture();
        config(['ikontrol.compatibility_inspector.targets.1.1.6.bundle_path' => $source, 'ikontrol.compatibility_inspector.targets.1.1.6.bundle_manifest_sha256' => $this->textHash($source.'/bundle-manifest.json')]);
        $service = new CompatibilityInspectorBundle();
        $staged = $service->stage('1.1.6');
        $this->assertFileExists($staged['entrypoint']);
        $this->assertStringStartsWith(str_replace('\\', '/', storage_path('app/compatibility-inspector/runtime/')), str_replace('\\', '/', $staged['path']).'/');
        $service->remove($staged);
        $this->assertDirectoryDoesNotExist($staged['path']);
    }

    public function test_tampered_bundle_is_rejected_before_process_execution(): void
    {
        $source = $this->bundleFixture();
        config(['ikontrol.compatibility_inspector.targets.1.1.6.bundle_path' => $source, 'ikontrol.compatibility_inspector.targets.1.1.6.bundle_manifest_sha256' => $this->textHash($source.'/bundle-manifest.json')]);
        File::append($source.'/inspect.php', 'tampered');
        $this->expectException(RuntimeException::class);
        (new CompatibilityInspectorBundle())->stage('1.1.6');
    }

    public function test_compatible_result_is_persisted_but_development_bundle_never_authorizes_deployment(): void
    {
        $evaluation = $this->service($this->inspectorOutput('COMPATIBLE', ['private_key' => 'never-store-this']))->evaluate($this->instance, '1.1.6', null, $this->user);
        $this->assertSame('COMPATIBLE', $evaluation->status);
        $this->assertFalse($evaluation->authorizes_deployment);
        $this->assertSame('DEVELOPMENT_VERIFIED', $evaluation->bundle_trust);
        $this->assertSame('1.1.5', data_get($evaluation->summary, 'versions.registered'));
        $this->assertStringNotContainsString('never-store-this', json_encode($evaluation->summary));
        $this->assertSame(64, strlen((string) $evaluation->files_fingerprint_sha256));
    }

    public function test_review_required_is_not_promoted_to_compatible(): void
    {
        $evaluation = $this->service($this->inspectorOutput('REVIEW_REQUIRED'))->evaluate($this->instance, '1.1.6', null, $this->user);
        $this->assertSame('REVIEW_REQUIRED', $evaluation->status);
        $this->assertFalse($evaluation->authorizes_deployment);
    }

    public function test_blocked_result_with_exit_code_two_is_stored_as_blocked(): void
    {
        $evaluation = $this->service($this->inspectorOutput('BLOCKED'), 2)->evaluate($this->instance, '1.1.6', null, $this->user);
        $this->assertSame('BLOCKED', $evaluation->status);
        $this->assertSame(['FISCAL_SCHEMA_PREREQUISITE_FAILED'], $evaluation->summary['blockers']);
    }

    public function test_positive_result_with_nonzero_exit_fails_closed(): void
    {
        $evaluation = $this->service($this->inspectorOutput('COMPATIBLE'), 2)->evaluate($this->instance, '1.1.6', null, $this->user);
        $this->assertSame('NOT_VERIFIED', $evaluation->status);
        $this->assertSame('INSPECTOR_EXIT_STATUS_MISMATCH', data_get($evaluation->error_evidence, 'code'));
    }

    public function test_missing_read_only_guarantee_fails_closed_without_running_inspector(): void
    {
        $bundle = $this->bundleMock();
        $credentials = Mockery::mock(ReadOnlyDatabaseCredentialVerifier::class);
        $credentials->shouldReceive('verify')->once()->andThrow(new RuntimeException('READ_ONLY_CREDENTIALS_NOT_CONFIGURED'));
        $process = Mockery::mock(CompatibilityInspectorProcessRunner::class);
        $process->shouldNotReceive('run');
        $evaluation = (new InstanceCompatibilityService($bundle, $credentials, $process))->evaluate($this->instance, '1.1.6', null, $this->user);
        $this->assertSame('NOT_VERIFIED', $evaluation->status);
        $this->assertSame('READ_ONLY_CREDENTIALS_NOT_CONFIGURED', data_get($evaluation->error_evidence, 'code'));
    }

    public function test_database_grants_must_be_physically_read_only(): void
    {
        $verifier = new ReadOnlyDatabaseCredentialVerifier();
        $fingerprint = $verifier->assertReadOnlyGrants([
            "GRANT USAGE ON *.* TO 'reader'@'localhost'",
            "GRANT SELECT, SHOW VIEW ON `golden_db`.* TO 'reader'@'localhost'",
        ]);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $fingerprint);
        $this->expectException(RuntimeException::class);
        $verifier->assertReadOnlyGrants(["GRANT SELECT, UPDATE ON `golden_db`.* TO 'reader'@'localhost'"]);
    }

    public function test_invalid_output_fails_closed_without_persisting_stdout(): void
    {
        $evaluation = $this->service('noise password=secret')->evaluate($this->instance, '1.1.6', null, $this->user);
        $this->assertSame('NOT_VERIFIED', $evaluation->status);
        $this->assertSame('INSPECTOR_OUTPUT_MISSING', data_get($evaluation->error_evidence, 'code'));
        $this->assertStringNotContainsString('secret', json_encode($evaluation->toArray()));
    }

    public function test_http_flow_requires_auth_and_renders_sanitized_evidence(): void
    {
        $this->get(route('instances.compatibility.show', [$this->instance, 999]))->assertRedirect(route('login'));
        $evaluation = $this->service($this->inspectorOutput('COMPATIBLE'))->evaluate($this->instance, '1.1.6', null, $this->user);
        $this->actingAs($this->user)->get(route('instances.compatibility.show', [$this->instance, $evaluation]))->assertOk()->assertSee('COMPATIBLE')->assertSee($evaluation->evidence_id);
    }

    public function test_development_evidence_cannot_open_release_deployment_gate(): void
    {
        $evaluation = $this->service($this->inspectorOutput('COMPATIBLE'))->evaluate($this->instance, '1.1.6', null, $this->user);
        $release = IkontrolRelease::create(['version' => '1.1.6', 'release_identifier' => 'v1.1.6', 'channel' => 'stable', 'git_tag' => 'v1.1.6', 'source_ref' => 'v1.1.6', 'commit_sha' => str_repeat('b', 40), 'source_repository' => config('ikontrol.releases.repository'), 'artifact_sha256' => str_repeat('c', 64), 'artifact_verification_status' => 'verified', 'status' => 'validated']);
        $this->assertFalse($evaluation->authorizes_deployment);
        $this->expectException(RuntimeException::class);
        app(CompatibilityDeploymentGate::class)->assertAllowed($this->instance, $release);
    }

    public function test_gate_reinspects_and_rejects_changed_release_snapshots(): void
    {
        $release = IkontrolRelease::create(['version' => '1.1.6', 'release_identifier' => 'v1.1.6', 'channel' => 'stable', 'git_tag' => 'v1.1.6', 'source_ref' => 'v1.1.6', 'commit_sha' => str_repeat('b', 40), 'source_repository' => config('ikontrol.releases.repository'), 'artifact_sha256' => str_repeat('c', 64), 'artifact_verification_status' => 'verified', 'status' => 'validated']);
        $evaluation = InstanceCompatibilityEvaluation::create([
            'instance_id' => $this->instance->id, 'release_id' => $release->id, 'source_version' => '1.1.5',
            'target_version' => '1.1.6', 'source_commit_sha' => '3c4a65d00d833743378ce526da7e5891da7509f9',
            'evaluated_release_commit_sha' => str_repeat('b', 40), 'evaluated_release_artifact_sha256' => str_repeat('c', 64),
            'status' => 'COMPATIBLE', 'bundle_trust' => 'RELEASE_VERIFIED', 'authorizes_deployment' => true, 'evaluated_at' => now(),
        ]);
        $inspector = Mockery::mock(InstanceCompatibilityService::class);
        $inspector->shouldReceive('evaluate')->twice()->andReturn($evaluation);
        $gate = new CompatibilityDeploymentGate($inspector);
        $gate->assertAllowed($this->instance, $release);
        $release->update(['commit_sha' => str_repeat('d', 40), 'artifact_sha256' => str_repeat('e', 64)]);
        $this->expectException(RuntimeException::class);
        $gate->assertAllowed($this->instance, $release->fresh());
    }

    public function test_gate_does_not_reuse_historic_compatible_evidence(): void
    {
        $release = IkontrolRelease::create(['version' => '1.1.6', 'release_identifier' => 'v1.1.6', 'channel' => 'stable', 'git_tag' => 'v1.1.6', 'source_ref' => 'v1.1.6', 'commit_sha' => str_repeat('b', 40), 'source_repository' => config('ikontrol.releases.repository'), 'artifact_sha256' => str_repeat('c', 64), 'artifact_verification_status' => 'verified', 'status' => 'validated']);
        InstanceCompatibilityEvaluation::create(['instance_id' => $this->instance->id, 'release_id' => $release->id, 'source_version' => '1.1.5', 'target_version' => '1.1.6', 'source_commit_sha' => '3c4a65d00d833743378ce526da7e5891da7509f9', 'evaluated_release_commit_sha' => str_repeat('b', 40), 'evaluated_release_artifact_sha256' => str_repeat('c', 64), 'status' => 'COMPATIBLE', 'bundle_trust' => 'RELEASE_VERIFIED', 'authorizes_deployment' => true, 'evaluated_at' => now()->subMinute()]);
        $fresh = InstanceCompatibilityEvaluation::create(['instance_id' => $this->instance->id, 'source_version' => '1.1.5', 'target_version' => '1.1.6', 'source_commit_sha' => '3c4a65d00d833743378ce526da7e5891da7509f9', 'status' => 'NOT_VERIFIED', 'bundle_trust' => 'DEVELOPMENT_VERIFIED', 'authorizes_deployment' => false, 'evaluated_at' => now()]);
        $inspector = Mockery::mock(InstanceCompatibilityService::class);
        $inspector->shouldReceive('evaluate')->once()->andReturn($fresh);
        $this->expectException(RuntimeException::class);
        (new CompatibilityDeploymentGate($inspector))->assertAllowed($this->instance, $release);
    }

    public function test_pending_golden_recovery_blocks_an_otherwise_compatible_result(): void
    {
        InstanceUpdateRun::create(['instance_id' => $this->instance->id, 'from_version' => '1.1.4', 'to_version' => '1.1.5', 'status' => 'MANUAL_REVIEW_REQUIRED']);
        $evaluation = $this->service($this->inspectorOutput('COMPATIBLE'))->evaluate($this->instance, '1.1.6', null, $this->user);
        $this->assertSame('BLOCKED', $evaluation->status);
        $this->assertContains('ADMIN_DEPLOYMENT_RECOVERY_REQUIRED', $evaluation->summary['blockers']);
        $this->assertTrue(data_get($evaluation->summary, 'admin_prerequisites.deployment_blocker'));
    }

    public function test_invoice_parameter_is_restricted_to_1410_by_http_validation(): void
    {
        $this->actingAs($this->user)->post(route('instances.compatibility.store', $this->instance), ['target_version' => '1.1.6', 'invoice_id' => 99])->assertSessionHasErrors('invoice_id');
        $this->assertDatabaseCount('instance_compatibility_evaluations', 0);
    }

    public function test_evaluation_migration_is_additive_and_has_required_indexes(): void
    {
        $this->assertTrue(Schema::hasColumns('instance_compatibility_evaluations', [
            'evidence_id', 'instance_id', 'release_id', 'evaluated_by', 'source_commit_sha', 'source_tree_sha256',
            'evaluated_release_commit_sha', 'evaluated_release_artifact_sha256', 'status', 'summary', 'error_evidence',
        ]));
        $indexes = collect(Schema::getIndexes('instance_compatibility_evaluations'))->pluck('name')->all();
        $this->assertContains('instance_compatibility_evaluations_evidence_id_unique', $indexes);
        $this->assertContains('compatibility_instance_target_date', $indexes);
    }

    public function test_partial_existing_evaluation_table_fails_closed_without_alteration(): void
    {
        Schema::drop('instance_compatibility_evaluations');
        Schema::create('instance_compatibility_evaluations', fn (Blueprint $table) => $table->id());
        $migration = require database_path('migrations/2026_10_26_000001_create_instance_compatibility_evaluations_table.php');
        try {
            $migration->up();
            $this->fail('A partial schema must not be accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('esquema parcial', $e->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('instance_compatibility_evaluations', 'id'));
        $this->assertFalse(Schema::hasColumn('instance_compatibility_evaluations', 'status'));
    }

    private function service(string $stdout, int $exitCode = 0): InstanceCompatibilityService
    {
        $bundle = $this->bundleMock();
        $credentials = Mockery::mock(ReadOnlyDatabaseCredentialVerifier::class);
        $credentials->shouldReceive('verify')->once()->andReturn(['verified' => true, 'grants_fingerprint_sha256' => str_repeat('d', 64)]);
        $credentials->shouldReceive('environment')->once()->andReturn(['database.default.username' => 'readonly', 'database.default.password' => 'not-persisted']);
        $process = Mockery::mock(CompatibilityInspectorProcessRunner::class);
        $process->shouldReceive('run')->once()->andReturn(['exit_code' => $exitCode, 'timed_out' => false, 'stdout' => $stdout]);
        return new InstanceCompatibilityService($bundle, $credentials, $process);
    }

    private function bundleMock(): CompatibilityInspectorBundle
    {
        $bundle = Mockery::mock(CompatibilityInspectorBundle::class);
        $bundle->shouldReceive('stage')->once()->andReturn(['path' => $this->root.'/bundle', 'entrypoint' => $this->root.'/bundle/inspect.php', 'manifest_sha256' => str_repeat('a', 64), 'contract_sha256' => str_repeat('b', 64), 'contract' => $this->contract(), 'trust' => 'DEVELOPMENT_VERIFIED']);
        $bundle->shouldReceive('remove')->once();
        return $bundle;
    }

    private function inspectorOutput(string $status, array $extraData = []): string
    {
        $result = [
            'schema_version' => 1, 'target_version' => '1.1.6', 'status' => $status, 'read_only' => true,
            'versions' => ['status' => 'VERIFIED', 'canonical' => '1.1.5', 'registered' => '1.1.5', 'expected_source_version' => '1.1.5', 'expected_source_commit' => '3c4a65d00d833743378ce526da7e5891da7509f9', 'match' => true],
            'files' => ['status' => 'VERIFIED', 'expected_count' => 296, 'matched_count' => 296, 'modified' => [], 'missing' => [], 'extra_in_scope' => [], 'origin_tree_sha256' => str_repeat('e', 64)],
            'schema' => ['status' => $status === 'BLOCKED' ? 'BLOCKED' : 'VERIFIED', 'fingerprint_sha256' => str_repeat('f', 64), 'tables' => [], 'missing_or_incomplete_tables' => [], 'invalid_index_tables' => []],
            'runtime' => ['status' => 'VERIFIED', 'coherent' => true, 'operational' => true], 'data' => ['baseline' => ['status' => 'VERIFIED']] + $extraData,
            'blockers' => $status === 'BLOCKED' ? ['FISCAL_SCHEMA_PREREQUISITE_FAILED'] : [], 'review_required' => $status === 'REVIEW_REQUIRED' ? ['LOCAL_CODE_DIFFERENCE'] : [], 'not_verified' => $status === 'NOT_VERIFIED' ? ['FISCAL_RUNTIME_NOT_VERIFIED'] : [], 'privacy' => ['contains_secrets' => false],
        ];
        return "IKONTROL_COMPATIBILITY_JSON_BEGIN\n".json_encode($result)."\nIKONTROL_COMPATIBILITY_JSON_END\n";
    }

    private function contract(): array
    {
        return ['target_version' => '1.1.6', 'source' => ['version' => '1.1.5', 'commit' => '3c4a65d00d833743378ce526da7e5891da7509f9', 'tree_sha256' => str_repeat('e', 64)]];
    }

    private function bundleFixture(): string
    {
        $path = $this->root.'/bundle-source'; File::ensureDirectoryExists($path);
        File::put($path.'/inspect.php', "<?php echo 'fixture';\n");
        File::put($path.'/InstanceCompatibilityInspector.php', "<?php final class FixtureInspector {}\n");
        File::put($path.'/contract-1.1.6.json', json_encode($this->contract()));
        $files = []; foreach (['inspect.php', 'InstanceCompatibilityInspector.php', 'contract-1.1.6.json'] as $file) $files[$file] = ['sha256' => $this->textHash($path.'/'.$file), 'hash_mode' => 'text-lf-sha256'];
        File::put($path.'/bundle-manifest.json', json_encode(['schema_version' => 1, 'target_version' => '1.1.6', 'source_commit' => '3c4a65d00d833743378ce526da7e5891da7509f9', 'entrypoint' => 'inspect.php', 'files' => $files]));
        return $path;
    }

    private function textHash(string $path): string
    {
        return hash('sha256', str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($path)));
    }
}
