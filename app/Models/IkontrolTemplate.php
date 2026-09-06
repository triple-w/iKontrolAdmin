<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IkontrolTemplate extends Model
{
    protected $fillable = ['version', 'name', 'app_version', 'schema_version', 'archive_path', 'database_dump_path', 'archive_sha256', 'database_sha256', 'active', 'is_default', 'notes'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'is_default' => 'boolean'];
    }

    public function scopeDefault($query)
    {
        return $query->where('active', true)->where('is_default', true);
    }

    public function instances(): HasMany
    {
        return $this->hasMany(IkontrolInstance::class);
    }
}
