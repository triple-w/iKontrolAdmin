<?php
namespace Tests\Feature;
use App\Models\AdminUser; use Illuminate\Foundation\Testing\RefreshDatabase; use Illuminate\Support\Facades\Auth; use Tests\TestCase;
class AdminFoundationTest extends TestCase {
 use RefreshDatabase; private const PASSWORD='a-secure-test-password';
 private function admin(array $attributes=[]):AdminUser{return AdminUser::create(array_merge(['name'=>'Admin de prueba','email'=>'admin@example.test','password'=>self::PASSWORD,'active'=>true],$attributes));}
 public function test_guest_is_redirected_to_login():void{$this->get('/')->assertRedirect(route('login'));}
 public function test_valid_login_redirects_to_dashboard_and_updates_access_data():void{$admin=$this->admin();$this->post('/login',['email'=>$admin->email,'password'=>self::PASSWORD])->assertRedirect(route('dashboard'));$this->assertAuthenticatedAs($admin);$this->assertNotNull($admin->fresh()->last_login_at);$this->assertDatabaseHas('admin_audit_logs',['action'=>'login','admin_user_id'=>$admin->id]);}
 public function test_invalid_login_returns_clear_error_without_authenticating():void{$admin=$this->admin();$this->from('/login')->post('/login',['email'=>$admin->email,'password'=>'incorrect-password'])->assertRedirect('/login')->assertSessionHasErrors('email');$this->assertGuest();}
 public function test_inactive_admin_cannot_login():void{$admin=$this->admin(['active'=>false]);$this->post('/login',['email'=>$admin->email,'password'=>self::PASSWORD])->assertSessionHasErrors('email');$this->assertGuest();}
 public function test_remember_me_issues_recaller_cookie():void{$admin=$this->admin();$this->post('/login',['email'=>$admin->email,'password'=>self::PASSWORD,'remember'=>'1'])->assertRedirect(route('dashboard'))->assertCookie(Auth::guard()->getRecallerName());}
 public function test_logout_ends_session_and_redirects_to_login():void{$admin=$this->admin();$this->actingAs($admin)->post('/logout')->assertRedirect(route('login'));$this->assertGuest();$this->assertDatabaseHas('admin_audit_logs',['action'=>'logout','admin_user_id'=>$admin->id]);}
 public function test_authenticated_admin_is_redirected_away_from_login():void{$this->actingAs($this->admin())->get('/login')->assertRedirect(route('dashboard'));}
}
