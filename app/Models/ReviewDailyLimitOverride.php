<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReviewDailyLimitOverride extends Model
{
    protected $fillable = [
        'user_id',
        'language_id',
        'study_date',
        'new_limit_delta',
        'review_limit_delta',
        'pause_new_cards',
    ];

    protected $hidden = ['user_id', 'language_id'];

    protected function casts(): array
    {
        return [
            'study_date' => 'date:Y-m-d',
            'new_limit_delta' => 'integer',
            'review_limit_delta' => 'integer',
            'pause_new_cards' => 'boolean',
        ];
    }
}
