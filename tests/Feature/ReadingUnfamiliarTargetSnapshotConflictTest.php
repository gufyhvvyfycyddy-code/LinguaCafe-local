<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ReadingUnfamiliarTarget;
use App\Models\User;
use App\Services\ReadingUnfamiliarTargetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * R3 optimistic-concurrency contract for whole-snapshot replacement.
 * Integration executes this against the merged Backend implementation.
 */
class ReadingUnfamiliarTargetSnapshotConflictTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_whole_snapshot_cannot_erase_newer_user_intent(): void
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
        $service = app(ReadingUnfamiliarTargetService::class);

        $clientA = $service->listCurrentTargets($user->id, 'english', $chapter->id);
        $clientB = $service->listCurrentTargets($user->id, 'english', $chapter->id);
        $versionA = $this->snapshotVersion($clientA);
        $versionB = $this->snapshotVersion($clientB);
        $this->assertSame($versionA, $versionB);

        $this->syncWithExpectedVersion($service, $user->id, $chapter->id, [[
            'kind' => ReadingUnfamiliarTarget::KIND_WORD,
            'start_word_index' => 0,
            'end_word_index' => 0,
        ]], $versionA);
        $afterA = $service->listCurrentTargets($user->id, 'english', $chapter->id);
        $this->assertNotSame($versionA, $this->snapshotVersion($afterA));
        $this->assertSame([0], array_column($afterA['targets'], 'start_word_index'));

        $staleFailure = null;
        try {
            $this->syncWithExpectedVersion($service, $user->id, $chapter->id, [[
                'kind' => ReadingUnfamiliarTarget::KIND_WORD,
                'start_word_index' => 1,
                'end_word_index' => 1,
            ]], $versionB);
        } catch (\Throwable $e) {
            $staleFailure = $e;
        }
        $this->assertNotNull($staleFailure, 'A stale whole-snapshot replacement must be rejected.');
        $this->assertNotSame('', trim($staleFailure->getMessage()));

        $final = $service->listCurrentTargets($user->id, 'english', $chapter->id);
        $this->assertSame([0], array_column($final['targets'], 'start_word_index'), 'Stale client B must not erase client A intent.');
    }

    private function snapshotVersion(array $payload): string
    {
        foreach (['snapshot_version', 'snapshot_token', 'version', 'etag', 'snapshot_hash'] as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key]) && (string) $payload[$key] !== '') {
                return (string) $payload[$key];
            }
        }

        $this->fail('R3 whole-snapshot contract must expose a server-issued optimistic-concurrency version/token.');
    }

    private function syncWithExpectedVersion(
        ReadingUnfamiliarTargetService $service,
        int $userId,
        int $chapterId,
        array $targets,
        string $expectedVersion,
    ): array {
        $method = new \ReflectionMethod($service, 'syncClientSnapshot');
        $this->assertGreaterThanOrEqual(
            5,
            $method->getNumberOfParameters(),
            'R3 syncClientSnapshot must accept an expected server-issued snapshot version/token.',
        );

        return $service->syncClientSnapshot($userId, 'english', $chapterId, $targets, $expectedVersion);
    }
}
