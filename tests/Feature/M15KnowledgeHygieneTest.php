<?php

namespace Tests\Feature;

use App\Models\KnowledgeHygieneOperation;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseTag;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class M15KnowledgeHygieneTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::forceCreate([
            'name' => 'M15 User',
            'email' => 'm15@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    public function test_preferences_are_bounded_and_user_scoped(): void
    {
        $payload = [
            'columns' => ['lemma', 'sense_zh', 'not_a_column'],
            'views' => [['name' => 'Again', 'filter_state' => ['q' => 'rated:again', 'unknown' => true], 'columns' => ['lemma']]],
        ];
        $this->actingAs($this->user)->putJson('/review-cards/knowledge-hygiene/preferences', $payload)
            ->assertOk()->assertExactJson([
                'columns' => ['lemma', 'sense_zh'],
                'views' => [['name' => 'Again', 'filter_state' => ['q' => 'rated:again'], 'columns' => ['lemma']]],
            ]);
        $this->actingAs($this->user)->getJson('/review-cards/knowledge-hygiene/preferences')
            ->assertOk()->assertJsonPath('views.0.name', 'Again');
    }

    public function test_find_replace_requires_preview_and_is_conflict_checked_and_undoable(): void
    {
        [$sense] = $this->senseCard('bank', ['sense_en' => 'river bank']);
        $before = [ReviewLog::count(), ReviewCard::count(), KnowledgeHygieneOperation::count()];
        $preview = $this->actingAs($this->user)->postJson('/review-cards/knowledge-hygiene/find-replace/preview', [
            'field' => 'sense_en', 'find' => 'bank', 'replace' => 'shore', 'q' => 'bank',
        ])->assertOk()->assertJsonPath('affected', 1);
        $this->assertSame($before, [ReviewLog::count(), ReviewCard::count(), KnowledgeHygieneOperation::count()]);

        $apply = $this->actingAs($this->user)->postJson('/review-cards/knowledge-hygiene/find-replace/apply', [
            'field' => 'sense_en', 'find' => 'bank', 'replace' => 'shore', 'q' => 'bank',
            'preview_fingerprint' => $preview->json('preview_fingerprint'),
        ])->assertOk();
        $this->assertSame('river shore', $sense->fresh()->sense_en);

        $this->actingAs($this->user)->postJson(
            '/review-cards/knowledge-hygiene/operations/' . $apply->json('operation_id') . '/undo'
        )->assertOk()->assertJsonPath('status', KnowledgeHygieneOperation::STATUS_UNDONE);
        $this->assertSame('river bank', $sense->fresh()->sense_en);
    }

    public function test_find_replace_rejects_a_stale_preview_without_partial_write(): void
    {
        [$sense] = $this->senseCard('stale', ['sense_en' => 'old bank']);
        $preview = $this->actingAs($this->user)->postJson('/review-cards/knowledge-hygiene/find-replace/preview', [
            'field' => 'sense_en', 'find' => 'bank', 'replace' => 'shore',
        ])->assertOk();
        $sense->sense_en = 'new bank';
        $sense->save();

        $this->actingAs($this->user)->postJson('/review-cards/knowledge-hygiene/find-replace/apply', [
            'field' => 'sense_en', 'find' => 'bank', 'replace' => 'shore',
            'preview_fingerprint' => $preview->json('preview_fingerprint'),
        ])->assertUnprocessable();

        $this->assertSame('new bank', $sense->fresh()->sense_en);
        $this->assertDatabaseCount('knowledge_hygiene_operations', 0);
    }

    public function test_safe_delete_appears_in_recent_deletes_and_restores_same_card(): void
    {
        [$sense, $card] = $this->senseCard('restore');
        ReviewLog::forceCreate($this->logAttributes($card, 'good'));

        $delete = $this->actingAs($this->user)
            ->deleteJson("/review-cards/manage/{$card->id}")
            ->assertOk()
            ->assertJsonPath('deleted', true);
        $this->assertDatabaseMissing('review_cards', ['id' => $card->id]);
        $this->assertDatabaseHas('word_senses', ['id' => $sense->id, 'status' => WordSense::STATUS_REJECTED]);
        $this->assertDatabaseCount('review_logs', 1);

        $this->actingAs($this->user)->getJson('/review-cards/knowledge-hygiene/recent-deletes')
            ->assertOk()->assertJsonPath('items.0.operation_id', $delete->json('operation_id'));
        $this->actingAs($this->user)->postJson(
            '/review-cards/knowledge-hygiene/operations/' . $delete->json('operation_id') . '/undo'
        )->assertOk();
        $this->assertDatabaseHas('review_cards', ['id' => $card->id, 'target_id' => $sense->id]);
        $this->assertDatabaseHas('word_senses', ['id' => $sense->id, 'status' => WordSense::STATUS_CONFIRMED]);
        $this->assertDatabaseCount('review_logs', 1);
    }

    public function test_duplicate_analysis_classifies_exact_and_requires_human_confirmation(): void
    {
        $this->senseCard('spring', ['sense_en' => 'a season', 'sense_zh' => '春天']);
        $this->senseCard('spring', ['sense_en' => 'a season', 'sense_zh' => '春天']);
        $this->actingAs($this->user)->postJson('/review-cards/knowledge-hygiene/duplicates')
            ->assertOk()
            ->assertJsonPath('items.0.classification', 'exact_duplicate')
            ->assertJsonPath('items.0.requires_human_confirmation', true);
    }

    public function test_merge_is_backup_gated_preserves_primary_schedule_and_is_undoable(): void
    {
        [$primarySense, $primaryCard] = $this->senseCard('merge', ['sense_en' => 'same']);
        [$duplicateSense, $duplicateCard] = $this->senseCard('merge', ['sense_en' => 'same']);
        ReviewLog::forceCreate($this->logAttributes($duplicateCard, 'again'));
        $primaryDue = $primaryCard->fsrs_due_at->toISOString();
        $this->mock(BackupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('createBackup')->once()->andReturn(['backup_id' => (string) Str::uuid()]);
        });

        $preview = $this->actingAs($this->user)->postJson('/review-cards/knowledge-hygiene/merge/preview', [
            'primary_review_card_id' => $primaryCard->id,
            'duplicate_review_card_id' => $duplicateCard->id,
        ])->assertOk()->assertJsonPath('impact.automatic_backup_required', true);
        $apply = $this->actingAs($this->user)->postJson('/review-cards/knowledge-hygiene/merge/apply', [
            'primary_review_card_id' => $primaryCard->id,
            'duplicate_review_card_id' => $duplicateCard->id,
            'preview_fingerprint' => $preview->json('preview_fingerprint'),
            'confirm' => true,
        ])->assertOk();
        $this->assertSame($primaryDue, $primaryCard->fresh()->fsrs_due_at->toISOString());
        $this->assertDatabaseMissing('review_cards', ['id' => $duplicateCard->id]);
        $this->assertDatabaseHas('review_logs', ['review_card_id' => $primaryCard->id, 'rating' => 'again']);
        $this->assertDatabaseHas('word_senses', ['id' => $duplicateSense->id, 'status' => WordSense::STATUS_REJECTED]);

        $this->actingAs($this->user)->postJson(
            '/review-cards/knowledge-hygiene/operations/' . $apply->json('operation_id') . '/undo'
        )->assertOk();
        $this->assertDatabaseHas('review_cards', ['id' => $duplicateCard->id]);
        $this->assertDatabaseHas('review_logs', ['review_card_id' => $duplicateCard->id, 'rating' => 'again']);
        $this->assertDatabaseHas('word_senses', ['id' => $duplicateSense->id, 'status' => WordSense::STATUS_CONFIRMED]);
        $this->assertSame($primarySense->id, $primaryCard->target_id);
    }

    public function test_merge_rejects_different_lemma_or_part_of_speech(): void
    {
        [, $primaryCard] = $this->senseCard('bank', ['pos' => 'noun']);
        [, $differentLemma] = $this->senseCard('shore', ['pos' => 'noun']);
        [, $differentPos] = $this->senseCard('bank', ['pos' => 'verb']);

        $this->actingAs($this->user)->postJson('/review-cards/knowledge-hygiene/merge/preview', [
            'primary_review_card_id' => $primaryCard->id,
            'duplicate_review_card_id' => $differentLemma->id,
        ])->assertUnprocessable();
        $this->actingAs($this->user)->postJson('/review-cards/knowledge-hygiene/merge/preview', [
            'primary_review_card_id' => $primaryCard->id,
            'duplicate_review_card_id' => $differentPos->id,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('knowledge_hygiene_operations', 0);
    }

    public function test_merge_undo_rejects_new_primary_tag_changes(): void
    {
        [$primarySense, $primaryCard] = $this->senseCard('tagged-merge', ['sense_en' => 'same']);
        [, $duplicateCard] = $this->senseCard('tagged-merge', ['sense_en' => 'same']);
        $this->mock(BackupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('createBackup')->once()->andReturn(['backup_id' => (string) Str::uuid()]);
        });
        $preview = $this->actingAs($this->user)->postJson('/review-cards/knowledge-hygiene/merge/preview', [
            'primary_review_card_id' => $primaryCard->id,
            'duplicate_review_card_id' => $duplicateCard->id,
        ])->assertOk();
        $apply = $this->actingAs($this->user)->postJson('/review-cards/knowledge-hygiene/merge/apply', [
            'primary_review_card_id' => $primaryCard->id,
            'duplicate_review_card_id' => $duplicateCard->id,
            'preview_fingerprint' => $preview->json('preview_fingerprint'),
            'confirm' => true,
        ])->assertOk();
        $tag = WordSenseTag::create([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'name' => 'After merge',
            'normalized_name' => 'after merge',
        ]);
        $primarySense->tags()->attach($tag->id);

        $this->actingAs($this->user)->postJson(
            '/review-cards/knowledge-hygiene/operations/' . $apply->json('operation_id') . '/undo'
        )->assertUnprocessable();
        $this->assertDatabaseMissing('review_cards', ['id' => $duplicateCard->id]);
        $this->assertDatabaseHas('word_sense_tag_assignments', [
            'word_sense_id' => $primarySense->id,
            'word_sense_tag_id' => $tag->id,
        ]);
    }

    public function test_card_and_operation_access_are_user_scoped(): void
    {
        $other = User::forceCreate([
            'name' => 'M15 Other',
            'email' => 'm15-other@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        $original = $this->user;
        $this->user = $other;
        [, $otherCard] = $this->senseCard('private');
        $this->user = $original;
        $otherOperation = KnowledgeHygieneOperation::forceCreate([
            'operation_id' => (string) Str::uuid(),
            'user_id' => $other->id,
            'language_id' => 'english',
            'operation_type' => KnowledgeHygieneOperation::TYPE_SAFE_DELETE,
            'status' => KnowledgeHygieneOperation::STATUS_APPLIED,
            'subject_ids' => [$otherCard->target_id],
            'before_snapshot' => [],
            'after_snapshot' => [],
            'preview_fingerprint' => str_repeat('a', 64),
            'metadata' => ['lemma' => 'private'],
        ]);

        $this->actingAs($original)->postJson('/review-cards/knowledge-hygiene/merge/preview', [
            'primary_review_card_id' => $otherCard->id,
            'duplicate_review_card_id' => $otherCard->id + 1,
        ])->assertNotFound();
        $this->actingAs($original)->postJson(
            '/review-cards/knowledge-hygiene/operations/' . $otherOperation->operation_id . '/undo'
        )->assertNotFound();
        $this->assertDatabaseHas('review_cards', ['id' => $otherCard->id, 'user_id' => $other->id]);
    }

    private function senseCard(string $lemma, array $overrides = []): array
    {
        $sense = WordSense::forceCreate(array_merge([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'noun',
            'sense_zh' => '含义',
            'sense_en' => 'meaning',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'Example.',
            'example_sentence_zh' => '例句。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => false,
            'sense_key' => hash('sha256', $lemma . Str::uuid()),
        ], $overrides));
        $card = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->addDays(5),
            'fsrs_last_reviewed_at' => now()->subDay(),
            'fsrs_stability' => 5,
            'fsrs_difficulty' => 5,
            'fsrs_reps' => 3,
            'fsrs_lapses' => 0,
            'fsrs_enabled' => true,
            'lifecycle_state' => 'active',
        ]);
        return [$sense, $card];
    }

    private function logAttributes(ReviewCard $card, string $rating): array
    {
        return [
            'user_id' => $card->user_id,
            'language' => $card->language,
            'language_id' => $card->language_id,
            'review_card_id' => $card->id,
            'rating' => $rating,
            'reviewed_at' => now(),
            'previous_state' => 'review',
            'new_state' => 'review',
            'previous_due_at' => now()->subDay(),
            'new_due_at' => now()->addDay(),
            'source' => ReviewLog::SOURCE_SENSE_REVIEW,
        ];
    }
}
