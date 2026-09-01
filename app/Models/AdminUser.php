<?php
namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable; use Illuminate\Notifications\Notifiable;
class AdminUser extends Authenticatable { use Notifiable; protected $fillable=['name','email','password','active','last_login_at','last_login_ip']; protected $hidden=['password','remember_token']; protected function casts(): array { return ['password'=>'hashed','active'=>'boolean','last_login_at'=>'datetime']; } }
