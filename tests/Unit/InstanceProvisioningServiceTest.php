<?php

namespace Tests\Unit;

use App\Services\AuditService;
use App\Services\CpanelService;
use App\Services\IkontrolInstanceConnectionService;
use App\Services\InstanceFilesystemService;
use App\Services\InstanceProvisioningService;
use Mockery;
use Tests\TestCase;

class InstanceProvisioningServiceTest extends TestCase
{
    public function test_database_name_uses_central_prefix(): void
    {
        config(['ikontrol.db.prefix' => 'tws001_ik_']);
        $service = new InstanceProvisioningService(
            $this->mock(CpanelService::class),
            app(InstanceFilesystemService::class),
            $this->mock(IkontrolInstanceConnectionService::class),
            $this->mock(AuditService::class),
        );

        $this->assertSame('tws001_ik_dmarco', $service->databaseName('dmarco'));
    }

    public function test_preflight_is_read_only_and_reports_each_check(): void
    {
        config(['ikontrol.db.prefix' => 'tws001_ik_', 'ikontrol.folder_suffix' => '.ikontrol.solutions']);
        $cpanel = Mockery::mock(CpanelService::class);
        $cpanel->shouldReceive('listDatabases')->once()->andReturn(['another_database']);
        $cpanel->shouldNotReceive('createDatabase');
        $cpanel->shouldNotReceive('assignUserToDatabase');
        $filesystem = Mockery::mock(InstanceFilesystemService::class);
        $filesystem->shouldReceive('folderName')->once()->with('dmarco')->andReturn('dmarco.ikontrol.solutions');
        $filesystem->shouldReceive('path')->once()->with('dmarco')->andReturn('/safe/dmarco.ikontrol.solutions');
        $filesystem->shouldReceive('validateSlug')->twice()->with('dmarco')->andReturnTrue();
        $filesystem->shouldReceive('folderExists')->once()->with('dmarco')->andReturnFalse();
        $filesystem->shouldReceive('rootWritable')->once()->andReturnTrue();
        $filesystem->shouldNotReceive('createFolder');
        $connection = Mockery::mock(IkontrolInstanceConnectionService::class);
        $connection->shouldReceive('testGlobalConnection')->once()->andReturn(['success' => true, 'status' => 'CONNECTED']);
        $service = new InstanceProvisioningService($cpanel, $filesystem, $connection, $this->mock(AuditService::class));

        $result = $service->preflight('dmarco');

        $this->assertTrue($result['success']);
        $this->assertSame([
            'slug_valid', 'folder_available', 'filesystem_writable', 'cpanel_connected',
            'database_not_existing', 'mysql_user_available',
        ], array_keys($result['checks']));
    }
}
