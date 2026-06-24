<?php

namespace App\Services;

use App\Models\EncounteredWord;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\WordSense;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReviewCardService
{
    public function __construct(private FsrsSchedulingService $fsrsSchedulingService)
    {
    }

    public function ensureWordCard(EncounteredWord $word): ?ReviewCard
    {
        if (!$this->isReviewableWord($word)) {
            return null;
        }

        return ReviewCard::firstOrCreate(
            [
                'user_id' => $word->user_id,
                'language_id' => $word->language,
                'language' => $word->language,
                'target_type' => ReviewCard::TARGET_WORD,
                'target_id' => $word->id,
            ],
            [
                'fsrs_state' => 'new',
                'fsrs_due_at' => Carbon::now(),
                'fsrs_enabled' => true,
            ]
        );
    }

    public function ensureSenseCard(WordSense $sense): ?ReviewCard
    {
        if (!$this->isReviewableSense($sense)) {
            return null;
        }

        return ReviewCard::firstOrCreate(
            [
                'user_id' => $sense->user_id,
                'language_id' => $sense->language_id,
                'language' => $sense->language,
                'target_type' => ReviewCard::TARGET_SENSE,
                'target_id' => $sense->id,
            ],
            [
                'fsrs_state' => 'new',
                'fsrs_due_at' => Carbon::now(),
                'fsrs_enabled' => true,
            ]
        );
    }

    public function disableWordCard(EncounteredWord $word): void
    {
        ReviewCard::where('user_id', $word->user_id)
            ->where('language_id', $word->language)
            ->where('target_type', ReviewCard::TARGET_WORD)
            ->where('target_id', $word->id)
            ->update(['fsrs_enabled' => false]);
    }

    public function initializeExistingWords(?int $userId = null, ?string $language = null): int
    {
        $created = 0;
        $this->initializableWordsQuery($userId, $language)->chunkById(500, function ($words) use (&$created) {
            foreach ($words as $word) {
                $card = $this->ensureWordCard($word);
                if ($card !== null && $card->wasRecentlyCreated) {
                    $created++;
                }
            }
        }, 'encountered_words.id', 'id');

        return $created;
    }

    public function countInitializableWords(?int $userId = null, ?string $language = null): int
    {
        return $this->initializableWordsQuery($userId, $language)->count();
    }

    /**
     * Reset a sense review card to new-card state, erasing all FSRS memory.
     *
     * Only sense cards (target_type=sense) with a confirmed WordSense are eligible.
     * Archived cards are force-enabled. Existing review_logs are preserved.
     * A new ReviewLog with rating='reset' and source='reset' is created.
     */
    public function resetCard(int $userId, string $language, int $reviewCardId): ReviewCard
    {
        return DB::transaction(function () use ($userId, $language, $reviewCardId) {
            $card = ReviewCard::lockForUpdate()
                ->where('user_id', $userId)
                ->where('language_id', $language)
                ->where('id', $reviewCardId)
                ->where('target_type', ReviewCard::TARGET_SENSE)
                ->first();

            if (!$card) {
                throw new \Exception('Review card does not exist, is not a sense card, or belongs to another user.');
            }

            $sense = WordSense::where('user_id', $userId)
                ->where('language_id', $language)
                ->where('id', $card->target_id)
                ->where('status', WordSense::STATUS_CONFIRMED)
                ->first();

            if (!$sense) {
                throw new \Exception('Review card target sense is not confirmed or does not exist.');
            }

            $previous = [
                'state' => $card->fsrs_state,
                'due_at' => $card->fsrs_due_at,
                'stability' => $card->fsrs_stability,
                'difficulty' => $card->fsrs_difficulty,
            ];

            $card->fsrs_state = 'new';
            $card->fsrs_due_at = Carbon::now();
            $card->fsrs_stability = null;
            $card->fsrs_difficulty = null;
            $card->fsrs_reps = 0;
            $card->fsrs_lapses = 0;
            $card->fsrs_last_reviewed_at = null;
            $card->fsrs_enabled = true;
            $card->save();

            ReviewLog::create([
                'user_id' => $userId,
                'language_id' => $language,
                'language' => $language,
                'review_card_id' => $card->id,
                'rating' => 'reset',
                'reviewed_at' => Carbon::now(),
                'previous_state' => $previous['state'],
                'new_state' => $card->fsrs_state,
                'previous_due_at' => $previous['due_at'],
                'new_due_at' => $card->fsrs_due_at,
                'previous_stability' => $previous['stability'],
                'new_stability' => $card->fsrs_stability,
                'previous_difficulty' => $previous['difficulty'],
                'new_difficulty' => $card->fsrs_difficulty,
                'source' => 'reset',
            ]);

            return $card;
        });
    }

    public function recordReview(int $userId, string $language, int $reviewCardId, string $rating, string $source = 'review'): ReviewCard
    {
        return DB::transaction(function () use ($userId, $language, $reviewCardId, $rating, $source) {
            $card = ReviewCard::lockForUpdate()
                ->where('user_id', $userId)
                ->where('language_id', $language)
                ->where('id', $reviewCardId)
                ->where('fsrs_enabled', true)
                ->first();

            if (!$card) {
                throw new \Exception('Review card does not exist, is disabled, or belongs to another user.');
            }

            if (!$this->isReviewableTarget($card, $language)) {
                throw new \Exception('Review card target is no longer reviewable.');
            }

            $previous = [
                'state' => $card->fsrs_state,
                'due_at' => $card->fsrs_due_at,
                'stability' => $card->fsrs_stability,
                'difficulty' => $card->fsrs_difficulty,
            ];

            $schedule = $this->fsrsSchedulingService->schedule($card, $rating);

            $card->fsrs_state = $schedule['state'];
            $card->fsrs_due_at = $schedule['due_at'];
            $card->fsrs_stability = $schedule['stability'];
            $card->fsrs_difficulty = $schedule['difficulty'];
            $card->fsrs_reps = $card->fsrs_reps + 1;
            $card->fsrs_lapses = $schedule['lapses'];
            $card->fsrs_last_reviewed_at = $schedule['reviewed_at'];
            $card->save();

            ReviewLog::create([
                'user_id' => $userId,
                'language_id' => $language,
                'language' => $language,
                'review_card_id' => $card->id,
                'rating' => $rating,
                'reviewed_at' => $schedule['reviewed_at'],
                'previous_state' => $previous['state'],
                'new_state' => $card->fsrs_state,
                'previous_due_at' => $previous['due_at'],
                'new_due_at' => $card->fsrs_due_at,
                'previous_stability' => $previous['stability'],
                'new_stability' => $card->fsrs_stability,
                'previous_difficulty' => $previous['difficulty'],
                'new_difficulty' => $card->fsrs_difficulty,
                'source' => $source,
            ]);

            return $card;
        });
    }

    private function wordForCard(ReviewCard $card): ?EncounteredWord
    {
        if ($card->target_type !== ReviewCard::TARGET_WORD) {
            return null;
        }

        return EncounteredWord::where('user_id', $card->user_id)
            ->where('language', $card->language_id)
            ->where('id', $card->target_id)
            ->first();
    }

    private function senseForCard(ReviewCard $card): ?WordSense
    {
        if ($card->target_type !== ReviewCard::TARGET_SENSE) {
            return null;
        }

        return WordSense::where('user_id', $card->user_id)
            ->where('language_id', $card->language_id)
            ->where('id', $card->target_id)
            ->first();
    }

    private function isReviewableTarget(ReviewCard $card, string $language): bool
    {
        if ($card->target_type === ReviewCard::TARGET_WORD) {
            $word = $this->wordForCard($card);

            return $word !== null && $word->language === $language && $this->isReviewableWord($word);
        }

        if ($card->target_type === ReviewCard::TARGET_SENSE) {
            $sense = $this->senseForCard($card);

            return $sense !== null && $sense->language_id === $language && $this->isReviewableSense($sense);
        }

        return false;
    }

    private function isReviewableWord(EncounteredWord $word): bool
    {
        return $word->stage < 0;
    }

    private function isReviewableSense(WordSense $sense): bool
    {
        return $sense->status === WordSense::STATUS_CONFIRMED;
    }

    private function initializableWordsQuery(?int $userId = null, ?string $language = null)
    {
        $query = EncounteredWord::query()
            ->leftJoin('review_cards', function ($join) {
                $join->on('review_cards.target_id', '=', 'encountered_words.id')
                    ->where('review_cards.target_type', ReviewCard::TARGET_WORD)
                    ->whereColumn('review_cards.user_id', 'encountered_words.user_id')
                    ->whereColumn('review_cards.language_id', 'encountered_words.language');
            })
            ->where('encountered_words.stage', '<', 0)
            ->whereNull('review_cards.id')
            ->select('encountered_words.*');

        if ($userId !== null) {
            $query->where('encountered_words.user_id', $userId);
        }

        if ($language !== null) {
            $query->where('encountered_words.language', $language);
        }

        return $query;
    }
}
