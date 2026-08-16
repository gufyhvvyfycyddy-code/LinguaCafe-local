<?php

namespace Tests\Feature;

use App\Jobs\ProcessChapter;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\User;
use App\Services\ReadingChapterTextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class F01MaterialMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_book_creation_defaults_to_personal_material(): void
    {
        $user = $this->user();

        $this->actingAs($user)->post('/books/create', [
            'bookName' => 'Personal article',
        ])->assertOk();

        $book = Book::query()->where('name', 'Personal article')->firstOrFail();
        $this->assertSame('personal', $book->material_type);
        $this->assertNull($book->exam_year);
        $this->assertNull($book->exam_set);
    }

    public function test_exam_metadata_is_validated_persisted_and_listed(): void
    {
        $user = $this->user();

        $this->actingAs($user)->post('/books/create', [
            'bookName' => 'CET-4 2025 Set 2',
            'materialType' => 'cet4',
            'examYear' => 2025,
            'examSet' => 2,
        ])->assertOk();

        $book = Book::query()->where('name', 'CET-4 2025 Set 2')->firstOrFail();
        $this->assertSame('cet4', $book->material_type);
        $this->assertSame(2025, $book->exam_year);
        $this->assertSame(2, $book->exam_set);

        $this->actingAs($user)->postJson('/books')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $book->id,
                'material_type' => 'cet4',
                'exam_year' => 2025,
                'exam_set' => 2,
            ]);
    }

    public function test_exam_metadata_rejects_unknown_or_incomplete_classification(): void
    {
        $user = $this->user();

        $this->actingAs($user)->postJson('/books/create', [
            'bookName' => 'Unknown exam',
            'materialType' => 'ielts',
            'examYear' => 2025,
            'examSet' => 1,
        ])->assertUnprocessable();

        $this->actingAs($user)->postJson('/books/create', [
            'bookName' => 'Incomplete CET-6',
            'materialType' => 'cet6',
        ])->assertUnprocessable();

        $this->actingAs($user)->postJson('/books/create', [
            'bookName' => 'Unclassified exam',
            'examYear' => 2025,
            'examSet' => 1,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('books', 0);
    }

    public function test_legacy_book_update_preserves_existing_metadata(): void
    {
        $user = $this->user();
        $book = $this->book($user, [
            'material_type' => 'postgraduate_exam',
            'exam_year' => 2024,
            'exam_set' => 1,
        ]);

        $this->actingAs($user)->post('/books/update', [
            'bookId' => $book->id,
            'bookName' => 'Renamed exam',
            'materialType' => null,
        ])->assertOk();

        $book->refresh();
        $this->assertSame('Renamed exam', $book->name);
        $this->assertSame('postgraduate_exam', $book->material_type);
        $this->assertSame(2024, $book->exam_year);
        $this->assertSame(1, $book->exam_set);

        $this->actingAs($user)->post('/books/update', [
            'bookId' => $book->id,
            'bookName' => 'Personal notes',
            'materialType' => 'personal',
        ])->assertOk();

        $book->refresh();
        $this->assertSame('personal', $book->material_type);
        $this->assertNull($book->exam_year);
        $this->assertNull($book->exam_set);
    }

    public function test_chapter_question_type_is_persisted_and_listed(): void
    {
        Queue::fake();
        $user = $this->user();
        $book = $this->book($user);

        $this->actingAs($user)->post('/chapters/create', [
            'bookId' => $book->id,
            'chapterName' => 'Reading comprehension',
            'chapterText' => 'Read this passage.',
            'questionType' => 'reading_comprehension',
        ])->assertOk();

        $chapter = Chapter::query()->where('name', 'Reading comprehension')->firstOrFail();
        $this->assertSame('reading_comprehension', $chapter->question_type);
        Queue::assertPushed(ProcessChapter::class, 1);

        $this->actingAs($user)->postJson('/chapters', ['bookId' => $book->id])
            ->assertOk()
            ->assertJsonPath('chapters.0.question_type', 'reading_comprehension');

        $this->actingAs($user)->post('/chapters/update', [
            'chapterId' => $chapter->id,
            'chapterName' => $chapter->name,
            'chapterText' => 'Translate this passage.',
            'sourceRevision' => app(ReadingChapterTextService::class)->sourceRevision($chapter),
            'questionType' => 'translation',
        ])->assertOk();

        $this->assertSame('translation', $chapter->fresh()->question_type);
        Queue::assertPushed(ProcessChapter::class, 2);
    }

    private function user(): User
    {
        return User::forceCreate([
            'name' => 'F01 user',
            'email' => 'f01-' . Str::uuid() . '@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => false,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    private function book(User $user, array $overrides = []): Book
    {
        return Book::forceCreate(array_merge([
            'user_id' => $user->id,
            'language' => 'english',
            'name' => 'F01 book',
            'word_count' => 0,
        ], $overrides));
    }
}
