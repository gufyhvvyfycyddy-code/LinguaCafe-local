<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ReadingProgress;
use App\Models\ReadingSession;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\ReadingChapterTextService;
use App\Services\ReadingContinuityService;
use App\Services\ReviewCardFsrsSnapshotService;
use App\Services\ReviewCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReadingContinuityProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Chapter $chapter;
    private string $sourceRevision;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'G06D Reading Continuity',
            'email' => 'g06d-reading-continuity-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        $book = Book::forceCreate([
            'user_id' => $this->user->id,
            'name' => 'G06D Reading Continuity Book',
            'language' => 'english',
        ]);

        $tokens = [];
        for ($index = 0; $index <= 120; $index++) {
            $tokens[] = [
                'word_index' => $index,
                'word' => $index === 60 ? 'NEWLINE' : 'word'.$index,
                'lemma' => 'word'.$index,
                'pos' => $index === 60 ? 'STRUCT' : 'NOUN',
                'is_structure' => $index === 60,
                'sentence_index' => $index < 60 ? 0 : 1,
                'spaceAfter' => true,
            ];
        }
        $tokens[] = [
            'word' => 'legacy-without-explicit-index',
            'lemma' => 'legacy-without-explicit-index',
            'pos' => 'NOUN',
            'sentence_index' => 1,
            'spaceAfter' => true,
        ];

        $this->chapter = Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => $book->id,
            'name' => 'G06D Reading Continuity Chapter',
            'language' => 'english',
            'raw_text' => 'G06D continuity source',
            'word_count' => 120,
            'read_count' => 0,
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode($tokens), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
        $this->sourceRevision = app(ReadingChapterTextService::class)->sourceRevision($this->chapter);
    }

    public function test_latest_can_move_backward_while_furthest_only_moves_forward_in_one_revision_row(): void
    {
        $this->actingAs($this->user)->getJson($this->continuityUrl())
            ->assertOk()
            ->assertJsonPath('source_revision', $this->sourceRevision)
            ->assertJsonPath('resume', null)
            ->assertJsonPath('furthest', null);

        $this->putProgress(40)
            ->assertOk()
            ->assertJsonPath('source_revision', $this->sourceRevision)
            ->assertJsonPath('canonical_token_index', 40)
            ->assertJsonMissingPath('furthest_canonical_token_index');
        $this->assertContinuityAnchors(40, 40);

        $this->putProgress(80)->assertOk();
        $this->assertContinuityAnchors(80, 80);

        $this->putProgress(50)->assertOk();
        $this->assertContinuityAnchors(50, 80);

        $this->putProgress(100)->assertOk();
        $this->putProgress(50)->assertOk()->assertJsonPath('canonical_token_index', 50);
        $this->assertContinuityAnchors(50, 100);

        $this->putProgress(50)->assertOk();
        $this->assertSame(1, ReadingProgress::query()->where($this->scope())->count());
        $stored = ReadingProgress::query()->where($this->scope())->firstOrFail();
        $this->assertSame(50, $stored->canonical_token_index);
        $this->assertSame(100, $stored->furthest_canonical_token_index);
    }

    public function test_progress_is_independent_of_reading_session_and_never_mutates_review_state(): void
    {
        $sense = WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'continuity',
            'surface_form' => 'continuity',
            'pos' => 'NOUN',
            'sense_zh' => '连续性',
            'sense_en' => 'continuity',
            'aliases_zh' => [],
            'collocations' => [],
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => false,
            'sense_key' => hash('sha256', 'g06d-continuity-'.Str::uuid()),
        ]);
        $card = app(ReviewCardService::class)->ensureSenseCard($sense);
        $card->forceFill([
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
            'fsrs_enabled' => true,
            'fsrs_due_at' => now(),
            'fsrs_reps' => 3,
            'fsrs_lapses' => 1,
        ])->save();
        $beforeCard = app(ReviewCardFsrsSnapshotService::class)->capture($card->fresh());
        $before = [
            'cards' => ReviewCard::count(),
            'logs' => ReviewLog::count(),
        ];

        $this->actingAs($this->user);
        $this->putProgress(80)->assertOk();

        ReadingSession::forceCreate([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'chapter_id' => $this->chapter->id,
            'source_revision' => $this->sourceRevision,
            'status' => ReadingSession::STATUS_ACTIVE,
            'started_at' => now(),
        ]);

        $this->getJson($this->continuityUrl())
            ->assertOk()
            ->assertJsonPath('resume.canonical_token_index', 80)
            ->assertJsonPath('furthest.canonical_token_index', 80);

        $this->assertSame($before, [
            'cards' => ReviewCard::count(),
            'logs' => ReviewLog::count(),
        ]);
        $this->assertSame(
            $beforeCard,
            app(ReviewCardFsrsSnapshotService::class)->capture($card->fresh()),
        );
    }

    public function test_progress_is_strictly_scoped_by_user_language_and_chapter(): void
    {
        $this->actingAs($this->user);
        $this->putProgress(30)->assertOk();

        $otherUser = User::forceCreate([
            'name' => 'G06D Foreign User',
            'email' => 'g06d-foreign-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        $this->flushSession();
        $this->actingAs($otherUser)->getJson($this->continuityUrl())
            ->assertNotFound()
            ->assertJsonPath('error_code', ReadingContinuityService::ERROR_CHAPTER_NOT_FOUND);

        $this->user->forceFill(['selected_language' => 'spanish'])->save();
        $this->flushSession();
        $this->actingAs($this->user)->getJson($this->continuityUrl())
            ->assertNotFound()
            ->assertJsonPath('error_code', ReadingContinuityService::ERROR_CHAPTER_NOT_FOUND);

        $this->user->forceFill(['selected_language' => 'english'])->save();
        $otherChapter = $this->chapter->replicate();
        $otherChapter->name = 'G06D Other Chapter';
        $otherChapter->save();
        $this->flushSession();
        $this->actingAs($this->user)->getJson('/chapters/'.$otherChapter->id.'/reading-continuity')
            ->assertOk()
            ->assertJsonPath('resume', null)
            ->assertJsonPath('furthest', null);
    }

    public function test_invalid_or_stale_anchor_fails_closed_and_old_revision_does_not_resume(): void
    {
        $this->actingAs($this->user);

        $this->putProgress(999)
            ->assertStatus(422)
            ->assertJsonPath('error_code', ReadingContinuityService::ERROR_INVALID_TOKEN);
        $this->putProgress(60)
            ->assertStatus(422)
            ->assertJsonPath('error_code', ReadingContinuityService::ERROR_INVALID_TOKEN);
        $this->putProgress(121)
            ->assertStatus(422)
            ->assertJsonPath('error_code', ReadingContinuityService::ERROR_INVALID_TOKEN);
        $this->putJson('/chapters/'.$this->chapter->id.'/reading-progress', [
            'source_revision' => 'sha256:stale',
            'canonical_token_index' => 20,
        ])->assertStatus(409)
            ->assertJsonPath('error_code', ReadingContinuityService::ERROR_STALE_SOURCE);
        $this->assertSame(0, ReadingProgress::query()->where($this->scope())->count());

        $this->putProgress(100)->assertOk();
        $oldRevision = $this->sourceRevision;
        $this->chapter->forceFill(['raw_text' => 'G06D continuity source changed'])->save();
        $newRevision = app(ReadingChapterTextService::class)->sourceRevision($this->chapter->fresh());
        $this->assertNotSame($oldRevision, $newRevision);

        $this->getJson($this->continuityUrl())
            ->assertOk()
            ->assertJsonPath('source_revision', $newRevision)
            ->assertJsonPath('resume', null)
            ->assertJsonPath('furthest', null);
        $this->putJson('/chapters/'.$this->chapter->id.'/reading-progress', [
            'source_revision' => $oldRevision,
            'canonical_token_index' => 40,
        ])->assertStatus(409)
            ->assertJsonPath('error_code', ReadingContinuityService::ERROR_STALE_SOURCE);

        $stored = ReadingProgress::query()->where($this->scope())->firstOrFail();
        $this->assertSame($oldRevision, $stored->source_revision);
        $this->assertSame(100, $stored->canonical_token_index);
        $this->assertSame(100, $stored->furthest_canonical_token_index);

        $this->sourceRevision = $newRevision;
        $this->putProgress(40)->assertOk();
        $this->assertContinuityAnchors(40, 40);
        $this->assertSame(2, ReadingProgress::query()->where($this->scope())->count());

        $oldStored = ReadingProgress::query()
            ->where($this->scope() + ['source_revision' => $oldRevision])
            ->firstOrFail();
        $this->assertSame(100, $oldStored->canonical_token_index);
        $this->assertSame(100, $oldStored->furthest_canonical_token_index);

        $currentStored = ReadingProgress::query()
            ->where($this->scope() + ['source_revision' => $newRevision])
            ->firstOrFail();
        $this->assertSame(40, $currentStored->canonical_token_index);
        $this->assertSame(40, $currentStored->furthest_canonical_token_index);
    }

    public function test_positionable_rank_excludes_structure_blank_and_legacy_tokens_and_final_rank_is_complete(): void
    {
        $tokens = [
            [
                'word_index' => 10,
                'word' => 'first',
                'lemma' => 'first',
                'pos' => 'NOUN',
                'sentence_index' => 0,
                'spaceAfter' => true,
            ],
            [
                'word_index' => 500,
                'word' => 'NEWLINE',
                'pos' => 'STRUCT',
                'is_structure' => true,
                'sentence_index' => 0,
                'spaceAfter' => true,
            ],
            [
                'word_index' => 100,
                'word' => 'last',
                'lemma' => 'last',
                'pos' => 'NOUN',
                'sentence_index' => 1,
                'spaceAfter' => true,
            ],
            [
                'word_index' => 700,
                'word' => '   ',
                'lemma' => '',
                'pos' => 'NOUN',
                'sentence_index' => 1,
                'spaceAfter' => true,
            ],
            [
                'word' => 'legacy-without-explicit-index',
                'lemma' => 'legacy-without-explicit-index',
                'pos' => 'NOUN',
                'sentence_index' => 1,
                'spaceAfter' => true,
            ],
        ];
        $this->chapter->forceFill([
            'raw_text' => 'G06D sparse canonical rank source',
            'processed_text' => gzcompress(json_encode($tokens), 1),
        ])->save();
        $this->chapter = $this->chapter->fresh();
        $chapterText = app(ReadingChapterTextService::class);
        $this->sourceRevision = $chapterText->sourceRevision($this->chapter);

        $ranks = $chapterText->positionableCanonicalTokenRanks($this->chapter);
        $this->assertSame([10 => 0, 100 => 1], $ranks);
        $this->assertEquals(1.0, ($ranks[100] + 1) / count($ranks));
        $this->assertFalse($chapterText->isCanonicalPositionToken($this->chapter, 500));
        $this->assertFalse($chapterText->isCanonicalPositionToken($this->chapter, 700));
        $this->assertFalse($chapterText->isCanonicalPositionToken($this->chapter, 4));

        $this->actingAs($this->user);
        $this->putProgress(10)->assertOk();
        $this->putProgress(100)->assertOk();
        $this->putProgress(10)->assertOk();
        $this->assertContinuityAnchors(10, 100);
    }

    public function test_client_supplied_furthest_field_cannot_override_server_owned_furthest(): void
    {
        $this->actingAs($this->user)->putJson('/chapters/'.$this->chapter->id.'/reading-progress', [
            'source_revision' => $this->sourceRevision,
            'canonical_token_index' => 40,
            'furthest_canonical_token_index' => 100,
        ])->assertOk()
            ->assertJsonPath('canonical_token_index', 40)
            ->assertJsonMissingPath('furthest_canonical_token_index');

        $this->assertContinuityAnchors(40, 40);
    }

    private function assertContinuityAnchors(int $latest, int $furthest): void
    {
        $this->getJson($this->continuityUrl())
            ->assertOk()
            ->assertJsonPath('resume.source_revision', $this->sourceRevision)
            ->assertJsonPath('resume.canonical_token_index', $latest)
            ->assertJsonPath('furthest.source_revision', $this->sourceRevision)
            ->assertJsonPath('furthest.canonical_token_index', $furthest);
    }

    private function continuityUrl(): string
    {
        return '/chapters/'.$this->chapter->id.'/reading-continuity';
    }

    private function putProgress(int $canonicalTokenIndex)
    {
        return $this->putJson('/chapters/'.$this->chapter->id.'/reading-progress', [
            'source_revision' => $this->sourceRevision,
            'canonical_token_index' => $canonicalTokenIndex,
        ]);
    }

    private function scope(): array
    {
        return [
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'chapter_id' => $this->chapter->id,
        ];
    }
}
