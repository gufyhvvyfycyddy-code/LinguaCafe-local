<?php

namespace App\Services;

use App\Models\EncounteredWord;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
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

            $word = $this->wordForCard($card);
            if (!$word || $word->language !== $language || !$this->isReviewableWord($word)) {
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

    private function isReviewableWord(EncounteredWord $word): bool
    {
        return $word->stage < 0;
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
