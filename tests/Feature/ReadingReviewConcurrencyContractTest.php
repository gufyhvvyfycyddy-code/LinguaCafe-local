<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\Goal;
use App\Models\GoalAchievement;
use App\Models\ReadingOccurrenceSenseEvidence;
use App\Models\ReadingSession;
use App\Models\ReadingSessionCardSettlement;
use App\Models\ReadingSessionCompletion;
use App\Models\ReadingSessionInteraction;
use App\Models\ReadingUnfamiliarTarget;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\GoalService;
use App\Services\ReadingOccurrenceSenseEvidenceService;
use App\Services\ReadingSessionService;
use App\Services\ReadingTargetCatalogService;
use App\Services\ReviewCardFsrsSnapshotService;
use App\Services\ReviewCardService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * True process/barrier tests for the R3 reading contracts. Integration owns
 * execution under the exclusive testing-DB lease. No sleep-based scheduling.
 */
class ReadingReviewConcurrencyContractTest extends TestCase
{
    private ?User $user = null;
    private Chapter $chapter;
    private WordSense $sense;
    private ReviewCard $card;
    private string $occurrenceId;
    private ReadingSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'PAB R3 Concurrency',
            'email' => 'pab-r3-concurrency-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        app(GoalService::class)->createGoalsForLanguage($this->user->id, 'english');
        $book = Book::forceCreate([
            'user_id' => $this->user->id,
            'name' => 'PAB R3 Concurrent Book',
            'language' => 'english',
        ]);
        $processed = [[
            'word_index' => 0,
            'word' => 'bank',
            'lemma' => 'bank',
            'pos' => 'NOUN',
            'sentence_index' => 0,
            'spaceAfter' => false,
        ]];
        $this->chapter = Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => $book->id,
            'name' => 'PAB R3 Concurrent Chapter',
            'language' => 'english',
            'raw_text' => 'bank',
            'word_count' => 1,
            'read_count' => 0,
            'unique_words' => '["bank"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode($processed), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
        $this->sense = $this->makeSense('bank', '银行');
        $this->card = app(ReviewCardService::class)->ensureSenseCard($this->sense);
        $this->card->forceFill([
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
            'fsrs_enabled' => true,
            'fsrs_due_at' => now(),
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
        ])->save();

