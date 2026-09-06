<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class FactucareConversionPlanItem extends Model { protected $fillable=['plan_id','entity_type','source_table','source_id','proposed_action','validation_status','source_hash','warnings_json','preview_json']; protected function casts():array{return ['warnings_json'=>'array','preview_json'=>'array'];} public function plan():BelongsTo{return $this->belongsTo(FactucareConversionPlan::class,'plan_id');} }