<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReviewCard extends Model
{
    use HasFactory;

    public const TARGET_WORD = 'word';
    public const TARGET_SENSE = 'sense';
    public const TARGET_PHRASE = 'phrase';

    protected $fillable = [
        'user_id',
        'language_id',
        'language',
        'target_type',
        'target_id',
        'fsrs_state',
        'fsrs_due_at',
        'fsrs_stability',
        'fsrs_difficulty',
        'fsrs_reps',
        'fsrs_lapses',
        'fsrs_last_reviewed_at',
        'fsrs_enabled',
    ];

    protected function casts(): array
    {
        return [
            'fsrs_due_at' => 'datetime',
            'fsrs_last_reviewed_at' => 'datetime',
            'fsrs_stability' => 'float',
            'fsrs_difficulty' => 'float',
            'fsrs_enabled' => 'boolean',
        ];
    }

    public function logs()
    {
        return $this->hasMany(ReviewLog::class);
    }

    public function sense()
    {
        return $this->belongsTo(WordSense::class, 'target_id');
    }
}
