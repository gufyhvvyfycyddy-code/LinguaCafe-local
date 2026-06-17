<?php

namespace App\Console\Commands;

use App\Services\SenseMappingValidationService;
use Illuminate\Console\Command;

class ValidateSenseMapping extends Command
{
    protected $signature = 'senses:validate-mapping {path} {--user_id=} {--language=}';

    protected $description = 'Validate a sense-mapping JSON file without importing it.';

    public function handle(SenseMappingValidationService $validator): int
    {
        $userId = (int) $this->option('user_id');
        $language = (string) $this->option('language');
        $path = (string) $this->argument('path');

        if ($userId <= 0 || $language === '') {
            $this->error('The --user_id and --language options are required.');

            return self::FAILURE;
        }

        $result = $validator->validateFile($path, $userId, $language);
        $this->line(json_encode($result['summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $result['valid'] ? self::SUCCESS : self::FAILURE;
    }
}
