<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use App\Models\Dictionary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class ImportEcdictDictionary extends Command
{
    protected $signature = 'dictionary:import-ecdict
                            {--csv= : Path to the pipe-delimited CSV file}
                            {--batch=2000 : Rows per batch commit}
                            {--dry-run : Validate CSV without inserting}
                            {--status : Only check and report dictionary health}
                            {--force : Skip health check and confirmation, force re-import}';

    protected $description = 'Import ECDICT EN-ZH pipe-delimited CSV into dict_en_ecdict_full.
Safe to run repeatedly — skips if dictionary is healthy unless --force is used.';

    private const TABLE_NAME = 'dict_en_ecdict_full';
    private const DICT_NAME = 'ECDICT EN-ZH';
    private const EXPECTED_MIN_ROWS = 700_000;
    private const DEFAULT_CSV = 'C:\Users\Administrator\Desktop\linguacafe\linguacafe_ecdict_en_zh_pipe.csv';

    public function handle(): int
    {
        ini_set('memory_limit', '512M');
        DB::disableQueryLog();

        $statusOnly = (bool) $this->option('status');
        $force = (bool) $this->option('force');

        // ─── --status: only report ───
        if ($statusOnly) {
            return $this->showStatus();
        }

        $csvPath = $this->option('csv') ?: self::DEFAULT_CSV;
        $batchSize = (int) $this->option('batch');
        $dryRun = (bool) $this->option('dry-run');

        // ─── pre-check: table healthy → skip ───
        $health = $this->checkHealth();
        if ($health['healthy'] && !$force) {
            $this->info("Dictionary '" . self::DICT_NAME . "' is healthy.");
            $this->info("  Table: " . self::TABLE_NAME);
            $this->info("  Rows:  " . number_format($health['row_count']));
            $this->info("  Metadata: " . ($health['has_metadata'] ? 'present' : 'missing'));
            $this->newLine();
            $this->info('Use --force to re-import, or --status to check health.');
            return 0;
        }

        if ($health['table_exists'] && !$health['healthy'] && !$force) {
            $this->warn("Dictionary table exists but row count is low: " . number_format($health['row_count']) . " (expected ~" . number_format(self::EXPECTED_MIN_ROWS) . "+).");
            if (!$this->confirm("Drop and re-import?", false)) {
                $this->info('Aborted. Use --force to skip confirmation.');
                return 0;
            }
        }

        // ─── CSV validation ───
        if (!file_exists($csvPath)) {
            $this->error("CSV file not found: $csvPath");
            $this->info('Specify path with: --csv=/path/to/file.csv');
            return 1;
        }

        $fileSizeMb = round(filesize($csvPath) / 1024 / 1024, 1);
        $this->info("CSV: $csvPath ({$fileSizeMb} MB)");
        $this->info("Batch size: $batchSize");

        // ─── header check ───
        $handle = fopen($csvPath, 'r');
        if (!$handle) {
            $this->error('Cannot open CSV file.');
            return 1;
        }
        $header = fgetcsv($handle, 4096, '|');
        fclose($handle);

        if (!$header || count($header) < 2 || strtolower(trim($header[0])) !== 'word') {
            $this->error('CSV header must be "Word|Translation". Got: ' . json_encode($header));
            return 1;
        }
        $this->info('CSV header confirmed: Word|Translation');

        // ─── drop existing table ───
        if (Schema::hasTable(self::TABLE_NAME)) {
            $existingCount = DB::table(self::TABLE_NAME)->count();

            if ($dryRun) {
                $this->info("Dry-run: would drop table '" . self::TABLE_NAME . "' ($existingCount rows).");
            } else {
                Schema::drop(self::TABLE_NAME);
                Dictionary::where('database_table_name', self::TABLE_NAME)->delete();
                $this->info("Dropped table '" . self::TABLE_NAME . "' ($existingCount rows) and removed metadata.");
            }
        }

        // ─── create table ───
        if (!$dryRun) {
            Schema::create(self::TABLE_NAME, function (Blueprint $table) {
                $table->id();
                $table->string('word', 256)->collation('utf8mb4_bin')->index();
                $table->string('definitions', 2048)->collation('utf8mb4_bin');
                $table->timestamps();
            });
            $this->info("Created table '" . self::TABLE_NAME . "'.");
        } else {
            $this->info("Dry-run: would create table '" . self::TABLE_NAME . "'.");
        }

        // ─── metadata ───
        if (!$dryRun) {
            $existingDict = Dictionary::where('database_table_name', self::TABLE_NAME)->first();
            if (!$existingDict) {
                $dict = new Dictionary();
                $dict->name = self::DICT_NAME;
                $dict->type = 'custom_csv';
                $dict->database_table_name = self::TABLE_NAME;
                $dict->source_language = 'english';
                $dict->target_language = 'chinese';
                $dict->color = '#42A5F5';
                $dict->enabled = true;
                $dict->created_at = Carbon::now();
                $dict->updated_at = Carbon::now();
                $dict->save();
                $this->info("Created dictionary metadata: '" . self::DICT_NAME . "' (english→chinese).");
            } else {
                $existingDict->update(['enabled' => true, 'updated_at' => Carbon::now()]);
                $this->info("Updated existing dictionary metadata: '" . self::DICT_NAME . "'.");
            }
        } else {
            $this->info("Dry-run: would create dictionary metadata '" . self::DICT_NAME . "'.");
        }

        // ─── import rows ───
        $this->info('Starting import...');

        $handle = fopen($csvPath, 'r');
        if (!$handle) {
            $this->error('Cannot open CSV for reading.');
            return 1;
        }

        // skip header
        fgetcsv($handle, 4096, '|');

        $total = 0;
        $skipped = 0;
        $errors = 0;
        $batch = [];
        $lineNo = 1;
        $startTime = microtime(true);

        if (!$dryRun) {
            DB::beginTransaction();
        }

        while (($row = fgetcsv($handle, 4096, '|')) !== false) {
            $lineNo++;

            if (count($row) < 2) {
                $errors++;
                if ($errors <= 5) {
                    $this->warn("Line $lineNo: missing columns, skipped.");
                }
                continue;
            }

            $word = trim($row[0]);
            $definitions = trim($row[1]);

            if ($word === '' || $definitions === '') {
                $skipped++;
                continue;
            }

            if (mb_strlen($word) > 255) {
                $skipped++;
                continue;
            }

            if (mb_strlen($definitions) > 2047) {
                $definitions = mb_substr($definitions, 0, 2047);
            }

            $word = mb_strtolower($word, 'UTF-8');

            if (!$dryRun) {
                $batch[] = [
                    'word' => $word,
                    'definitions' => $definitions,
                ];
            }

            $total++;

            if (count($batch) >= $batchSize) {
                if (!$dryRun) {
                    DB::table(self::TABLE_NAME)->insert($batch);
                    DB::commit();
                    DB::beginTransaction();
                }

                $elapsed = microtime(true) - $startTime;
                $rate = $total / max($elapsed, 1);
                $this->info("  Progress: $total rows (skipped: $skipped, errors: $errors) — " . round($rate) . " rows/sec");

                $batch = [];
                gc_collect_cycles();
            }
        }

        if (count($batch) > 0 && !$dryRun) {
            DB::table(self::TABLE_NAME)->insert($batch);
            DB::commit();
        } elseif (!$dryRun) {
            DB::commit();
        }

        unset($batch);
        gc_collect_cycles();
        fclose($handle);

        $elapsed = round(microtime(true) - $startTime, 1);

        $this->newLine();
        $this->info("=== Import Complete ===");
        $this->info("Lines processed: $lineNo");
        $this->info("Imported: $total");
        $this->info("Skipped (empty/long): $skipped");
        $this->info("Errors (malformed): $errors");
        $this->info("Time: {$elapsed}s");

        if (!$dryRun) {
            $dbCount = DB::table(self::TABLE_NAME)->count();
            $this->info("Rows in database: " . number_format($dbCount));
        }

        return 0;
    }

    private function checkHealth(): array
    {
        $tableExists = Schema::hasTable(self::TABLE_NAME);
        $rowCount = $tableExists ? DB::table(self::TABLE_NAME)->count() : 0;
        $hasMetadata = Dictionary::where('database_table_name', self::TABLE_NAME)->exists();
        $healthy = $tableExists && $rowCount >= self::EXPECTED_MIN_ROWS;

        return [
            'table_exists' => $tableExists,
            'row_count' => $rowCount,
            'has_metadata' => $hasMetadata,
            'healthy' => $healthy,
        ];
    }

    private function showStatus(): int
    {
        $health = $this->checkHealth();

        $this->newLine();
        $this->info("=== ECDICT Dictionary Status ===");
        $this->info("Dictionary name: " . self::DICT_NAME);
        $this->info("Table name:      " . self::TABLE_NAME);
        $this->info("Table exists:    " . ($health['table_exists'] ? 'YES' : 'NO'));
        $this->info("Row count:       " . number_format($health['row_count']));
        $this->info("Expected min:    " . number_format(self::EXPECTED_MIN_ROWS));
        $this->info("Metadata:        " . ($health['has_metadata'] ? 'present' : 'MISSING'));
        $this->info("Overall health:  " . ($health['healthy'] ? 'HEALTHY' : 'DEGRADED'));

        if (!$health['healthy']) {
            $this->newLine();
            $this->warn("Dictionary is not healthy. To restore:");
            $this->warn("  php artisan dictionary:import-ecdict");
            $this->warn("  php artisan dictionary:import-ecdict --force   (if table exists but is incomplete)");
        }

        return $health['healthy'] ? 0 : 1;
    }
}
