<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstanceUpdateRun extends Model
{
    public const STATUSES = ['pending', 'preflight', 'DEPLOYMENT_READY', 'DEPLOYMENT_CONFLICT', 'DEPLOYMENT_BLOCKED', 'backup', 'deploying', 'migrating', 'commands', 'health_check', 'completed', 'failed', 'rolled_back', 'MANUAL_REVIEW_REQUIRED'];

    protected $fillable = [
        'instance_id', 'from_version', 'to_version', 'release_id', 'status',
        'started_at', 'finished_at', 'backup_reference', 'deployment_plan', 'backup_manifest',
        'staging_reference', 'rollback_status', 'database_review_required', 'log', 'error_message',
    ];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'deployment_plan' => 'array', 'backup_manifest' => 'array', 'database_review_required' => 'boolean'];
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
