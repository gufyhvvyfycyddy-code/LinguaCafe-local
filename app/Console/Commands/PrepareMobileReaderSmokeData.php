<?php

namespace App\Console\Commands;

use App\Enums\ChapterProcessingStatusEnum;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrepareMobileReaderSmokeData extends Command
{
    protected $signature = 'smoke:mobile-reader-data
        {--email= : Existing testing user email}
        {--marker= : Optional marker suffix}
        {--json : Print JSON summary only}';

    protected $description = 'Prepare one deterministic English article for rendered Mobile Reader acceptance.';

    public function handle(): int
    {
        if (!app()->environment('testing')) {
            $this->error('APP_ENV must be testing.');
            return self::FAILURE;
        }

        $database = strtolower((string) DB::connection()->getDatabaseName());
        if ($database === '' || !str_contains($database, 'test')) {
            $this->error('A dedicated testing database is required.');
            return self::FAILURE;
        }
        $sentinel = (string) (getenv('LINGUACAFE_TEST_SENTINEL') ?: '');
        if (!str_starts_with($sentinel, '__testing_acceptance_sentinel_')
            || !DB::table('migrations')->where('migration', $sentinel)->exists()) {
            $this->error('A live PAB testing sentinel is required.');
            return self::FAILURE;
        }

        $email = trim((string) $this->option('email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid --email option is required.');
            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();
        if (!$user || $user->selected_language !== 'english') {
            $this->error('An existing English testing user is required.');
            return self::FAILURE;
        }

        $marker = $this->normalizeMarker((string) ($this->option('marker') ?: 'h10_ios_reader'));
        $result = DB::transaction(function () use ($user, $marker) {
            $book = Book::forceCreate([
                'name' => "H10 iOS Reader {$marker}",
                'language' => 'english',
                'user_id' => $user->id,
            ]);

            $tokens = [
                $this->token(0, 'Open', 'open', 'verb', true),
                $this->token(1, 'a', 'a', 'determiner', true),
                $this->token(2, 'bank', 'bank', 'noun', true),
                $this->token(3, 'account', 'account', 'noun', false),
            ];
            $chapter = Chapter::forceCreate([
                'name' => 'Reader touch source binding',
                'language' => 'english',
                'user_id' => $user->id,
                'book_id' => $book->id,
                'read_count' => 0,
                'word_count' => count($tokens),
                'raw_text' => 'Open a bank account',
                'unique_words' => json_encode(['open', 'a', 'bank', 'account'], JSON_THROW_ON_ERROR),
                'unique_word_ids' => '[]',
                'processed_text' => gzcompress(json_encode($tokens, JSON_THROW_ON_ERROR), 1),
                'subtitle_timestamps' => '[]',
                'processing_status' => ChapterProcessingStatusEnum::PROCESSED->value,
            ]);

            return [
                'marker' => $marker,
                'user_id' => $user->id,
                'book_id' => $book->id,
                'book_name' => $book->name,
                'chapter_id' => $chapter->id,
                'chapter_name' => $chapter->name,
                'source_text' => $chapter->raw_text,
            ];
        });

        $encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($this->option('json')) {
            $this->line($encoded);
            return self::SUCCESS;
        }

        $this->info('Mobile Reader smoke article prepared.');
        $this->line($encoded);
        return self::SUCCESS;
    }

    private function normalizeMarker(string $marker): string
    {
        $marker = strtolower(trim($marker));
        $marker = preg_replace('/[^a-z0-9_]+/', '_', $marker) ?: 'h10_ios_reader';
        return trim($marker, '_') ?: 'h10_ios_reader';
    }

    private function token(
        int $wordIndex,
        string $word,
        string $lemma,
        string $pos,
        bool $spaceAfter,
    ): object {
        return (object) [
            'word_index' => $wordIndex,
            'word' => $word,
            'lemma' => $lemma,
            'pos' => $pos,
            'sentence_index' => 0,
            'section_index' => 0,
            'spaceAfter' => $spaceAfter,
            'is_structure' => false,
        ];
    }
}
