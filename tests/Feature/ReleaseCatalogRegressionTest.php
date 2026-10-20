<?php

namespace Tests\Feature;

use App\Enums\InstallationStatus;
use App\Models\{AdminUser, Client, IkontrolInstance, IkontrolRelease};
use App\Services\Versioning\ReleasePromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ReleaseCatalogRegressionTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;
    private IkontrolInstance $golden;
    private IkontrolRelease $release;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ikontrol.upgrade.canonical_version' => '1.1.5']);

        $this->admin = AdminUser::create([
            'name' => 'Release Admin',
            'email' => 'release-regression@example.test',
            'password' => 'a-secure-test-password',
            'active' => true,
        ]);
        $client = Client::create(['name' => 'Golden Client']);
        $this->golden = IkontrolInstance::create([
            'client_id' => $client->id,
            'name' => 'Golden',
            'slug' => 'golden',
            'folder_name' => 'ikontrol.ikontrol.solutions',
            'absolute_path' => 'C:\\instances\\golden',
            'domain' => 'ikontrol.ikontrol.solutions',
            'db_name' => 'golden_db',
            'detected_version' => '1.1.4',
            'current_version' => '1.1.4',
            'canonical_version' => '1.1.4',
            'target_version' => '1.1.4',
            'database_status' => 'READY',
            'baseline_status' => 'FAIL',
            'upgrade_status' => 'CURRENT',
            'installation_origin' => 'EXISTING',
            'installation_status' => InstallationStatus::Ready,
            'active' => true,
        ]);
        config(['ikontrol.release_deployment.golden_instance_id' => $this->golden->id]);

        $this->release = IkontrolRelease::create([
            'version' => '1.1.5',
            'release_identifier' => 'ikontrol-1.1.5-canonical-stamp-wallet-resolution',
            'channel' => 'stable',
            'git_tag' => 'v1.1.5',
            'source_ref' => 'v1.1.5',
            'commit_sha' => '3c4a65d00d833743378ce526da7e5891da7509f9',
            'source_repository' => 'triple-w/ikontrol-platform',
            'artifact_url' => 'https://github.com/triple-w/ikontrol-platform/releases/download/v1.1.5/ikontrol-platform-1.1.5.zip',
            'artifact_sha256' => 'e2d6763291c341c75ad8d09f901d66e7f545269c52a28e8a0a54033e565245f6',
            'artifact_verification_status' => 'unverified',
            'manifest_hash' => str_repeat('d', 64),
            'manifest_json' => ['from_versions' => ['1.1.4']],
            'status' => 'validated',
            'promotion_status' => 'BUILT',
            'published_at' => now(),
            'discovered_at' => now(),
        ]);
    }

    public function test_existing_release_manifest_opens_and_renders_real_states(): void
    {
        $this->withoutVite()->actingAs($this->admin)
            ->get(route('versions.releases.manifest', $this->release))
            ->assertOk()
            ->assertSee('BUILT')
            ->assertSee('UNVERIFIED')
            ->assertSee('Admin todavía no descargó ni inspeccionó el ZIP');
    }

    public function test_compatibility_section_opens_without_server_error(): void
    {
        $this->withoutVite()->actingAs($this->admin)
            ->get(route('versions.releases.manifest', [$this->release, 'section' => 'compatibility']))
            ->assertOk()
            ->assertSee('Compatibilidad')
            ->assertSee('1.1.4');
    }

    public function test_internal_current_is_distinct_from_compatible_code_release(): void
    {
        $this->withoutVite()->actingAs($this->admin)
            ->get(route('instances.show', [$this->golden, 'tab' => 'upgrade']))
            ->assertOk()
            ->assertSee('Estado interno inspeccionado')
            ->assertSee('CURRENT')
            ->assertSee('Release de código disponible: 1.1.5')
            ->assertSee('Baseline funcional: FAIL')
            ->assertSee('Preparar código 1.1.5');
    }

    public function test_built_release_is_allowed_for_golden_but_blocked_for_client(): void
    {
        app(ReleasePromotionService::class)->assertDeploymentAllowed($this->golden, $this->release);

        $client = Client::create(['name' => 'Normal Client']);
        $normal = IkontrolInstance::create([
            'client_id' => $client->id,
            'name' => 'Smartfree',
            'slug' => 'smartfree',
            'folder_name' => 'smartfree.ikontrol.solutions',
            'absolute_path' => 'C:\\instances\\smartfree',
            'domain' => 'smartfree.ikontrol.solutions',
            'db_name' => 'smartfree_db',
            'detected_version' => '1.1.4',
            'current_version' => '1.1.4',
            'update_channel' => 'stable',
            'installation_origin' => 'EXISTING',
            'installation_status' => InstallationStatus::Ready,
            'active' => true,
        ]);

        $this->expectException(RuntimeException::class);
        app(ReleasePromotionService::class)->assertDeploymentAllowed($normal, $this->release);
    }
}
