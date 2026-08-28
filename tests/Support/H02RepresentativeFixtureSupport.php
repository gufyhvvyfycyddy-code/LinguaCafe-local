<?php

declare(strict_types=1);

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\ReviewCardService;
use App\Services\WordSenseService;
use Illuminate\Support\Facades\DB;

final class H02RepresentativeFixtureSupport
{
    /** @return list<array{email:string,password:string,lemma:string,language:string}> */
    public static function prepareRows(int $vus): array
    {
        if ($vus < 1 || $vus > 1000) {
            throw new InvalidArgumentException('H02_VUS_INVALID');
        }

        $rows = [];
        for ($index = 1; $index <= $vus; $index++) {
            $suffix = sprintf('%03d', $index);
            $rows[] = [
                'email' => "h02-vu-{$suffix}@example.test",
                'password' => "H02-testing-{$suffix}!",
                'lemma' => "h02-vu-{$suffix}",
                'language' => 'en',
            ];
        }

        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $fixtureRows
     * @return array{
     *     rows:list<array<string,mixed>>,
     *     user_ids:list<int>,
     *     book_ids:list<int>,
     *     chapter_ids:list<int>,
     *     sense_ids:list<int>,
     *     review_card_ids:list<int>
     * }
     */
    public static function provision(array $fixtureRows): array
    {
        return DB::transaction(function () use ($fixtureRows): array {
            $fixtureState = [
                'rows' => [],
                'user_ids' => [],
                'book_ids' => [],
                'chapter_ids' => [],
                'sense_ids' => [],
                'review_card_ids' => [],
            ];

            foreach ($fixtureRows as $row) {
                $user = User::forceCreate([
                    'name' => "H02 {$row['lemma']}",
                    'email' => $row['email'],
                    'password' => $row['password'],
                    'selected_language' => $row['language'],
                    'is_admin' => false,
                ]);

                $book = Book::forceCreate([
                    'user_id' => $user->id,
                    'name' => "H02 {$row['lemma']} book",
                    'language' => $row['language'],
                    'word_count' => 1,
                ]);

                $chapter = Chapter::forceCreate([
                    'user_id' => $user->id,
                    'book_id' => $book->id,
                    'name' => "H02 {$row['lemma']} chapter",
                    'read_count' => 0,
                    'word_count' => 1,
                    'language' => $row['language'],
                    'raw_text' => "H02 {$row['lemma']} sentence.",
                    'unique_words' => json_encode([$row['lemma']], JSON_THROW_ON_ERROR),
                    'unique_word_ids' => '[]',
                    'processed_text' => gzcompress(json_encode([
                        (object) [
                            'word' => $row['lemma'],
                            'lemma' => $row['lemma'],
                            'pos' => 'NOUN',
                            'stage' => 2,
                            'spaceAfter' => true,
                            'sentence_index' => 0,
                            'phrase_ids' => [],
                        ],
                    ], JSON_THROW_ON_ERROR), 1),
                    'type' => 'text',
                    'subtitle_timestamps' => '[]',
                    'processing_status' => 'processed',
                ]);

                $sense = app(WordSenseService::class)->createSense([
                    'user_id' => $user->id,
                    'language' => $row['language'],
                    'language_id' => $row['language'],
                    'lemma' => $row['lemma'],
                    'surface_form' => $row['lemma'],
                    'pos' => 'noun',
                    'sense_zh' => "H02 {$row['lemma']}",
                    'sense_en' => "H02 {$row['lemma']}",
                    'example_sentence_en' => "H02 {$row['lemma']} sentence.",
                    'source_chapter_id' => $chapter->id,
                    'sentence_id' => '0',
                    'status' => WordSense::STATUS_CONFIRMED,
                ]);

                $reviewCard = app(ReviewCardService::class)->ensureSenseCard($sense);
                if ($reviewCard === null) {
                    throw new RuntimeException('H02_FIXTURE_CARD_CREATE_FAILED');
                }

                $fixtureState['rows'][] = [
                    'email' => $row['email'],
                    'password' => $row['password'],
                    'chapter_id' => $chapter->id,
                    'lemma' => $row['lemma'],
                    'language' => $row['language'],
                    'review_card_id' => $reviewCard->id,
                ];
                $fixtureState['user_ids'][] = $user->id;
                $fixtureState['book_ids'][] = $book->id;
                $fixtureState['chapter_ids'][] = $chapter->id;
                $fixtureState['sense_ids'][] = $sense->id;
                $fixtureState['review_card_ids'][] = $reviewCard->id;
            }

            $userIds = $fixtureState['user_ids'];
            $reviewCardIds = $fixtureState['review_card_ids'];
            if (count($userIds) !== count(array_unique($userIds))
                || count($reviewCardIds) !== count(array_unique($reviewCardIds))
            ) {
                throw new RuntimeException('H02_FIXTURE_IDENTITY_DUPLICATE');
            }

            return $fixtureState;
        });
    }

    public static function cleanup(array $fixtureState): void
    {
        $userIds = array_values(array_unique(array_map('intval', $fixtureState['user_ids'] ?? [])));
        $bookIds = array_values(array_unique(array_map('intval', $fixtureState['book_ids'] ?? [])));
        $chapterIds = array_values(array_unique(array_map('intval', $fixtureState['chapter_ids'] ?? [])));
        $senseIds = array_values(array_unique(array_map('intval', $fixtureState['sense_ids'] ?? [])));
        $reviewCardIds = array_values(array_unique(array_map('intval', $fixtureState['review_card_ids'] ?? [])));

        if ($reviewCardIds !== []) {
            ReviewLog::whereIn('review_card_id', $reviewCardIds)->delete();
            ReviewCard::whereIn('id', $reviewCardIds)->delete();
        }
        if ($senseIds !== []) {
            WordSense::whereIn('id', $senseIds)->delete();
        }
        if ($chapterIds !== []) {
            Chapter::whereIn('id', $chapterIds)->delete();
        }
        if ($bookIds !== []) {
            Book::whereIn('id', $bookIds)->delete();
        }
        if ($userIds !== []) {
            User::whereIn('id', $userIds)->delete();
        }

        if (($userIds !== [] && User::whereIn('id', $userIds)->exists())
            || ($reviewCardIds !== [] && ReviewCard::whereIn('id', $reviewCardIds)->exists())
        ) {
            throw new RuntimeException('H02_FIXTURE_CLEANUP_UNPROVEN');
        }
    }
}
