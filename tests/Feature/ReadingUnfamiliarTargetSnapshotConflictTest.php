<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ReadingUnfamiliarTarget;
use App\Models\User;
use App\Services\AiReadingAssistV2Service;
use App\Services\ReadingUnfamiliarTargetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AI source snapshot consistency must remain read-only while preserving stale-version rejection.
 */
class ReadingUnfamiliarTargetSnapshotConflictTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_version_conflicting_ai_source_cannot_replace_server_targets(): void
    {
        [$user, $chapter, $targets, $assist] = $this->fixture();
        $targets->createTarget($user->id, 'english', $chapter->id, ReadingUnfamiliarTarget::KIND_WORD, 0, 0);
        $snapshot = $targets->listCurrentTargets($user->id, 'english', $chapter->id);

        $result = $assist->buildSourcePackages(
            $user->id,
            'english',
            $chapter->id,
            [[
                'kind' => ReadingUnfamiliarTarget::KIND_WORD,
                'start_word_index' => 1,
                'end_word_index' => 1,
            ]],
            $this->snapshotVersion($snapshot),
        );

        $this->assertFalse($result['success']);
        $this->assertSame(AiReadingAssistV2Service::ERROR_TARGET_SET_MISMATCH, $result['error_code']);
        $final = $targets->listCurrentTargets($user->id, 'english', $chapter->id);
        $this->assertSame([0], array_column($final['targets'], 'start_word_index'));
    }

    public function test_stale_ai_source_cannot_erase_newer_server_intent(): void
    {
        [$user, $chapter, $targets, $assist] = $this->fixture();
        $staleSnapshot = $targets->listCurrentTargets($user->id, 'english', $chapter->id);
        $targets->createTarget($user->id, 'english', $chapter->id, ReadingUnfamiliarTarget::KIND_WORD, 0, 0);

        $result = $assist->buildSourcePackages(
            $user->id,
            'english',
            $chapter->id,
            [[
                'kind' => ReadingUnfamiliarTarget::KIND_WORD,
                'start_word_index' => 1,
                'end_word_index' => 1,
            ]],
            $this->snapshotVersion($staleSnapshot),
        );

        $this->assertFalse($result['success']);
        $this->assertSame(AiReadingAssistV2Service::ERROR_TARGET_SET_MISMATCH, $result['error_code']);
        $final = $targets->listCurrentTargets($user->id, 'english', $chapter->id);
        $this->assertSame([0], array_column($final['targets'], 'start_word_index'));
    }

    private function fixture(): array
    {
        $user = User::forceCreate([
            'name' => 'PAB R3 Snapshot',
            'email' => 'pab-r3-snapshot-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        $book = Book::forceCreate(['user_id' => $user->id, 'name' => 'Snapshot Book', 'language' => 'english']);
        $tokens = [
            ['word_index' => 0, 'word' => 'alpha', 'lemma' => 'alpha', 'pos' => 'NOUN', 'sentence_index' => 0, 'spaceAfter' => true],
            ['word_index' => 1, 'word' => 'beta', 'lemma' => 'beta', 'pos' => 'NOUN', 'sentence_index' => 0, 'spaceAfter' => false],
        ];
        $chapter = Chapter::forceCreate([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'name' => 'Snapshot Chapter',
            'language' => 'english',
            'raw_text' => 'alpha beta',
            'word_count' => 2,
            'read_count' => 0,
            'unique_words' => '["alpha","beta"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode($tokens), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);

        return [
            $user,
            $chapter,
            app(ReadingUnfamiliarTargetService::class),
            app(AiReadingAssistV2Service::class),
        ];
    }

    private function snapshotVersion(array $payload): string
    {
        $this->assertArrayHasKey('snapshot_version', $payload);
        $this->assertIsString($payload['snapshot_version']);
        $this->assertNotSame('', $payload['snapshot_version']);

        return $payload['snapshot_version'];
    }

}
