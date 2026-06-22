<?php

namespace App\Console\Commands;

use App\Models\Goal;
use App\Models\Setting;
use App\Models\Dictionary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseDoctor extends Command
{
    protected $signature = 'db:doctor {--fix : Auto-repair missing settings and goals}';

    protected $description = 'Check database consistency: settings, goals, dictionaries, test isolation.';

    private int $issues = 0;

    public function handle(): int
    {
        $this->info('=== LinguaCafe Database Doctor ===');
        $this->newLine();

        $this->checkEnvironment();
        $this->checkSettings();
        $this->checkGoals();
        $this->checkEcdict();

        $this->newLine();
        if ($this->issues === 0) {
            $this->info('All checks passed — database is healthy.');
        } else {
            $this->warn("{$this->issues} issue(s) found. Run with --fix to auto-repair.");
        }

        return $this->issues > 0 ? 1 : 0;
    }

    private function checkEnvironment(): void
    {
        $this->info('── Environment ──');
        $appEnv = config('app.env');
        $dbName = config('database.connections.mysql.database');

        $this->line("  APP_ENV:       {$appEnv}");
        $this->line("  DB_DATABASE:   {$dbName}");

        // Check test isolation
        $testEnvFile = base_path('.env.testing');
        $phpunitXml = base_path('phpunit.xml');

        if (file_exists($testEnvFile)) {
            $testEnv = file_get_contents($testEnvFile);
            preg_match('/DB_DATABASE=(\S+)/', $testEnv, $m);
            $testDb = $m[1] ?? 'NOT SET';
            $this->line("  .env.testing DB: {$testDb}");

            if ($testDb === $dbName && $appEnv !== 'testing') {
                $this->warn("  ⚠ .env.testing uses the same database as development ({$dbName})!");
                $this->warn("    Run php artisan test will CLEAR the development database.");
                $this->issues++;
            }
        } else {
            $this->warn('  ⚠ .env.testing not found — tests may run against development database!');
            $this->warn('    Create .env.testing with a separate DB_DATABASE.');
            $this->issues++;
        }

        $this->newLine();
    }

    private function checkSettings(): void
    {
        $this->info('── Settings ──');

        $required = ['reviewIntervals'];
        $missing = [];

        foreach ($required as $name) {
            $exists = Setting::where('name', $name)->exists();
            $status = $exists ? 'present' : 'MISSING';
            $this->line("  {$name}: {$status}");
            if (!$exists) {
                $missing[] = $name;
            }
        }

        if (!empty($missing)) {
            $this->issues++;
            if ($this->option('fix')) {
                $this->info('  → Running SettingsSeeder...');
                $seeder = new \Database\Seeders\SettingsSeeder();
                $seeder->run();
                $this->info('  → SettingsSeeder completed.');
            } else {
                $this->warn('  → Fix: php artisan db:seed --class=SettingsSeeder');
            }
        }

        // optional
        $optional = ['ankiAutoAddCards', 'ankiShowNotifications'];
        foreach ($optional as $name) {
            $exists = Setting::where('name', $name)->exists();
            if (!$exists) {
                $this->line("  {$name}: MISSING (optional)");
            }
        }

        $this->newLine();
    }

    private function checkGoals(): void
    {
        $this->info('── Goals (user 1, english) ──');

        $goalTypes = ['review', 'read_words', 'learn_words'];
        $missing = [];

        foreach ($goalTypes as $type) {
            $exists = Goal::where('user_id', 1)->where('language', 'english')->where('type', $type)->exists();
            $status = $exists ? 'present' : 'MISSING';
            $this->line("  {$type}: {$status}");
            if (!$exists) {
                $missing[] = $type;
            }
        }

        if (!empty($missing)) {
            $this->issues++;
            if ($this->option('fix')) {
                $this->info('  → Auto-creating goals...');
                $goalService = app(\App\Services\GoalService::class);
                $goalService->createGoalsForLanguage(1, 'english');
                $this->info('  → Goals created.');
            } else {
                $this->warn("  → Fix: php artisan tinker --execute=\"(new App\\Services\\GoalService())->createGoalsForLanguage(1, 'english');\"");
            }
        }

        $this->newLine();
    }

    private function checkEcdict(): void
    {
        $this->info('── ECDICT Dictionary ──');

        $tableExists = Schema::hasTable('dict_en_ecdict_full');
        $rowCount = $tableExists ? DB::table('dict_en_ecdict_full')->count() : 0;
        $hasMeta = Dictionary::where('database_table_name', 'dict_en_ecdict_full')->exists();
        $healthy = $tableExists && $rowCount >= 700_000;

        $this->line('  Table:        ' . ($tableExists ? "YES ({$rowCount} rows)" : 'MISSING'));
        $this->line('  Metadata:     ' . ($hasMeta ? 'present' : 'MISSING'));
        $this->line('  Health:       ' . ($healthy ? 'HEALTHY' : 'DEGRADED'));

        if (!$healthy) {
            $this->issues++;
            $this->warn('  → Fix: php artisan dictionary:import-ecdict');
            $this->warn('  → Check: php artisan dictionary:import-ecdict --status');
        }

        $this->newLine();
    }
}
