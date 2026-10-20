<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use LogicException;

class InstanceUpdateRun extends Model
{
    public const STATUSES = ['pending', 'preflight', 'DEPLOYMENT_READY', 'DEPLOYMENT_CONFLICT', 'DEPLOYMENT_BLOCKED', 'backup', 'deploying', 'migrating', 'commands', 'health_check', 'completed', 'failed', 'rolled_back', 'CODE_ROLLED_BACK_DB_ADVANCED', 'PARTIAL_CODE_DEPLOYMENT', 'MANUAL_REVIEW_REQUIRED', 'RECOVERY_VERIFIED_SOURCE', 'RECOVERY_VERIFIED_TARGET'];

    protected $fillable = [
        'instance_id', 'from_version', 'to_version', 'release_id', 'release_commit_sha', 'release_artifact_sha256', 'status',
        'started_at', 'finished_at', 'backup_reference', 'deployment_plan', 'backup_manifest',
        'staging_reference', 'rollback_status', 'database_review_required', 'log', 'error_message',
    ];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'deployment_plan' => 'array', 'backup_manifest' => 'array', 'database_review_required' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(function (InstanceUpdateRun $run) {
            if ($run->isDirty(['instance_id', 'release_id', 'release_commit_sha', 'release_artifact_sha256', 'to_version'])) {
                throw new LogicException('La identidad y los snapshots de un update run son inmutables.');
            }
        });
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(IkontrolInstance::class, 'instance_id');
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(IkontrolRelease::class, 'release_id');
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(ReleasePromotion::class, 'instance_update_run_id');
    }
}
