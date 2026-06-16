<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\EncounteredWord;
use App\Models\ReviewCard;

class ReviewService {
    
    public function __construct() {
    }

    public function getReviewItems($userId, $language, $bookId, $chapterId, $practiceMode, $languagesWithoutSpaces) {
        // check if book exists
        if ($bookId !== -1) {
            $book = Book
                ::where('user_id', $userId)
                ->where('id', $bookId)
                ->where('language', $language)
                ->first();
            
            if (!$book) {
                throw new \Exception('Book does not exist, or it belongs to a different user.');
            }
        }

        // check if chapter exists
        if ($chapterId !== -1) {
            $chapter = Chapter
                ::where('user_id', $userId)
                ->where('book_id', $bookId)
                ->where('id', $chapterId)
                ->where('language', $language)
                ->first();
            
            if (!$chapter) {
                throw new \Exception('Chapter does not exist, or it belongs to a different book or user.');
            }
        }

        // base query. FSRS phase one supports word cards only.
        $reviewWords = EncounteredWord
            ::select('encountered_words.*', 'review_cards.id as review_card_id')
            ->join('review_cards', function ($join) {
                $join->on('review_cards.target_id', '=', 'encountered_words.id')
                    ->where('review_cards.target_type', ReviewCard::TARGET_WORD);
            })
            ->where('encountered_words.user_id', $userId)
            ->where('review_cards.user_id', $userId)
            ->where('review_cards.language_id', $language)
            ->where('encountered_words.language', $language)
            ->where('encountered_words.stage', '<', 0)
            ->where('review_cards.fsrs_enabled', true);

        // practice mode
        if (!$practiceMode) {
            $reviewWords = $reviewWords->where('review_cards.fsrs_due_at', '<=', Carbon::now());
        }
        
        // retrieve chapter words and phrases by chapter id
        $uniqueWords = [];
        if ($chapterId !== -1 || $bookId !== -1) {
            if ($chapterId !== -1) {
                $chapterIds = Chapter
                    ::where('id', $chapterId)
                    ->where('user_id', $userId)
                    ->pluck('id')
                    ->toArray();
            } else {
                $chapterIds = Chapter
                    ::where('book_id', $bookId)
                    ->where('user_id', $userId)
                    ->pluck('id')
                    ->toArray();
            }

            foreach ($chapterIds as $chapterId) {
                $chapter = Chapter
                    ::where('user_id', $userId)
                    ->where('id', $chapterId)
                    ->first();

                $words = $chapter->getProcessedText();
                
                foreach ($words as $word) {
                    if (!in_array(mb_strtolower($word->word), $uniqueWords, true)) {
                        array_push($uniqueWords, mb_strtolower($word->word, 'UTF-8'));
                    }
                }
            }

            $reviewWords = $reviewWords->whereIn('encountered_words.word', $uniqueWords);
        }

        $reviewWords = $reviewWords->inRandomOrder()->get();

        // brush words and phrases together into one array
        $reviews = [];
        foreach ($reviewWords as $word) {
            $word->type = 'word';
            $reviews[] = $word; 
        }

        return $reviews;
    }
}
