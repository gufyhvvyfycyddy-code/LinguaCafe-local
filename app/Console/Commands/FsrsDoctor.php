<?php

namespace App\Console\Commands;

use App\Models\EncounteredWord;
use App\Models\ReviewCard;
use App\Models\WordSense;
use App\Services\ReviewCardService;
use Illuminate\Console\Command;

class FsrsDoctor extends Command
{
    protected $signature = 'fsrs:doctor
                            {--fix : Create missing review cards}
                            {--user_id= : Filter by user ID}
                            {--language= : Filter by language}';

    protected $description = 'Check and repair FSRS review card consistency.
Finds Learning words and confirmed senses that are missing review cards.
Use --fix to create missing cards.';

    public function handle(ReviewCardService $reviewCardService): int
    {
        $userId = $this->option('user_id') ? (int) $this->option('user_id') : null;
        $language = $this->option('language');
        $fix = (bool) $this->option('fix');

        $this->info('=== FSRS Review Card Doctor ===');
        $this->newLine();

        $wordStats = $this->checkWordCards($userId, $language, $fix, $reviewCardService);
        $senseStats = $this->checkSenseCards($userId, $language, $fix, $reviewCardService);

        $this->newLine();
        $this->info('=== Summary ===');

        $totalChecked = $wordStats['checked'] + $senseStats['checked'];
        $totalMissing = $wordStats['missing'] + $senseStats['missing'];
        $totalCreated = $wordStats['created'] + $senseStats['created'];

        $this->info("  Word cards:  {$wordStats['checked']} checked, {$wordStats['missing']} missing" . ($fix ? ", {$wordStats['created']} created" : ''));
        $this->info("  Sense cards: {$senseStats['checked']} checked, {$senseStats['missing']} missing" . ($fix ? ", {$senseStats['created']} created" : ''));

        if ($totalMissing > 0 && !$fix) {
            $this->newLine();
            $this->warn("{$totalMissing} review card(s) missing. Run with --fix to create them.");
        } elseif ($totalMissing === 0) {
            $this->info('All review cards are present.');
        }

        return $totalMissing > 0 && !$fix ? 1 : 0;
    }

    private function checkWordCards(?int $userId, ?string $language, bool $fix, ReviewCardService $service): array
    {
        $this->info('── Word Review Cards ──');

        $query = EncounteredWord::query()
            ->leftJoin('review_cards', function ($join) {
                $join->on('review_cards.target_id', '=', 'encountered_words.id')
                    ->where('review_cards.target_type', ReviewCard::TARGET_WORD)
                    ->whereColumn('review_cards.user_id', 'encountered_words.user_id')
                    ->whereColumn('review_cards.language_id', 'encountered_words.language');
            })
            ->where('encountered_words.stage', '<', 0)
            ->whereNull('review_cards.id')
            ->select('encountered_words.*');

        if ($userId !== null) {
            $query->where('encountered_words.user_id', $userId);
        }
        if ($language !== null) {
            $query->where('encountered_words.language', $language);
        }

        $missingWords = $query->get();
        $checked = EncounteredWord::where('stage', '<', 0)
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->when($language !== null, fn ($q) => $q->where('language', $language))
            ->count();
        $missing = $missingWords->count();
        $created = 0;

        $this->line("  Learning words (stage < 0): {$checked}");
        $this->line("  Missing word cards: {$missing}");

        if ($missing > 0 && $fix) {
            $this->info("  → Creating word review cards...");
            $bar = $this->output->createProgressBar($missing);
            $bar->start();

            foreach ($missingWords as $word) {
                $card = $service->ensureWordCard($word);
                if ($card !== null && $card->wasRecentlyCreated) {
                    $created++;
                }
                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
            $this->info("  → Created {$created} word card(s).");
        } elseif ($missing > 0 && !$fix) {
            $examples = $missingWords->take(5)->map(fn ($w) => "{$w->word} (id={$w->id}, user={$w->user_id})")->implode(', ');
            $this->line("  Examples: {$examples}");
        }

        $this->newLine();
        return ['checked' => $checked, 'missing' => $missing, 'created' => $created];
    }

    private function checkSenseCards(?int $userId, ?string $language, bool $fix, ReviewCardService $service): array
    {
        $this->info('── Sense Review Cards ──');

        $baseQuery = WordSense::query()->where('word_senses.status', WordSense::STATUS_CONFIRMED);
        if ($userId !== null) {
            $baseQuery->where('word_senses.user_id', $userId);
        }
        if ($language !== null) {
            $baseQuery->where('word_senses.language_id', $language);
        }

        $checked = (clone $baseQuery)->count();

        $missingSenses = (clone $baseQuery)
            ->leftJoin('review_cards', function ($join) {
                $join->on('review_cards.target_id', '=', 'word_senses.id')
                    ->where('review_cards.target_type', ReviewCard::TARGET_SENSE)
                    ->whereColumn('review_cards.user_id', 'word_senses.user_id')
                    ->whereColumn('review_cards.language_id', 'word_senses.language_id');
            })
            ->whereNull('review_cards.id')
            ->select('word_senses.*')
            ->get();

        $missing = $missingSenses->count();
        $created = 0;

        $this->line("  Confirmed senses: {$checked}");
        $this->line("  Missing sense cards: {$missing}");

        if ($missing > 0 && $fix) {
            $this->info("  → Creating sense review cards...");
            $bar = $this->output->createProgressBar($missing);
            $bar->start();

            foreach ($missingSenses as $sense) {
                $card = $service->ensureSenseCard($sense);
                if ($card !== null && $card->wasRecentlyCreated) {
                    $created++;
                }
                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
            $this->info("  → Created {$created} sense card(s).");
        } elseif ($missing > 0 && !$fix) {
            $examples = $missingSenses->take(5)->map(fn ($s) => "{$s->lemma} (id={$s->id}, user={$s->user_id})")->implode(', ');
            $this->line("  Examples: {$examples}");
        }

        $this->newLine();
        return ['checked' => $checked, 'missing' => $missing, 'created' => $created];
    }
}
