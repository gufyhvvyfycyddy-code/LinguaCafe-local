<?php

namespace Tests\Feature;

use App\Models\EncounteredWord;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
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

    public function test_sense_review_payload_has_null_sentence_tokens_without_source(): void
    {
        // Create sense without occurrence / source_chapter_id
        $sense = $this->createSense($this->user->id, 'english', 'sourceless', 'noun', '无源测试', 'source less');
        $sense->example_sentence_en = 'No source sentence.';
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

        // example_sentence_tokens should be null
        $this->assertNull($reviews[0]['example_sentence_tokens']);
        // example_sentence_en should still be returned
        $this->assertSame('No source sentence.', $reviews[0]['example_sentence_en']);
    }

    public function test_sense_review_sentence_tokens_do_not_cross_user_scope(): void
    {
        // Create otherUser's chapter
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
                    (object) ['word' => 'secret', 'stage' => 2, 'spaceAfter' => true, 'sentence_index' => 0],
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

        // Chapter lookup filters by user_id, so tokens should be null
        // (the chapter belongs to otherUser, so find will fail)
        $this->assertNull($reviews[0]['example_sentence_tokens'], 'Tokens should be null when chapter belongs to other user');
        $this->assertSame('This should not leak.', $reviews[0]['example_sentence_en']);
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
