<?php
namespace Tests\Feature;
use App\Models\AdminUser; use Illuminate\Foundation\Testing\RefreshDatabase; use Illuminate\Support\Facades\Hash; use Tests\TestCase;
class CreateAdminCommandTest extends TestCase {
 use RefreshDatabase;
 public function test_command_creates_active_admin_with_hashed_password():void{$this->artisan('ikontroladmin:create-admin',['--name'=>'Carlos Alberto Camarena','--email'=>'CALCAMAREN@gmail.com'])->expectsQuestion('Contraseña (mínimo 12 caracteres)','local-test-password')->expectsQuestion('Confirmar contraseña','local-test-password')->assertSuccessful();$admin=AdminUser::sole();$this->assertTrue($admin->active);$this->assertSame('calcamaren@gmail.com',$admin->email);$this->assertTrue(Hash::check('local-test-password',$admin->password));$this->assertNotSame('local-test-password',$admin->password);}
 public function test_command_rejects_duplicate_email():void{AdminUser::create(['name'=>'Existente','email'=>'calcamaren@gmail.com','password'=>'local-test-password','active'=>true]);$this->artisan('ikontroladmin:create-admin',['--name'=>'Duplicado','--email'=>'calcamaren@gmail.com'])->expectsOutput('Ya existe un administrador con ese email.')->assertFailed();$this->assertSame(1,AdminUser::count());}
}
