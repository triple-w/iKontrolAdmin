<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{HasMany, HasOne};

class IkontrolRelease extends Model
{
    public const CHANNELS = ['canary', 'stable'];
    public const STATUSES = ['discovered', 'validated', 'invalid', 'deprecated'];
    public const PROMOTION_BUILT = 'BUILT';
    public const PROMOTION_STAGING = 'STAGING';
    public const PROMOTION_APPROVED = 'APPROVED';
    public const PROMOTION_DEPRECATED = 'DEPRECATED';
    public const PROMOTION_STATUSES = [self::PROMOTION_BUILT, self::PROMOTION_STAGING, self::PROMOTION_APPROVED, self::PROMOTION_DEPRECATED];

    protected $fillable = [
        'version', 'release_identifier', 'channel', 'git_tag', 'source_ref', 'commit_sha', 'source_repository',
        'artifact_url', 'artifact_path', 'artifact_sha256', 'artifact_verification_status', 'artifact_manifest_json',
        'manifest_hash', 'manifest_json', 'validation_errors', 'published_at',
        'discovered_at', 'status', 'promotion_status',
    ];

    protected function casts(): array
    {
        return [
            'manifest_json' => 'array',
            'artifact_manifest_json' => 'array',
            'validation_errors' => 'array',
            'published_at' => 'datetime',
            'discovered_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (IkontrolRelease $release) => $release->promotion_status ??= self::PROMOTION_BUILT);
    }

    public function updateRuns(): HasMany
    {
        return $this->hasMany(InstanceUpdateRun::class, 'release_id');
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(ReleasePromotion::class, 'ikontrol_release_id');
    }

    public function latestPromotion(): HasOne
    {
        return $this->hasOne(ReleasePromotion::class, 'ikontrol_release_id')->latestOfMany();
    }
}
