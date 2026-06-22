<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class TokenizerDoctor extends Command
{
    protected $signature = 'tokenizer:doctor
                            {--language=english : Language code to check}
                            {--json : Output results as JSON (for scripting)}
                            {--include-bad-lemma-scan : Also scan DB for known bad lemmas}';

    protected $description = 'Diagnose Python tokenizer health: spaCy model, LemmInflect, test cases, bad lemma scan.

Checks:
  1. Python tokenizer service reachable
  2. spaCy en_core_web_sm model loaded
  3. spaCy returns correct lemmas for opened→open, called→call
  4. LemmInflect importable and returns correct lemmas
  5. (optional) Scan encountered_words for known bad lemmas';

    /**
     * Known bad lemmas that the old PHP fallback could produce.
     * These are hardcoded stem+'e' errors on regular -ed/-ing verbs.
     */
    private const KNOWN_BAD_LEMMAS = [
        'opene'  => 'open',
        'reporte'=> 'report',
        'walke'  => 'walk',
        'looke'  => 'look',
        'worke'  => 'work',
        'talke'  => 'talk',
        'likee'  => 'like',
        'makee'  => 'make',
        'takee'  => 'take',
        'givee'  => 'give',
        'havee'  => 'have',
        'livee'  => 'live',
        'lovee'  => 'love',
        'comee'  => 'come',
        'reade'  => 'read',
        'nee'    => 'need',
        'cal'    => 'call',
        'calle'  => 'call',
    ];

    private string $tokenizerUrl;
    private bool $asJson = false;

    public function handle(): int
    {
        $this->asJson = (bool) $this->option('json');
        $includeBadLemmaScan = (bool) $this->option('include-bad-lemma-scan');

        $this->tokenizerUrl = $this->resolveTokenizerUrl();

        if (!$this->asJson) {
            $this->info('=== Tokenizer Doctor ===');
            $this->info("Tokenizer URL: {$this->tokenizerUrl}");
            $this->newLine();
        }

        $results = [
            'tokenizer_reachable' => false,
            'spacy_model_loaded' => false,
            'spacy_lemmas_correct' => false,
            'lemminflect_available' => false,
            'lemminflect_lemmas_correct' => false,
            'test_cases' => [],
            'bad_lemmas' => [],
        ];

        // Check 1: Tokenizer reachable
        $results['tokenizer_reachable'] = $this->checkReachable();

        if ($results['tokenizer_reachable']) {
            // Check 2-5: Use health endpoint for detailed checks
            $health = $this->fetchHealth();
            if ($health) {
                $results['spacy_model_loaded'] = $health['en_core_web_sm_loaded'] ?? false;
                $results['lemminflect_available'] = $health['lemminflect_available'] ?? false;

                // Verify test cases
                $tests = $health['tests'] ?? [];
                $results['test_cases'] = $tests;

                // Check spaCy lemmas for opened, called
                $spacyOk = true;
                foreach (['opened' => 'open', 'called' => 'call'] as $word => $expected) {
                    $actual = $tests[$word] ?? null;
                    if ($actual !== $expected) {
                        $spacyOk = false;
                    }
                }
                $results['spacy_lemmas_correct'] = $spacyOk;

                // Check LemmInflect lemmas
                $liOk = $health['lemminflect_available'] ?? false;
                foreach (['lemminflect_opened' => 'open', 'lemminflect_called' => 'call'] as $key => $expected) {
                    $actual = $tests[$key] ?? null;
                    if ($actual !== $expected) {
                        $liOk = false;
                    }
                }
                $results['lemminflect_lemmas_correct'] = $liOk;
            }
        }

        // Check 6: DB scan for known bad lemmas
        if ($includeBadLemmaScan) {
            $results['bad_lemmas'] = $this->scanBadLemmas();
        }

        if ($this->asJson) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->renderHumanOutput($results, $includeBadLemmaScan);
        }

        // Determine exit code
        $allOk = $results['tokenizer_reachable']
            && $results['spacy_model_loaded']
            && $results['spacy_lemmas_correct'];

        if (!$allOk) {
            return 1;
        }

        return 0;
    }

    private function resolveTokenizerUrl(): string
    {
        $configured = env('PYTHON_CONTAINER_NAME', 'http://127.0.0.1:8678');
        if (str_starts_with($configured, 'http://') || str_starts_with($configured, 'https://')) {
            return rtrim($configured, '/');
        }
        return 'http://' . rtrim($configured, '/') . ':8678';
    }

    private function checkReachable(): bool
    {
        try {
            $response = Http::timeout(5)->get($this->tokenizerUrl . '/models/list');
            return $response->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function fetchHealth(): ?array
    {
        try {
            $response = Http::timeout(30)->get($this->tokenizerUrl . '/tokenizer/health');
            if ($response->successful()) {
                return json_decode($response->body(), true);
            }
        } catch (\Throwable $e) {
            // Health endpoint unavailable — maybe old tokenizer without this endpoint
        }
        return null;
    }

    private function scanBadLemmas(): array
    {
        $badLemmas = array_keys(self::KNOWN_BAD_LEMMAS);
        $found = [];

        try {
            $rows = DB::table('encountered_words')
                ->where('language', 'english')
                ->whereIn('lemma', $badLemmas)
                ->select('id', 'word', 'lemma', 'base_word', 'study_base')
                ->get();

            foreach ($rows as $row) {
                $correct = self::KNOWN_BAD_LEMMAS[$row->lemma] ?? null;
                $found[] = [
                    'id' => $row->id,
                    'word' => $row->word,
                    'current_lemma' => $row->lemma,
                    'correct_lemma' => $correct,
                    'base_word' => $row->base_word,
                    'study_base' => $row->study_base,
                ];
            }
        } catch (\Throwable $e) {
            // DB might not be available
        }

        return $found;
    }

    private function renderHumanOutput(array $results, bool $scannedBadLemmas): void
    {
        // 1. Tokenizer reachable
        $this->renderCheck(
            'Python tokenizer service reachable',
            $results['tokenizer_reachable'],
            $results['tokenizer_reachable'] ? $this->tokenizerUrl : 'Service not running — start tokenizer-start.bat'
        );

        // 2. spaCy model
        $this->renderCheck(
            'spaCy en_core_web_sm model loaded',
            $results['spacy_model_loaded'],
            $results['spacy_model_loaded'] ? 'Model ready' : 'Model not loaded — run tokenizer-install-deps.bat'
        );

        // 3. spaCy lemmas
        $testCases = $results['test_cases'];
        $spacyDetail = '';
        if (!empty($testCases)) {
            $parts = [];
            foreach (['opened', 'called', 'stopped', 'running', 'walking'] as $word) {
                $lemma = $testCases[$word] ?? '?';
                $parts[] = "{$word}→{$lemma}";
            }
            $spacyDetail = implode(', ', $parts);
        }
        $this->renderCheck(
            'spaCy lemma accuracy (opened→open, called→call)',
            $results['spacy_lemmas_correct'],
            $spacyDetail
        );

        // 4. LemmInflect
        $liDetail = $results['lemminflect_available'] ? 'Available' : 'Not installed — run pip install lemminflect';
        if ($results['lemminflect_available'] && !empty($testCases)) {
            $parts = [];
            foreach (['lemminflect_opened', 'lemminflect_called', 'lemminflect_children'] as $key) {
                $lemma = $testCases[$key] ?? '?';
                $parts[] = str_replace('lemminflect_', '', $key) . '→' . ($lemma ?? 'null');
            }
            $liDetail = implode(', ', $parts);
        }
        $this->renderCheck(
            'LemmInflect available + correct',
            $results['lemminflect_lemmas_correct'],
            $liDetail
        );

        // 5. Bad lemma scan
        if ($scannedBadLemmas) {
            $this->newLine();
            $bad = $results['bad_lemmas'];
            if (empty($bad)) {
                $this->info('[Bad lemma scan] No known bad lemmas found in DB.');
            } else {
                $this->warn('[Bad lemma scan] Found ' . count($bad) . ' words with known bad lemmas:');
                foreach ($bad as $b) {
                    $this->line("  #{$b['id']} {$b['word']} → current={$b['current_lemma']}, correct={$b['correct_lemma']}");
                }
                $this->newLine();
                $this->info('Run: php artisan study-base:doctor --fix-bad-lemmas --fix  to repair.');
            }
        }

        $this->newLine();
        $allOk = $results['tokenizer_reachable']
            && $results['spacy_model_loaded']
            && $results['spacy_lemmas_correct'];

        if ($allOk) {
            $this->info('✓ Tokenizer is healthy.');
        } else {
            $this->error('✗ Tokenizer has issues. See details above.');
        }
    }

    private function renderCheck(string $label, bool $pass, string $detail): void
    {
        $status = $pass ? 'PASS' : 'FAIL';
        $icon = $pass ? '✓' : '✗';

        if ($pass) {
            $this->line("  [{$icon} {$status}] {$label}");
        } else {
            $this->error("  [{$icon} {$status}] {$label}");
        }

        if ($detail) {
            $this->line("        {$detail}");
        }
    }
}
