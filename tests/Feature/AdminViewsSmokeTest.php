<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminViewsSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_admin_can_open_primary_screens(): void
    {
        $this->withoutVite();

        $admin = AdminUser::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'a-secure-test-password',
            'active' => true,
        ]);

        foreach (['/', '/clients', '/clients/create', '/instances', '/instances/register', '/provisioning/new', '/legacy/factucare', '/audit', '/configuration'] as $uri) {
            $this->actingAs($admin)->get($uri)->assertOk();
        }
    }
}
