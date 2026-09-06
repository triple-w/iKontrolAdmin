<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo,HasMany};
class FactucareConversionPlan extends Model { protected $fillable=['legacy_user_id','legacy_rfc','destination_instance_id','status','options_json','summary_json','source_fingerprint','created_by']; protected function casts():array{return ['options_json'=>'array','summary_json'=>'array'];} public function items():HasMany{return $this->hasMany(FactucareConversionPlanItem::class,'plan_id');} public function destination():BelongsTo{return $this->belongsTo(IkontrolInstance::class,'destination_instance_id');} }