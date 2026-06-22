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
                            {--dry-run : Validate CSV without inserting}';

    protected $description = 'One-shot import of ECDICT EN-ZH pipe-delimited CSV into a local dictionary table.';

    public function handle(): int
    {
        // raise memory limit for large CSV
        ini_set('memory_limit', '512M');
        DB::disableQueryLog();

        $csvPath = $this->option('csv') ?: 'C:\Users\Administrator\Desktop\linguacafe\linguacafe_ecdict_en_zh_pipe.csv';
        $batchSize = (int) $this->option('batch');
        $dryRun = (bool) $this->option('dry-run');

        if (!file_exists($csvPath)) {
            $this->error("CSV file not found: $csvPath");
            return 1;
        }

        $fileSizeMb = round(filesize($csvPath) / 1024 / 1024, 1);
        $this->info("CSV: $csvPath ({$fileSizeMb} MB)");
        $this->info("Batch size: $batchSize");

        // --- read first line to confirm format ---
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

        // --- table and dictionary names ---
        $tableName = 'dict_en_ecdict_full';
        $dictionaryName = 'ECDICT EN-ZH';

        // --- check if table already exists ---
        if (Schema::hasTable($tableName)) {
            $existingCount = DB::table($tableName)->count();
            $this->warn("Table '$tableName' already exists with $existingCount rows.");

            if ($dryRun) {
                $this->info("Dry-run: would skip import (table exists).");
                return 0;
            }

            if (!$this->confirm("Drop table '$tableName' and re-import? This will delete all $existingCount existing entries.", false)) {
                $this->info('Aborted by user.');
                return 0;
            }

            Schema::drop($tableName);
            Dictionary::where('database_table_name', $tableName)->delete();
            $this->info("Dropped table '$tableName' and removed dictionary metadata.");
        }

        // --- create table ---
        if (!$dryRun) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->string('word', 256)->collation('utf8mb4_bin')->index();
                $table->string('definitions', 2048)->collation('utf8mb4_bin');
                $table->timestamps();
            });
            $this->info("Created table '$tableName'.");
        } else {
            $this->info("Dry-run: would create table '$tableName'.");
        }

        // --- create dictionary metadata ---
        if (!$dryRun) {
            $existingDict = Dictionary::where('database_table_name', $tableName)->first();
            if (!$existingDict) {
                $dict = new Dictionary();
                $dict->name = $dictionaryName;
                $dict->type = 'custom_csv';
                $dict->database_table_name = $tableName;
                $dict->source_language = 'english';
                $dict->target_language = 'chinese';
                $dict->color = '#42A5F5';
                $dict->enabled = true;
                $dict->created_at = Carbon::now();
                $dict->updated_at = Carbon::now();
                $dict->save();
                $this->info("Created dictionary metadata: '$dictionaryName' (english→chinese).");
            } else {
                $existingDict->update(['enabled' => true, 'updated_at' => Carbon::now()]);
                $this->info("Updated existing dictionary metadata: '$dictionaryName'.");
            }
        } else {
            $this->info("Dry-run: would create dictionary metadata '$dictionaryName'.");
        }

        // --- import rows ---
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
        $lineNo = 1; // header was line 0
        $startTime = microtime(true);

        if (!$dryRun) {
            DB::beginTransaction();
        }

        while (($row = fgetcsv($handle, 4096, '|')) !== false) {
            $lineNo++;

            // validate row
            if (count($row) < 2) {
                $errors++;
                if ($errors <= 5) {
                    $this->warn("Line $lineNo: missing columns, skipped.");
                }
                continue;
            }

            $word = trim($row[0]);
            $definitions = trim($row[1]);

            // skip empty
            if ($word === '' || $definitions === '') {
                $skipped++;
                continue;
            }

            // skip too long
            if (mb_strlen($word) > 255) {
                $skipped++;
                continue;
            }

            // truncate definitions to 2047 chars
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
                    DB::table($tableName)->insert($batch);
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

        // commit remaining batch
        if (count($batch) > 0 && !$dryRun) {
            DB::table($tableName)->insert($batch);
            DB::commit();
        } elseif ($dryRun) {
            // no-op
        } else {
            DB::commit(); // commit empty (just in case)
        }

        unset($batch);
        gc_collect_cycles();

        fclose($handle);

        $elapsed = round(microtime(true) - $startTime, 1);

        $this->newLine();
        $this->info("=== Import Complete ===");
        $this->info("Total lines processed: $lineNo");
        $this->info("Imported: $total");
        $this->info("Skipped (empty/long): $skipped");
        $this->info("Errors (malformed): $errors");
        $this->info("Time: {$elapsed}s");

        if (!$dryRun) {
            $dbCount = DB::table($tableName)->count();
            $this->info("Rows in database: $dbCount");
        }

        return 0;
    }
}
