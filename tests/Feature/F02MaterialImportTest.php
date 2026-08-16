<?php

namespace Tests\Feature;

use App\Jobs\ProcessChapter;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class F02MaterialImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_text_import_creates_classified_material_and_queues_processing(): void
    {
        Queue::fake();
        Http::fake(['*/tokenizer/import-text' => Http::response(['Imported English passage.'])]);
        $user = $this->user();

        $this->actingAs($user)->post('/import', $this->payload([
            'bookName' => 'CET-6 2025 Set 2',
            'materialType' => 'cet6',
            'examYear' => 2025,
            'examSet' => 2,
            'questionType' => 'reading_comprehension',
        ]))->assertOk()->assertJsonPath('processing_mode', 'tokenizer');

        $book = Book::query()->where('user_id', $user->id)->firstOrFail();
        $chapter = Chapter::query()->where('book_id', $book->id)->firstOrFail();
        $this->assertSame('cet6', $book->material_type);
        $this->assertSame(2025, $book->exam_year);
        $this->assertSame(2, $book->exam_set);
        $this->assertSame('reading_comprehension', $chapter->question_type);
        Queue::assertPushed(ProcessChapter::class, 1);
    }

    public function test_legacy_import_payload_defaults_to_personal_material(): void
    {
        Queue::fake();
        Http::fake(['*/tokenizer/import-text' => Http::response(['Personal English passage.'])]);
        $user = $this->user();

        $this->actingAs($user)->post('/import', $this->payload())->assertOk();

        $book = Book::query()->where('user_id', $user->id)->firstOrFail();
        $chapter = Chapter::query()->where('book_id', $book->id)->firstOrFail();
        $this->assertSame('personal', $book->material_type);
        $this->assertNull($book->exam_year);
        $this->assertNull($book->exam_set);
        $this->assertNull($chapter->question_type);
    }

    public function test_existing_book_metadata_is_preserved_when_importing_a_question_chapter(): void
    {
        Queue::fake();
        Http::fake(['*/tokenizer/import-text' => Http::response(['Translation prompt.'])]);
        $user = $this->user();
        $book = Book::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'name' => 'CET-4 2024 Set 1',
            'word_count' => 0,
            'material_type' => 'cet4',
            'exam_year' => 2024,
            'exam_set' => 1,
        ]);

        $this->actingAs($user)->post('/import', $this->payload([
            'bookId' => $book->id,
            'bookName' => '',
            'materialType' => 'personal',
            'questionType' => 'translation',
        ]))->assertOk();

        $book->refresh();
        $this->assertSame('cet4', $book->material_type);
        $this->assertSame(2024, $book->exam_year);
        $this->assertSame(1, $book->exam_set);
        $this->assertSame('translation', Chapter::query()->where('book_id', $book->id)->firstOrFail()->question_type);
    }

    public function test_invalid_material_metadata_is_rejected_without_writes(): void
    {
        Queue::fake();
        Http::fake();
        $user = $this->user();

        $this->actingAs($user)->postJson('/import', $this->payload([
            'materialType' => 'ielts',
            'questionType' => 'multiple_choice',
        ]))->assertUnprocessable();

        $this->assertDatabaseCount('books', 0);
        $this->assertDatabaseCount('chapters', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_empty_tokenizer_result_returns_safe_failure_without_partial_material(): void
    {
        Queue::fake();
        Http::fake(['*/tokenizer/import-text' => Http::response([])]);
        $user = $this->user();

        $this->actingAs($user)->post('/import', $this->payload([
            'materialType' => 'personal',
            'questionType' => 'other',
        ]))->assertStatus(500)->assertJsonPath('error.code', 'CONTENT_IMPORT_FAILED');

        $this->assertDatabaseCount('books', 0);
        $this->assertDatabaseCount('chapters', 0);
        Queue::assertNothingPushed();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'importType' => 'plain-text',
            'importText' => 'English material for import.',
            'textProcessingMethod' => 'detailed',
            'eBookChapterSortMethod' => 'default',
            'bookId' => -1,
            'bookName' => 'My material',
            'chapterName' => 'Passage 1',
            'maximumCharactersPerChapter' => 3000,
        ], $overrides);
    }

    private function user(): User
    {
        return User::forceCreate([
            'name' => 'F02 user',
            'email' => 'f02-' . Str::uuid() . '@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => false,
            'uuid' => (string) Str::uuid(),
        ]);
    }
}
