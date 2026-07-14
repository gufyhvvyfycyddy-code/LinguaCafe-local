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
        'review_duration_ms',
        'previous_state',
        'new_state',
        'previous_due_at',
        'new_due_at',
        'previous_stability',
        'new_stability',
        'previous_difficulty',
        'new_difficulty',
        'source',
        // Undo ledger fields (ADR-0009)
        'review_session_id',
        'before_card_snapshot',
        'after_card_snapshot',
        'undone_at',
        'undo_request_id',
        'undo_source',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'review_duration_ms' => 'integer',
            'previous_due_at' => 'datetime',
            'new_due_at' => 'datetime',
            'previous_stability' => 'float',
            'new_stability' => 'float',
            'previous_difficulty' => 'float',
            'new_difficulty' => 'float',
            // Undo ledger casts
            'before_card_snapshot' => 'array',
            'after_card_snapshot' => 'array',
            'undone_at' => 'datetime',
        ];
    }

    /**
     * Scope: exclude undone review logs.
     *
     * Product analytics queries (daily report, 7-day trend, 30-day
     * calendar, stats, optimization, learning feedback, session
     * summary) MUST apply this scope. Audit queries (management
     * page logs, session action timeline, diagnostics) MUST NOT.
     */
    public function scopeNotUndone($query)
    {
        return $query->whereNull('undone_at');
    }

    public function card()
    {
        return $this->belongsTo(ReviewCard::class, 'review_card_id');
    }
}
