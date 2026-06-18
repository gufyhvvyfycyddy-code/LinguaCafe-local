<?php

namespace App\Console\Commands;

use App\Services\SenseMappingImportService;
use App\Services\SenseMappingValidationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class GptSenseWorkflow extends Command
{
    protected $signature = 'senses:gpt-workflow
        {action : prepare, validate-latest, import-latest, or doctor}
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
            'doctor' => $this->doctor($userId, $language),
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

    private function doctor(int $userId, string $language): int
    {
        $failed = false;
        $this->line('LinguaCafe FSRS GPT workflow doctor');

        $this->doctorLine(version_compare(PHP_VERSION, '8.2.0', '>='), 'PHP version: ' . PHP_VERSION, 'Install PHP 8.2 or newer.', $failed);
        $this->doctorLine(
            $this->pythonAvailable(),
            'Python is available for tokenizer startup',
            '安装 Python 3，并确认 python 在 PATH 中；Windows 可在 scripts/windows/gpt-workflow-config.bat 设置 PYTHON_EXE。',
            $failed,
            true
        );
        $tokenizerUrl = $this->tokenizerUrl();
        $this->doctorLine(
            $tokenizerUrl !== '',
            'tokenizer URL is configured: ' . ($tokenizerUrl ?: '(empty)'),
            '设置 .env 的 PYTHON_CONTAINER_NAME=http://127.0.0.1:8678，或在 Docker 中使用 linguacafe-python-service。',
            $failed
        );
        $this->doctorLine(
            $this->tokenizerReachable($tokenizerUrl),
            'Python tokenizer service is reachable',
            '运行 scripts/windows/tokenizer-start.bat；若缺少依赖，安装 scripts/windows/tokenizer-requirements.txt 中的包，并安装 en_core_web_sm。',
            $failed,
            true
        );
        $this->doctorLine(
            extension_loaded('fsrs-rs-php') && class_exists('\fsrs\FSRS') && function_exists('get_default_parameters'),
            'fsrs-rs-php native extension loaded',
            'Build and load fsrs-rs-php. Use fallback only for local tests.',
            $failed,
            true
        );

        foreach (['review_cards', 'review_logs', 'word_senses', 'word_sense_occurrences'] as $table) {
            $this->doctorLine(Schema::hasTable($table), "{$table} table exists", 'Run php artisan migrate --force.', $failed);
        }

        foreach (['input', 'package', 'downloads', 'validated', 'imported', 'failed'] as $directory) {
            $this->doctorLine(is_dir(base_path(self::ROOT . '/' . $directory)), "{$directory} workflow directory exists", 'Run any senses:gpt-workflow action to create workflow directories.', $failed, true);
        }

        $tempInput = self::ROOT . '/input/doctor-material.txt';
        File::put(base_path($tempInput), 'They charge a fee.');

        $packageExit = Artisan::call('senses:make-gpt-package', [
            '--user_id' => $userId,
            '--language' => $language,
            '--input' => $tempInput,
            '--output' => self::ROOT . '/package/doctor-package.md',
        ]);
        $this->doctorLine($packageExit === self::SUCCESS, 'make-gpt-package command works', 'Check storage permissions and learned sense export.', $failed);

        $exportExit = Artisan::call('senses:export-learned', [
            '--user_id' => $userId,
            '--language' => $language,
            '--output' => self::ROOT . '/package/doctor-learned-senses.json',
        ]);
        $this->doctorLine($exportExit === self::SUCCESS, 'learned-senses export works', 'Check database connection and storage permissions.', $failed);

        if ($this->latestJson('downloads') === null) {
            $this->warn('WARN validate-latest: no downloaded JSON found. Fix: put sense-mapping.json into storage/app/gpt-workflow/downloads/.');
        } else {
            $validateExit = Artisan::call('senses:gpt-workflow', [
                'action' => 'validate-latest',
                '--user_id' => $userId,
                '--language' => $language,
            ]);
            $this->doctorLine($validateExit === self::SUCCESS, 'validate-latest works', 'Inspect failed/ error report and fix GPT JSON.', $failed, true);
        }

        if ($this->latestJson('validated') === null) {
            $this->warn('WARN import-latest --dry-run: no validated JSON found. Fix: run validate-latest successfully first.');
        } else {
            $dryRunExit = Artisan::call('senses:gpt-workflow', [
                'action' => 'import-latest',
                '--user_id' => $userId,
                '--language' => $language,
                '--dry-run' => true,
            ]);
            $this->doctorLine($dryRunExit === self::SUCCESS, 'import-latest --dry-run works', 'Fix import summary errors before formal import.', $failed, true);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
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

    private function doctorLine(bool $ok, string $message, string $fix, bool &$failed, bool $warnOnly = false): void
    {
        if ($ok) {
            $this->info("OK {$message}");

            return;
        }

        if ($warnOnly) {
            $this->warn("WARN {$message}. Fix: {$fix}");

            return;
        }

        $failed = true;
        $this->error("FAIL {$message}. Fix: {$fix}");
    }

    private function tokenizerUrl(): string
    {
        $configured = env('PYTHON_CONTAINER_NAME', 'http://127.0.0.1:8678');
        if (str_starts_with($configured, 'http://') || str_starts_with($configured, 'https://')) {
            return rtrim($configured, '/');
        }

        return 'http://' . rtrim($configured, '/') . ':8678';
    }

    private function tokenizerReachable(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        try {
            return Http::timeout(3)->get($url . '/models/list')->successful();
        } catch (\Throwable $exception) {
            return false;
        }
    }

    private function pythonAvailable(): bool
    {
        $command = PHP_OS_FAMILY === 'Windows' ? 'where python' : 'command -v python3 || command -v python';
        $output = [];
        $exitCode = 1;
        @exec($command, $output, $exitCode);

        return $exitCode === 0;
    }
}
