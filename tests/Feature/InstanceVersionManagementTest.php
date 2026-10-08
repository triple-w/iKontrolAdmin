<?php

namespace Tests\Feature;

use App\Enums\InstallationStatus;
use App\Models\{AdminUser, Client, IkontrolInstance, IkontrolUpgradeAudit};
use App\Services\{AllowedSparkRunner, ManagedCommandJsonProtocol};
use App\Services\Upgrade\InstanceVersionManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class InstanceVersionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ikontrol.upgrade.canonical_version' => '1.1.4']);
    }

    public function test_golden_114_is_current(): void
    {
        [$service, $runner] = $this->service(); $instance = $this->makeInstance('golden');
        $this->inspectionExpectations($runner, $instance, '1.1.4');
        $result = $service->inspect($instance);
        $this->assertSame('CURRENT', $result['status']);
        $this->assertDatabaseHas('ikontrol_instances', ['id' => $instance->id, 'detected_version' => '1.1.4', 'canonical_version' => '1.1.4', 'upgrade_status' => 'CURRENT', 'database_status' => 'READY', 'baseline_status' => 'READY']);
    }

    public function test_smartfree_113_has_update_available(): void
    {
        [$service, $runner] = $this->service(); $instance = $this->makeInstance('smartfree');
        $this->inspectionExpectations($runner, $instance, '1.1.3');
        $this->assertSame('UPDATE_AVAILABLE', $service->inspect($instance)['status']);
    }

    public function test_null_version_with_compatible_adoption_is_legacy_adoptable(): void
    {
        [$service, $runner] = $this->service(); $instance = $this->makeInstance('navika');
        $runner->shouldReceive('inspectVersion')->once()->with($instance->absolute_path)->andReturn($this->process(['status' => 'READY', 'current_version' => null]));
        $runner->shouldReceive('run')->once()->with($instance->absolute_path, 'ikontrol:database-check')->andReturn($this->process(['status' => 'READY']));
        $runner->shouldReceive('run')->once()->with($instance->absolute_path, 'ikontrol:baseline-check')->andReturn($this->process(['status' => 'FAILED']));
        $runner->shouldReceive('runAdoptBaseline')->once()->with($instance->absolute_path)->andReturn($this->process(['status' => 'ADOPTABLE', 'adoptable' => true]));
        $this->assertSame('LEGACY_ADOPTABLE', $service->inspect($instance)['status']);
        $this->assertSame('LEGACY', $instance->fresh()->installation_origin);
    }

    public function test_database_check_failure_blocks_instance(): void
    {
        [$service, $runner] = $this->service(); $instance = $this->makeInstance('blocked');
        $this->inspectionExpectations($runner, $instance, '1.1.3', 'FAILED');
        $this->assertSame('BLOCKED', $service->inspect($instance)['status']);
    }

    public function test_inaccessible_instance_is_unreachable(): void
    {
        [$service, $runner] = $this->service(); $instance = $this->makeInstance('offline');
        $runner->shouldReceive('inspectVersion')->once()->andThrow(new RuntimeException('connection failed'));
        $this->assertSame('UNREACHABLE', $service->inspect($instance)['status']);
        $this->assertSame('ERROR', $instance->fresh()->last_connection_status);
    }

    public function test_upgrade_plan_compatible_is_stored(): void
    {
        [$service, $runner] = $this->service(); $instance = $this->makeInstance('plan', ['detected_version' => '1.1.3', 'upgrade_status' => 'UPDATE_AVAILABLE']);
        $runner->shouldReceive('runUpgradePlan')->once()->with($instance->absolute_path, '1.1.4')->andReturn($this->process(['status' => 'READY', 'compatible' => true, 'steps' => ['backup', 'upgrade']]));
        $audit = $service->plan($instance);
        $this->assertSame('PLAN_READY', $audit->status); $this->assertSame('COMPATIBLE', $audit->compatibility);
        $this->assertSame(['backup', 'upgrade'], $audit->plan_json['steps']);
    }

    public function test_upgrade_plan_blocked_blocks_instance(): void
    {
        [$service, $runner] = $this->service(); $instance = $this->makeInstance('planblocked', ['detected_version' => '1.1.3', 'upgrade_status' => 'UPDATE_AVAILABLE']);
        $runner->shouldReceive('runUpgradePlan')->once()->andReturn($this->process(['status' => 'BLOCKED', 'compatible' => false]));
        $audit = $service->plan($instance);
        $this->assertSame('PLAN_BLOCKED', $audit->status); $this->assertSame('BLOCKED', $instance->fresh()->upgrade_status);
    }

    public function test_adoption_dry_run_is_stored_without_execution(): void
    {
        [$service, $runner] = $this->service(); $instance = $this->makeInstance('adoptdry', ['upgrade_status' => 'LEGACY_ADOPTABLE', 'installation_origin' => 'LEGACY']);
        $runner->shouldReceive('runAdoptBaseline')->once()->with($instance->absolute_path)->andReturn($this->process(['status' => 'ADOPTABLE', 'adoptable' => true]));
        $audit = $service->adoptionPlan($instance);
        $this->assertSame('ADOPTION_READY', $audit->status); $this->assertSame('LEGACY_ADOPTABLE', $instance->fresh()->upgrade_status);
    }

    public function test_adoption_execute_rechecks_and_reinspects(): void
    {
        [$service, $runner] = $this->service(); $instance = $this->makeInstance('adoptexec', ['upgrade_status' => 'LEGACY_ADOPTABLE', 'installation_origin' => 'LEGACY']);
        $audit = $this->audit($instance, 'ADOPTION_READY');
        $runner->shouldReceive('runAdoptBaseline')->once()->with($instance->absolute_path)->andReturn($this->process(['status' => 'ADOPTABLE', 'adoptable' => true]));
        $runner->shouldReceive('runAdoptBaseline')->once()->with($instance->absolute_path, true)->andReturn($this->process(['status' => 'ADOPTED']));
        $this->inspectionExpectations($runner, $instance, '1.1.3');
        $service->executeAdoption($instance, $audit);
        $this->assertSame('ADOPTED', $audit->fresh()->status); $this->assertSame('UPDATE_AVAILABLE', $instance->fresh()->upgrade_status); $this->assertSame('ADOPTED', $instance->fresh()->installation_origin);
    }

    public function test_upgrade_execute_rechecks_executes_and_reinspects(): void
    {
        [$service, $runner] = $this->service(); $instance = $this->makeInstance('upgradeexec', ['detected_version' => '1.1.3', 'upgrade_status' => 'UPDATE_AVAILABLE']);
        $audit = $this->audit($instance, 'PLAN_READY');
        $runner->shouldReceive('runUpgradePlan')->once()->with($instance->absolute_path, '1.1.4')->andReturn($this->process(['status' => 'READY', 'compatible' => true]));
        $runner->shouldReceive('executeUpgrade')->once()->with($instance->absolute_path, '1.1.4')->andReturn($this->process(['status' => 'COMPLETED']));
        $this->inspectionExpectations($runner, $instance, '1.1.4');
        $service->executeUpgrade($instance, $audit);
        $this->assertSame('COMPLETED', $audit->fresh()->status); $this->assertSame('CURRENT', $instance->fresh()->upgrade_status); $this->assertSame('COMPLETED', $instance->fresh()->last_update_status);
    }

    public function test_non_allowlisted_command_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        app(AllowedSparkRunner::class)->run('C:\\not-used', 'shell:exec', ['whoami']);
    }

    public function test_malicious_target_version_is_rejected_before_process_execution(): void
    {
        $this->expectException(RuntimeException::class);
        app(AllowedSparkRunner::class)->runUpgradePlan('C:\\not-used', '1.1.4;rm -rf');
    }

    public function test_upgrade_endpoint_requires_exact_confirmation_and_audits_execution(): void
    {
        $admin = AdminUser::create(['name' => 'Admin', 'email' => 'upgrade@example.test', 'password' => 'a-secure-test-password', 'active' => true]);
        $instance = $this->makeInstance('confirmed', ['detected_version' => '1.1.3', 'upgrade_status' => 'UPDATE_AVAILABLE']);
        $audit = $this->audit($instance, 'PLAN_READY');

        $this->actingAs($admin)->post(route('instances.upgrade.execute', [$instance, $audit]), ['confirmation' => 'wrong'])->assertSessionHasErrors('confirmation');
        $this->assertDatabaseMissing('admin_audit_logs', ['action' => 'INSTANCE_UPGRADE_EXECUTION_STARTED']);

        $runner = Mockery::mock(AllowedSparkRunner::class);
        $runner->shouldReceive('runUpgradePlan')->once()->andReturn($this->process(['status' => 'READY', 'compatible' => true]));
        $runner->shouldReceive('executeUpgrade')->once()->andReturn($this->process(['status' => 'COMPLETED']));
        $this->inspectionExpectations($runner, $instance, '1.1.4');
        $this->app->instance(AllowedSparkRunner::class, $runner);

        $this->actingAs($admin)->post(route('instances.upgrade.execute', [$instance, $audit]), ['confirmation' => $instance->slug])->assertRedirect(route('instances.show', [$instance, 'tab' => 'upgrade']));
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'INSTANCE_UPGRADE_EXECUTION_STARTED', 'entity_id' => $instance->id]);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'INSTANCE_UPGRADE_EXECUTION_COMPLETED', 'entity_id' => $instance->id]);
    }

    public function test_ui_lists_canonical_state_and_only_shows_actions_for_current_state(): void
    {
        $admin = AdminUser::create(['name' => 'UI Admin', 'email' => 'upgrade-ui@example.test', 'password' => 'a-secure-test-password', 'active' => true]);
        $current = $this->makeInstance('uicurrent', ['detected_version' => '1.1.4', 'canonical_version' => '1.1.4', 'target_version' => '1.1.4', 'upgrade_status' => 'CURRENT']);
        $update = $this->makeInstance('uiupdate', ['detected_version' => '1.1.3', 'canonical_version' => '1.1.4', 'target_version' => '1.1.4', 'upgrade_status' => 'UPDATE_AVAILABLE']);
        $legacy = $this->makeInstance('uilegacy', ['canonical_version' => '1.1.4', 'target_version' => '1.1.4', 'upgrade_status' => 'LEGACY_ADOPTABLE', 'installation_origin' => 'LEGACY']);

        $this->withoutVite()->actingAs($admin)->get(route('instances.index'))->assertOk()->assertSee('Versión detectada')->assertSee('UPDATE AVAILABLE')->assertSee('LEGACY');
        $this->actingAs($admin)->get(route('instances.show', [$current, 'tab' => 'upgrade']))->assertOk()->assertSee('Instancia actualizada')->assertDontSee('Ver plan');
        $this->actingAs($admin)->get(route('instances.show', [$update, 'tab' => 'upgrade']))->assertOk()->assertSee('Ver plan')->assertDontSee('Evaluar adopción');
        $this->actingAs($admin)->get(route('instances.show', [$legacy, 'tab' => 'upgrade']))->assertOk()->assertSee('Evaluar adopción')->assertDontSee('Ver plan');
    }

    private function service(): array
    {
        $runner = Mockery::mock(AllowedSparkRunner::class);
        return [new InstanceVersionManagementService($runner, app(ManagedCommandJsonProtocol::class)), $runner];
    }

    private function inspectionExpectations($runner, IkontrolInstance $instance, string $version, string $database = 'READY', string $baseline = 'READY'): void
    {
        $runner->shouldReceive('inspectVersion')->once()->with($instance->absolute_path)->andReturn($this->process(['status' => 'READY', 'current_version' => $version]));
        $runner->shouldReceive('run')->once()->with($instance->absolute_path, 'ikontrol:database-check')->andReturn($this->process(['status' => $database]));
        $runner->shouldReceive('run')->once()->with($instance->absolute_path, 'ikontrol:baseline-check')->andReturn($this->process(['status' => $baseline]));
    }

    private function process(array $payload): array
    {
        return ['execution_id' => 'TEST1234', 'exit_code' => 0, 'stdout' => "IKONTROL_JSON_BEGIN\n".json_encode($payload, JSON_THROW_ON_ERROR)."\nIKONTROL_JSON_END", 'stderr' => ''];
    }

    private function makeInstance(string $slug, array $replace = []): IkontrolInstance
    {
        $client = Client::create(['name' => 'Cliente '.$slug]);
        return IkontrolInstance::create(array_replace(['client_id' => $client->id, 'name' => ucfirst($slug), 'slug' => $slug, 'folder_name' => $slug.'.ikontrol.solutions', 'absolute_path' => 'C:\\instances\\'.$slug, 'domain' => $slug.'.ikontrol.solutions', 'db_name' => 'db_'.$slug, 'installation_status' => InstallationStatus::Ready, 'installation_origin' => 'EXISTING', 'upgrade_status' => 'NOT_AUDITED', 'active' => true], $replace));
    }

    private function audit(IkontrolInstance $instance, string $status): IkontrolUpgradeAudit
    {
        return IkontrolUpgradeAudit::create(['ikontrol_instance_id' => $instance->id, 'source_version' => $instance->detected_version, 'target_version' => '1.1.4', 'status' => $status, 'compatibility' => 'COMPATIBLE', 'started_at' => now(), 'finished_at' => now(), 'plan_json' => ['compatible' => true]]);
    }
}
