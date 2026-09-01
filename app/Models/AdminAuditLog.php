<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class AdminAuditLog extends Model { public $timestamps=false; protected $fillable=['admin_user_id','action','entity_type','entity_id','description','ip_address','metadata_json','created_at']; protected function casts(): array { return ['metadata_json'=>'array','created_at'=>'datetime']; } public function adminUser():BelongsTo{return $this->belongsTo(AdminUser::class);} }
