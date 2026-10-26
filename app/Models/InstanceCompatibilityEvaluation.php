<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class InstanceCompatibilityEvaluation extends Model
{
    public const STATUSES = ['COMPATIBLE', 'REVIEW_REQUIRED', 'BLOCKED', 'NOT_VERIFIED'];

    protected $fillable = [
        'evidence_id', 'instance_id', 'release_id', 'evaluated_by', 'source_version', 'target_version',
        'source_commit_sha', 'source_tree_sha256', 'status', 'bundle_trust', 'authorizes_deployment',
        'evaluated_release_commit_sha', 'evaluated_release_artifact_sha256',
        'contract_sha256', 'bundle_sha256', 'files_fingerprint_sha256', 'schema_fingerprint_sha256',
        'summary', 'error_evidence', 'evaluated_at',
    ];

    protected function casts(): array
    {
        return ['summary' => 'array', 'error_evidence' => 'array', 'authorizes_deployment' => 'boolean', 'evaluated_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $evaluation) => $evaluation->evidence_id ??= (string) Str::uuid());
        static::updating(function (self $evaluation) {
            if ($evaluation->isDirty()) throw new \LogicException('La evidencia de compatibilidad es inmutable.');
        });
    }

    public function instance(): BelongsTo { return $this->belongsTo(IkontrolInstance::class, 'instance_id'); }
    public function release(): BelongsTo { return $this->belongsTo(IkontrolRelease::class, 'release_id'); }
    public function user(): BelongsTo { return $this->belongsTo(AdminUser::class, 'evaluated_by'); }
}
