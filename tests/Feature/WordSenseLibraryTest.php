<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WordSenseLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_word_sense_page_requires_authentication_and_returns_spa_shell(): void
    {
        $this->get('/word-senses')->assertRedirect('/login');

        $user = $this->makeUser();

        $this->actingAs($user)
            ->get('/word-senses')
            ->assertOk()
            ->assertViewIs('home');
    }

    public function test_data_returns_only_confirmed_senses_for_current_user_and_selected_language(): void
    {
        $user = $this->makeUser('english');
        $otherUser = $this->makeUser('english');

        $beta = $this->makeSense($user, [
            'lemma' => 'beta',
            'sense_zh' => '贝塔义',
            'aliases_zh' => ['贝塔', 'β'],
            'collocations' => ['beta test', 'beta release'],
        ]);
        $alphaFirst = $this->makeSense($user, [
            'lemma' => 'alpha',
            'sense_zh' => '阿尔法第一义',
        ]);
        $alphaSecond = $this->makeSense($user, [
            'lemma' => 'alpha',
            'sense_zh' => '阿尔法第二义',
        ]);

        $this->makeSense($user, [
            'lemma' => 'suggested',
            'status' => WordSense::STATUS_AI_SUGGESTED,
        ]);
        $this->makeSense($user, [
            'lemma' => 'rejected',
            'status' => WordSense::STATUS_REJECTED,
        ]);
        $this->makeSense($otherUser, [
            'lemma' => 'other-user',
        ]);
        $this->makeSense($user, [
            'lemma' => 'other-language',
            'language' => 'spanish',
            'language_id' => 'spanish',
        ]);

        $response = $this->actingAs($user)->getJson(
            '/word-senses/data?user_id='.$otherUser->id.'&language_id=spanish'
        );

        $response->assertOk();

        $items = $response->json('data');
        $this->assertSame(
            [$alphaFirst->id, $alphaSecond->id, $beta->id],
            array_column($items, 'sense_id'),
        );

        foreach ($items as $item) {
            $keys = array_keys($item);
            sort($keys);
            $this->assertSame(
                ['aliases_zh', 'collocations', 'lemma', 'pos', 'sense_en', 'sense_id', 'sense_zh'],
                $keys,
            );
        }

        $betaItem = $items[2];
        $this->assertSame(['贝塔', 'β'], $betaItem['aliases_zh']);
        $this->assertSame(['beta test', 'beta release'], $betaItem['collocations']);

        $this->assertSame([
            'current_page' => 1,
            'last_page' => 1,
            'per_page' => 20,
            'total' => 3,
        ], $response->json('pagination'));
    }

    public function test_search_matches_all_d05_text_fields_and_escapes_wildcards(): void
    {
        $user = $this->makeUser();

        $lemmaMatch = $this->makeSense($user, [
            'lemma' => 'lemma-hit-token',
            'sense_zh' => '普通中文释义',
            'sense_en' => 'ordinary English meaning',
        ]);
        $zhMatch = $this->makeSense($user, [
            'lemma' => 'zh-entry',
            'sense_zh' => '包含中文命中词',
            'sense_en' => 'ordinary English meaning',
        ]);
        $enMatch = $this->makeSense($user, [
            'lemma' => 'en-entry',
            'sense_zh' => '普通中文释义',
            'sense_en' => 'contains english-hit-token here',
        ]);
        $surfaceMatch = $this->makeSense($user, [
            'lemma' => 'surface-entry',
            'surface_form' => 'surface-only-token',
            'sense_zh' => '普通中文释义',
            'sense_en' => 'ordinary English meaning',
        ]);
        $posMatch = $this->makeSense($user, [
            'lemma' => 'pos-entry',
            'pos' => 'd05-pos-hit-token',
            'sense_zh' => '普通中文释义',
            'sense_en' => 'ordinary English meaning',
        ]);
        $percentMatch = $this->makeSense($user, [
            'lemma' => 'literal-100%-entry',
            'sense_zh' => '百分比',
            'sense_en' => 'literal percent',
        ]);
        $this->makeSense($user, [
            'lemma' => 'literal-100x-entry',
            'sense_zh' => '百分比通配符干扰项',
            'sense_en' => 'percent wildcard decoy',
        ]);
        $underscoreMatch = $this->makeSense($user, [
            'lemma' => 'literal_under_entry',
            'sense_zh' => '下划线',
            'sense_en' => 'literal underscore',
        ]);
        $this->makeSense($user, [
            'lemma' => 'literalXunder_entry',
            'sense_zh' => '下划线通配符干扰项',
            'sense_en' => 'underscore wildcard decoy',
        ]);

        $this->assertSearchIds($user, 'lemma-hit-token', [$lemmaMatch->id]);
        $this->assertSearchIds($user, '中文命中词', [$zhMatch->id]);
        $this->assertSearchIds($user, 'english-hit-token', [$enMatch->id]);
        $this->assertSearchIds($user, 'surface-only-token', [$surfaceMatch->id]);
        $this->assertSearchIds($user, 'd05-pos-hit-token', [$posMatch->id]);
        $this->assertSearchIds($user, '100%', [$percentMatch->id]);
        $this->assertSearchIds($user, 'literal_under', [$underscoreMatch->id]);

        $emptyQuery = $this->actingAs($user)->getJson('/word-senses/data?q=');
        $emptyQuery->assertOk();
        $this->assertCount(9, $emptyQuery->json('data'));
        $this->assertContains($surfaceMatch->id, array_column($emptyQuery->json('data'), 'sense_id'));
        $this->assertContains($posMatch->id, array_column($emptyQuery->json('data'), 'sense_id'));

        $noResult = $this->actingAs($user)->getJson('/word-senses/data?q=missing-search-token');
        $noResult->assertOk()->assertJson(['data' => []]);
    }

    public function test_pagination_metadata_and_limits_are_enforced(): void
    {
        $user = $this->makeUser();
        $this->makeSense($user, ['lemma' => 'alpha']);
        $this->makeSense($user, ['lemma' => 'beta']);
        $gamma = $this->makeSense($user, ['lemma' => 'gamma']);

        $defaultPage = $this->actingAs($user)->getJson('/word-senses/data');
        $defaultPage->assertOk();
        $this->assertSame([
            'current_page' => 1,
            'last_page' => 1,
            'per_page' => 20,
            'total' => 3,
        ], $defaultPage->json('pagination'));

        $secondPage = $this->actingAs($user)->getJson('/word-senses/data?per_page=2&page=2');
        $secondPage->assertOk();
        $this->assertSame([$gamma->id], array_column($secondPage->json('data'), 'sense_id'));
        $this->assertSame([
            'current_page' => 2,
            'last_page' => 2,
            'per_page' => 2,
            'total' => 3,
        ], $secondPage->json('pagination'));

        $outOfRangePage = $this->actingAs($user)->getJson('/word-senses/data?per_page=2&page=999');
        $outOfRangePage->assertOk();
        $this->assertSame([$gamma->id], array_column($outOfRangePage->json('data'), 'sense_id'));
        $this->assertSame([
            'current_page' => 2,
            'last_page' => 2,
            'per_page' => 2,
            'total' => 3,
        ], $outOfRangePage->json('pagination'));

        $this->actingAs($user)
            ->getJson('/word-senses/data?per_page=101')
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    public function test_data_get_does_not_write_word_sense_review_card_or_review_log_state(): void
    {
        $user = $this->makeUser();
        $sense = $this->makeSense($user, [
            'lemma' => 'stable',
            'status' => WordSense::STATUS_CONFIRMED,
        ])->fresh();

        $beforeSenseCount = WordSense::query()->count();
        $beforeReviewCardCount = ReviewCard::query()->count();
        $beforeReviewLogCount = ReviewLog::query()->count();
        $beforeStatus = $sense->status;
        $beforeUpdatedAt = $sense->getRawOriginal('updated_at');

        $this->actingAs($user)
            ->getJson('/word-senses/data?q=stable')
            ->assertOk();

        $this->assertSame($beforeSenseCount, WordSense::query()->count());
        $this->assertSame($beforeReviewCardCount, ReviewCard::query()->count());
        $this->assertSame($beforeReviewLogCount, ReviewLog::query()->count());

        $sense->refresh();
        $this->assertSame($beforeStatus, $sense->status);
        $this->assertSame($beforeUpdatedAt, $sense->getRawOriginal('updated_at'));
    }

    private function makeUser(string $selectedLanguage = 'english'): User
    {
        return User::factory()->create([
            'selected_language' => $selectedLanguage,
        ]);
    }

    private function makeSense(User $user, array $overrides = []): WordSense
    {
        return WordSense::query()->create(array_merge([
            'user_id' => $user->id,
            'language' => $user->selected_language,
            'language_id' => $user->selected_language,
            'lemma' => 'word-'.$user->id.'-'.uniqid(),
            'surface_form' => 'surface',
            'pos' => 'noun',
            'sense_key' => 'sense-'.$user->id.'-'.uniqid(),
            'sense_zh' => '中文释义',
            'sense_en' => 'English meaning',
            'status' => WordSense::STATUS_CONFIRMED,
        ], $overrides));
    }

    private function assertSearchIds(User $user, string $query, array $expectedIds): void
    {
        $response = $this->actingAs($user)->getJson(
            '/word-senses/data?q='.rawurlencode($query)
        );

        $response->assertOk();
        $this->assertSame($expectedIds, array_column($response->json('data'), 'sense_id'));
    }
}
