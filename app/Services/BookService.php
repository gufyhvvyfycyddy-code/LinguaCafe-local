<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\EncounteredWord;
use App\Models\ReadingSession;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Enums\ChapterProcessingStatusEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BookService {

    public function __construct(private ReadingContinuityService $readingContinuityService) {
    }
    
    public function getBooks($userId, $language) {
        $books = Book
            ::where('user_id', $userId)
            ->where('language', $language)
            ->orderBy('updated_at', 'DESC')
            ->get();

        $chapters = Chapter::query()
            ->select(['id', 'book_id', 'raw_text', 'processed_text'])
            ->where('user_id', $userId)
            ->where('language', $language)
            ->where('processing_status', ChapterProcessingStatusEnum::PROCESSED->value)
            ->whereIn('book_id', $books->pluck('id'))
            ->get();
        $chapterProgress = $this->readingContinuityService->projectChapterProgress(
            $userId,
            $language,
            $chapters,
        );
        $chapterIdsByBook = $chapters
            ->groupBy('book_id')
            ->map(fn ($bookChapters) => $bookChapters->pluck('id'));

        // sets initial values used by Vue in the library
        foreach ($books as $book) {
            $book->wordCount = null;
            $bookChapterIds = $chapterIdsByBook->get($book->id, collect());
            $book->readingProgress = $this->readingContinuityService->aggregateProgress(
                $bookChapterIds->map(fn ($chapterId) => $chapterProgress[(int) $chapterId]),
            );
        }

        return $books;
    }


    public function getBookWordCounts($userId, $language, $bookId) {
        $book = Book
            ::where('user_id', $userId)
            ->where('language', $language)
            ->where('id', $bookId)
            ->first();
                
        if (!$book) {
            throw new \Exception('Book does not exist, or it belongs to a different user.');
        }
        
        // get words for calculating word counts
        $words = EncounteredWord
            ::select(['id', 'word', 'stage'])
            ->where('user_id', $userId)
            ->where('language', $book->language)
            ->get()
            ->keyBy('id')
            ->toArray();

        // calculate word counts
        return $book->getWordCounts($userId, $words);
    }

    /*
        Updates the word count of the book. This only stores the length of the book,
        other word counts are being calculated in real time.
    */

    public function updateBookWordCount($userId, $language, $bookId) {
        // calculate book word count
        $bookWordCount = Chapter
            ::where('user_id', $userId)
            ->where('language', $language)
            ->where('book_id', $bookId)
            ->where('processing_status', ChapterProcessingStatusEnum::PROCESSED->value)
            ->sum('word_count');

        $bookWordCount = intval($bookWordCount);

        // update book word count
        Book
            ::where('user_id', $userId)
            ->where('language', $language)
            ->where('id', $bookId)
            ->update(['word_count' => $bookWordCount]);
    }

    public function createBook($userId, $selectedLanguage, $bookName, $bookCoverFile, array $metadata = []) {
        // create book model
        $book = new Book();
        $book->user_id = $userId;
        $book->cover_image = null;
        $book->language = $selectedLanguage;
        $book->name = $bookName;
        $book->material_type = $metadata['materialType'] ?? 'personal';
        $book->exam_year = $metadata['examYear'] ?? null;
        $book->exam_set = $metadata['examSet'] ?? null;

        // save new book
        $book->save();
        
        // update image
        if (!is_null($bookCoverFile)) {
            $this->saveBookImage($book, $bookCoverFile);
        }

        return true;
    }

    public function updateBook($userId, $language, $bookId, $bookName, $bookCoverFile, array $metadata = []) {
        $book = Book
            ::where('user_id', $userId)
            ->where('language', $language)
            ->where('id', $bookId)
            ->first();

        if (!$book) {
            throw new \Exception('Book does not exist, or it belongs to a different user.');
        }

        // update and save book
        $book->name = $bookName;
        if (isset($metadata['materialType'])) {
            $book->material_type = $metadata['materialType'];
            $book->exam_year = $metadata['examYear'] ?? null;
            $book->exam_set = $metadata['examSet'] ?? null;
        }
        $book->save();
        
        // update image
        if (!is_null($bookCoverFile)) {
            $this->saveBookImage($book, $bookCoverFile);
        }

        return true;
    }

    private function saveBookImage($book, $bookCoverFile) {
        // delete old image
        if ($book->cover_image !== '' && $book->cover_image !== null) {
            Storage::delete('/images/book_images/' . $book->cover_image);
        }

        // save image on server
        $timestamp = implode('_', explode(' ', Carbon::now()->toDateTimeString()));
        $fileName = $book->id . '_' . $timestamp . '.' . ($bookCoverFile->getClientOriginalExtension());
        $bookCoverFile->storeAs('/images/book_images/', $fileName);

        // save image in database
        $book->cover_image = $fileName;
        $book->save();
    }

    public function getDeletionImpact($userId, $language, $bookId) {
        $book = Book
            ::where('user_id', $userId)
            ->where('language', $language)
            ->where('id', $bookId)
            ->first();

        if (!$book) {
            throw new \Exception('Book does not exist, or it belongs to a different user.');
        }

        $chapterIds = Chapter
            ::where('user_id', $userId)
            ->where('language', $language)
            ->where('book_id', $bookId)
            ->pluck('id');
        $occurrences = WordSenseOccurrence
            ::where('user_id', $userId)
            ->where('language_id', $language)
            ->whereIn('chapter_id', $chapterIds);
        $senseIds = WordSense
            ::where('user_id', $userId)
            ->where('language_id', $language)
            ->whereIn('id', (clone $occurrences)->whereNotNull('word_sense_id')->pluck('word_sense_id'))
            ->pluck('id');
        $cardIds = ReviewCard
            ::where('user_id', $userId)
            ->where('language_id', $language)
            ->where('target_type', ReviewCard::TARGET_SENSE)
            ->whereIn('target_id', $senseIds)
            ->pluck('id');

        return [
            'book_name' => $book->name,
            'chapter_count' => $chapterIds->count(),
            'source_occurrence_count' => (clone $occurrences)->count(),
            'word_sense_count' => $senseIds->count(),
            'review_card_count' => $cardIds->count(),
            'review_log_count' => ReviewLog::where('user_id', $userId)->whereIn('review_card_id', $cardIds)->count(),
            'reading_session_count' => ReadingSession
                ::where('user_id', $userId)
                ->where('language_id', $language)
                ->whereIn('chapter_id', $chapterIds)
                ->count(),
        ];
    }

    public function deleteBook($userId, $language, $bookId) {
        $coverImage = DB::transaction(function () use ($userId, $language, $bookId) {
            $book = Book
                ::where('user_id', $userId)
                ->where('language', $language)
                ->where('id', $bookId)
                ->lockForUpdate()
                ->first();

            if (!$book) {
                throw new \Exception('Book does not exist, or it belongs to a different user.');
            }

            $chapters = Chapter
                ::where('user_id', $userId)
                ->where('language', $language)
                ->where('book_id', $bookId);
            $chapterIds = (clone $chapters)->lockForUpdate()->pluck('id');
            $this->readingContinuityService->deleteProgressForChapters($userId, $language, $chapterIds);
            $chapters->delete();

            $coverImage = $book->cover_image;
            $book->delete();

            return $coverImage;
        });

        if ($coverImage !== '' && $coverImage !== null) {
            Storage::delete('/images/book_images/' . $coverImage);
        }

        return true;
    }
}
