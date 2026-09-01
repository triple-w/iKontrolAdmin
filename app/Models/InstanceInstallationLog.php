<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class InstanceInstallationLog extends Model { public $timestamps=false; protected $fillable=['instance_id','step','status','message','context_json','created_at']; protected function casts(): array { return ['context_json'=>'array','created_at'=>'datetime']; } public function instance(): BelongsTo { return $this->belongsTo(IkontrolInstance::class,'instance_id'); } }
