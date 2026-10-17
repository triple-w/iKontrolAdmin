<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class ReleasePromotion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'ikontrol_release_id', 'from_status', 'to_status', 'actor_user_id',
        'instance_update_run_id', 'commit_sha', 'artifact_sha256', 'evidence',
        'notes', 'created_at',
    ];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El historial de promoción es append-only.'));
        static::deleting(fn () => throw new LogicException('El historial de promoción es append-only.'));
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(IkontrolRelease::class, 'ikontrol_release_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'actor_user_id');
    }

    public function updateRun(): BelongsTo
    {
        return $this->belongsTo(InstanceUpdateRun::class, 'instance_update_run_id');
    }
}
