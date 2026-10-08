<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IkontrolRelease extends Model
{
    public const CHANNELS = ['canary', 'stable'];
    public const STATUSES = ['discovered', 'validated', 'invalid', 'deprecated'];

    protected $fillable = [
        'version', 'channel', 'git_tag', 'commit_sha', 'source_repository',
        'manifest_hash', 'manifest_json', 'validation_errors', 'published_at',
        'discovered_at', 'status',
    ];

    protected function casts(): array
    {
        return [
            'manifest_json' => 'array',
            'validation_errors' => 'array',
            'published_at' => 'datetime',
            'discovered_at' => 'datetime',
        ];
    }

    public function updateRuns(): HasMany
    {
        return $this->hasMany(InstanceUpdateRun::class, 'release_id');
    }
}
