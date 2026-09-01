<?php

namespace Tests\Unit;

use App\Exceptions\CpanelException;
use App\Models\IkontrolInstance;
use App\Services\CpanelService;
use App\Services\IkontrolInstanceConnectionService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class InfrastructureConnectionTest extends TestCase
{
    public function test_global_mysql_connection_does_not_require_an_instance(): void
    {
        config(['ikontrol.db' => ['host' => '127.0.0.1', 'port' => 3306, 'username' => 'global-user', 'password' => 'secret', 'prefix' => 'test_']]);
        $connection = Mockery::mock();
        $connection->shouldReceive('select')->once()->with('SELECT 1')->andReturn([(object) ['connection_test' => 1]]);
        DB::shouldReceive('purge')->once()->with('ikontrol_global_connection');
        DB::shouldReceive('connection')->once()->with('ikontrol_global_connection')->andReturn($connection);
        DB::shouldReceive('disconnect')->once()->with('ikontrol_global_connection');

        $result = app(IkontrolInstanceConnectionService::class)->testGlobalConnection();

        $this->assertTrue($result['success']);
        $this->assertSame('CONNECTED', $result['status']);
    }

    public function test_specific_instance_connection_uses_its_database(): void
    {
        config(['ikontrol.db' => ['host' => '127.0.0.1', 'port' => 3306, 'username' => 'global-user', 'password' => 'secret', 'prefix' => 'test_']]);
        $instance = Mockery::mock(IkontrolInstance::class)->makePartial();
        $instance->db_name = 'customer_database';
        $instance->exists = true;
        $instance->shouldReceive('save')->once()->andReturnTrue();
        $connection = Mockery::mock();
        $connection->shouldReceive('select')->once()->andReturn([(object) ['connection_test' => 1]]);
        DB::shouldReceive('purge')->once();
        DB::shouldReceive('connection')->once()->withArgs(function ($name) {
            $this->assertSame('customer_database', config("database.connections.$name.database"));

            return true;
        })->andReturn($connection);
        DB::shouldReceive('disconnect')->once();

        $result = app(IkontrolInstanceConnectionService::class)->test($instance);

        $this->assertTrue($result['success']);
    }

    public function test_cpanel_error_is_sanitized_and_logged_without_credentials(): void
    {
        config(['ikontrol.cpanel' => ['host' => 'cpanel.example.test', 'port' => 2083, 'username' => 'panel-user', 'token' => 'top-secret-token']]);
        Http::fake(['*' => Http::response(['status' => 0, 'errors' => ['Token top-secret-token was rejected']], 403)]);
        Log::spy();

        try {
            app(CpanelService::class)->testConnection();
            $this->fail('A cPanel exception was expected.');
        } catch (CpanelException $exception) {
            $this->assertStringNotContainsString('top-secret-token', $exception->safeMessage());
            $this->assertSame(403, $exception->diagnostic()['http_status']);
        }

        Log::shouldHaveReceived('warning')->once()->withArgs(function ($message, $context) {
            return $context['operation'] === 'Mysql/list_databases'
                && ! str_contains(json_encode($context), 'top-secret-token')
                && ! array_key_exists('authorization', $context);
        });
    }

    public function test_database_assignment_uses_mysql_user_not_cpanel_user(): void
    {
        config([
            'ikontrol.cpanel' => ['host' => 'cpanel.example.test', 'port' => 2083, 'username' => 'panel-user', 'token' => 'token'],
            'ikontrol.db.username' => 'mysql-user',
        ]);
        Http::fake(['*' => Http::response(['status' => 1, 'data' => []])]);

        app(CpanelService::class)->assignUserToDatabase('account_database');

        Http::assertSent(fn (Request $request) => $request['user'] === 'mysql-user' && $request['database'] === 'account_database');
    }
}
