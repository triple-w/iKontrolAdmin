<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class IkontrolUpgradeAuditItem extends Model { protected $fillable=['upgrade_audit_id','category','object_type','object_name','status','severity','current_value','expected_value','details_json']; protected function casts():array{return['details_json'=>'array'];} public function audit():BelongsTo{return $this->belongsTo(IkontrolUpgradeAudit::class,'upgrade_audit_id');} }