        $catalog = app(ReadingTargetCatalogService::class)->build($this->user->id, 'english', $this->chapter->id);
        $this->assertCount(1, $catalog['targets']);
        $this->occurrenceId = $catalog['targets'][0]['occurrence_id'];
        $session = app(ReadingSessionService::class)->startSession($this->user->id, 'english', $this->chapter->id);
        $this->session = ReadingSession::where('uuid', $session['reading_session_id'])->firstOrFail();
    }

    protected function tearDown(): void
    {
        if ($this->user !== null) {
            $userId = $this->user->id;
            ReadingSessionCardSettlement::query()->where('user_id', $userId)->delete();
            ReadingSessionCompletion::query()->where('user_id', $userId)->delete();
            ReadingSessionInteraction::query()->where('user_id', $userId)->delete();
            ReadingOccurrenceSenseEvidence::query()->where('user_id', $userId)->delete();
            ReadingUnfamiliarTarget::query()->where('user_id', $userId)->delete();
            ReviewLog::query()->where('user_id', $userId)->delete();
            ReadingSession::query()->where('user_id', $userId)->delete();
            ReviewCard::query()->where('user_id', $userId)->delete();
            GoalAchievement::query()->where('user_id', $userId)->delete();
            Goal::query()->where('user_id', $userId)->delete();
            WordSense::query()->where('user_id', $userId)->delete();
            Chapter::query()->where('user_id', $userId)->delete();
            Book::query()->where('user_id', $userId)->delete();
            User::query()->where('id', $userId)->delete();
        }
        parent::tearDown();
    }

    #[DataProvider('ratings')]
    public function test_canonical_reading_rating_endpoint_records_each_rating_with_exact_source_and_snapshot(string $rating): void
    {
        $before = app(ReviewCardFsrsSnapshotService::class)->capture($this->card->fresh());
        $response = $this->actingAs($this->user)->postJson('/reviews/senses/'.$this->card->id.'/rate', [
            'rating' => $rating,
            'reading_session_id' => $this->session->uuid,
            'occurrence_id' => $this->occurrenceId,
            'ignoreDailyLimits' => true,
        ]);

        $response->assertOk()->assertJsonPath('action.rating', $rating);
        $log = ReviewLog::where('review_card_id', $this->card->id)->where('source', ReviewLog::SOURCE_READING_EXPLICIT)->sole();
        $this->assertSame($rating, $log->rating);
        $this->assertSame($this->session->uuid, $log->review_session_id);
        $this->assertSame($before, $log->before_card_snapshot);
        $this->assertSame($log->id, $response->json('action.review_log_id'));
    }

    public static function ratings(): array
    {
        return [['again'], ['hard'], ['good'], ['easy']];
    }

    public function test_sequential_duplicate_explicit_rating_returns_same_action_and_one_formal_log(): void
    {
        $payload = [
            'rating' => 'good',
            'reading_session_id' => $this->session->uuid,
            'occurrence_id' => $this->occurrenceId,
            'ignoreDailyLimits' => true,
        ];
        $first = $this->actingAs($this->user)->postJson('/reviews/senses/'.$this->card->id.'/rate', $payload)->assertOk();
        $second = $this->actingAs($this->user)->postJson('/reviews/senses/'.$this->card->id.'/rate', $payload)->assertOk();

        $this->assertSame(1, ReviewLog::where('review_card_id', $this->card->id)->where('source', ReviewLog::SOURCE_READING_EXPLICIT)->count());
        $this->assertSame($first->json('action.review_log_id'), $second->json('action.review_log_id'));
    }

    public function test_true_concurrent_duplicate_explicit_rating_creates_one_formal_log(): void
    {
        $payload = $this->explicitWorkerPayload('good');
        $results = $this->runConcurrent([
            ['explicit-rate', $payload],
            ['explicit-rate', $payload],
        ]);

        $this->assertAllWorkersSucceeded($results);
        $logs = ReviewLog::where('review_card_id', $this->card->id)->where('source', ReviewLog::SOURCE_READING_EXPLICIT)->get();
        $this->assertCount(1, $logs);
        $this->assertSame($logs[0]->id, $results[0]['json']['action']['review_log_id']);
        $this->assertSame($logs[0]->id, $results[1]['json']['action']['review_log_id']);
    }

    public function test_true_concurrent_start_creates_one_current_active_session(): void
    {
        ReadingSession::query()
            ->where('user_id', $this->user->id)
            ->where('chapter_id', $this->chapter->id)
            ->delete();
        $payload = [
            'user_id' => $this->user->id,
            'language' => 'english',
            'chapter_id' => $this->chapter->id,
        ];
        $results = $this->runConcurrent([
            ['start-session', $payload],
            ['start-session', $payload],
        ]);

        $this->assertAllWorkersSucceeded($results);
        $this->assertSame(1, ReadingSession::where('user_id', $this->user->id)->where('chapter_id', $this->chapter->id)->where('status', ReadingSession::STATUS_ACTIVE)->count());
        $this->assertSame($results[0]['json']['reading_session_id'], $results[1]['json']['reading_session_id']);
    }

    public function test_true_concurrent_finish_commits_one_completion_one_legacy_effect_and_one_passive_good(): void
    {
        $this->bindCurrentOccurrenceTo($this->sense);
        $results = $this->runConcurrent([
            ['finish-commit', $this->finishWorkerPayload()],
            ['finish-commit', $this->finishWorkerPayload()],
        ]);

        $this->assertAllWorkersSucceeded($results);
        $this->assertSame(1, ReadingSessionCompletion::where('reading_session_id', $this->session->id)->count());
        $this->assertSame(1, $this->chapter->fresh()->read_count);
        $this->assertSame(1, ReviewLog::where('review_card_id', $this->card->id)->where('source', ReviewLog::SOURCE_READING_PASSIVE)->count());
    }

    public function test_true_explicit_vs_finish_race_never_creates_both_explicit_and_passive_for_same_card_session(): void
    {
        $this->bindCurrentOccurrenceTo($this->sense);
        $results = $this->runConcurrent([
            ['explicit-rate', $this->explicitWorkerPayload('hard')],
            ['finish-commit', $this->finishWorkerPayload()],
        ]);

        $this->assertWorkerOutcome($results[0], [ReadingSessionService::ERROR_SESSION_NOT_ACTIVE]);
        $this->assertWorkerOutcome($results[1]);
        $logs = ReviewLog::where('review_card_id', $this->card->id)
            ->whereIn('source', [ReviewLog::SOURCE_READING_EXPLICIT, ReviewLog::SOURCE_READING_PASSIVE])
            ->get();
        $this->assertCount(1, $logs, $this->workerDiagnostics($results));
        $this->assertContains($logs[0]->source, [ReviewLog::SOURCE_READING_EXPLICIT, ReviewLog::SOURCE_READING_PASSIVE]);
        $this->assertLessThanOrEqual(1, ReadingSessionCompletion::where('reading_session_id', $this->session->id)->count());
    }

    public function test_true_opened_vs_finish_race_has_no_impossible_opened_plus_passive_terminal_state(): void
    {
        $this->bindCurrentOccurrenceTo($this->sense);
        $results = $this->runConcurrent([
            ['interaction', $this->interactionWorkerPayload('opened')],
            ['finish-commit', $this->finishWorkerPayload()],
        ]);

        $this->assertWorkerOutcome($results[0], [ReadingSessionService::ERROR_SESSION_NOT_ACTIVE]);
        $this->assertWorkerOutcome($results[1]);
        $opened = ReadingSessionInteraction::where('reading_session_id', $this->session->id)
            ->where('interaction_type', ReadingSessionInteraction::TYPE_OPENED)->exists();
        $passive = ReviewLog::where('review_card_id', $this->card->id)->where('source', ReviewLog::SOURCE_READING_PASSIVE)->exists();
        $this->assertFalse($opened && $passive, $this->workerDiagnostics($results));
    }

    public function test_true_helped_vs_finish_race_has_no_impossible_helped_plus_passive_terminal_state(): void
    {
        $this->bindCurrentOccurrenceTo($this->sense);
        $results = $this->runConcurrent([
            ['interaction', $this->interactionWorkerPayload('helped')],
            ['finish-commit', $this->finishWorkerPayload()],
        ]);

        $this->assertWorkerOutcome($results[0], [ReadingSessionService::ERROR_SESSION_NOT_ACTIVE]);
        $this->assertWorkerOutcome($results[1]);
        $helped = ReadingSessionInteraction::where('reading_session_id', $this->session->id)
            ->where('interaction_type', ReadingSessionInteraction::TYPE_HELPED)->exists();
        $passive = ReviewLog::where('review_card_id', $this->card->id)->where('source', ReviewLog::SOURCE_READING_PASSIVE)->exists();
        $this->assertFalse($helped && $passive, $this->workerDiagnostics($results));
    }

    public function test_true_evidence_correction_vs_finish_race_serializes_to_one_valid_passive_choice(): void
    {
        $alternate = $this->makeSense('bank', '河岸');
        $alternateCard = app(ReviewCardService::class)->ensureSenseCard($alternate);
        $alternateCard->forceFill(['lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE, 'fsrs_enabled' => true, 'fsrs_due_at' => now()])->save();
        $catalog = app(ReadingTargetCatalogService::class)->build($this->user->id, 'english', $this->chapter->id);
        $this->occurrenceId = $catalog['targets'][0]['occurrence_id'];
        $this->bindCurrentOccurrenceTo($this->sense);

        $results = $this->runConcurrent([
            ['user-evidence', [
                'user_id' => $this->user->id,
                'language' => 'english',
                'chapter_id' => $this->chapter->id,
                'occurrence_id' => $this->occurrenceId,
                'resolution' => ReadingOccurrenceSenseEvidence::RESOLUTION_MATCHED_EXISTING,
                'word_sense_id' => $alternate->id,
            ]],
            ['finish-commit', $this->finishWorkerPayload()],
        ]);

        $this->assertAllWorkersSucceeded($results);
        $passive = ReviewLog::where('source', ReviewLog::SOURCE_READING_PASSIVE)
            ->whereIn('review_card_id', [$this->card->id, $alternateCard->id])->get();
        $this->assertLessThanOrEqual(1, $passive->count(), $this->workerDiagnostics($results));
        if (ReadingSessionCompletion::where('reading_session_id', $this->session->id)->exists()) {
            $this->assertCount(1, $passive, $this->workerDiagnostics($results));
        } else {
            $this->assertCount(0, $passive, $this->workerDiagnostics($results));
        }
    }

    public function test_true_source_change_vs_finish_race_has_only_a_serialized_success_or_stale_terminal_state(): void
    {
        $this->bindCurrentOccurrenceTo($this->sense);
        $beforeReadCount = $this->chapter->read_count;
        $results = $this->runConcurrent([
            ['chapter-source-change', [
                'user_id' => $this->user->id,
                'language' => 'english',
                'chapter_id' => $this->chapter->id,
            ]],
            ['finish-commit', $this->finishWorkerPayload()],
        ]);

        $this->assertWorkerOutcome($results[0]);
        $this->assertWorkerOutcome($results[1], [ReadingSessionService::ERROR_SESSION_STALE_SOURCE]);
        $completed = ReadingSessionCompletion::where('reading_session_id', $this->session->id)->exists();
        $passiveCount = ReviewLog::where('review_card_id', $this->card->id)->where('source', ReviewLog::SOURCE_READING_PASSIVE)->count();
        $readDelta = $this->chapter->fresh()->read_count - $beforeReadCount;
        if ($completed) {
            $this->assertSame(1, $passiveCount, $this->workerDiagnostics($results));
            $this->assertSame(1, $readDelta, $this->workerDiagnostics($results));
        } else {
            $this->assertSame(0, $passiveCount, $this->workerDiagnostics($results));
            $this->assertSame(0, $readDelta, $this->workerDiagnostics($results));
        }
    }

    private function makeSense(string $lemma, string $senseZh): WordSense
    {
        return WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'NOUN',
            'sense_zh' => $senseZh,
            'sense_en' => $senseZh,
            'aliases_zh' => [],
            'collocations' => [],
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', $lemma.'|'.$senseZh.'|'.Str::uuid()),
        ]);
    }

    private function bindCurrentOccurrenceTo(WordSense $sense): void
    {
        app(ReadingOccurrenceSenseEvidenceService::class)->storeUserDecision(
            $this->user->id,
            'english',
            $this->chapter->id,
            $this->occurrenceId,
            ReadingOccurrenceSenseEvidence::RESOLUTION_MATCHED_EXISTING,
            $sense->id,
        );
    }

    private function explicitWorkerPayload(string $rating): array
    {
        return [
            'user_id' => $this->user->id,
            'review_card_id' => $this->card->id,
            'rating' => $rating,
            'reading_session_id' => $this->session->uuid,
            'occurrence_id' => $this->occurrenceId,
        ];
    }

    private function interactionWorkerPayload(string $type): array
    {
        return [
            'user_id' => $this->user->id,
            'language' => 'english',
            'reading_session_id' => $this->session->uuid,
            'interaction_type' => $type,
            'occurrence_id' => $this->occurrenceId,
        ];
    }

    private function finishWorkerPayload(): array
    {
        return [
            'user_id' => $this->user->id,
            'language' => 'english',
            'chapter_id' => $this->chapter->id,
            'reading_session_id' => $this->session->uuid,
            'auto_move_words_to_known' => false,
            'unique_words' => [],
            'auto_level_up_words' => false,
            'leveled_up_words' => [],
            'leveled_up_phrases' => [],
        ];
    }

    /** @param array<int, array{0:string,1:array}> $operations */
    private function runConcurrent(array $operations): array
    {
        $processes = [];
        $code = <<<'PHP'
$basePath = $argv[1];
$operation = $argv[2];
$payload = json_decode(base64_decode($argv[3]), true, 512, JSON_THROW_ON_ERROR);
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
require $basePath.'/vendor/autoload.php';
$app = require $basePath.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
fgets(STDIN);
try {
    $result = Tests\Support\PabR3ReadingConcurrencyWorker::run($operation, $payload);
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}
PHP;
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        foreach ($operations as [$operation, $payload]) {
            $process = proc_open([
                PHP_BINARY,
                '-d',
                'max_execution_time=20',
                '-r',
                $code,
                base_path(),
                $operation,
                base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
            ], $descriptors, $pipes, base_path());
            if (!is_resource($process)) {
                throw new RuntimeException('Could not start PAB R3 concurrency worker.');
            }
            $ready = fgets($pipes[1]);
            if ($ready !== "READY\n") {
                $stderr = stream_get_contents($pipes[2]);
                throw new RuntimeException("Concurrency worker failed before barrier: {$stderr}");
            }
            $processes[] = [$process, $pipes, $operation];
        }

        foreach ($processes as [, $pipes]) {
            fwrite($pipes[0], "go\n");
            fclose($pipes[0]);
        }

        $results = [];
        foreach ($processes as [$process, $pipes, $operation]) {
            $stdout = trim(stream_get_contents($pipes[1]));
            $stderr = trim(stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            $lines = array_values(array_filter(preg_split('/\R/', $stdout) ?: [], fn (string $line) => trim($line) !== ''));
            $jsonLine = $lines !== [] ? end($lines) : null;
            $json = is_string($jsonLine) ? json_decode($jsonLine, true) : null;
            $results[] = compact('operation', 'exitCode', 'stdout', 'stderr', 'json');
        }

        return $results;
    }

    private function assertAllWorkersSucceeded(array $results): void
    {
        foreach ($results as $result) {
            $this->assertWorkerOutcome($result);
        }
    }

    private function assertWorkerOutcome(array $result, array $allowedErrors = []): void
    {
        if ($result['exitCode'] === 0) {
            $this->assertIsArray($result['json'], $this->workerDiagnostics([$result]));
            return;
        }

        foreach ($allowedErrors as $allowedError) {
            if (str_contains($result['stderr'], $allowedError)) {
                $this->addToAssertionCount(1);
                return;
            }
        }

        $this->fail('Unexpected concurrency worker failure: '.$this->workerDiagnostics([$result]));
    }

    private function workerDiagnostics(array $results): string
    {
        return json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
