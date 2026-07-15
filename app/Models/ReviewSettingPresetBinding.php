<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewSettingPresetBinding extends Model
{
    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function preset(): BelongsTo
    {
        return $this->belongsTo(ReviewSettingPreset::class, 'preset_id');
    }
}
