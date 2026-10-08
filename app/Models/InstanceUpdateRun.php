<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstanceUpdateRun extends Model
{
    public const STATUSES = ['pending', 'preflight', 'backup', 'deploying', 'migrating', 'commands', 'health_check', 'completed', 'failed', 'rolled_back'];

    protected $fillable = [
        'instance_id', 'from_version', 'to_version', 'release_id', 'status',
        'started_at', 'finished_at', 'backup_reference', 'log', 'error_message',
    ];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(IkontrolInstance::class, 'instance_id');
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(IkontrolRelease::class, 'release_id');
    }
}
