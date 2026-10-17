<?php

namespace Tests\Feature;

use App\Enums\InstallationStatus;
use App\Models\{AdminUser, Client, IkontrolInstance, IkontrolRelease, IkontrolUpgradeAudit, InstanceUpdateRun, ReleasePromotion};
use App\Services\Versioning\{GoldenInstanceResolver, ReleaseCompatibilityService, ReleasePromotionService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class ReleasePromotionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $actor;
    private IkontrolInstance $golden;
    private IkontrolInstance $normal;
    private IkontrolRelease $release;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = AdminUser::create(['name' => 'Release Approver', 'email' => 'approver@example.test', 'password' => 'a-secure-test-password', 'active' => true]);
        $client = Client::create(['name' => 'Promotion Client']);
        $this->golden = $this->createInstance($client, 'Golden', 'golden');
        $this->normal = $this->createInstance($client, 'Smartfree', 'smartfree');
        config(['ikontrol.release_deployment.golden_instance_id' => $this->golden->id]);
        $this->release = IkontrolRelease::create([
            'version' => '1.1.5', 'release_identifier' => 'ikontrol-1.1.5-test', 'channel' => 'stable',
            'git_tag' => 'v1.1.5', 'source_ref' => 'v1.1.5', 'commit_sha' => str_repeat('b', 40),
            'source_repository' => 'triple-w/ikontrol-platform', 'artifact_sha256' => str_repeat('c', 64),
            'manifest_json' => ['from_versions' => ['1.1.4']], 'status' => 'validated', 'promotion_status' => 'BUILT',
        ]);
    }

    public function test_built_is_allowed_only_on_configured_golden(): void
    {
        $service = $this->promotions();
        $service->assertDeploymentAllowed($this->golden, $this->release);
        $this->assertTrue(app(ReleaseCompatibilityService::class)->isCompatible($this->golden, $this->release));

        $this->expectException(RuntimeException::class);
        $service->assertDeploymentAllowed($this->normal, $this->release);
    }

    public function test_staging_cannot_deploy_to_non_golden(): void
    {
        $run = $this->completedRun($this->golden);
        $this->promotions()->promoteToStaging($this->release, $run, $this->actor);
        $this->expectException(RuntimeException::class);
        $this->promotions()->assertDeploymentAllowed($this->normal, $this->release->fresh());
    }

    public function test_approved_can_deploy_to_compatible_normal_instance(): void
    {
        $this->approve();
        $this->promotions()->assertDeploymentAllowed($this->normal, $this->release->fresh());
        $this->assertTrue(app(ReleaseCompatibilityService::class)->isCompatible($this->normal, $this->release->fresh()));
    }

    public function test_deprecated_blocks_new_deployment(): void
    {
        $this->promotions()->deprecate($this->release, $this->actor, $this->release->release_identifier, 'Sustituida por otra release.');
        $this->expectException(RuntimeException::class);
        $this->promotions()->assertDeploymentAllowed($this->golden, $this->release->fresh());
    }

    public function test_built_to_staging_requires_successful_golden_run(): void
    {
        $this->expectException(RuntimeException::class);
        $this->promotions()->promoteToStaging($this->release, $this->completedRun($this->golden, ['status' => 'failed']), $this->actor);
    }

    public function test_built_to_staging_rejects_run_from_another_instance(): void
    {
        $this->expectException(RuntimeException::class);
        $this->promotions()->promoteToStaging($this->release, $this->completedRun($this->normal), $this->actor);
    }

    public function test_built_to_staging_rejects_another_commit(): void
    {
        $this->expectException(RuntimeException::class);
        $this->promotions()->promoteToStaging($this->release, $this->completedRun($this->golden, ['release_commit_sha' => str_repeat('d', 40)]), $this->actor);
    }

    public function test_built_to_staging_rejects_another_artifact(): void
    {
        $this->expectException(RuntimeException::class);
        $this->promotions()->promoteToStaging($this->release, $this->completedRun($this->golden, ['release_artifact_sha256' => str_repeat('e', 64)]), $this->actor);
    }

    public function test_built_to_staging_records_valid_golden_run_snapshot(): void
    {
        $run = $this->completedRun($this->golden);
        $event = $this->promotions()->promoteToStaging($this->release, $run, $this->actor, 'Validación técnica completada.');
        $this->assertSame('STAGING', $this->release->fresh()->promotion_status);
        $this->assertSame($run->id, $event->instance_update_run_id);
        $this->assertSame($this->release->commit_sha, $event->commit_sha);
        $this->assertSame($this->release->artifact_sha256, $event->artifact_sha256);
    }

    public function test_staging_to_approved_requires_authenticated_user(): void
    {
        $run = $this->completedRun($this->golden);
        $this->promotions()->promoteToStaging($this->release, $run, $this->actor);
        $this->post(route('versions.releases.approve', $this->release), [
            'instance_update_run_id' => $run->id,
            'confirmation' => $this->release->release_identifier,
            'evidence' => ['golden_smoke_test'],
        ])->assertRedirect(route('login'));
        $this->assertSame('STAGING', $this->release->fresh()->promotion_status);
    }

    public function test_staging_to_approved_requires_evidence(): void
    {
        $run = $this->completedRun($this->golden);
        $this->promotions()->promoteToStaging($this->release, $run, $this->actor);
        $this->actingAs($this->actor);
        $this->expectException(RuntimeException::class);
        $this->promotions()->approve($this->release->fresh(), $run, $this->actor, $this->release->release_identifier, []);
    }

    public function test_approved_records_actor_timestamp_and_evidence(): void
    {
        $event = $this->approve();
        $this->assertSame($this->actor->id, $event->actor_user_id);
        $this->assertNotNull($event->created_at);
        $this->assertSame(['golden_smoke_test'], $event->evidence);
    }

    public function test_changed_commit_or_artifact_invalidates_previous_approval(): void
    {
        $this->approve();
        $this->release->update(['artifact_sha256' => str_repeat('f', 64)]);
        $release = $this->release->fresh();
        $this->assertFalse($this->promotions()->hasCurrentApproval($release));
        $this->expectException(RuntimeException::class);
        $this->promotions()->assertDeploymentAllowed($this->normal, $release);
    }

    public function test_changed_commit_also_invalidates_previous_approval(): void
    {
        $this->approve();
        $this->release->update(['commit_sha' => str_repeat('a', 40)]);
        $release = $this->release->fresh();
        $this->assertFalse($this->promotions()->hasCurrentApproval($release));
        $this->expectException(RuntimeException::class);
        $this->promotions()->assertDeploymentAllowed($this->golden, $release);
    }

    public function test_direct_http_prepare_rejects_unapproved_release_for_non_golden(): void
    {
        config(['ikontrol.upgrade.canonical_version' => '1.1.5']);
        $this->actingAs($this->actor)->post(route('instances.releases.prepare', [$this->normal, $this->release]))
            ->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseCount('instance_update_runs', 0);
    }

    public function test_legacy_upgrade_http_route_cannot_bypass_promotion_gate(): void
    {
        $audit = IkontrolUpgradeAudit::create([
            'ikontrol_instance_id' => $this->normal->id,
            'source_version' => '1.1.4',
            'target_version' => $this->release->version,
            'status' => 'PLAN_READY',
            'compatibility' => 'COMPATIBLE',
            'started_at' => now(),
        ]);

        $this->actingAs($this->actor)
            ->post(route('instances.upgrade.execute', [$this->normal, $audit]), ['confirmation' => $this->normal->slug])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('PLAN_READY', $audit->fresh()->status);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'INSTANCE_UPGRADE_EXECUTION_FAILED',
            'entity_id' => $this->normal->id,
        ]);
    }

    public function test_generic_migrate_operation_has_no_http_route(): void
    {
        $this->actingAs($this->actor)
            ->post(route('instances.operations.run', [$this->normal, 'migrate']))
            ->assertNotFound();
    }

    public function test_non_golden_ui_does_not_offer_unapproved_deployment(): void
    {
        $this->normal->update(['canonical_version' => '1.1.5']);
        $this->withoutVite()->actingAs($this->actor)
            ->get(route('instances.show', [$this->normal, 'tab' => 'upgrade']))
            ->assertOk()->assertDontSee('Preparar actualización');
    }

    public function test_http_transitions_are_append_only_and_audited(): void
    {
        $run = $this->completedRun($this->golden);
        $this->actingAs($this->actor)->post(route('versions.releases.promote-staging', $this->release), [
            'instance_update_run_id' => $run->id,
            'notes' => 'Golden técnico correcto.',
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'RELEASE_PROMOTED_TO_STAGING', 'entity_id' => $this->release->id]);

        $this->post(route('versions.releases.approve', $this->release), [
            'instance_update_run_id' => $run->id,
            'confirmation' => $this->release->release_identifier,
            'evidence' => ['golden_smoke_test'],
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'RELEASE_APPROVED', 'entity_id' => $this->release->id]);

        $this->post(route('versions.releases.deprecate', $this->release), [
            'confirmation' => $this->release->release_identifier,
            'notes' => 'Fin de vigencia.',
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'RELEASE_DEPRECATED', 'entity_id' => $this->release->id]);
        $this->assertDatabaseCount('release_promotions', 3);
    }

    public function test_invalid_transition_is_rejected(): void
    {
        $run = $this->completedRun($this->golden);
        $this->promotions()->promoteToStaging($this->release, $run, $this->actor);
        $this->expectException(RuntimeException::class);
        $this->promotions()->promoteToStaging($this->release->fresh(), $run, $this->actor);
    }

    public function test_promotion_history_is_append_only(): void
    {
        $event = $this->promotions()->promoteToStaging($this->release, $this->completedRun($this->golden), $this->actor);
        try { $event->update(['notes' => 'altered']); $this->fail('Historical update should fail.'); } catch (LogicException) {}
        $event->refresh();
        try { $event->delete(); $this->fail('Historical delete should fail.'); } catch (LogicException) {}
        $this->assertDatabaseHas('release_promotions', ['id' => $event->id, 'notes' => null]);
    }

    public function test_golden_is_resolved_only_from_stable_configured_id(): void
    {
        $resolver = app(GoldenInstanceResolver::class);
        $this->assertTrue($resolver->isGolden($this->golden));
        $this->assertFalse($resolver->isGolden($this->normal));
        $this->normal->update(['is_release_canary' => true]);
        $this->assertFalse($resolver->isGolden($this->normal->fresh()));
    }

    public function test_missing_configured_golden_fails_closed(): void
    {
        config(['ikontrol.release_deployment.golden_instance_id' => 999999]);
        $this->expectException(RuntimeException::class);
        app(GoldenInstanceResolver::class)->resolve();
    }

    private function approve(): ReleasePromotion
    {
        $run = $this->completedRun($this->golden);
        $this->promotions()->promoteToStaging($this->release, $run, $this->actor);
        $this->actingAs($this->actor);
        return $this->promotions()->approve($this->release->fresh(), $run, $this->actor, $this->release->release_identifier, ['golden_smoke_test']);
    }

    private function completedRun(IkontrolInstance $instance, array $overrides = []): InstanceUpdateRun
    {
        return InstanceUpdateRun::create(array_merge([
            'instance_id' => $instance->id, 'release_id' => $this->release->id,
            'release_commit_sha' => $this->release->commit_sha,
            'release_artifact_sha256' => $this->release->artifact_sha256,
            'from_version' => '1.1.4', 'to_version' => '1.1.5', 'status' => 'completed',
            'started_at' => now()->subMinute(), 'finished_at' => now(),
        ], $overrides));
    }

    private function createInstance(Client $client, string $name, string $slug): IkontrolInstance
    {
        return IkontrolInstance::create([
            'client_id' => $client->id, 'name' => $name, 'slug' => $slug, 'folder_name' => $slug,
            'absolute_path' => storage_path('framework/testing/'.$slug), 'db_name' => $slug.'_db',
            'current_version' => '1.1.4', 'detected_version' => '1.1.4', 'update_channel' => 'stable',
            'database_status' => 'READY', 'upgrade_status' => 'UPDATE_AVAILABLE',
            'installation_status' => InstallationStatus::Ready, 'active' => true,
        ]);
    }

    private function promotions(): ReleasePromotionService
    {
        return app(ReleasePromotionService::class);
    }
}
