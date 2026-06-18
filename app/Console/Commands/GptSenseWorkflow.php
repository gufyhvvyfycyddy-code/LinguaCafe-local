<?php

namespace App\Console\Commands;

use App\Services\SenseMappingImportService;
use App\Services\SenseMappingValidationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class GptSenseWorkflow extends Command
{
    protected $signature = 'senses:gpt-workflow
        {action : prepare, validate-latest, or import-latest}
        {--user_id=}
        {--language=}
        {--input=}
        {--dry-run}';

    protected $description = 'Run local semi-automatic GPT sense mapping workflow steps.';

    private const ROOT = 'storage/app/gpt-workflow';

    public function handle(SenseMappingValidationService $validator, SenseMappingImportService $importer): int
    {
        $userId = (int) $this->option('user_id');
        $language = (string) $this->option('language');
        $action = (string) $this->argument('action');

        if ($userId <= 0 || $language === '') {
            $this->error('The --user_id and --language options are required.');

            return self::FAILURE;
        }

        $this->ensureDirectories();

        return match ($action) {
            'prepare' => $this->prepare($userId, $language),
            'validate-latest' => $this->validateLatest($validator, $userId, $language),
            'import-latest' => $this->importLatest($importer, $userId, $language, (bool) $this->option('dry-run')),
            default => $this->failAction($action),
        };
    }

    private function prepare(int $userId, string $language): int
    {
        $input = (string) $this->option('input');
        if ($input === '') {
            $this->error('The --input option is required for prepare.');

            return self::FAILURE;
        }

        $package = self::ROOT . '/package/gpt-sense-package.md';
        $exitCode = Artisan::call('senses:make-gpt-package', [
            '--user_id' => $userId,
            '--language' => $language,
            '--input' => $input,
            '--output' => $package,
        ]);

        if ($exitCode !== self::SUCCESS) {
            $this->line(Artisan::output());

            return self::FAILURE;
        }

        $prompt = self::ROOT . '/package/prompt.txt';
        File::put(base_path($prompt), '请严格按照 package 中的规则输出 sense-mapping.json，不要输出解释，不要输出 Markdown。');

        $this->info("Package: {$package}");
        $this->info("Prompt: {$prompt}");
        $this->line('Next: open ChatGPT, paste or upload the package, then put the downloaded JSON into storage/app/gpt-workflow/downloads/.');

        return self::SUCCESS;
    }

    private function validateLatest(SenseMappingValidationService $validator, int $userId, string $language): int
    {
        $latest = $this->latestJson('downloads');
        if ($latest === null) {
            $this->error('No JSON files found in storage/app/gpt-workflow/downloads/.');

            return self::FAILURE;
        }

        $result = $validator->validateFile($latest, $userId, $language);
        $destination = $this->archive($latest, $result['valid'] ? 'validated' : 'failed');
        $this->line(json_encode($result['summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if (!$result['valid']) {
            $report = $destination . '.errors.json';
            File::put($report, json_encode($result['summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->error("Validation failed. Copied file to {$this->relative($destination)} and report to {$this->relative($report)}.");

            return self::FAILURE;
        }

        $this->info("Validation passed. Copied file to {$this->relative($destination)}.");

        return self::SUCCESS;
    }

    private function importLatest(SenseMappingImportService $importer, int $userId, string $language, bool $dryRun): int
    {
        $latest = $this->latestJson('validated');
        if ($latest === null) {
            $this->error('No validated JSON files found. Run validate-latest first.');

            return self::FAILURE;
        }

        $summary = $importer->importFile($latest, $userId, $language, $dryRun);
        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if ($summary['errors'] !== []) {
            return self::FAILURE;
        }

        if (!$dryRun) {
            $destination = $this->archive($latest, 'imported');
            $this->info("Imported successfully. Copied file to {$this->relative($destination)}.");
            $this->line('Next: open /senses/review to confirm imported occurrences.');
        }

        return self::SUCCESS;
    }

    private function ensureDirectories(): void
    {
        foreach (['input', 'package', 'downloads', 'validated', 'imported', 'failed'] as $directory) {
            File::ensureDirectoryExists(base_path(self::ROOT . '/' . $directory));
        }
    }

    private function latestJson(string $directory): ?string
    {
        $files = collect(File::files(base_path(self::ROOT . '/' . $directory)))
            ->filter(fn ($file) => strtolower($file->getExtension()) === 'json')
            ->sortByDesc(fn ($file) => $file->getMTime())
            ->values();

        return $files->isEmpty() ? null : $files->first()->getPathname();
    }

    private function archive(string $path, string $directory): string
    {
        $timestamp = Carbon::now()->format('Ymd_His');
        $destination = base_path(self::ROOT . '/' . $directory . '/' . $timestamp . '_' . basename($path));
        File::copy($path, $destination);

        return $destination;
    }

    private function failAction(string $action): int
    {
        $this->error("Unknown action: {$action}");

        return self::FAILURE;
    }

    private function relative(string $path): string
    {
        return str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path);
    }
}
