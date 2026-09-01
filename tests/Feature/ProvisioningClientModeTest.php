<?php

namespace Tests\Feature;

use App\Http\Requests\ProvisionInstanceRequest;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ProvisioningClientModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_client_mode_accepts_only_client_id(): void
    {
        $client = Client::create(['name' => 'DOLD / NAVIKA', 'active' => true]);

        $valid = $this->validator(['client_mode' => 'existing', 'client_id' => $client->id]);
        $invalid = $this->validator(['client_mode' => 'existing', 'client_id' => $client->id, 'new_client_name' => 'Otro']);

        $this->assertFalse($valid->fails());
        $this->assertTrue($invalid->fails());
        $this->assertArrayHasKey('new_client_name', $invalid->errors()->toArray());
    }

    public function test_new_client_mode_accepts_only_new_client_name(): void
    {
        $client = Client::create(['name' => 'Existente', 'active' => true]);

        $valid = $this->validator(['client_mode' => 'new', 'new_client_name' => 'DOLD / NAVIKA']);
        $invalid = $this->validator(['client_mode' => 'new', 'new_client_name' => 'DOLD / NAVIKA', 'client_id' => $client->id]);

        $this->assertFalse($valid->fails());
        $this->assertTrue($invalid->fails());
        $this->assertArrayHasKey('client_id', $invalid->errors()->toArray());
    }

    private function validator(array $clientData)
    {
        $request = new ProvisionInstanceRequest();

        return Validator::make($clientData + ['name' => 'DOLD', 'slug' => 'dold'], $request->rules(), $request->messages());
    }
}
