<?php

namespace Tests\Feature;

use App\Models\{AdminUser, IkontrolTemplate, IkontrolVersion};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class IkontrolVersionCatalogTest extends TestCase
{
    use RefreshDatabase;
    private string $root;

    protected function setUp(): void
    {
        parent::setUp(); $this->root = storage_path('framework/testing/version-catalog-'.bin2hex(random_bytes(3)));
        File::ensureDirectoryExists($this->root.'/2.0.0'); config(['ikontrol.templates.root'=>$this->root]); $this->artifact();
    }

    protected function tearDown(): void { File::deleteDirectory($this->root); parent::tearDown(); }

    public function test_admin_registers_a_complete_valid_default_version(): void
    {
        $this->actingAs($this->admin())->post(route('versions.store'), $this->payload())->assertRedirect(route('versions.index'));
        $template = IkontrolTemplate::sole(); $this->assertTrue($template->active); $this->assertTrue($template->is_default);
        $this->assertDatabaseHas('admin_audit_logs',['action'=>'create_ikontrol_version']);
    }

    public function test_inactive_version_is_marked_inactive(): void
    {
        $payload = $this->payload(); unset($payload['active'], $payload['is_default']);
        $this->actingAs($this->admin())->post(route('versions.store'), $payload)->assertRedirect(route('versions.index'));
        $this->assertFalse(IkontrolTemplate::sole()->active);
    }

    public function test_legacy_2_0_0_can_be_completed_without_visible_duplicate(): void
    {
        $legacy = IkontrolVersion::create(['version'=>'2.0.0','name'=>'iKontrol 2.0.0 Base','source_type'=>'archive','source_reference'=>'ikontrol-2.0.0.zip','checksum'=>hash_file('sha256',$this->root.'/2.0.0/ikontrol-2.0.0.zip'),'active'=>true]);
        $this->actingAs($this->admin())->put(route('versions.update',$legacy), $this->payload())->assertRedirect(route('versions.index'));
        $this->assertDatabaseCount('ikontrol_templates',1); $this->assertDatabaseCount('ikontrol_versions',1);
        $this->actingAs($this->admin())->get(route('versions.index'))->assertOk()->assertSee('iKontrol 2.0.0 Base')->assertSee('VALID')->assertDontSee('SQL pendiente');
    }

    public function test_unsafe_relative_path_is_rejected(): void
    {
        $this->actingAs($this->admin())->post(route('versions.store'), $this->payload(['archive_path'=>'../archivo.zip']))->assertSessionHasErrors('archive_path');
    }

    public function test_absolute_and_remote_paths_are_rejected(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('versions.store'), $this->payload(['archive_path'=>'C:\\algo\\archivo.zip']))->assertSessionHasErrors('archive_path');
        $this->actingAs($admin)->post(route('versions.store'), $this->payload(['archive_path'=>'https://example.test/archivo.zip']))->assertSessionHasErrors('archive_path');
        $this->actingAs($admin)->post(route('versions.store'), $this->payload(['archive_path'=>'/absolute/archivo.zip']))->assertSessionHasErrors('archive_path');
    }

    public function test_invalid_sql_checksum_is_rejected(): void
    {
        $this->actingAs($this->admin())->post(route('versions.store'), $this->payload(['database_sha256'=>str_repeat('0',64)]))->assertSessionHasErrors('database_sha256');
        $this->assertDatabaseCount('ikontrol_templates',0);
    }

    public function test_only_active_valid_versions_appear_in_provisioning(): void
    {
        IkontrolTemplate::create($this->payload());
        File::ensureDirectoryExists($this->root.'/2.0.1'); File::copy($this->root.'/2.0.0/ikontrol-2.0.0.zip',$this->root.'/2.0.1/ikontrol-2.0.1.zip'); File::copy($this->root.'/2.0.0/ikontrol-2.0.0.sql',$this->root.'/2.0.1/ikontrol-2.0.1.sql');
        IkontrolTemplate::create(array_replace($this->payload(),['version'=>'2.0.1','name'=>'Versión inactiva','app_version'=>'2.0.1','archive_path'=>'2.0.1/ikontrol-2.0.1.zip','database_dump_path'=>'2.0.1/ikontrol-2.0.1.sql','archive_sha256'=>hash_file('sha256',$this->root.'/2.0.1/ikontrol-2.0.1.zip'),'database_sha256'=>hash_file('sha256',$this->root.'/2.0.1/ikontrol-2.0.1.sql'),'active'=>false,'is_default'=>false]));
        $this->withoutVite(); $this->actingAs($this->admin())->get(route('provisioning.create'))->assertOk()->assertSee('iKontrol 2.0.0 Base')->assertSee('App 2.0.0')->assertSee('Schema rise-administrative-baseline-1')->assertDontSee('Versión inactiva');
    }

    public function test_menu_exposes_only_versions_label(): void
    {
        $menu = File::get(resource_path('menu/verticalMenu.json'));
        $this->assertSame(1, substr_count($menu, 'Versiones iKontrol')); $this->assertStringNotContainsString('Plantillas iKontrol',$menu);
    }

    private function admin(): AdminUser { return AdminUser::firstOrCreate(['email'=>'versions@example.test'],['name'=>'Admin','password'=>'password','active'=>true]); }
    private function payload(array $replace=[]): array { return array_replace(['version'=>'2.0.0','name'=>'iKontrol 2.0.0 Base','app_version'=>'2.0.0','schema_version'=>'rise-administrative-baseline-1','archive_path'=>'2.0.0/ikontrol-2.0.0.zip','database_dump_path'=>'2.0.0/ikontrol-2.0.0.sql','archive_sha256'=>hash_file('sha256',$this->root.'/2.0.0/ikontrol-2.0.0.zip'),'database_sha256'=>hash_file('sha256',$this->root.'/2.0.0/ikontrol-2.0.0.sql'),'active'=>1,'is_default'=>1],$replace); }
    private function artifact(): void { $zip=new ZipArchive();$zip->open($this->root.'/2.0.0/ikontrol-2.0.0.zip',ZipArchive::CREATE|ZipArchive::OVERWRITE);foreach(['index.php'=>'<?php','spark'=>'cli','.env.example'=>'CI_ENVIRONMENT = production','app/Config/App.php'=>'<?php','system/CodeIgniter.php'=>'<?php'] as $name=>$body)$zip->addFromString($name,$body);$zip->close();File::put($this->root.'/2.0.0/ikontrol-2.0.0.sql','CREATE TABLE settings (id INT);'); }
}
