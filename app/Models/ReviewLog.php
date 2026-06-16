<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReviewLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'language_id',
        'language',
        'review_card_id',
        'rating',
        'reviewed_at',
        'previous_state',
        'new_state',
        'previous_due_at',
        'new_due_at',
        'previous_stability',
        'new_stability',
        'previous_difficulty',
        'new_difficulty',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'previous_due_at' => 'datetime',
            'new_due_at' => 'datetime',
            'previous_stability' => 'float',
            'new_stability' => 'float',
            'previous_difficulty' => 'float',
            'new_difficulty' => 'float',
        ];
    }

    public function card()
    {
        return $this->belongsTo(ReviewCard::class, 'review_card_id');
    }
}
