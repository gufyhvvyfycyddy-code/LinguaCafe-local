<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewSettingPreset extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bindings(): HasMany
    {
        return $this->hasMany(ReviewSettingPresetBinding::class, 'preset_id');
    }
}
