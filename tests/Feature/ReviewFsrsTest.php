<?php

namespace Tests\Feature;

use App\Models\EncounteredWord;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Services\FsrsSchedulingService;
use App\Services\ReviewCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ReviewFsrsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'FSRS User',
            'email' => 'fsrs@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->otherUser = User::forceCreate([
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        foreach ([
            [$this->user->id, 'english', 'review', 'Reviews', 0],
            [$this->user->id, 'english', 'read_words', 'Reading', 1000],
            [$this->user->id, 'english', 'learn_words', 'New words', 10],
            [$this->user->id, 'spanish', 'review', 'Reviews', 0],
            [$this->otherUser->id, 'english', 'review', 'Reviews', 0],
        ] as $goal) {
            \App\Models\Goal::forceCreate([
                'user_id' => $goal[0],
                'language' => $goal[1],
                'type' => $goal[2],
                'name' => $goal[3],
                'quantity' => $goal[4],
            ]);
        }
    }

    public function test_initialize_cards_creates_only_reviewable_word_cards(): void
    {
        $this->createWord($this->user->id, 'english', -1, 'apple');
        $this->createWord($this->user->id, 'english', -3, 'banana');
        $this->createWord($this->user->id, 'english', 1, 'ignored');
        $this->createWord($this->user->id, 'english', 0, 'known');
        $this->createWord($this->user->id, 'english', 2, 'new');
        $this->createWord($this->user->id, 'spanish', -1, 'manzana');
        $this->createWord($this->otherUser->id, 'english', -1, 'orange');

        $this->artisan('reviews:initialize-cards --dry-run')
            ->expectsOutput('Dry run: 4 review cards would be created.')
            ->assertSuccessful();
        $this->assertSame(0, ReviewCard::count());

        $this->artisan('reviews:initialize-cards')
            ->expectsOutput('Created 4 review cards.')
            ->assertSuccessful();

        $this->assertSame(4, ReviewCard::count());
        $this->assertSame(2, ReviewCard::where('user_id', $this->user->id)->where('language', 'english')->count());
        $this->assertSame(1, ReviewCard::where('user_id', $this->user->id)->where('language', 'spanish')->count());
        $this->assertSame(1, ReviewCard::where('user_id', $this->otherUser->id)->where('language', 'english')->count());

        $this->artisan('reviews:initialize-cards --dry-run')
            ->expectsOutput('Dry run: 0 review cards would be created.')
            ->assertSuccessful();
    }

    public function test_review_queue_returns_only_due_enabled_cards_for_current_user_and_language(): void
    {
        $dueWord = $this->createWord($this->user->id, 'english', -1, 'apple');
        $disabledWord = $this->createWord($this->user->id, 'english', -1, 'banana');
        $futureWord = $this->createWord($this->user->id, 'english', -1, 'cherry');
        $this->createWord($this->user->id, 'english', 1, 'ignored');
        $this->createWord($this->user->id, 'english', 0, 'known');
        $this->createWord($this->user->id, 'english', 2, 'new');
        $this->createWord($this->user->id, 'spanish', -1, 'manzana');
        $this->createWord($this->otherUser->id, 'english', -1, 'orange');

        $service = app(ReviewCardService::class);
        $dueCard = $service->ensureWordCard($dueWord);
        $disabledCard = $service->ensureWordCard($disabledWord);
        $futureCard = $service->ensureWordCard($futureWord);

        $disabledCard->update(['fsrs_enabled' => false]);
        $futureCard->update(['fsrs_due_at' => now()->addDays(2)]);
        $service->initializeExistingWords();

        $response = $this->actingAs($this->user)->post('/reviews', [
            'bookId' => -1,
            'chapterId' => -1,
            'practiceMode' => false,
        ]);

        $response->assertOk();
        $reviews = $response->json('reviews');
        // 日常复习只保留 sense card，孤立 word card 不再进入复习
        $this->assertCount(0, $reviews, 'Word cards should no longer appear in review queue');
    }

    public function test_each_rating_updates_card_and_writes_log(): void
    {
        foreach (['again', 'hard', 'good', 'easy'] as $rating) {
            ReviewLog::query()->delete();
            ReviewCard::query()->delete();
            EncounteredWord::query()->delete();

            $word = $this->createWord($this->user->id, 'english', -1, "word-{$rating}");
            $card = app(ReviewCardService::class)->ensureWordCard($word);

            $response = $this->actingAs($this->user)->post('/reviews/rate', [
                'reviewCardId' => $card->id,
                'rating' => $rating,
            ]);

            $response->assertOk();
            $card->refresh();
            $this->assertSame(1, $card->fsrs_reps);
            $this->assertNotNull($card->fsrs_due_at);
            $this->assertNotNull($card->fsrs_last_reviewed_at);
            $this->assertNotNull($card->fsrs_stability);
            $this->assertNotNull($card->fsrs_difficulty);
            $this->assertSame($rating === 'again' ? 1 : 0, $card->fsrs_lapses);

            $log = ReviewLog::first();
            $this->assertNotNull($log);
            $this->assertSame($this->user->id, $log->user_id);
            $this->assertSame('english', $log->language);
            $this->assertSame($card->id, $log->review_card_id);
            $this->assertSame($rating, $log->rating);
            $this->assertSame('new', $log->previous_state);
            $this->assertSame($card->fsrs_state, $log->new_state);
            $this->assertNotNull($log->previous_due_at);
            $this->assertNotNull($log->new_due_at);
            $this->assertNotNull($log->new_stability);
            $this->assertNotNull($log->new_difficulty);
        }
    }

    public function test_rating_cannot_cross_user_or_language(): void
    {
        $otherWord = $this->createWord($this->otherUser->id, 'english', -1, 'orange');
        $otherCard = app(ReviewCardService::class)->ensureWordCard($otherWord);

        $this->actingAs($this->user)->post('/reviews/rate', [
            'reviewCardId' => $otherCard->id,
            'rating' => 'good',
        ])->assertStatus(500);

        $spanishWord = $this->createWord($this->user->id, 'spanish', -1, 'manzana');
        $spanishCard = app(ReviewCardService::class)->ensureWordCard($spanishWord);

        $this->actingAs($this->user)->post('/reviews/rate', [
            'reviewCardId' => $spanishCard->id,
            'rating' => 'good',
        ])->assertStatus(500);

        $this->assertSame(0, ReviewLog::count());
    }

    public function test_vocabulary_export_still_uses_existing_search_path(): void
    {
        $this->createWord($this->user->id, 'english', -1, 'apple');

        $csv = app(\App\Services\VocabularyService::class)->exportToCsv(
            $this->user->id,
            'english',
            'anytext',
            -1,
            -1,
            -999,
            'only words',
            'words',
            'any',
            [
                ['export' => true, 'headerName' => 'Word', 'searchObjectProperty' => 'word'],
                ['export' => true, 'headerName' => 'Translation', 'searchObjectProperty' => 'translation'],
            ],
            []
        );

        $content = $csv->toString();
        $this->assertStringContainsString('Word|Translation', $content);
        $this->assertStringContainsString('apple|"apple translation"', $content);
    }

    // ==================== Sense card tests ====================

    public function test_review_queue_returns_due_sense_cards_in_global_mode(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'test', 'noun', '测试', 'test');
        $card = app(ReviewCardService::class)->ensureSenseCard($sense);

        $response = $this->actingAs($this->user)->post('/reviews', [
            'bookId' => -1,
            'chapterId' => -1,
            'practiceMode' => false,
        ]);

        $response->assertOk();
        $reviews = $response->json('reviews');
        $this->assertCount(1, $reviews);
        $this->assertSame('sense', $reviews[0]['type']);
        $this->assertSame($card->id, $reviews[0]['review_card_id']);
        $this->assertSame('test', $reviews[0]['lemma']);
        $this->assertSame('测试', $reviews[0]['sense_zh']);
        $this->assertSame('test', $reviews[0]['sense_en']);
        $this->assertArrayHasKey('example_sentence_en', $reviews[0]);
    }

    public function test_review_queue_returns_word_and_sense_cards_mixed(): void
    {
        $dueWord = $this->createWord($this->user->id, 'english', -1, 'apple');
        app(ReviewCardService::class)->ensureWordCard($dueWord);

        $sense = $this->createSense($this->user->id, 'english', 'test', 'noun', '测试', 'test');
        app(ReviewCardService::class)->ensureSenseCard($sense);

        $response = $this->actingAs($this->user)->post('/reviews', [
            'bookId' => -1,
            'chapterId' => -1,
            'practiceMode' => false,
        ]);

        $response->assertOk();
        $reviews = $response->json('reviews');
        // 日常复习只保留 sense card：word card 不再进入队列
        $this->assertCount(1, $reviews);

        $types = array_column($reviews, 'type');
        $this->assertContains('sense', $types);
        $this->assertNotContains('word', $types, 'Word cards should not appear in review queue');
        $this->assertSame($sense->id, $reviews[0]['word_sense_id']);
    }

    public function test_review_queue_does_not_return_sense_in_chapter_mode(): void
    {
        // Create a due sense card
        $sense = $this->createSense($this->user->id, 'english', 'chapterexcluded', 'noun', '排除测试', 'excluded');
        app(ReviewCardService::class)->ensureSenseCard($sense);

        // Verify the sense card appears in global mode first
        $globalResponse = $this->actingAs($this->user)->post('/reviews', [
            'bookId' => -1,
            'chapterId' => -1,
            'practiceMode' => false,
        ]);
        $globalReviews = $globalResponse->json('reviews');
        $globalTypes = array_column($globalReviews, 'type');
        $this->assertContains('sense', $globalTypes);

        // bookId/chapterId 限定模式第一版不支持 sense 过滤，返回空队列
        $chapterResponse = $this->actingAs($this->user)->post('/reviews', [
            'bookId' => 1,
            'chapterId' => -1,
            'practiceMode' => false,
        ]);
        $chapterResponse->assertOk();
        $this->assertEmpty($chapterResponse->json('reviews'), 'Chapter mode should return empty queue');
    }

    public function test_review_queue_filters_out_archived_sense(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'archived', 'noun', '归档测试', 'archived test');
        $sense->status = WordSense::STATUS_REJECTED;
        $sense->save();

        // ensureSenseCard won't create a card for rejected sense
        // (isReviewableSense checks for confirmed status)
        $card = app(ReviewCardService::class)->ensureSenseCard($sense);
        $this->assertNull($card);

        $response = $this->actingAs($this->user)->post('/reviews', [
            'bookId' => -1,
            'chapterId' => -1,
            'practiceMode' => false,
        ]);

        $response->assertOk();
        $reviews = $response->json('reviews');
        foreach ($reviews as $review) {
            $this->assertNotSame('archived', $review['lemma'] ?? null);
        }
    }

    public function test_review_queue_filters_out_other_user_sense(): void
    {
        $sense = $this->createSense($this->otherUser->id, 'english', 'other', 'noun', '他人测试', 'other test');
        $card = app(ReviewCardService::class)->ensureSenseCard($sense);
        $this->assertNotNull($card);

        $response = $this->actingAs($this->user)->post('/reviews', [
            'bookId' => -1,
            'chapterId' => -1,
            'practiceMode' => false,
        ]);

        $response->assertOk();
        $reviews = $response->json('reviews');
        foreach ($reviews as $review) {
            $this->assertNotSame('other', $review['lemma'] ?? null);
        }
    }

    public function test_review_queue_filters_out_future_sense(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'future', 'noun', '未来测试', 'future test');
        $card = app(ReviewCardService::class)->ensureSenseCard($sense);
        // Push due date to the future
        $card->update(['fsrs_due_at' => now()->addDays(7)]);

        $response = $this->actingAs($this->user)->post('/reviews', [
            'bookId' => -1,
            'chapterId' => -1,
            'practiceMode' => false,
        ]);

        $response->assertOk();
        $reviews = $response->json('reviews');
        foreach ($reviews as $review) {
            $this->assertNotSame('future', $review['lemma'] ?? null);
        }
    }

    public function test_rate_sense_card_updates_fsrs_and_writes_log(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'ratesense', 'noun', '评分测试', 'rate test');
        $card = app(ReviewCardService::class)->ensureSenseCard($sense);

        $response = $this->actingAs($this->user)->post('/reviews/rate', [
            'reviewCardId' => $card->id,
            'rating' => 'good',
        ]);

        $response->assertOk();
        $card->refresh();
        $this->assertSame(1, $card->fsrs_reps);
        $this->assertNotNull($card->fsrs_due_at);
        $this->assertNotNull($card->fsrs_last_reviewed_at);

        $log = ReviewLog::where('review_card_id', $card->id)->first();
        $this->assertNotNull($log);
        $this->assertSame($this->user->id, $log->user_id);
        $this->assertSame('english', $log->language);
        $this->assertSame('good', $log->rating);
    }

    public function test_rate_sense_card_cannot_cross_user(): void
    {
        $sense = $this->createSense($this->otherUser->id, 'english', 'crossuser', 'noun', '跨用户测试', 'cross user test');
        $card = app(ReviewCardService::class)->ensureSenseCard($sense);

        $this->actingAs($this->user)->post('/reviews/rate', [
            'reviewCardId' => $card->id,
            'rating' => 'good',
        ])->assertStatus(500);

        // Card should NOT be updated by the wrong user
        $card->refresh();
        $this->assertSame(0, $card->fsrs_reps);

        // No log should be written for this user
        $this->assertSame(0, ReviewLog::where('user_id', $this->user->id)->count());
    }

    public function test_sense_card_payload_matches_serialize_card(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'payload', 'verb', '载荷测试', 'payload test');
        $sense->example_sentence_en = 'This is a test sentence.';
        $sense->example_sentence_zh = '这是一个测试句。';
        $sense->save();

        $card = app(ReviewCardService::class)->ensureSenseCard($sense);

        $serialized = app(\App\Services\SenseReviewService::class)->serializeCard($card);

        // Verify key fields match between serializeCard and what the API returns
        $response = $this->actingAs($this->user)->post('/reviews', [
            'bookId' => -1,
            'chapterId' => -1,
            'practiceMode' => false,
        ]);

        $response->assertOk();
        $reviews = $response->json('reviews');
        $this->assertCount(1, $reviews);

        $apiCard = $reviews[0];
        $this->assertSame($serialized['review_card_id'], $apiCard['review_card_id']);
        $this->assertSame($serialized['lemma'], $apiCard['lemma']);
        $this->assertSame($serialized['sense_zh'], $apiCard['sense_zh']);
        $this->assertSame($serialized['sense_en'], $apiCard['sense_en']);
        $this->assertSame($serialized['example_sentence_en'], $apiCard['example_sentence_en']);
        $this->assertSame($serialized['example_sentence_zh'], $apiCard['example_sentence_zh']);
        $this->assertSame('sense', $apiCard['type']);
    }

    public function test_word_card_not_in_queue_even_when_due(): void
    {
        $dueWord = $this->createWord($this->user->id, 'english', -1, 'apple');
        $wordCard = app(ReviewCardService::class)->ensureWordCard($dueWord);

        $response = $this->actingAs($this->user)->post('/reviews', [
            'bookId' => -1,
            'chapterId' => -1,
            'practiceMode' => false,
        ]);

        $response->assertOk();
        $reviews = $response->json('reviews');
        // word card must not appear — sense-only queue
        $this->assertCount(0, $reviews, 'Due word card should not enter sense-only review queue');
        $this->assertNotNull($wordCard->fresh(), 'Word card should still exist in database');
    }

    public function test_old_word_card_not_deleted_after_sense_only_switch(): void
    {
        $dueWord = $this->createWord($this->user->id, 'english', -1, 'oldword');
        $wordCard = app(ReviewCardService::class)->ensureWordCard($dueWord);

        // Trigger review queue (sense-only)
        $this->actingAs($this->user)->post('/reviews', [
            'bookId' => -1,
            'chapterId' => -1,
            'practiceMode' => false,
        ])->assertOk();

        // Word card must still exist — not deleted
        $this->assertDatabaseHas('review_cards', [
            'id' => $wordCard->id,
            'target_type' => ReviewCard::TARGET_WORD,
            'target_id' => $dueWord->id,
        ]);
    }

    public function test_reviews_senses_endpoint_still_accessible(): void
    {
        $this->actingAs($this->user)->get('/reviews/senses')->assertOk();
    }

    public function test_sense_review_payload_includes_sentence_tokens_from_occurrence(): void
    {
        // Create chapter with processed_text
        $chapter = \App\Models\Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => 1,
            'name' => 'Test Chapter',
            'read_count' => 0,
            'word_count' => 50,
            'language' => 'english',
            'raw_text' => 'U.S. retail sales increased. Other text.',
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'subtitle_timestamps' => '[]',
            'type' => 'text',
            'processing_status' => 'processed',
            'processed_text' => gzcompress(json_encode((object) [
                'words' => [
                    (object) ['word' => 'U.S.', 'stage' => -7, 'spaceAfter' => true, 'sentence_index' => 0],
                    (object) ['word' => 'retail', 'stage' => -7, 'spaceAfter' => true, 'sentence_index' => 0],
                    (object) ['word' => 'sales', 'stage' => 2, 'spaceAfter' => true, 'sentence_index' => 0],
                    (object) ['word' => 'increased', 'stage' => 2, 'spaceAfter' => false, 'sentence_index' => 0],
                    (object) ['word' => '.', 'stage' => 2, 'spaceAfter' => true, 'sentence_index' => 0],
                    (object) ['word' => 'Other', 'stage' => 2, 'spaceAfter' => true, 'sentence_index' => 1],
                ],
                'phrases' => [],
                'uniqueWords' => [],
            ]), 1),
        ]);

        // Create confirmed WordSense
        $sense = $this->createSense($this->user->id, 'english', 'retail', 'noun', '零售', 'retail');
        $sense->example_sentence_en = 'U.S. retail sales increased.';
        $sense->save();

        // Create WordSenseOccurrence
        \App\Models\WordSenseOccurrence::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'chapter_id' => $chapter->id,
            'sentence_id' => '0',
            'sentence_en' => 'U.S. retail sales increased.',
            'surface' => 'retail',
            'lemma' => 'retail',
            'type' => \App\Models\WordSenseOccurrence::TYPE_WORD,
            'pos' => 'noun',
            'decision' => \App\Models\WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'confidence' => 1.0,
            'evidence' => ['source' => 'test'],
            'auto_fsrs_allowed' => true,
            'status' => \App\Models\WordSenseOccurrence::STATUS_BOUND,
            'source' => \App\Models\WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'raw_payload' => [],
        ]);

        app(\App\Services\ReviewCardService::class)->ensureSenseCard($sense);

        $response = $this->actingAs($this->user)->post('/reviews', [
            'bookId' => -1,
            'chapterId' => -1,
            'practiceMode' => false,
        ]);

        $response->assertOk();
        $reviews = $response->json('reviews');
        $this->assertCount(1, $reviews);
        $this->assertSame('sense', $reviews[0]['type']);

        // Tokens should exist
        $this->assertNotNull($reviews[0]['example_sentence_tokens'], 'Tokens should not be null');
        $this->assertNotEmpty($reviews[0]['example_sentence_tokens'], 'Tokens should not be empty');

        $tokens = $reviews[0]['example_sentence_tokens'];

        // Should only contain sentence_index=0 words (not "Other" at sentence_index=1)
        $tokenWords = array_column($tokens, 'word');
        $this->assertContains('retail', $tokenWords);
        $this->assertContains('U.S.', $tokenWords);
        $this->assertContains('sales', $tokenWords);
        $this->assertNotContains('Other', $tokenWords, 'Tokens from other sentences should not appear');

        // Verify token structure
        foreach ($tokens as $token) {
            $this->assertArrayHasKey('word', $token);
            $this->assertArrayHasKey('stage', $token);
            $this->assertArrayHasKey('spaceAfter', $token);
            $this->assertArrayHasKey('is_structure', $token);
            $this->assertArrayHasKey('sentence_index', $token);
            $this->assertArrayHasKey('wordIndex', $token);
        }

        // Verify source
        $this->assertSame('occurrence', $reviews[0]['example_sentence_token_source']);
    }

    public function test_sense_review_payload_builds_synthetic_tokens_without_source(): void
    {
        // Create sense without occurrence / source_chapter_id, but with example_sentence_en
        $sense = $this->createSense($this->user->id, 'english', 'retail', 'noun', '零售', 'retail');
        $sense->example_sentence_en = 'U.S. retail sales increased.';
        $sense->save();

        app(\App\Services\ReviewCardService::class)->ensureSenseCard($sense);

        $response = $this->actingAs($this->user)->post('/reviews', [
            'bookId' => -1,
            'chapterId' => -1,
            'practiceMode' => false,
        ]);

        $response->assertOk();
        $reviews = $response->json('reviews');
        $this->assertCount(1, $reviews);

        // Tokens should exist (synthetic fallback)
        $this->assertNotNull($reviews[0]['example_sentence_tokens'], 'Tokens should not be null with example_sentence_en');
        $this->assertNotEmpty($reviews[0]['example_sentence_tokens'], 'Tokens should not be empty');
        $this->assertSame('synthetic', $reviews[0]['example_sentence_token_source']);

        $tokens = $reviews[0]['example_sentence_tokens'];

        // Check token words include original sentence words
        $tokenWords = array_column($tokens, 'word');
        $this->assertContains('U.S', $tokenWords);
        $this->assertContains('retail', $tokenWords);
        $this->assertContains('sales', $tokenWords);

        // Target word 'retail' should be marked as target with stage=-7
        $targetTokens = array_filter($tokens, fn ($t) => ($t['word'] ?? '') === 'retail');
        $this->assertNotEmpty($targetTokens, 'retail token should exist');
        $targetToken = array_values($targetTokens)[0];
        $this->assertTrue($targetToken['is_target'], 'retail should be marked is_target=true');
        $this->assertSame(-7, $targetToken['stage'], 'target word should have stage=-7');

        // example_sentence_en should still be returned
        $this->assertSame('U.S. retail sales increased.', $reviews[0]['example_sentence_en']);
    }

    public function test_sense_review_sentence_tokens_do_not_cross_user_scope(): void
    {
        // Create otherUser's chapter with a sentence
        $otherChapter = \App\Models\Chapter::forceCreate([
            'user_id' => $this->otherUser->id,
            'book_id' => 1,
            'name' => 'Other Chapter',
            'read_count' => 0,
            'word_count' => 20,
            'language' => 'english',
            'raw_text' => 'Other user secret text.',
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'subtitle_timestamps' => '[]',
            'type' => 'text',
            'processing_status' => 'processed',
            'processed_text' => gzcompress(json_encode((object) [
                'words' => [
                    (object) ['word' => 'Other', 'stage' => 2, 'spaceAfter' => true, 'sentence_index' => 0],
                    (object) ['word' => 'user', 'stage' => 2, 'spaceAfter' => true, 'sentence_index' => 0],
                    (object) ['word' => 'secret', 'stage' => -7, 'spaceAfter' => true, 'sentence_index' => 0],
                    (object) ['word' => 'text', 'stage' => 2, 'spaceAfter' => false, 'sentence_index' => 0],
                ],
                'phrases' => [],
                'uniqueWords' => [],
            ]), 1),
        ]);

        // Create current user's sense with occurrence pointing to other user's chapter
        $sense = $this->createSense($this->user->id, 'english', 'crossscope', 'noun', '跨域测试', 'cross scope test');
        $sense->example_sentence_en = 'This should not leak.';
        $sense->save();

        \App\Models\WordSenseOccurrence::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'chapter_id' => $otherChapter->id, // belongs to otherUser!
            'sentence_id' => '0',
            'sentence_en' => 'Other user secret text.',
            'surface' => 'secret',
            'lemma' => 'secret',
            'type' => \App\Models\WordSenseOccurrence::TYPE_WORD,
            'pos' => 'noun',
            'decision' => \App\Models\WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'confidence' => 1.0,
            'evidence' => ['source' => 'test'],
            'auto_fsrs_allowed' => true,
            'status' => \App\Models\WordSenseOccurrence::STATUS_BOUND,
            'source' => \App\Models\WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'raw_payload' => [],
        ]);

        app(\App\Services\ReviewCardService::class)->ensureSenseCard($sense);

        $response = $this->actingAs($this->user)->post('/reviews', [
            'bookId' => -1,
            'chapterId' => -1,
            'practiceMode' => false,
        ]);

        $response->assertOk();
        $reviews = $response->json('reviews');
        $this->assertCount(1, $reviews);

        // Chapter lookup filters by user_id — real tokens must NOT leak
        // But synthetic fallback still generates tokens from example_sentence_en
        $this->assertNotNull($reviews[0]['example_sentence_tokens'], 'Synthetic tokens should still exist');
        // Source must NOT be 'occurrence' (real tokens from other user's chapter did not leak)
        $this->assertNotSame('occurrence', $reviews[0]['example_sentence_token_source'],
            'Real occurrence tokens must not leak from other user chapter');
        // Source should be synthetic since real chapter lookup failed
        $this->assertSame('synthetic', $reviews[0]['example_sentence_token_source']);
        $this->assertSame('This should not leak.', $reviews[0]['example_sentence_en']);
    }

    public function test_sense_review_payload_extracts_tokens_from_nested_processed_text(): void
    {
        // Create chapter with nested processed_text (not simple words at top level)
        $chapter = \App\Models\Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => 1,
            'name' => 'Nested Chapter',
            'read_count' => 0,
            'word_count' => 50,
            'language' => 'english',
            'raw_text' => 'Sure enough the Census Bureau released.',
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'subtitle_timestamps' => '[]',
            'type' => 'text',
            'processing_status' => 'processed',
            'processed_text' => gzcompress(json_encode((object) [
                'blocks' => [
                    (object) [
                        'words' => [
                            (object) ['word' => 'Sure', 'stage' => 2, 'spaceAfter' => true, 'sentence_index' => 0],
                            (object) ['word' => 'enough', 'stage' => 2, 'spaceAfter' => false, 'sentence_index' => 0],
                            (object) ['word' => ',', 'stage' => 2, 'spaceAfter' => true, 'sentence_index' => 0],
                            (object) ['word' => 'the', 'stage' => 2, 'spaceAfter' => true, 'sentence_index' => 0],
                            (object) ['word' => 'Census', 'stage' => 2, 'spaceAfter' => true, 'sentence_index' => 0],
                            (object) ['word' => 'Bureau', 'stage' => -7, 'spaceAfter' => true, 'sentence_index' => 0],
                            (object) ['word' => 'released', 'stage' => 2, 'spaceAfter' => false, 'sentence_index' => 0],
                            (object) ['word' => '.', 'stage' => 2, 'spaceAfter' => true, 'sentence_index' => 0],
                        ],
                    ],
                ],
            ]), 1),
        ]);

        // Create confirmed WordSense
        $sense = $this->createSense($this->user->id, 'english', 'Bureau', 'noun', '局', 'Bureau');
        $sense->example_sentence_en = 'Sure enough, the Census Bureau released.';
        $sense->source_chapter_id = $chapter->id;
        $sense->sentence_id = '0';
        $sense->save();

        app(\App\Services\ReviewCardService::class)->ensureSenseCard($sense);

        $response = $this->actingAs($this->user)->post('/reviews', [
            'bookId' => -1,
            'chapterId' => -1,
            'practiceMode' => false,
        ]);

        $response->assertOk();
        $reviews = $response->json('reviews');
        $this->assertCount(1, $reviews);

        // Tokens should be extracted despite nested structure
        $this->assertNotNull($reviews[0]['example_sentence_tokens'], 'Tokens should be extracted from nested processed_text');
        $this->assertNotEmpty($reviews[0]['example_sentence_tokens']);

        $tokens = $reviews[0]['example_sentence_tokens'];
        $tokenWords = array_column($tokens, 'word');
        $this->assertContains('Bureau', $tokenWords, 'Bureau token must be found');

        // Find Bureau token and verify stage=-7
        foreach ($tokens as $token) {
            if ($token['word'] === 'Bureau') {
                $this->assertSame(-7, $token['stage'], 'Bureau should have stage=-7');
                break;
            }
        }
    }

    public function test_sense_review_payload_can_match_sentence_by_example_text(): void
    {
        // Create chapter with processed_text
        $chapter = \App\Models\Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => 1,
            'name' => 'Match Chapter',
            'read_count' => 0,
            'word_count' => 30,
            'language' => 'english',
            'raw_text' => 'Hello world. Another sentence here.',
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'subtitle_timestamps' => '[]',
            'type' => 'text',
            'processing_status' => 'processed',
            'processed_text' => gzcompress(json_encode((object) [
                'words' => [
                    (object) ['word' => 'Hello', 'stage' => -7, 'spaceAfter' => true, 'sentence_index' => 0],
                    (object) ['word' => 'world', 'stage' => 2, 'spaceAfter' => false, 'sentence_index' => 0],
                    (object) ['word' => '.', 'stage' => 2, 'spaceAfter' => true, 'sentence_index' => 0],
                    (object) ['word' => 'Another', 'stage' => 2, 'spaceAfter' => true, 'sentence_index' => 1],
                    (object) ['word' => 'sentence', 'stage' => 2, 'spaceAfter' => true, 'sentence_index' => 1],
                    (object) ['word' => 'here', 'stage' => 2, 'spaceAfter' => false, 'sentence_index' => 1],
                    (object) ['word' => '.', 'stage' => 2, 'spaceAfter' => true, 'sentence_index' => 1],
                ],
                'phrases' => [],
                'uniqueWords' => [],
            ]), 1),
        ]);

        // Create sense — occurrence has chapter_id but sentence_id='99' which won't match any token
        $sense = $this->createSense($this->user->id, 'english', 'world', 'noun', '世界', 'world');
        $sense->example_sentence_en = 'Hello world.';
        $sense->save();

        // Occurrence has a non-matching sentence_id
        \App\Models\WordSenseOccurrence::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'chapter_id' => $chapter->id,
            'sentence_id' => '99', // won't match any token
            'sentence_en' => 'Hello world.',
            'surface' => 'world',
            'lemma' => 'world',
            'type' => \App\Models\WordSenseOccurrence::TYPE_WORD,
            'pos' => 'noun',
            'decision' => \App\Models\WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'confidence' => 1.0,
            'evidence' => ['source' => 'test'],
            'auto_fsrs_allowed' => true,
            'status' => \App\Models\WordSenseOccurrence::STATUS_BOUND,
            'source' => \App\Models\WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'raw_payload' => [],
        ]);

        app(\App\Services\ReviewCardService::class)->ensureSenseCard($sense);

        $response = $this->actingAs($this->user)->post('/reviews', [
            'bookId' => -1,
            'chapterId' => -1,
            'practiceMode' => false,
        ]);

        $response->assertOk();
        $reviews = $response->json('reviews');
        $this->assertCount(1, $reviews);

        // Layer 1 should fail (sentence_id=99 matches nothing)
        // Layer 2 should succeed via text match
        $this->assertSame('sentence_text_match', $reviews[0]['example_sentence_token_source']);
        $this->assertNotNull($reviews[0]['example_sentence_tokens']);
        $this->assertNotEmpty($reviews[0]['example_sentence_tokens']);

        $tokens = $reviews[0]['example_sentence_tokens'];
        $tokenWords = array_column($tokens, 'word');
        $this->assertContains('Hello', $tokenWords);
        $this->assertContains('world', $tokenWords);
        $this->assertNotContains('Another', $tokenWords, 'Tokens from other sentences should not appear');
    }

    // ==================== No-example-sentence edge case ====================

    public function test_sense_review_payload_returns_null_tokens_only_when_no_example_sentence(): void
    {
        // Sense with no example_sentence_en at all
        $sense = $this->createSense($this->user->id, 'english', 'noexample', 'noun', '无例句', 'no example');
        // example_sentence_en is null (default from createSense)

        app(\App\Services\ReviewCardService::class)->ensureSenseCard($sense);

        $response = $this->actingAs($this->user)->post('/reviews', [
            'bookId' => -1,
            'chapterId' => -1,
            'practiceMode' => false,
        ]);

        $response->assertOk();
        $reviews = $response->json('reviews');
        $this->assertCount(1, $reviews);

        // Only truly null when there is NO example_sentence_en
        $this->assertNull($reviews[0]['example_sentence_tokens']);
        $this->assertNull($reviews[0]['example_sentence_token_source']);
    }

    // ==================== Review card management integration ====================

    public function test_review_queue_returns_only_target_type_sense(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'reviewlemma', 'noun', '复习', 'review');
        app(\App\Services\ReviewCardService::class)->ensureSenseCard($sense);

        $response = $this->actingAs($this->user)->post('/reviews', [
            'bookId' => -1, 'chapterId' => -1, 'practiceMode' => false,
        ]);

        $response->assertOk();
        $reviews = $response->json('reviews');
        $types = array_column($reviews, 'type');
        $this->assertContains('sense', $types);
    }

    public function test_review_queue_excludes_target_type_word(): void
    {
        $word = $this->createWord($this->user->id, 'english', -1, 'wordcard');
        $sense = $this->createSense($this->user->id, 'english', 'sensecard', 'noun', '义卡', 'sense');
        app(\App\Services\ReviewCardService::class)->ensureSenseCard($sense);

        $response = $this->actingAs($this->user)->post('/reviews', [
            'bookId' => -1, 'chapterId' => -1, 'practiceMode' => false,
        ]);

        $response->assertOk();
        $reviews = $response->json('reviews');
        $types = array_column($reviews, 'type');
        $this->assertNotContains('word', $types);
    }

    public function test_disabled_sense_card_does_not_appear_in_review(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'disabledsense', 'noun', '禁用', 'disabled');
        $card = app(\App\Services\ReviewCardService::class)->ensureSenseCard($sense);
        $card->update(['fsrs_enabled' => false, 'fsrs_due_at' => now()->subHour()]);

        $response = $this->actingAs($this->user)->post('/reviews', [
            'bookId' => -1, 'chapterId' => -1, 'practiceMode' => false,
        ]);

        $response->assertOk();
        $reviews = $response->json('reviews');
        $this->assertCount(0, $reviews);
    }

    public function test_enabled_and_due_sense_card_appears_in_review(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'duesense', 'noun', '到期', 'due');
        $card = app(\App\Services\ReviewCardService::class)->ensureSenseCard($sense);
        $card->update(['fsrs_enabled' => true, 'fsrs_due_at' => now()->subHour()]);

        $response = $this->actingAs($this->user)->post('/reviews', [
            'bookId' => -1, 'chapterId' => -1, 'practiceMode' => false,
        ]);

        $response->assertOk();
        $reviews = $response->json('reviews');
        $this->assertCount(1, $reviews);
        $this->assertSame('sense', $reviews[0]['type']);
    }

    public function test_normal_edit_sense_zh_reflected_in_review(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'editsense', 'noun', '原释义', 'old def');
        $card = app(\App\Services\ReviewCardService::class)->ensureSenseCard($sense);
        $card->update(['fsrs_enabled' => true, 'fsrs_due_at' => now()->subHour()]);

        // Edit sense_zh via manage controller
        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", ['sense_zh' => '新释义123']);

        $response = $this->actingAs($this->user)->post('/reviews', [
            'bookId' => -1, 'chapterId' => -1, 'practiceMode' => false,
        ]);

        $response->assertOk();
        $reviews = $response->json('reviews');
        $this->assertSame('新释义123', $reviews[0]['sense_zh']);
    }

    public function test_normal_edit_sense_en_reflected_in_review(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'editen', 'noun', '释义', 'old english');
        $card = app(\App\Services\ReviewCardService::class)->ensureSenseCard($sense);
        $card->update(['fsrs_enabled' => true, 'fsrs_due_at' => now()->subHour()]);

        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", ['sense_en' => 'new english def']);

        $response = $this->actingAs($this->user)->post('/reviews', [
            'bookId' => -1, 'chapterId' => -1, 'practiceMode' => false,
        ]);

        $reviews = $response->json('reviews');
        $this->assertSame('new english def', $reviews[0]['sense_en']);
    }

    public function test_normal_edit_example_sentence_en_reflected_in_review(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'editex', 'noun', '例句', 'example');
        $sense->update(['example_sentence_en' => 'Old sentence.']);
        $card = app(\App\Services\ReviewCardService::class)->ensureSenseCard($sense);
        $card->update(['fsrs_enabled' => true, 'fsrs_due_at' => now()->subHour()]);

        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", ['example_sentence_en' => 'New review sentence.']);

        $response = $this->actingAs($this->user)->post('/reviews', [
            'bookId' => -1, 'chapterId' => -1, 'practiceMode' => false,
        ]);

        $reviews = $response->json('reviews');
        $this->assertSame('New review sentence.', $reviews[0]['example_sentence_en']);
    }

    public function test_normal_edit_does_not_rebuild_card(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'norebuild', 'noun', '无重建', 'no rebuild');
        $card = app(\App\Services\ReviewCardService::class)->ensureSenseCard($sense);
        $oldCardCount = ReviewCard::count();

        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", ['sense_zh' => '不重建']);

        $this->assertSame($oldCardCount, ReviewCard::count());
    }

    public function test_normal_edit_does_not_add_review_logs(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'nologs', 'noun', '无日志', 'no logs');
        $card = app(\App\Services\ReviewCardService::class)->ensureSenseCard($sense);
        $oldLogCount = ReviewLog::count();

        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", ['sense_zh' => '无日志修改']);

        $this->assertSame($oldLogCount, ReviewLog::count());
    }

    public function test_due_now_enabled_card_appears_in_review(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'duenowenabled', 'noun', '立即到期启用', 'due now');
        $card = app(\App\Services\ReviewCardService::class)->ensureSenseCard($sense);
        // Card is enabled by default, but due in future
        $card->update(['fsrs_enabled' => true, 'fsrs_due_at' => now()->addDays(7)]);

        $this->actingAs($this->user)->post("/review-cards/manage/{$card->id}/due-now");

        $response = $this->actingAs($this->user)->post('/reviews', [
            'bookId' => -1, 'chapterId' => -1, 'practiceMode' => false,
        ]);

        $reviews = $response->json('reviews');
        $this->assertCount(1, $reviews);
        $this->assertSame('sense', $reviews[0]['type']);
    }

    public function test_due_now_disabled_card_still_not_in_review(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'duenowdisabled', 'noun', '到期禁用', 'due now dis');
        $card = app(\App\Services\ReviewCardService::class)->ensureSenseCard($sense);
        $card->update(['fsrs_enabled' => false, 'fsrs_due_at' => now()->addDays(7)]);

        $this->actingAs($this->user)->post("/review-cards/manage/{$card->id}/due-now");

        $response = $this->actingAs($this->user)->post('/reviews', [
            'bookId' => -1, 'chapterId' => -1, 'practiceMode' => false,
        ]);

        $reviews = $response->json('reviews');
        $this->assertCount(0, $reviews);
    }

    // ==================== Review-page archive & delete integration ====================

    public function test_archive_card_via_manage_api_excludes_from_senses_review_queue(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'tobearchived', 'noun', '待归档', 'to be archived');
        $card = app(\App\Services\ReviewCardService::class)->ensureSenseCard($sense);
        $card->update(['fsrs_enabled' => true, 'fsrs_due_at' => now()->subHour()]);

        // Verify card is in review queue before archive
        $before = $this->actingAs($this->user)->getJson('/reviews/senses');
        $before->assertOk();
        $beforeCards = $before->json('cards');
        $this->assertNotEmpty($beforeCards);
        $beforeIds = array_column($beforeCards, 'review_card_id');
        $this->assertContains($card->id, $beforeIds);

        // Archive via manage endpoint (same as what review page does)
        $this->actingAs($this->user)
            ->patch("/review-cards/manage/{$card->id}/enabled", ['enabled' => false])
            ->assertOk();

        // Verify card is excluded from review queue after archive
        $after = $this->actingAs($this->user)->getJson('/reviews/senses');
        $after->assertOk();
        $afterCards = $after->json('cards');
        $afterIds = array_column($afterCards, 'review_card_id');
        $this->assertNotContains($card->id, $afterIds);
    }

    public function test_archive_via_manage_api_does_not_reject_word_sense(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'archivesense', 'noun', '归档释义', 'archive sense');
        $card = app(\App\Services\ReviewCardService::class)->ensureSenseCard($sense);
        $card->update(['fsrs_enabled' => true, 'fsrs_due_at' => now()->subHour()]);

        $this->actingAs($this->user)
            ->patch("/review-cards/manage/{$card->id}/enabled", ['enabled' => false])
            ->assertOk();

        // WordSense must remain confirmed
        $this->assertSame(WordSense::STATUS_CONFIRMED, $sense->fresh()->status);
        // ReviewCard must still exist
        $this->assertNotNull(ReviewCard::find($card->id));
        $this->assertFalse(ReviewCard::find($card->id)->fsrs_enabled);
    }

    public function test_archive_via_manage_api_does_not_create_review_logs(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'archivenolog', 'noun', '归档无日志', 'archive no log');
        $card = app(\App\Services\ReviewCardService::class)->ensureSenseCard($sense);
        $card->update(['fsrs_enabled' => true, 'fsrs_due_at' => now()->subHour()]);

        $oldLogCount = ReviewLog::count();

        $this->actingAs($this->user)
            ->patch("/review-cards/manage/{$card->id}/enabled", ['enabled' => false])
            ->assertOk();

        $this->assertSame($oldLogCount, ReviewLog::count());
    }

    public function test_delete_card_via_manage_api_excludes_from_senses_review_queue(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'tobedeleted', 'noun', '待删除', 'to be deleted');
        $card = app(\App\Services\ReviewCardService::class)->ensureSenseCard($sense);
        $card->update(['fsrs_enabled' => true, 'fsrs_due_at' => now()->subHour()]);

        // Verify card is in review queue before delete
        $before = $this->actingAs($this->user)->getJson('/reviews/senses');
        $before->assertOk();
        $beforeCards = $before->json('cards');
        $beforeIds = array_column($beforeCards, 'review_card_id');
        $this->assertContains($card->id, $beforeIds);

        // Delete via manage endpoint (same as what review page does)
        $this->actingAs($this->user)
            ->delete("/review-cards/manage/{$card->id}")
            ->assertOk();

        // Verify card is excluded from review queue after delete
        $after = $this->actingAs($this->user)->getJson('/reviews/senses');
        $after->assertOk();
        $afterCards = $after->json('cards');
        $afterIds = array_column($afterCards, 'review_card_id');
        $this->assertNotContains($card->id, $afterIds);
    }

    public function test_delete_via_manage_api_rejects_word_sense(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'deletesense', 'noun', '删除释义', 'delete sense');
        $card = app(\App\Services\ReviewCardService::class)->ensureSenseCard($sense);
        $card->update(['fsrs_enabled' => true, 'fsrs_due_at' => now()->subHour()]);

        $this->actingAs($this->user)
            ->delete("/review-cards/manage/{$card->id}")
            ->assertOk();

        // WordSense must be rejected
        $this->assertSame(WordSense::STATUS_REJECTED, $sense->fresh()->status);
        // ReviewCard must be deleted
        $this->assertNull(ReviewCard::find($card->id));
    }

    public function test_delete_via_manage_api_does_not_create_review_logs(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'deletenolog', 'noun', '删除无日志', 'delete no log');
        $card = app(\App\Services\ReviewCardService::class)->ensureSenseCard($sense);
        $card->update(['fsrs_enabled' => true, 'fsrs_due_at' => now()->subHour()]);

        $oldLogCount = ReviewLog::count();

        $this->actingAs($this->user)
            ->delete("/review-cards/manage/{$card->id}")
            ->assertOk();

        $this->assertSame($oldLogCount, ReviewLog::count());
    }

    public function test_delete_last_linked_sense_via_manage_api_restores_encountered_word(): void
    {
        $word = \App\Models\EncounteredWord::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'stage' => -7,
            'word' => 'restoreword',
            'kanji' => '',
            'reading' => '',
            'translation' => 'restore translation',
            'base_word' => '',
            'base_word_reading' => '',
            'lookup_count' => 0,
            'read_count' => 0,
            'lemma' => '',
            'added_to_srs' => now()->toDateString(),
            'next_review' => now()->toDateString(),
            'relearning' => true,
        ]);

        $sense = $this->createSense($this->user->id, 'english', 'restoreword', 'noun', '恢复测试', 'restore test');
        $sense->update(['encountered_word_id' => $word->id]);
        $card = app(\App\Services\ReviewCardService::class)->ensureSenseCard($sense);
        $card->update(['fsrs_enabled' => true, 'fsrs_due_at' => now()->subHour()]);

        $this->actingAs($this->user)
            ->delete("/review-cards/manage/{$card->id}")
            ->assertOk();

        // EncounteredWord should be restored to New (stage=2)
        $word->refresh();
        $this->assertSame(2, $word->stage, 'Word should be restored to New (stage=2)');
        $this->assertSame(0, (int) $word->relearning);
        $this->assertNull($word->next_review);
    }

    public function test_archive_via_manage_api_cannot_cross_user(): void
    {
        $otherSense = $this->createSense($this->otherUser->id, 'english', 'otherarchive', 'noun', '他人归档', 'other archive');
        $otherCard = app(\App\Services\ReviewCardService::class)->ensureSenseCard($otherSense);
        $otherCard->update(['fsrs_enabled' => true]);

        $response = $this->actingAs($this->user)
            ->patch("/review-cards/manage/{$otherCard->id}/enabled", ['enabled' => false]);

        $this->assertTrue(in_array($response->status(), [404, 403]));
        $this->assertTrue($otherCard->fresh()->fsrs_enabled, 'Other user card should remain enabled');
    }

    public function test_delete_via_manage_api_cannot_cross_user(): void
    {
        $otherSense = $this->createSense($this->otherUser->id, 'english', 'otherdelete', 'noun', '他人删除', 'other delete');
        $otherCard = app(\App\Services\ReviewCardService::class)->ensureSenseCard($otherSense);
        $otherCard->update(['fsrs_enabled' => true]);

        $response = $this->actingAs($this->user)
            ->delete("/review-cards/manage/{$otherCard->id}");

        $this->assertTrue(in_array($response->status(), [404, 403]));
        $this->assertNotNull(ReviewCard::find($otherCard->id), 'Other user card should still exist');
    }

    // ==================== FSRS desired retention configuration ====================

    public function test_desired_retention_defaults_to_0_90_when_setting_missing(): void
    {
        $service = app(FsrsSchedulingService::class);

        // No fsrsDesiredRetention setting exists; should default to 0.90
        $this->assertSame(0.90, $service->desiredRetention());
    }

    public function test_desired_retention_reads_from_settings_table(): void
    {
        Setting::forceCreate([
            'user_id' => -1,
            'name' => 'fsrsDesiredRetention',
            'value' => json_encode(0.95),
        ]);

        $service = app(FsrsSchedulingService::class);

        $this->assertSame(0.95, $service->desiredRetention());
    }

    public function test_desired_retention_clamps_low_value_to_0_70(): void
    {
        Setting::forceCreate([
            'user_id' => -1,
            'name' => 'fsrsDesiredRetention',
            'value' => json_encode(0.50),
        ]);

        $service = app(FsrsSchedulingService::class);

        $this->assertSame(0.70, $service->desiredRetention());
    }

    public function test_desired_retention_clamps_high_value_to_0_97(): void
    {
        Setting::forceCreate([
            'user_id' => -1,
            'name' => 'fsrsDesiredRetention',
            'value' => json_encode(0.99),
        ]);

        $service = app(FsrsSchedulingService::class);

        $this->assertSame(0.97, $service->desiredRetention());
    }

    public function test_desired_retention_defaults_to_0_90_for_non_numeric(): void
    {
        Setting::forceCreate([
            'user_id' => -1,
            'name' => 'fsrsDesiredRetention',
            'value' => json_encode('not-a-number'),
        ]);

        $service = app(FsrsSchedulingService::class);

        $this->assertSame(0.90, $service->desiredRetention());
    }

    // ==================== Reset card ====================

    public function test_reset_card_resets_all_fsrs_fields_to_defaults(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'resetall', 'noun', '全部重置', 'reset all');
        $card = app(ReviewCardService::class)->ensureSenseCard($sense);
        // Give the card some review history
        $card->update([
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->addDays(30),
            'fsrs_stability' => 12.5,
            'fsrs_difficulty' => 3.2,
            'fsrs_reps' => 15,
            'fsrs_lapses' => 2,
            'fsrs_last_reviewed_at' => now()->subDays(5),
            'fsrs_enabled' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/review-cards/manage/{$card->id}/reset");

        $response->assertOk();
        $data = $response->json();
        $this->assertSame('new', $data['fsrs_state']);
        $this->assertNotNull($data['fsrs_due_at']);
        $this->assertNull($data['fsrs_stability']);
        $this->assertNull($data['fsrs_difficulty']);
        $this->assertSame(0, $data['fsrs_reps']);
        $this->assertSame(0, $data['fsrs_lapses']);
        $this->assertTrue($data['fsrs_enabled']);

        // Verify database
        $card->refresh();
        $this->assertSame('new', $card->fsrs_state);
        $this->assertNotNull($card->fsrs_due_at);
        $this->assertNull($card->fsrs_stability);
        $this->assertNull($card->fsrs_difficulty);
        $this->assertSame(0, $card->fsrs_reps);
        $this->assertSame(0, $card->fsrs_lapses);
        $this->assertNull($card->fsrs_last_reviewed_at);
        $this->assertTrue($card->fsrs_enabled);
    }

    public function test_reset_card_creates_review_log_with_source_reset(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'resetlog', 'noun', '重置日志', 'reset log');
        $card = app(ReviewCardService::class)->ensureSenseCard($sense);
        $card->update([
            'fsrs_state' => 'review',
            'fsrs_stability' => 8.0,
            'fsrs_difficulty' => 4.5,
            'fsrs_due_at' => now()->addDays(10),
            'fsrs_last_reviewed_at' => now()->subDays(3),
        ]);

        $oldLogCount = ReviewLog::count();

        $this->actingAs($this->user)
            ->postJson("/review-cards/manage/{$card->id}/reset")
            ->assertOk();

        $this->assertSame($oldLogCount + 1, ReviewLog::count());

        $log = ReviewLog::latest()->first();
        $this->assertSame('reset', $log->rating);
        $this->assertSame('reset', $log->source);
        $this->assertSame('review', $log->previous_state);
        $this->assertSame('new', $log->new_state);
        $this->assertSame($card->id, $log->review_card_id);
        $this->assertSame($this->user->id, $log->user_id);
        $this->assertSame('english', $log->language_id);
    }

    public function test_reset_card_preserves_old_review_logs(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'preservelog', 'noun', '保留日志', 'preserve log');
        $card = app(ReviewCardService::class)->ensureSenseCard($sense);
        $card->update(['fsrs_due_at' => now()->subHour()]);

        // Rate the card to create a review log
        $this->actingAs($this->user)
            ->postJson("/reviews/senses/{$card->id}/rate", ['rating' => 'good'])
            ->assertOk();

        $oldLogs = ReviewLog::where('review_card_id', $card->id)->get();
        $oldLogCount = $oldLogs->count();
        $this->assertGreaterThan(0, $oldLogCount, 'Should have at least one rating log before reset');

        // Reset the card
        $this->actingAs($this->user)
            ->postJson("/review-cards/manage/{$card->id}/reset")
            ->assertOk();

        // Old logs should still exist
        $allLogs = ReviewLog::where('review_card_id', $card->id)->get();
        $this->assertSame($oldLogCount + 1, $allLogs->count(), 'Should have old logs plus one reset log');

        foreach ($oldLogs as $oldLog) {
            $this->assertNotNull(ReviewLog::find($oldLog->id), 'Old review log should not be deleted');
        }
    }

    public function test_reset_card_does_not_affect_word_sense(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'keepsense', 'noun', '保留释义', 'keep sense');
        $card = app(ReviewCardService::class)->ensureSenseCard($sense);
        $card->update([
            'fsrs_state' => 'review',
            'fsrs_stability' => 5.0,
            'fsrs_difficulty' => 3.0,
            'fsrs_due_at' => now()->addDays(5),
        ]);

        $this->actingAs($this->user)
            ->postJson("/review-cards/manage/{$card->id}/reset")
            ->assertOk();

        $sense->refresh();
        $this->assertSame(WordSense::STATUS_CONFIRMED, $sense->status, 'Sense status should remain confirmed');
        $this->assertSame('保留释义', $sense->sense_zh);
        $this->assertSame('keep sense', $sense->sense_en);
        $this->assertSame('keepsense', $sense->lemma);
        $this->assertSame('noun', $sense->pos);
    }

    public function test_reset_card_does_not_affect_encountered_word(): void
    {
        $word = EncounteredWord::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'stage' => -7,
            'word' => 'resetword',
            'kanji' => '',
            'reading' => '',
            'translation' => 'reset word translation',
            'base_word' => '',
            'base_word_reading' => '',
            'lookup_count' => 0,
            'read_count' => 0,
            'lemma' => '',
            'added_to_srs' => now()->toDateString(),
            'next_review' => now()->toDateString(),
            'relearning' => true,
        ]);

        $sense = $this->createSense($this->user->id, 'english', 'resetword', 'noun', '重置词', 'reset word');
        $sense->update(['encountered_word_id' => $word->id]);
        $card = app(ReviewCardService::class)->ensureSenseCard($sense);
        $card->update([
            'fsrs_state' => 'review',
            'fsrs_stability' => 6.0,
            'fsrs_difficulty' => 3.5,
            'fsrs_due_at' => now()->addDays(3),
        ]);

        $this->actingAs($this->user)
            ->postJson("/review-cards/manage/{$card->id}/reset")
            ->assertOk();

        $word->refresh();
        $this->assertSame(-7, $word->stage, 'EncounteredWord stage should not change');
        $this->assertSame(1, (int) $word->relearning, 'EncounteredWord relearning should not change');
    }

    public function test_reset_card_appears_in_senses_review_queue(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'resetqueue', 'noun', '进队测试', 'queue test');
        $card = app(ReviewCardService::class)->ensureSenseCard($sense);
        // Card is due far in the future
        $card->update([
            'fsrs_state' => 'review',
            'fsrs_stability' => 10.0,
            'fsrs_difficulty' => 2.0,
            'fsrs_due_at' => now()->addDays(60),
            'fsrs_enabled' => true,
        ]);

        // Before reset: card should NOT be in review queue
        $before = $this->actingAs($this->user)->getJson('/reviews/senses');
        $before->assertOk();
        $beforeIds = array_column($before->json('cards'), 'review_card_id');
        $this->assertNotContains($card->id, $beforeIds, 'Card should not be in queue before reset');

        // Reset the card
        $this->actingAs($this->user)
            ->postJson("/review-cards/manage/{$card->id}/reset")
            ->assertOk();

        // After reset: card should be in review queue
        $after = $this->actingAs($this->user)->getJson('/reviews/senses');
        $after->assertOk();
        $afterIds = array_column($after->json('cards'), 'review_card_id');
        $this->assertContains($card->id, $afterIds, 'Card should appear in queue after reset');
    }

    public function test_reset_archived_card_auto_enables(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'archivedreset', 'noun', '归档重置', 'archived reset');
        $card = app(ReviewCardService::class)->ensureSenseCard($sense);
        $card->update([
            'fsrs_state' => 'review',
            'fsrs_stability' => 7.0,
            'fsrs_difficulty' => 4.0,
            'fsrs_due_at' => now()->addDays(14),
            'fsrs_enabled' => false, // archived
        ]);

        $this->actingAs($this->user)
            ->postJson("/review-cards/manage/{$card->id}/reset")
            ->assertOk();

        $card->refresh();
        $this->assertTrue($card->fsrs_enabled, 'Archived card should be re-enabled after reset');
        $this->assertSame('new', $card->fsrs_state);
    }

    public function test_reset_already_new_card_is_idempotent(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'newreset', 'noun', '新卡重置', 'new reset');
        $card = app(ReviewCardService::class)->ensureSenseCard($sense);
        // Card already has default new-card state
        $this->assertSame('new', $card->fsrs_state);

        $oldLogCount = ReviewLog::count();

        $response = $this->actingAs($this->user)
            ->postJson("/review-cards/manage/{$card->id}/reset");

        $response->assertOk();
        $data = $response->json();
        $this->assertSame('new', $data['fsrs_state']);
        $this->assertSame(0, $data['fsrs_reps']);
        $this->assertSame(0, $data['fsrs_lapses']);
        $this->assertTrue($data['fsrs_enabled']);

        // A reset log should still be created
        $this->assertSame($oldLogCount + 1, ReviewLog::count());
        $log = ReviewLog::latest()->first();
        $this->assertSame('reset', $log->rating);
        $this->assertSame('new', $log->previous_state);
        $this->assertSame('new', $log->new_state);
    }

    public function test_reset_card_cannot_cross_user(): void
    {
        $otherSense = $this->createSense($this->otherUser->id, 'english', 'otherreset', 'noun', '他人重置', 'other reset');
        $otherCard = app(ReviewCardService::class)->ensureSenseCard($otherSense);
        $otherCard->update([
            'fsrs_state' => 'review',
            'fsrs_stability' => 5.0,
            'fsrs_difficulty' => 3.0,
            'fsrs_due_at' => now()->addDays(7),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/review-cards/manage/{$otherCard->id}/reset");

        $this->assertTrue(in_array($response->status(), [404, 403]));
        // Other user's card must remain unchanged
        $otherCard->refresh();
        $this->assertSame('review', $otherCard->fsrs_state, 'Other user card state should not change');
        $this->assertSame(5.0, $otherCard->fsrs_stability, 'Other user card stability should not change');
    }

    public function test_reset_card_cannot_cross_language(): void
    {
        $sense = $this->createSense($this->user->id, 'spanish', 'idioma', 'noun', '语言', 'language');
        $card = app(ReviewCardService::class)->ensureSenseCard($sense);
        $card->update([
            'fsrs_state' => 'review',
            'fsrs_stability' => 5.0,
            'fsrs_difficulty' => 3.0,
            'fsrs_due_at' => now()->addDays(7),
        ]);

        // User's selected_language is 'english', but card is 'spanish'
        $response = $this->actingAs($this->user)
            ->postJson("/review-cards/manage/{$card->id}/reset");

        $this->assertSame(404, $response->status());
        // Card must remain unchanged
        $card->refresh();
        $this->assertSame('review', $card->fsrs_state, 'Cross-language card state should not change');
    }

    public function test_reset_card_not_applicable_to_word_card(): void
    {
        $word = $this->createWord($this->user->id, 'english', -1, 'wordcard');
        $card = app(ReviewCardService::class)->ensureWordCard($word);
        $this->assertNotNull($card);
        $this->assertSame(ReviewCard::TARGET_WORD, $card->target_type);

        $response = $this->actingAs($this->user)
            ->postJson("/review-cards/manage/{$card->id}/reset");

        $this->assertSame(404, $response->status());
        // Word card must remain unchanged
        $card->refresh();
        $this->assertSame('new', $card->fsrs_state);
    }

    public function test_reset_card_rejected_sense_returns_404(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'rejected', 'noun', '已拒绝', 'rejected');
        $card = app(ReviewCardService::class)->ensureSenseCard($sense);
        $card->update([
            'fsrs_state' => 'review',
            'fsrs_stability' => 4.0,
            'fsrs_difficulty' => 2.5,
            'fsrs_due_at' => now()->addDays(3),
        ]);

        // Reject the sense
        $sense->update(['status' => WordSense::STATUS_REJECTED]);

        $response = $this->actingAs($this->user)
            ->postJson("/review-cards/manage/{$card->id}/reset");

        $this->assertSame(404, $response->status());
        // Card must remain unchanged
        $card->refresh();
        $this->assertSame('review', $card->fsrs_state, 'Card for rejected sense should not be reset');
    }

    private function createSense(int $userId, string $language, string $lemma, string $pos, string $senseZh, string $senseEn): WordSense
    {
        return WordSense::forceCreate([
            'user_id' => $userId,
            'language' => $language,
            'language_id' => $language,
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => $pos,
            'sense_zh' => $senseZh,
            'sense_en' => $senseEn,
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => null,
            'example_sentence_zh' => null,
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', strtolower("{$language}|{$lemma}|{$pos}|{$senseZh}|{$senseEn}")),
        ]);
    }

    private function createWord(int $userId, string $language, int $stage, string $word): EncounteredWord
    {
        return EncounteredWord::forceCreate([
            'user_id' => $userId,
            'language' => $language,
            'stage' => $stage,
            'word' => $word,
            'kanji' => '',
            'reading' => '',
            'translation' => "{$word} translation",
            'base_word' => '',
            'base_word_reading' => '',
            'lookup_count' => 0,
            'read_count' => 0,
            'lemma' => '',
            'added_to_srs' => $stage < 0 ? now()->toDateString() : null,
            'next_review' => $stage < 0 ? now()->toDateString() : null,
            'relearning' => false,
        ]);
    }
}
