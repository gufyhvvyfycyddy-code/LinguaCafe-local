<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReviewCard extends Model
{
    use HasFactory;

    public const TARGET_WORD = 'word';
    public const TARGET_SENSE = 'sense';
    public const TARGET_PHRASE = 'phrase';

    public const LIFECYCLE_ACTIVE = 'active';
    public const LIFECYCLE_BURIED = 'buried';
    public const LIFECYCLE_SUSPENDED = 'suspended';
    public const LIFECYCLE_ARCHIVED = 'archived';

    public const MARKER_UNMARKED = 0;
    public const MARKER_MIN = 0;
    public const MARKER_MAX = 7;

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
        'lifecycle_state',
        'buried_until',
        'lifecycle_version',
        'lifecycle_changed_at',
        'marker',
    ];

    protected function casts(): array
    {
        return [
            'fsrs_due_at' => 'datetime',
            'fsrs_last_reviewed_at' => 'datetime',
            'fsrs_stability' => 'float',
            'fsrs_difficulty' => 'float',
            'fsrs_enabled' => 'boolean',
            'buried_until' => 'datetime',
            'lifecycle_changed_at' => 'datetime',
            'lifecycle_version' => 'integer',
            'marker' => 'integer',
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

    public function stateEvents()
    {
        return $this->hasMany(\App\Models\ReviewCardStateEvent::class, 'review_card_id');
    }

    /**
     * Unified queue-eligibility scope for sense review (ADR-0010).
     *
     * Applies:
     *   - user_id, language_id
     *   - target_type = sense
     *   - effective lifecycle state = active:
     *       lifecycle_state = 'active' AND (buried_until IS NULL OR <= now)
     *       OR lifecycle_state = 'buried' AND buried_until <= now (expired)
     *   - fsrs_enabled = true (compatibility mirror)
     *
     * The caller is responsible for joining word_senses and filtering
     * status = confirmed (use SenseReviewQueryService::confirmedSenseCardQuery
     * as the base), and for adding the due filter (fsrs_due_at <= $now).
     */
    public function scopeSenseReviewEligible(Builder $query, int $userId, string $language, Carbon $now): Builder
    {
        return $query
            ->where('review_cards.user_id', $userId)
            ->where('review_cards.language_id', $language)
            ->where('review_cards.target_type', self::TARGET_SENSE)
            ->where(function (Builder $q) use ($now) {
                // Active cards (no effective bury)
                $q->where(function (Builder $inner) use ($now) {
                    $inner->where('review_cards.lifecycle_state', self::LIFECYCLE_ACTIVE)
                        ->where(function (Builder $b) use ($now) {
                            $b->whereNull('review_cards.buried_until')
                                ->orWhere('review_cards.buried_until', '<=', $now);
                        });
                })
                // OR expired buried cards (auto-revert to active by query semantics)
                ->orWhere(function (Builder $inner) use ($now) {
                    $inner->where('review_cards.lifecycle_state', self::LIFECYCLE_BURIED)
                        ->whereNotNull('review_cards.buried_until')
                        ->where('review_cards.buried_until', '<=', $now);
                });
            })
            ->where('review_cards.fsrs_enabled', true);
    }
}
