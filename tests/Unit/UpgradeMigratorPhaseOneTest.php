<?php

namespace Tests\Unit;

use App\Models\IkontrolInstance;
use App\Services\Upgrade\{IkontrolVersionDefinition,InstanceDatabaseUpgradeAuditor,InstanceFilesUpgradeAuditor,InstanceSettingsUpgradeAuditor,InstanceUpgradeCompatibilityService,InstanceUpgradePlanService,InstanceVersionDetectorService};
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class UpgradeMigratorPhaseOneTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/upgrade-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->root.'/app/Config');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        Mockery::close();
        parent::tearDown();
    }

    public function test_current_ikontrol_version_is_detected_from_multiple_signals(): void
    {
        File::put($this->root.'/composer.json', json_encode(['require'=>['codeigniter4/framework'=>'4.6.1']]));
        File::put($this->root.'/version.json', json_encode(['ikontrol_version'=>'2.0.0']));
        File::put($this->root.'/spark', 'read only marker');
        $result=(new InstanceVersionDetectorService)->detect($this->instanceRecord());
        $this->assertSame('iKontrol 2.0.0',$result['detected_version']);
        $this->assertSame('CodeIgniter',$result['framework']);
        $this->assertNotEmpty($result['signals']);
    }

    public function test_unknown_version_requires_manual_review(): void
    {
        $detection=(new InstanceVersionDetectorService)->detect($this->instanceRecord());
        $decision=(new InstanceUpgradeCompatibilityService)->evaluate($detection,[],IkontrolVersionDefinition::load('2.0.0'));
        $this->assertSame('LEGACY_UNKNOWN',$detection['detected_version']);
        $this->assertSame('MANUAL_REVIEW',$decision['compatibility']);
    }

    public function test_missing_and_customized_files_are_classified_without_writes(): void
    {
        File::put($this->root.'/composer.json','custom composer');
        $before=hash_file('sha256',$this->root.'/composer.json');
        $base=IkontrolVersionDefinition::load('2.0.0');
        $definition=new IkontrolVersionDefinition('2.0.0',$base->manifest,$base->database,$base->settings,['files'=>[['path'=>'spark','type'=>'REQUIRED'],['path'=>'composer.json','type'=>'CUSTOMIZABLE','sha256'=>hash('sha256','baseline')]]]);
        $items=(new InstanceFilesUpgradeAuditor)->audit($this->instanceRecord(),$definition);
        $this->assertTrue(collect($items)->contains(fn($i)=>$i['object_name']==='spark'&&$i['status']==='MISSING'));
        $this->assertTrue(collect($items)->contains(fn($i)=>$i['object_name']==='composer.json'&&$i['status']==='CUSTOMIZED'));
        $this->assertSame($before,hash_file('sha256',$this->root.'/composer.json'));
    }

    public function test_setting_missing_and_valid_custom_value_are_not_invalid(): void
    {
        $db=Mockery::mock(ConnectionInterface::class);
        $db->shouldReceive('select')->once()->with('SELECT setting_name, setting_value FROM settings')->andReturn([(object)['setting_name'=>'default_currency','setting_value'=>'USD'],(object)['setting_name'=>'no_of_decimals','setting_value'=>'2']]);
        $items=(new InstanceSettingsUpgradeAuditor)->audit(IkontrolVersionDefinition::load('2.0.0'),$db);
        $currency=collect($items)->firstWhere('object_name','default_currency');
        $this->assertSame('CUSTOMIZED',$currency['status']);
        $this->assertSame('INFO',$currency['severity']);
        $this->assertTrue(collect($items)->contains(fn($i)=>$i['object_name']==='timezone'&&$i['status']==='MISSING'));
        $this->assertTrue(collect($items)->contains(fn($i)=>data_get($i,'details_json.difference')==='SECRET_NOT_CHECKED'));
    }

    public function test_database_reports_missing_table_column_and_index_and_incompatible_type(): void
    {
        $db=Mockery::mock(ConnectionInterface::class);
        $db->shouldReceive('select')->andReturnUsing(function($sql){
            if(str_contains($sql,'INFORMATION_SCHEMA.TABLES'))return[(object)['TABLE_NAME'=>'settings'],(object)['TABLE_NAME'=>'users']];
            if(str_contains($sql,'INFORMATION_SCHEMA.COLUMNS')&&str_contains($sql,'TABLE_NAME = ?'))return[(object)['COLUMN_NAME'=>'id','COLUMN_TYPE'=>'varchar(20)','IS_NULLABLE'=>'NO','COLUMN_DEFAULT'=>null]];
            if(str_contains($sql,'INFORMATION_SCHEMA.STATISTICS'))return[];
            return[];
        });
        $items=(new InstanceDatabaseUpgradeAuditor)->audit($this->instanceRecord(),IkontrolVersionDefinition::load('2.0.0'),$db);
        $this->assertTrue(collect($items)->contains(fn($i)=>data_get($i,'details_json.difference')==='TABLE_MISSING'));
        $this->assertTrue(collect($items)->contains(fn($i)=>data_get($i,'details_json.difference')==='COLUMN_MISSING'));
        $this->assertTrue(collect($items)->contains(fn($i)=>data_get($i,'details_json.difference')==='COLUMN_TYPE_DIFFERENT'));
        $this->assertTrue(collect($items)->contains(fn($i)=>data_get($i,'details_json.difference')==='INDEX_MISSING'));
    }

    public function test_legacy_without_fiscal_is_not_automatically_incompatible_and_plan_installs_it(): void
    {
        $definition=IkontrolVersionDefinition::load('2.0.0');
        $detection=['detected_version'=>'Rise 3.9.4','rise_version'=>'3.9.4','ikontrol_version'=>null];
        $items=[['category'=>'FISCAL','status'=>'NOT_INSTALLED','severity'=>'INFO','details_json'=>[]]];
        $decision=(new InstanceUpgradeCompatibilityService)->evaluate($detection,$items,$definition);
        $plan=(new InstanceUpgradePlanService)->generate($detection,$definition,$items,$decision);
        $this->assertSame('COMPATIBLE',$decision['compatibility']);
        $this->assertContains('INSTALL_FISCAL_STRUCTURE',$plan['fiscal_actions']);
        $this->assertTrue($plan['read_only']);
        $this->assertContains('.env',$plan['preserve']);
    }

    public function test_critical_column_conflict_forces_manual_review(): void
    {
        $items=[['category'=>'DATABASE','status'=>'INCOMPATIBLE','severity'=>'CRITICAL','details_json'=>['difference'=>'COLUMN_TYPE_DIFFERENT']]];
        $decision=(new InstanceUpgradeCompatibilityService)->evaluate(['detected_version'=>'Rise 3.9.4','rise_version'=>'3.9.4'],$items,IkontrolVersionDefinition::load('2.0.0'));
        $this->assertSame('MANUAL_REVIEW',$decision['compatibility']);
        $this->assertContains('COLUMN_TYPE_DIFFERENT',$decision['reasons']);
    }

    private function instanceRecord(): IkontrolInstance
    {
        $instance=new IkontrolInstance(['absolute_path'=>$this->root,'db_name'=>'legacy']);
        $instance->id=99;
        return $instance;
    }
}
