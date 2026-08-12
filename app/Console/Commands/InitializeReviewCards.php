<?php

namespace App\Console\Commands;

use App\Services\ReviewCardService;
use Illuminate\Console\Command;

class InitializeReviewCards extends Command
{
    protected $signature = 'reviews:initialize-cards {--user_id=} {--language=} {--dry-run}';

    protected $description = 'Diagnose missing legacy word review cards after the D-04 cutover.';

    public function handle(ReviewCardService $reviewCardService): int
    {
        $userId = $this->option('user_id') !== null ? (int) $this->option('user_id') : null;
        $language = $this->option('language') ?: null;

        if ($this->option('dry-run')) {
            $count = $reviewCardService->countInitializableWords($userId, $language);
            $this->info("Dry run: {$count} legacy word card(s) are missing (diagnostic only).");

            return self::SUCCESS;
        }

        $this->error('Legacy word-card creation is disabled after the D-04 cutover.');

        return self::FAILURE;
    }
}
