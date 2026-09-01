<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstanceInspectionSnapshot extends Model
{
    public $timestamps = false;
    protected $fillable = ['instance_id', 'schema_status', 'app_version', 'schema_version', 'company_name', 'legal_name', 'rfc', 'url', 'database_size', 'last_activity_at', 'counts', 'technical_metadata', 'schema_error', 'inspected_at'];

    protected function casts(): array
    {
        return ['counts' => 'array', 'technical_metadata' => 'array', 'database_size' => 'integer', 'last_activity_at' => 'datetime', 'inspected_at' => 'datetime'];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(IkontrolInstance::class);
    }
}
