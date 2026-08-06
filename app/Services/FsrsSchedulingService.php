<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Services\Settings\Presets\ReviewSettingsResolver;
use Carbon\Carbon;

class FsrsSchedulingService
{
    public const RATING_AGAIN = 'again';
    public const RATING_HARD = 'hard';
    public const RATING_GOOD = 'good';
    public const RATING_EASY = 'easy';

    /** Resolve the active user/language Preset's desired retention. */
    private ReviewSettingsResolver $reviewSettings;

    public function __construct(?ReviewSettingsResolver $reviewSettings = null)
    {
        $this->reviewSettings = $reviewSettings ?? app(ReviewSettingsResolver::class);
    }

    public function desiredRetention(int $userId, string $language): float
    {
        return $this->reviewSettings->resolve($userId, $language)->fsrsDesiredRetention();
    }

    /**
     * Returns the currently active FSRS parameters for scheduling.
     *
     * Reads the active user/language Preset through ReviewSettingsResolver.
     * If the config is missing, empty, invalid, wrong count,
     * contains non-numeric or out-of-range values, or any error occurs,
     * falls back to get_default_parameters().
     *
     * This method MUST NOT throw exceptions during review scheduling.
     *
     * Made public for read-only use by FsrsReschedulePreviewService (D.4-a).
     *
     * @return float[]
     */
    public function getActiveFsrsParameters(int $userId, string $language): array
    {
        try {
            $params = $this->reviewSettings->resolve($userId, $language)->fsrsParameters();
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

        $config = $this->resolvedConfig($card);
        $scheduling = $config->scheduling();
        $interval = min(
            $scheduling['maximum_interval_days'],
            max(1, (int) round($itemState['interval'])),
        );
        $policy = $this->applyStepPolicy($card, $rating, $reviewedAt, $interval, $scheduling);

        return [
            'state' => $policy['state'],
            'step_index' => $policy['step_index'],
            'due_at' => $policy['due_at'],
            'stability' => $itemState['stability'],
            'difficulty' => $itemState['difficulty'],
            'lapses' => $card->fsrs_lapses
                + ($rating === self::RATING_AGAIN && $card->fsrs_state === 'review' ? 1 : 0),
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

    /**
     * ADR-0008: Batch pure projection for all four ratings.
     *
     * Calls schedule() once per rating and returns a structured array.
     * This is the single method used by the interval-preview endpoint.
     * It does NOT save, create ReviewLog, or modify the model.
     *
     * The rating order comes from ratings() — there is no second map.
     *
     * @return array<string, array{state: string, step_index: ?int, due_at: Carbon, stability: float, difficulty: float, lapses: int, reviewed_at: Carbon, interval_seconds: int}>
     */
    public function previewAllRatings(ReviewCard $card, ?Carbon $reviewedAt = null): array
    {
        $reviewedAt = $reviewedAt ?: Carbon::now();

        $preview = [];
        foreach ($this->ratings() as $rating) {
            $schedule = $this->schedule($card, $rating, $reviewedAt);
            $intervalSeconds = (int) max(0, $reviewedAt->diffInSeconds($schedule['due_at'], false));
            $preview[$rating] = [
                'state' => $schedule['state'],
                'step_index' => $schedule['step_index'],
                'due_at' => $schedule['due_at'],
                'stability' => $schedule['stability'],
                'difficulty' => $schedule['difficulty'],
                'lapses' => $schedule['lapses'],
                'reviewed_at' => $schedule['reviewed_at'],
                'interval_seconds' => $intervalSeconds,
            ];
        }

        return $preview;
    }

    /**
     * ADR-0008: Identify which engine was used for the last projection.
     *
     * Returns 'fsrs' when the extension is loaded, 'fallback' otherwise.
     * Used by the preview service for the diagnostics-only `engine` field.
     */
    public function activeEngine(): string
    {
        if (extension_loaded('fsrs-rs-php') && class_exists('\fsrs\FSRS') && function_exists('get_default_parameters')) {
            return 'fsrs';
        }

        return 'fallback';
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

        $userId = (int) $card->user_id;
        $language = (string) $card->language_id;
        if ($userId > 0 && $language !== '') {
            $config = $this->reviewSettings->resolve($userId, $language);
            $parameters = $config->fsrsParameters();
            $retention = $config->fsrsDesiredRetention();
        } else {
            $parameters = get_default_parameters();
            $retention = 0.90;
        }
        $fsrs = new \fsrs\FSRS($parameters);
        $states = $fsrs->next_states($memory, $retention, $elapsedDays);

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

    private function resolvedConfig(ReviewCard $card)
    {
        $userId = (int) $card->user_id;
        $language = (string) $card->language_id;
        if ($userId > 0 && $language !== '') {
            return $this->reviewSettings->resolve($userId, $language);
        }

        return \App\Services\Settings\Presets\ReviewSettingsPresetConfig::defaults();
    }

    private function applyStepPolicy(
        ReviewCard $card,
        string $rating,
        Carbon $reviewedAt,
        int $fsrsIntervalDays,
        array $settings,
    ): array {
        $currentState = (string) $card->fsrs_state;
        $phase = in_array($currentState, ['learning', 'relearning'], true)
            ? $currentState
            : ($currentState === 'new' ? 'learning' : null);

        if ($currentState === 'review' && $rating === self::RATING_AGAIN) {
            $phase = 'relearning';
        }

        if ($phase !== null) {
            $steps = $phase === 'learning'
                ? $settings['learning_steps_minutes']
                : $settings['relearning_steps_minutes'];
            $stepResult = $this->stepResult(
                $phase,
                $steps,
                $card->fsrs_step_index,
                $rating,
                $reviewedAt,
            );
            if ($stepResult !== null) {
                return $stepResult;
            }
        }

        if ($phase === 'relearning') {
            $fsrsIntervalDays = max(
                $settings['minimum_relearning_interval_days'],
                $fsrsIntervalDays,
            );
        }
        $fsrsIntervalDays = min($settings['maximum_interval_days'], $fsrsIntervalDays);
        $dueAt = $this->applyEasyDays(
            $reviewedAt,
            $fsrsIntervalDays,
            $settings['maximum_interval_days'],
            $settings['easy_days'],
        );

        return [
            'state' => 'review',
            'step_index' => null,
            'due_at' => $dueAt,
        ];
    }

    private function stepResult(
        string $phase,
        array $steps,
        mixed $currentIndex,
        string $rating,
        Carbon $reviewedAt,
    ): ?array {
        if ($steps === [] || $rating === self::RATING_EASY) {
            return null;
        }

        $index = $currentIndex === null ? -1 : (int) $currentIndex;
        if ($rating === self::RATING_AGAIN) {
            $nextIndex = 0;
            $minutes = $steps[0];
        } elseif ($rating === self::RATING_HARD) {
            $nextIndex = min(count($steps) - 1, max(0, $index));
            $currentMinutes = $steps[min($nextIndex, count($steps) - 1)];
            $followingMinutes = $steps[min($nextIndex + 1, count($steps) - 1)];
            $minutes = min(1439, (int) ceil(($currentMinutes + $followingMinutes) / 2));
        } else {
            $nextIndex = $index < 0 ? 1 : $index + 1;
            if (!array_key_exists($nextIndex, $steps)) {
                return null;
            }
            $minutes = $steps[$nextIndex];
        }

        return [
            'state' => $phase,
            'step_index' => $nextIndex,
            'due_at' => $reviewedAt->copy()->addMinutes($minutes),
        ];
    }

    private function applyEasyDays(
        Carbon $reviewedAt,
        int $intervalDays,
        int $maximumIntervalDays,
        array $easyDays,
    ): Carbon {
        if ($intervalDays < 2 || count(array_unique($easyDays)) === 1) {
            return $reviewedAt->copy()->addDays($intervalDays);
        }

        $weights = ['normal' => 0.0, 'reduced' => 1.0, 'minimum' => 2.0];
        $bestInterval = $intervalDays;
        $bestScore = INF;
        foreach (range(-2, 2) as $shift) {
            $candidateInterval = $intervalDays + $shift;
            if ($candidateInterval < 1 || $candidateInterval > $maximumIntervalDays) {
                continue;
            }
            $candidate = $reviewedAt->copy()->addDays($candidateInterval);
            $score = ($weights[$easyDays[$candidate->dayOfWeek] ?? 'normal'] ?? 0.0)
                + (abs($shift) * 0.3);
            if ($score < $bestScore) {
                $bestScore = $score;
                $bestInterval = $candidateInterval;
            }
        }

        return $reviewedAt->copy()->addDays($bestInterval);
    }

    private function allowsInternalFallback(): bool
    {
        $appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV');

        return $appEnv === 'testing' || getenv('FSRS_ALLOW_INTERNAL_FALLBACK') === 'true';
    }
}
