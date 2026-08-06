<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseTag;
use App\Services\ReviewCardManageFilterState;
use App\Services\WordSenseTagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class M10UnifiedSearchTagFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private WordSenseTagService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('m10@example.test', 'english');
        $this->service = app(WordSenseTagService::class);
    }

    public function test_filter_state_canonicalizes_tag_ids(): void
    {
        $state = ReviewCardManageFilterState::fromArray([
            'filter' => 'all',
            'tag_ids' => [9, 2, 5],
        ]);

        $this->assertSame([2, 5, 9], $state->get('tag_ids'));
        $this->assertSame([2, 5, 9], $state->toArray()['tag_ids']);
    }

    public function test_tag_names_are_normalized_and_unique_per_user_language(): void
    {
        $tag = $this->service->create($this->user->id, 'english', ' Grammar :: Verbs ');

        $this->assertSame('Grammar::Verbs', $tag->name);
        $this->assertSame('grammar::verbs', $tag->normalized_name);

        $this->expectException(ValidationException::class);
        $this->service->create($this->user->id, 'english', 'grammar::VERBS');
    }

    public function test_same_normalized_name_is_allowed_for_other_scope(): void
    {
        $other = $this->createUser('m10-other@example.test', 'english');

        $first = $this->service->create($this->user->id, 'english', 'Academic');
        $otherUser = $this->service->create($other->id, 'english', 'academic');
        $otherLanguage = $this->service->create($this->user->id, 'spanish', 'academic');

        $this->assertNotSame($first->id, $otherUser->id);
        $this->assertNotSame($first->id, $otherLanguage->id);
    }

    public function test_bulk_add_and_remove_are_idempotent_and_do_not_touch_learning_state(): void
    {
        [$firstCard, $firstSense] = $this->createSenseCard($this->user, 'first');
        [$secondCard, $secondSense] = $this->createSenseCard($this->user, 'second');
        $tag = $this->service->create($this->user->id, 'english', 'Priority');

        $before = ReviewCard::query()
            ->whereIn('id', [$firstCard->id, $secondCard->id])
            ->get()
            ->mapWithKeys(fn ($card) => [$card->id => [
                $card->fsrs_state,
                optional($card->fsrs_due_at)->toISOString(),
                $card->fsrs_reps,
                $card->fsrs_lapses,
                $card->lifecycle_state,
            ]])
            ->all();

        $this->service->applyToReviewCards(
            $this->user->id,
            'english',
            [$firstCard->id, $secondCard->id],
            [$tag->id],
            'add',
        );
        $this->service->applyToReviewCards(
            $this->user->id,
            'english',
            [$firstCard->id, $secondCard->id],
            [$tag->id],
            'add',
        );

        $this->assertDatabaseCount('word_sense_tag_assignments', 2);
        $this->assertSame([$tag->id], $firstSense->fresh()->tags()->pluck('word_sense_tags.id')->all());
        $this->assertSame([$tag->id], $secondSense->fresh()->tags()->pluck('word_sense_tags.id')->all());
        $this->assertDatabaseCount('review_logs', 0);

        $after = ReviewCard::query()
            ->whereIn('id', [$firstCard->id, $secondCard->id])
            ->get()
            ->mapWithKeys(fn ($card) => [$card->id => [
                $card->fsrs_state,
                optional($card->fsrs_due_at)->toISOString(),
                $card->fsrs_reps,
                $card->fsrs_lapses,
                $card->lifecycle_state,
            ]])
            ->all();
        $this->assertSame($before, $after);

        $this->service->applyToReviewCards(
            $this->user->id,
            'english',
            [$firstCard->id, $secondCard->id],
            [$tag->id],
            'remove',
        );
        $this->service->applyToReviewCards(
            $this->user->id,
            'english',
            [$firstCard->id, $secondCard->id],
            [$tag->id],
            'remove',
        );

        $this->assertDatabaseCount('word_sense_tag_assignments', 0);
    }

    public function test_cross_scope_bulk_request_is_atomic(): void
    {
        [$ownedCard] = $this->createSenseCard($this->user, 'owned');
        $other = $this->createUser('m10-isolated@example.test', 'english');
        [$otherCard] = $this->createSenseCard($other, 'other');
        $tag = $this->service->create($this->user->id, 'english', 'Private');

        try {
            $this->service->applyToReviewCards(
                $this->user->id,
                'english',
                [$ownedCard->id, $otherCard->id],
                [$tag->id],
                'add',
            );
            $this->fail('Cross-scope bulk assignment should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('review_card_ids', $exception->errors());
        }

        $this->assertDatabaseCount('word_sense_tag_assignments', 0);
    }

    public function test_tag_delete_removes_only_assignments(): void
    {
        [$card, $sense] = $this->createSenseCard($this->user, 'delete-safe');
        $tag = $this->service->create($this->user->id, 'english', 'Temporary');
        $this->service->applyToReviewCards(
            $this->user->id,
            'english',
            [$card->id],
            [$tag->id],
            'add',
        );

        $this->service->delete($tag->id, $this->user->id, 'english');

        $this->assertDatabaseMissing('word_sense_tags', ['id' => $tag->id]);
        $this->assertDatabaseMissing('word_sense_tag_assignments', [
            'word_sense_tag_id' => $tag->id,
        ]);
        $this->assertDatabaseHas('word_senses', ['id' => $sense->id]);
        $this->assertDatabaseHas('review_cards', ['id' => $card->id]);
    }

    public function test_assignment_integrity_follows_sense_and_user_deletion(): void
    {
        [, $sense] = $this->createSenseCard($this->user, 'cascade-sense');
        $senseTag = $this->service->create($this->user->id, 'english', 'Cascade::Sense');
        $sense->tags()->attach($senseTag->id);

        $sense->delete();

        $this->assertDatabaseMissing('word_sense_tag_assignments', [
            'word_sense_id' => $sense->id,
        ]);
        $this->assertDatabaseHas('word_sense_tags', ['id' => $senseTag->id]);

        $other = $this->createUser('m10-cascade-user@example.test', 'english');
        $userTag = $this->service->create($other->id, 'english', 'Cascade::User');

        $other->delete();

        $this->assertDatabaseMissing('word_sense_tags', ['id' => $userTag->id]);
    }

    public function test_browser_query_uses_and_semantics_and_serializes_tags(): void
    {
        [$bothCard] = $this->createSenseCard($this->user, 'both');
        [$oneCard] = $this->createSenseCard($this->user, 'one');
        [$noneCard] = $this->createSenseCard($this->user, 'none');
        $grammar = $this->service->create($this->user->id, 'english', 'Grammar');
        $priority = $this->service->create($this->user->id, 'english', 'Priority');

        $this->service->applyToReviewCards(
            $this->user->id,
            'english',
            [$bothCard->id, $oneCard->id],
            [$grammar->id],
            'add',
        );
        $this->service->applyToReviewCards(
            $this->user->id,
            'english',
            [$bothCard->id],
            [$priority->id],
            'add',
        );

        $response = $this->actingAs($this->user)->getJson(
            '/review-cards/manage/data?filter=all&tag_ids[]='
            . $grammar->id
            . '&tag_ids[]='
            . $priority->id
        );

        $response->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('items.0.review_card_id', $bothCard->id)
            ->assertJsonPath('items.0.tags.0.name', 'Grammar')
            ->assertJsonPath('items.0.tags.1.name', 'Priority');

        $this->assertNotSame($oneCard->id, $response->json('items.0.review_card_id'));
        $this->assertNotSame($noneCard->id, $response->json('items.0.review_card_id'));
    }

    public function test_unknown_or_cross_scope_tag_id_fails_closed(): void
    {
        $this->createSenseCard($this->user, 'visible');
        $other = $this->createUser('m10-tag-owner@example.test', 'english');
        $otherTag = $this->service->create($other->id, 'english', 'Other');

        $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=all&tag_ids[]=' . $otherTag->id)
            ->assertOk()
            ->assertJsonPath('pagination.total', 0)
            ->assertJsonCount(0, 'items');

        $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=all&tag_ids[]=999999999')
            ->assertOk()
            ->assertJsonPath('pagination.total', 0)
            ->assertJsonCount(0, 'items');
    }

    public function test_web_tag_crud_and_bulk_contract_are_scoped(): void
    {
        [$card, $sense] = $this->createSenseCard($this->user, 'web-contract');

        $created = $this->actingAs($this->user)
            ->postJson('/review-cards/manage/tags', ['name' => 'Reading::Core'])
            ->assertCreated()
            ->assertJsonPath('name', 'Reading::Core');
        $tagId = $created->json('id');

        $this->actingAs($this->user)
            ->getJson('/review-cards/manage/tags')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.senses_count', 0);

        $this->actingAs($this->user)
            ->postJson('/review-cards/manage/tags/bulk-assignments', [
                'review_card_ids' => [$card->id],
                'tag_ids' => [$tagId],
                'action' => 'add',
            ])
            ->assertOk()
            ->assertJsonPath('result.review_card_count', 1)
            ->assertJsonPath('result.word_sense_count', 1)
            ->assertJsonPath('result.tag_count', 1)
            ->assertJsonPath('result.action', 'add');

        $this->assertDatabaseHas('word_sense_tag_assignments', [
            'word_sense_id' => $sense->id,
            'word_sense_tag_id' => $tagId,
        ]);

        $this->actingAs($this->user)
            ->patchJson("/review-cards/manage/tags/{$tagId}", ['name' => 'Reading::Essential'])
            ->assertOk()
            ->assertJsonPath('name', 'Reading::Essential');

        $other = $this->createUser('m10-route-other@example.test', 'english');
        $this->flushSession();
        $this->actingAs($other)
            ->deleteJson("/review-cards/manage/tags/{$tagId}")
            ->assertNotFound();

        $this->flushSession();
        $this->actingAs($this->user)
            ->deleteJson("/review-cards/manage/tags/{$tagId}")
            ->assertNoContent();
        $this->assertDatabaseHas('word_senses', ['id' => $sense->id]);
        $this->assertDatabaseHas('review_cards', ['id' => $card->id]);
    }

    public function test_mobile_search_reuses_tag_criteria_and_caps_pagination(): void
    {
        [$taggedCard] = $this->createSenseCard($this->user, 'mobile-tagged');
        $this->createSenseCard($this->user, 'mobile-untagged');
        $tag = $this->service->create($this->user->id, 'english', 'Mobile');
        $this->service->applyToReviewCards(
            $this->user->id,
            'english',
            [$taggedCard->id],
            [$tag->id],
            'add',
        );
        $token = $this->issueMobileToken($this->user);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/review-cards/search?filter=all&tag_ids[]=' . $tag->id . '&per_page=1')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.pagination.per_page', 1)
            ->assertJsonPath('data.items.0.review_card_id', $taggedCard->id)
            ->assertJsonPath('data.items.0.tags.0.name', 'Mobile')
            ->assertJsonPath('data.criteria.tag_ids.0', $tag->id)
            ->assertJsonPath('data.criteria_version', 2);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/review-cards/search?per_page=101')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_mobile_search_cannot_use_another_users_tag_to_broaden_scope(): void
    {
        $this->createSenseCard($this->user, 'mobile-private');
        $other = $this->createUser('m10-mobile-other@example.test', 'english');
        $otherTag = $this->service->create($other->id, 'english', 'Other');
        $token = $this->issueMobileToken($this->user);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/review-cards/search?filter=all&tag_ids[]=' . $otherTag->id)
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 0)
            ->assertJsonCount(0, 'data.items');
    }

    public function test_tag_criteria_have_browser_and_export_parity(): void
    {
        [$taggedCard] = $this->createSenseCard($this->user, 'parity-tagged');
        $this->createSenseCard($this->user, 'parity-untagged');
        $tag = $this->service->create($this->user->id, 'english', 'Parity');
        $this->service->applyToReviewCards(
            $this->user->id,
            'english',
            [$taggedCard->id],
            [$tag->id],
            'add',
        );
        $query = 'filter=all&tag_ids[]=' . $tag->id;

        $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?' . $query)
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('items.0.lemma', 'parity-tagged');

        $this->actingAs($this->user)
            ->getJson('/review-cards/manage/export?' . $query . '&fields[]=lemma&fields[]=tags')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('items.0.lemma', 'parity-tagged')
            ->assertJsonPath('items.0.tags.0.name', 'Parity');

        $csv = $this->actingAs($this->user)
            ->get('/review-cards/manage/export-csv?' . $query . '&fields[]=lemma&fields[]=tags')
            ->assertOk()
            ->assertHeader('X-Export-Count', '1')
            ->getContent();
        $this->assertStringContainsString('parity-tagged', $csv);
        $this->assertStringContainsString('Parity', $csv);
        $this->assertStringNotContainsString('parity-untagged', $csv);

        $tsv = $this->actingAs($this->user)
            ->get('/review-cards/manage/export-anki-tsv?' . $query)
            ->assertOk()
            ->assertHeader('X-Export-Count', '1')
            ->getContent();
        $this->assertStringContainsString("\tTags\t", explode("\n", $tsv)[0]);
        $this->assertStringContainsString('parity-tagged', $tsv);
        $this->assertStringContainsString("\tParity\t", $tsv);
        $this->assertStringNotContainsString('parity-untagged', $tsv);
    }

    public function test_anki_tsv_uses_anki_space_separated_tag_format(): void
    {
        [$card] = $this->createSenseCard($this->user, 'anki-tag-format');
        $alpha = $this->service->create($this->user->id, 'english', 'Alpha');
        $nested = $this->service->create($this->user->id, 'english', 'Grammar::Verb');
        $spaced = $this->service->create($this->user->id, 'english', 'Needs Attention');
        $zero = $this->service->create($this->user->id, 'english', '0');
        $this->service->applyToReviewCards(
            $this->user->id,
            'english',
            [$card->id],
            [$alpha->id, $nested->id, $spaced->id, $zero->id],
            'add',
        );

        $tsv = $this->actingAs($this->user)
            ->get('/review-cards/manage/export-anki-tsv?filter=all')
            ->assertOk()
            ->assertHeader('X-Export-Count', '1')
            ->getContent();

        $this->assertStringContainsString("\t0 Alpha Grammar::Verb Needs_Attention\t", $tsv);
        $this->assertStringNotContainsString("\tAlpha, Grammar::Verb\t", $tsv);
        $this->assertStringNotContainsString('Needs Attention', $tsv);
    }

    private function createUser(string $email, string $language): User
    {
        return User::forceCreate([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => $language,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    /**
     * @return array{ReviewCard, WordSense}
     */
    private function createSenseCard(User $user, string $lemma): array
    {
        $sense = WordSense::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'noun',
            'sense_zh' => $lemma,
            'sense_en' => $lemma,
            'aliases_zh' => [],
            'collocations' => [],
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', "{$user->id}|english|{$lemma}"),
        ]);

        $card = ReviewCard::forceCreate([
            'user_id' => $user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
        ]);

        return [$card, $sense];
    }

    private function issueMobileToken(User $user): string
    {
        return $this->postJson('/api/v1/mobile/auth/tokens', [
            'email' => $user->email,
            'password' => 'password',
            'device_uuid' => (string) Str::uuid(),
            'platform' => 'android',
            'device_name' => 'M10 Test',
            'app_version' => '1.0.0',
        ])->assertCreated()->json('data.token');
    }
}
