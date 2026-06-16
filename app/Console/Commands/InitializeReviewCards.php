<?php

namespace App\Console\Commands;

use App\Services\ReviewCardService;
use Illuminate\Console\Command;

class InitializeReviewCards extends Command
{
    protected $signature = 'reviews:initialize-cards {--user_id=} {--language=} {--dry-run}';

    protected $description = 'Create FSRS review cards for existing reviewable vocabulary.';

    public function handle(ReviewCardService $reviewCardService): int
    {
        $userId = $this->option('user_id') !== null ? (int) $this->option('user_id') : null;
        $language = $this->option('language') ?: null;

        if ($this->option('dry-run')) {
            $count = $reviewCardService->countInitializableWords($userId, $language);
            $this->info("Dry run: {$count} review cards would be created.");

            return self::SUCCESS;
        }

        $created = $reviewCardService->initializeExistingWords($userId, $language);

        $this->info("Created {$created} review cards.");

        return self::SUCCESS;
    }
}
