<?php

namespace Tests\Feature;

use App\Models\{AdminUser, IkontrolVersion};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IkontrolVersionCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_register_archive_and_make_it_the_only_default(): void
    {
        $admin = AdminUser::create(['name'=>'Admin','email'=>'versions@example.test','password'=>'test-password','active'=>true]);
        IkontrolVersion::create(['version'=>'0.9.0','name'=>'Anterior','source_type'=>'archive','source_reference'=>'old.zip','active'=>true,'is_default'=>true]);

        $this->actingAs($admin)->post(route('versions.store'), ['version'=>'1.0.0','name'=>'iKontrol Base 1.0.0','source_type'=>'archive','source_reference'=>'ikontrol-1.0.0.zip','checksum'=>str_repeat('a',64),'active'=>1,'is_default'=>1])->assertRedirect(route('versions.index'));

        $this->assertSame('1.0.0', IkontrolVersion::default()->sole()->version);
        $this->assertDatabaseHas('admin_audit_logs', ['action'=>'create_ikontrol_version']);
    }

    public function test_git_reference_is_reserved_and_cannot_be_active(): void
    {
        $admin = AdminUser::create(['name'=>'Admin','email'=>'git-version@example.test','password'=>'test-password','active'=>true]);
        $this->actingAs($admin)->post(route('versions.store'), ['version'=>'2.0.0','name'=>'Git futura','source_type'=>'git','source_reference'=>'v2.0.0','active'=>1,'is_default'=>1])->assertRedirect(route('versions.index'));

        $version = IkontrolVersion::where('version','2.0.0')->sole();
        $this->assertFalse($version->active);
        $this->assertFalse($version->is_default);
    }

    public function test_archive_reference_rejects_traversal(): void
    {
        $admin = AdminUser::create(['name'=>'Admin','email'=>'unsafe-version@example.test','password'=>'test-password','active'=>true]);
        $this->actingAs($admin)->post(route('versions.store'), ['version'=>'1.0.0','name'=>'Unsafe','source_type'=>'archive','source_reference'=>'../dold.zip','active'=>1])->assertSessionHasErrors('source_reference');
        $this->assertDatabaseCount('ikontrol_versions', 0);
    }
}
