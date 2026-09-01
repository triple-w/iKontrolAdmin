<?php

namespace Tests\Feature;

use App\Http\Requests\InstanceRequest;
use App\Models\AdminUser;
use App\Models\Client;
use App\Models\IkontrolInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExistingInstanceRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public static function folderNames(): array
    {
        return [
            'navika domain' => ['navika.ikontrol.solutions', true],
            'customer domain' => ['cliente.ikontrol.solutions', true],
            'parent traversal' => ['../navika', false],
            'forward slash' => ['navika/otro', false],
            'backslash' => ['navika\\otro', false],
        ];
    }

    #[DataProvider('folderNames')]
    public function test_folder_name_validation(string $folderName, bool $expectedValid): void
    {
        $client = Client::create(['name' => 'Cliente de prueba', 'active' => true]);
        $request = new InstanceRequest();
        $validator = Validator::make([
            'client_id' => $client->id,
            'name' => 'DOLD',
            'slug' => 'dold',
            'folder_name' => $folderName,
            'db_name' => 'tws001_ikontrol_dold',
        ], $request->rules());

        $this->assertSame($expectedValid, $validator->passes());
    }

    public function test_authenticated_admin_can_register_an_existing_instance(): void
    {
        $admin = AdminUser::create([
            'name' => 'Administrador de prueba',
            'email' => 'instance-admin@example.test',
            'password' => 'test-password-only',
            'active' => true,
        ]);
        $client = Client::create(['name' => 'DOLD / NAVIKA', 'active' => true]);

        $response = $this->actingAs($admin)->post(route('instances.store'), [
            'client_id' => $client->id,
            'name' => 'DOLD',
            'slug' => 'dold',
            'folder_name' => 'navika.ikontrol.solutions',
            'domain' => 'navika.ikontrol.solutions',
            'url' => 'https://navika.ikontrol.solutions',
            'db_name' => 'tws001_ikontrol_dold',
        ]);

        $instance = IkontrolInstance::firstOrFail();
        $response->assertRedirect(route('instances.show', $instance));
        $this->assertDatabaseHas('ikontrol_instances', [
            'client_id' => $client->id,
            'folder_name' => 'navika.ikontrol.solutions',
            'db_name' => 'tws001_ikontrol_dold',
        ]);
        $this->assertDatabaseHas('instance_installation_logs', [
            'instance_id' => $instance->id,
            'step' => 'REGISTERING',
            'status' => 'SUCCESS',
        ]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'register_instance',
            'entity_id' => $instance->id,
        ]);
    }
}
