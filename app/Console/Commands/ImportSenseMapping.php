<?php

namespace App\Console\Commands;

use App\Services\SenseMappingImportService;
use Illuminate\Console\Command;

class ImportSenseMapping extends Command
{
    protected $signature = 'senses:import-mapping {path} {--user_id=} {--language=} {--dry-run}';

    protected $description = 'Import a validated sense-mapping JSON file into word sense occurrences.';

    public function handle(SenseMappingImportService $importer): int
    {
        $userId = (int) $this->option('user_id');
        $language = (string) $this->option('language');

        if ($userId <= 0 || $language === '') {
            $this->error('The --user_id and --language options are required.');

            return self::FAILURE;
        }

        $summary = $importer->importFile(
            (string) $this->argument('path'),
            $userId,
            $language,
            (bool) $this->option('dry-run'),
        );

        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $summary['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
