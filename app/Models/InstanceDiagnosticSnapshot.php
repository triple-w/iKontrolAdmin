<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstanceDiagnosticSnapshot extends Model
{
    protected $fillable = ['instance_id', 'status', 'checks', 'recommendations', 'duration_ms', 'checked_at'];

    protected function casts(): array
    {
        return ['checks' => 'array', 'recommendations' => 'array', 'checked_at' => 'datetime'];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(IkontrolInstance::class, 'instance_id');
    }
}
