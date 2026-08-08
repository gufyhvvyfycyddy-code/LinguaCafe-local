<?php

namespace Tests\Support;

use App\Http\Controllers\SenseReviewController;
use App\Models\Chapter;
use App\Services\ReadingFinishSettlementService;
use App\Services\ReadingOccurrenceSenseEvidenceService;
use App\Services\ReadingSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class PabR3ReadingConcurrencyWorker
{
    public static function run(string $operation, array $payload): array
    {
        return match ($operation) {
            'start-session' => self::startSession($payload),
            'explicit-rate' => self::explicitRate($payload),
            'explicit-undo' => self::explicitUndo($payload),
            'finish-commit' => self::finishCommit($payload),
            'interaction' => self::interaction($payload),
            'user-evidence' => self::userEvidence($payload),
            'chapter-source-change' => self::chapterSourceChange($payload),
            default => throw new \InvalidArgumentException("Unknown PAB R3 concurrency operation: {$operation}"),
        };
    }

    private static function startSession(array $payload): array
    {
        return app(ReadingSessionService::class)->startSession(
            (int) $payload['user_id'],
            (string) $payload['language'],
            (int) $payload['chapter_id'],
            $payload['resume_reading_session_id'] ?? null,
        );
    }

    private static function explicitRate(array $payload): array
    {
        Auth::loginUsingId((int) $payload['user_id']);
        $request = Request::create(
            '/reviews/senses/'.(int) $payload['review_card_id'].'/rate',
            'POST',
            [
                'rating' => (string) $payload['rating'],
                'reading_session_id' => (string) $payload['reading_session_id'],
                'occurrence_id' => (string) $payload['occurrence_id'],
                'reading_action_id' => (string) $payload['reading_action_id'],
                'ignoreDailyLimits' => true,
            ],
        );
        $request->headers->set('Accept', 'application/json');

        $response = app(SenseReviewController::class)->rate((int) $payload['review_card_id'], $request);

        return [
            'operation' => 'explicit-rate',
            'http_status' => $response->getStatusCode(),
            'body' => $response->getData(true),
        ];
    }

    private static function explicitUndo(array $payload): array
    {
        Auth::loginUsingId((int) $payload['user_id']);
        $reviewLogId = (int) $payload['review_log_id'];
        $request = Request::create(
            '/reviews/senses/review-actions/'.$reviewLogId.'/undo',
            'POST',
            [
                'review_session_id' => (string) $payload['review_session_id'],
                'undo_request_id' => (string) $payload['undo_request_id'],
                'source' => (string) ($payload['source'] ?? 'sense_review_snackbar'),
            ],
        );
        $request->headers->set('Accept', 'application/json');

        $response = app(SenseReviewController::class)->undo($reviewLogId, $request);

        return [
            'operation' => 'explicit-undo',
            'http_status' => $response->getStatusCode(),
            'body' => $response->getData(true),
        ];
    }

    private static function finishCommit(array $payload): array
    {
        return app(ReadingFinishSettlementService::class)->finishChapterWithSession(
            (int) $payload['user_id'],
            (string) $payload['language'],
            (int) $payload['chapter_id'],
            (string) $payload['reading_session_id'],
            (bool) ($payload['auto_move_words_to_known'] ?? false),
            $payload['unique_words'] ?? [],
            (bool) ($payload['auto_level_up_words'] ?? false),
            $payload['leveled_up_words'] ?? [],
            $payload['leveled_up_phrases'] ?? [],
            'commit',
        );
    }

    private static function interaction(array $payload): array
    {
        return app(ReadingSessionService::class)->recordOccurrenceInteraction(
            (int) $payload['user_id'],
            (string) $payload['language'],
            (string) $payload['reading_session_id'],
            (string) $payload['interaction_type'],
            (string) $payload['occurrence_id'],
        );
    }

    private static function userEvidence(array $payload): array
    {
        $row = app(ReadingOccurrenceSenseEvidenceService::class)->storeUserDecision(
            (int) $payload['user_id'],
            (string) $payload['language'],
            (int) $payload['chapter_id'],
            (string) $payload['occurrence_id'],
            (string) $payload['resolution'],
            isset($payload['word_sense_id']) ? (int) $payload['word_sense_id'] : null,
        );

        return ['success' => true, 'evidence_id' => $row->id];
    }

    private static function chapterSourceChange(array $payload): array
    {
        return DB::transaction(function () use ($payload) {
            $chapter = Chapter::query()
                ->lockForUpdate()
                ->where('id', (int) $payload['chapter_id'])
                ->where('user_id', (int) $payload['user_id'])
                ->where('language', (string) $payload['language'])
                ->firstOrFail();
            $chapter->raw_text = (string) $chapter->raw_text.' [pab-r3-concurrent-source-change]';
            $chapter->save();

            return ['success' => true, 'chapter_id' => $chapter->id];
        });
    }
}
