<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\HasMany;
class Client extends Model { protected $fillable=['name','legal_name','rfc','email','phone','notes','active']; protected function casts(): array { return ['active'=>'boolean']; } public function instances(): HasMany { return $this->hasMany(IkontrolInstance::class); } }
