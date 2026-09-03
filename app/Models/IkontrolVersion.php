<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IkontrolVersion extends Model
{
    protected $fillable = ['version', 'name', 'source_type', 'source_reference', 'checksum', 'active', 'is_default', 'notes'];

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
