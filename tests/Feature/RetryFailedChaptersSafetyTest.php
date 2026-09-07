<?php

namespace Tests\Feature;

use App\Jobs\ProcessChapter;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\User;
use App\Services\RestoreWriteFence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class RetryFailedChaptersSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'backup.restore_coordination_store' => 'array',
            'cache.default' => 'array',
        ]);
    }

    public function test_retry_route_rejects_safe_http_methods(): void
    {
        $user = $this->user('retry-method@example.test');
        $book = $this->book($user);

        $this->actingAs($user)
            ->getJson("/chapters/retry-failed-chapters/{$book->id}")
            ->assertMethodNotAllowed();

        $this->actingAs($user)
            ->call('HEAD', "/chapters/retry-failed-chapters/{$book->id}")
            ->assertMethodNotAllowed();
    }

    public function test_post_retries_only_failed_chapters_owned_by_the_current_user_and_language(): void
    {
        Queue::fake();

        $user = $this->user('retry-owner@example.test');
        $other = $this->user('retry-other@example.test');
        $book = $this->book($user);
        $otherBook = $this->book($other);

        $failed = $this->chapter($user, $book, 'failed');
        $processed = $this->chapter($user, $book, 'processed');
        $otherFailed = $this->chapter($other, $otherBook, 'failed');

        $this->actingAs($user)
            ->postJson("/chapters/retry-failed-chapters/{$book->id}")
            ->assertOk();

        $this->assertSame('unprocessed', $failed->fresh()->processing_status);
        $this->assertSame('processed', $processed->fresh()->processing_status);
        $this->assertSame('failed', $otherFailed->fresh()->processing_status);

        Queue::assertPushed(ProcessChapter::class, 1);
    }

    public function test_repeated_post_after_success_does_not_dispatch_the_same_chapter_twice(): void
    {
        Queue::fake();

        $user = $this->user('retry-repeat@example.test');
        $book = $this->book($user);
        $failed = $this->chapter($user, $book, 'failed');

        $this->actingAs($user)
            ->postJson("/chapters/retry-failed-chapters/{$book->id}")
            ->assertOk();

        $this->actingAs($user)
            ->postJson("/chapters/retry-failed-chapters/{$book->id}")
            ->assertOk();

        $this->assertSame('unprocessed', $failed->fresh()->processing_status);
        Queue::assertPushed(ProcessChapter::class, 1);
    }

    public function test_restore_fence_blocks_retry_before_state_or_queue_changes(): void
    {
        Queue::fake();

        $user = $this->user('retry-fence@example.test');
        $book = $this->book($user);
        $failed = $this->chapter($user, $book, 'failed');
        $operationId = (string) Str::uuid();
        app(RestoreWriteFence::class)->activate($operationId);

        try {
            $this->actingAs($user)
                ->postJson("/chapters/retry-failed-chapters/{$book->id}")
                ->assertServiceUnavailable()
                ->assertJsonPath('error.code', 'RESTORE_WRITE_FENCE_ACTIVE');
        } finally {
            app(RestoreWriteFence::class)->deactivate($operationId);
        }

        $this->assertSame('failed', $failed->fresh()->processing_status);
        Queue::assertNothingPushed();
    }

    private function user(string $email): User
    {
        return User::forceCreate([
            'name' => Str::before($email, '@'),
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => false,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    private function book(User $user): Book
    {
        return Book::forceCreate([
            'user_id' => $user->id,
            'name' => 'Retry book',
            'language' => 'english',
            'word_count' => 0,
        ]);
    }

    private function chapter(User $user, Book $book, string $status): Chapter
    {
        return Chapter::forceCreate([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'name' => 'Retry chapter ' . Str::uuid(),
            'read_count' => 0,
            'word_count' => 0,
            'language' => 'english',
            'raw_text' => 'Retry text.',
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress('[]', 1),
            'type' => 'text',
            'subtitle_timestamps' => '[]',
            'processing_status' => $status,
        ]);
    }
}
