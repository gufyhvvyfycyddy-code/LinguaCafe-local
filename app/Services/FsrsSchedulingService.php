<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Models\Setting;
use Carbon\Carbon;

class FsrsSchedulingService
{
    public const RATING_AGAIN = 'again';
    public const RATING_HARD = 'hard';
    public const RATING_GOOD = 'good';
    public const RATING_EASY = 'easy';

    /**
     * Read the user-configured FSRS desired retention from the global settings table.
     *
     * Defaults to 0.90 when the setting is missing or invalid.
     * Clamped to [0.70, 0.97] regardless of stored value.
     *
     * @return float
     */
    public function desiredRetention(): float
    {
        $setting = \App\Models\Setting::where('name', 'fsrsDesiredRetention')
            ->where('user_id', -1)
            ->first();

        if (!$setting) {
            return 0.90;
        }

        $value = json_decode($setting->value, true);

        if (!is_numeric($value)) {
            return 0.90;
        }

        $value = (float) $value;

        return max(0.70, min(0.97, $value));
    }

    /**
     * Returns the currently active FSRS parameters for scheduling.
     *
     * Reads the fsrs_parameters global setting (user_id=-1).
     * If the setting is missing, empty, invalid JSON, wrong count,
     * contains non-numeric or out-of-range values, or any error occurs,
     * falls back to get_default_parameters().
     *
     * This method MUST NOT throw exceptions during review scheduling.
     *
     * Made public for read-only use by FsrsReschedulePreviewService (D.4-a).
     *
     * @return float[]
     */
    public function getActiveFsrsParameters(): array
    {
        try {
            $setting = Setting::where('name', 'fsrs_parameters')
                ->where('user_id', -1)
                ->first();

            if (!$setting || empty($setting->value)) {
                return get_default_parameters();
            }

            $params = json_decode($setting->value, true);

            if (!is_array($params)) {
                return get_default_parameters();
            }

            $params = array_values($params);
            $count = count($params);

            if ($count < 19 || $count > 21) {
                return get_default_parameters();
            }

            foreach ($params as $v) {
                if (!is_numeric($v) || !is_finite((float) $v) || abs((float) $v) > 1000) {
                    return get_default_parameters();
                }
            }

            return array_map('floatval', $params);
        } catch (\Throwable $e) {
            return get_default_parameters();
        }
    }

    public function schedule(ReviewCard $card, string $rating, ?Carbon $reviewedAt = null): array
    {
        $rating = strtolower($rating);
        if (!in_array($rating, $this->ratings(), true)) {
            throw new \InvalidArgumentException('Invalid FSRS rating.');
        }

        $reviewedAt = $reviewedAt ?: Carbon::now();
        $itemState = $this->extensionItemState($card, $rating, $reviewedAt);

        if ($itemState === null) {
            if (!$this->allowsInternalFallback()) {
                throw new \RuntimeException('The fsrs-rs-php extension is not loaded.');
            }

            $itemState = $this->fallbackItemState($card, $rating, $reviewedAt);
        }

        $interval = max(1, (int) round($itemState['interval']));

        return [
            'state' => $this->nextState($card, $rating),
            'due_at' => $reviewedAt->copy()->addDays($interval),
            'stability' => $itemState['stability'],
            'difficulty' => $itemState['difficulty'],
            'lapses' => $card->fsrs_lapses + ($rating === self::RATING_AGAIN ? 1 : 0),
            'reviewed_at' => $reviewedAt,
        ];
    }

    public function ratings(): array
    {
        return [
            self::RATING_AGAIN,
            self::RATING_HARD,
            self::RATING_GOOD,
            self::RATING_EASY,
        ];
    }

    private function extensionItemState(ReviewCard $card, string $rating, Carbon $reviewedAt): ?array
    {
        if (!extension_loaded('fsrs-rs-php') || !class_exists('\fsrs\FSRS') || !function_exists('get_default_parameters')) {
            return null;
        }

        $memory = null;
        if ($card->fsrs_stability !== null && $card->fsrs_difficulty !== null) {
            $memory = new \fsrs\MemoryState($card->fsrs_stability, $card->fsrs_difficulty);
        }

        $elapsedDays = 0;
        if ($card->fsrs_last_reviewed_at !== null) {
            $elapsedDays = (int) max(0, $card->fsrs_last_reviewed_at->diffInDays($reviewedAt));
        }

        $fsrs = new \fsrs\FSRS($this->getActiveFsrsParameters());
        $states = $fsrs->next_states($memory, $this->desiredRetention(), $elapsedDays);

        $state = match ($rating) {
            self::RATING_AGAIN => $states->get_again(),
            self::RATING_HARD => $states->get_hard(),
            self::RATING_GOOD => $states->get_good(),
            self::RATING_EASY => $states->get_easy(),
        };

        $memory = $state->get_memory();

        return [
            'interval' => $state->get_interval(),
            'stability' => $memory->get_stability(),
            'difficulty' => $memory->get_difficulty(),
        ];
    }

    private function fallbackItemState(ReviewCard $card, string $rating, Carbon $reviewedAt): array
    {
        $stability = $card->fsrs_stability ?: 1.0;
        $difficulty = $card->fsrs_difficulty ?: 5.0;
        $elapsedDays = $card->fsrs_last_reviewed_at ? (int) max(1, $card->fsrs_last_reviewed_at->diffInDays($reviewedAt)) : 1;

        $interval = match ($rating) {
            self::RATING_AGAIN => 1,
            self::RATING_HARD => max(1, $stability * 1.2),
            self::RATING_GOOD => max(1, $stability * 2.5),
            self::RATING_EASY => max(2, $stability * 4.0),
        };

        $stability = match ($rating) {
            self::RATING_AGAIN => max(0.5, $stability * 0.55),
            self::RATING_HARD => max(0.5, $stability + ($elapsedDays * 0.2)),
            self::RATING_GOOD => max(1.0, $stability + ($elapsedDays * 0.8)),
            self::RATING_EASY => max(1.0, $stability + ($elapsedDays * 1.5)),
        };

        $difficulty = match ($rating) {
            self::RATING_AGAIN => min(10.0, $difficulty + 1.0),
            self::RATING_HARD => min(10.0, $difficulty + 0.3),
            self::RATING_GOOD => max(1.0, $difficulty - 0.15),
            self::RATING_EASY => max(1.0, $difficulty - 0.7),
        };

        return [
            'interval' => $interval,
            'stability' => $stability,
            'difficulty' => $difficulty,
        ];
    }

    private function nextState(ReviewCard $card, string $rating): string
    {
        if ($rating === self::RATING_AGAIN) {
            return 'relearning';
        }

        if ($card->fsrs_reps === 0 && $rating === self::RATING_HARD) {
            return 'learning';
        }

        return 'review';
    }

    private function allowsInternalFallback(): bool
    {
        $appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV');

        return $appEnv === 'testing' || getenv('FSRS_ALLOW_INTERNAL_FALLBACK') === 'true';
    }
}
