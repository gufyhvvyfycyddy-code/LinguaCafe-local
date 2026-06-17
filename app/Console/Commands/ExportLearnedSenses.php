<?php

namespace App\Console\Commands;

use App\Services\LearnedSenseExportService;
use Illuminate\Console\Command;

class ExportLearnedSenses extends Command
{
    protected $signature = 'senses:export-learned {--user_id=} {--language=} {--output=}';

    protected $description = 'Export confirmed learned word senses for one user and language.';

    public function handle(LearnedSenseExportService $exportService): int
    {
        $userId = (int) $this->option('user_id');
        $language = (string) $this->option('language');
        $output = (string) $this->option('output');

        if ($userId <= 0 || $language === '' || $output === '') {
            $this->error('The --user_id, --language, and --output options are required.');

            return self::FAILURE;
        }

        $payload = $exportService->payload($userId, $language);

        $path = base_path($output);
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info("Exported " . count($payload['senses']) . " learned senses to {$output}.");

        return self::SUCCESS;
    }
}
