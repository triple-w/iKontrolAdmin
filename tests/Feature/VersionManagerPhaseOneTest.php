<?php

namespace Tests\Feature;

use App\Enums\InstallationStatus;
use App\Models\{AdminUser, Client, IkontrolInstance, IkontrolRelease};
use App\Services\Versioning\{GitHubReleaseService, ReleaseCompatibilityService, ReleaseManifestValidator, ReleaseSyncService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class VersionManagerPhaseOneTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_a_valid_canary_release_and_is_idempotent(): void
    {
        $github = Mockery::mock(GitHubReleaseService::class);
        $github->shouldReceive('discover')->twice()->andReturn([$this->remote('v1.0.1-rc.1', true)]);
        $github->shouldReceive('repository')->twice()->andReturn('triple-w/ikontrol-platform');
        $github->shouldReceive('commitSha')->twice()->with('v1.0.1-rc.1')->andReturn(str_repeat('a', 40));
        $github->shouldReceive('manifest')->twice()->with('1.0.1-rc.1', 'v1.0.1-rc.1')->andReturn($this->manifest('1.0.1-rc.1', 'canary'));
        $sync = new ReleaseSyncService($github, app(ReleaseManifestValidator::class));

        $first = $sync->sync();
        $second = $sync->sync();

        $this->assertSame(['discovered' => 1, 'imported' => 1, 'updated' => 0, 'invalid' => 0], $first);
        $this->assertSame(['discovered' => 1, 'imported' => 0, 'updated' => 0, 'invalid' => 0], $second);
        $this->assertDatabaseCount('ikontrol_releases', 1);
        $this->assertDatabaseHas('ikontrol_releases', ['version' => '1.0.1-rc.1', 'channel' => 'canary', 'status' => 'validated']);
    }

    public function test_imports_a_valid_stable_release(): void
    {
        $release = $this->syncOne('v1.0.1', false, $this->manifest('1.0.1', 'stable'));
        $this->assertSame('stable', $release->channel);
        $this->assertSame('validated', $release->status);
    }

    public function test_invalid_manifest_is_recorded_as_invalid(): void
    {
        $release = $this->syncOne('v1.0.1', false, '{bad json');
        $this->assertSame('invalid', $release->status);
        $this->assertNotEmpty($release->validation_errors);
    }

    public function test_version_mismatch_between_tag_and_manifest_is_invalid(): void
    {
        $release = $this->syncOne('v1.0.1', false, $this->manifest('1.0.2', 'stable'));
        $this->assertSame('invalid', $release->status);
        $this->assertStringContainsString('no coincide', $release->validation_errors[0]);
    }

    public function test_stable_instance_does_not_receive_release_candidate(): void
    {
        $this->assertFalse($this->compatibility()->isCompatible($this->makeInstance('stable'), $this->release('1.0.1-rc.1', 'canary', ['1.0.0'])));
    }

    public function test_canary_instance_detects_release_candidate(): void
    {
        $this->assertTrue($this->compatibility()->isCompatible($this->makeInstance('canary'), $this->release('1.0.1-rc.1', 'canary', ['1.0.0'])));
    }

    public function test_same_version_is_not_updateable(): void
    {
        $this->assertFalse($this->compatibility()->isCompatible($this->makeInstance('canary'), $this->release('1.0.0', 'stable', ['1.0.0'])));
    }

    public function test_from_version_must_be_compatible(): void
    {
        $this->assertFalse($this->compatibility()->isCompatible($this->makeInstance('canary'), $this->release('1.0.2', 'stable', ['1.0.1'])));
    }

    public function test_github_token_and_error_secret_are_not_persisted(): void
    {
        config(['ikontrol.releases.github_token' => 'top-secret-token']);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/releases')) return Http::response([$this->remote('v1.0.1', false)]);
            return Http::response(['message' => 'Token top-secret-token was rejected'], 403);
        });

        (new ReleaseSyncService(app(GitHubReleaseService::class), app(ReleaseManifestValidator::class)))->sync();
        $serialized = IkontrolRelease::sole()->toJson();
        $this->assertStringNotContainsString('top-secret-token', $serialized);
        $this->assertStringNotContainsString('secret', strtolower($serialized));
    }

    public function test_manifest_rejects_secret_fields(): void
    {
        $data = json_decode($this->manifest('1.0.1', 'stable'), true);
        $data['github_token'] = 'do-not-store';
        $this->expectException(\InvalidArgumentException::class);
        app(ReleaseManifestValidator::class)->parse(json_encode($data));
    }

    public function test_authenticated_admin_can_view_release_manifest_and_change_instance_channel(): void
    {
        $admin = AdminUser::create(['name' => 'Version Admin', 'email' => 'release-admin@example.test', 'password' => 'a-secure-test-password', 'active' => true]);
        $release = $this->release('1.0.1-rc.1', 'canary', ['1.0.0']);
        $instance = $this->makeInstance('stable');

        $this->withoutVite()->actingAs($admin)->get(route('versions.releases.manifest', $release))->assertOk()->assertSee('1.0.1-rc.1')->assertSee('Compatibilidad');
        $this->actingAs($admin)->patch(route('instances.update-channel', $instance), ['update_channel' => 'canary'])->assertSessionHasNoErrors();

        $this->assertSame('canary', $instance->fresh()->update_channel);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'update_instance_channel', 'entity_id' => $instance->id]);
    }

    private function syncOne(string $tag, bool $prerelease, string $manifest): IkontrolRelease
    {
        $github = Mockery::mock(GitHubReleaseService::class);
        $github->shouldReceive('discover')->once()->andReturn([$this->remote($tag, $prerelease)]);
        $github->shouldReceive('repository')->once()->andReturn('triple-w/ikontrol-platform');
        $github->shouldReceive('commitSha')->once()->andReturn(str_repeat('b', 40));
        $github->shouldReceive('manifest')->once()->andReturn($manifest);
        (new ReleaseSyncService($github, app(ReleaseManifestValidator::class)))->sync();
        return IkontrolRelease::sole();
    }

    private function remote(string $tag, bool $prerelease): array
    {
        return ['tag_name' => $tag, 'prerelease' => $prerelease, 'draft' => false, 'published_at' => '2026-10-01T12:00:00Z'];
    }

    private function manifest(string $version, string $channel): string
    {
        return json_encode(['schema_version' => 1, 'product' => 'ikontrol', 'version' => $version, 'channel' => $channel, 'from_versions' => ['1.0.0'], 'migrations' => [['id' => '2026-10-01-120000_AddSatCatalogInfrastructure', 'required' => true]], 'commands' => [], 'health_checks' => ['ikontrol:baseline-check'], 'requires_backup' => true], JSON_THROW_ON_ERROR);
    }

    private function makeInstance(string $channel): IkontrolInstance
    {
        $client = Client::create(['name' => 'Cliente '.$channel]);
        return IkontrolInstance::create(['client_id' => $client->id, 'name' => 'Instancia '.$channel, 'slug' => 'instance_'.$channel, 'folder_name' => 'instance_'.$channel, 'db_name' => 'db_'.$channel, 'current_version' => '1.0.0', 'update_channel' => $channel, 'installation_status' => InstallationStatus::Ready, 'active' => true]);
    }

    private function release(string $version, string $channel, array $from): IkontrolRelease
    {
        return IkontrolRelease::create(['version' => $version, 'channel' => $channel, 'git_tag' => 'v'.$version, 'commit_sha' => str_repeat('c', 40), 'source_repository' => 'triple-w/ikontrol-platform', 'manifest_hash' => str_repeat('d', 64), 'manifest_json' => ['from_versions' => $from], 'published_at' => now(), 'discovered_at' => now(), 'status' => 'validated']);
    }

    private function compatibility(): ReleaseCompatibilityService
    {
        return app(ReleaseCompatibilityService::class);
    }
}
