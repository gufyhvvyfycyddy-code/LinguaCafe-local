<?php

namespace App\Console\Commands;

use App\Models\EncounteredWord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LemmaDoctor extends Command
{
    protected $signature = 'lemma:doctor
                            {--language=english : Language code to scan}
                            {--user_id= : Filter by user ID (default: all)}
                            {--fix : Apply lemma corrections (dry-run only without this flag)}
                            {--limit= : Limit suggestions to N entries (for dry-run preview)}';

    protected $description = 'Diagnose and optionally fix English lemma/base_word in encountered_words.
Scans for words where base_word is missing or clearly wrong,
applies conservative lemmatization rules, and suggests corrections.
Use --fix to actually update base_word values.';

    private array $stats = [
        'total_checked' => 0,
        'already_ok' => 0,
        'candidates' => 0,
        'skipped' => 0,
        'fixed' => 0,
        'examples' => [],
    ];

    public function handle(): int
    {
        $language = $this->option('language') ?: 'english';
        $userId = $this->option('user_id') ? (int) $this->option('user_id') : null;
        $fix = (bool) $this->option('fix');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $this->info('=== Lemma Doctor ===');
        $this->info("Language: {$language}");
        $this->info("Mode: " . ($fix ? 'FIX (will write to DB)' : 'DRY-RUN (no writes)'));
        if ($userId) {
            $this->info("User ID: {$userId}");
        }
        if ($limit) {
            $this->info("Limit: {$limit} suggestions");
        }
        $this->newLine();

        if (!in_array($language, ['english'], true)) {
            $this->warn("Lemma doctor currently only supports English. Other languages not yet supported.");
            return 1;
        }

        // 1. Find words where base_word is empty (likely fallback tokenizer cleared it)
        $this->scanMissingLemmas($language, $userId, $fix, $limit);

        // 2. Find words where base_word equals word and word looks like a plural/conjugated form
        $this->scanSuspiciousEquals($language, $userId, $fix, $limit);

        // 3. Summary
        $this->newLine();
        $this->info('=== Summary ===');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total checked', $this->stats['total_checked']],
                ['Already OK', $this->stats['already_ok']],
                ['Candidates for fix', $this->stats['candidates']],
                ['Skipped (conflict)', $this->stats['skipped']],
                ['Fixed' . ($fix ? '' : ' (would fix)'), $this->stats['fixed']],
            ]
        );

        if (!empty($this->stats['examples'])) {
            $this->newLine();
            $this->info('=== Examples ===');
            $this->table(
                ['Word', 'Current lemma', 'Suggested lemma', 'Action'],
                array_slice($this->stats['examples'], 0, 30)
            );
        }

        if (!$fix && $this->stats['candidates'] > 0) {
            $this->newLine();
            $this->info('Run with --fix to apply these corrections.');
        }

        return 0;
    }

    private function scanMissingLemmas(string $language, ?int $userId, bool $fix, ?int $limit): void
    {
        $this->info('--- Phase 1: Missing base_word ---');

        $query = EncounteredWord::where('language', $language)
            ->where(function ($q) {
                $q->whereNull('base_word')
                  ->orWhere('base_word', '');
            })
            ->where('stage', '<>', 1) // skip ignored words
            ->orderBy('id');

        if ($userId) {
            $query->where('user_id', $userId);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $words = $query->get();
        $this->stats['total_checked'] += $words->count();

        foreach ($words as $word) {
            $lowerWord = mb_strtolower($word->word, 'UTF-8');
            $suggestedLemma = $this->computeLemma($lowerWord);

            // If lemma equals word (no better lemma found), skip
            if ($suggestedLemma === $lowerWord) {
                $this->stats['already_ok']++;
                continue;
            }

            $this->stats['candidates']++;

            // If lemma conflicts with existing base_word (non-empty), skip
            if (!empty($word->base_word) && $word->base_word !== $suggestedLemma) {
                $this->stats['skipped']++;
                $this->stats['examples'][] = [
                    $word->word,
                    $word->base_word ?: '(empty)',
                    $suggestedLemma,
                    'SKIPPED — base_word already set differently',
                ];
                continue;
            }

            $this->stats['examples'][] = [
                $word->word,
                $word->base_word ?: '(empty)',
                $suggestedLemma,
                $fix ? 'FIXED' : 'would fix',
            ];

            if ($fix) {
                $word->base_word = $suggestedLemma;
                $word->lemma = $suggestedLemma;
                $word->save();
                $this->stats['fixed']++;
            }
        }
    }

    private function scanSuspiciousEquals(string $language, ?int $userId, bool $fix, ?int $limit): void
    {
        $this->info('--- Phase 2: base_word == word (suspicious equal) ---');

        $query = EncounteredWord::where('language', $language)
            ->whereRaw('base_word = word')
            ->where('stage', '<>', 1)
            ->whereRaw('LENGTH(word) >= 4') // at least 4 chars to be worth lemmatizing
            ->orderBy('id');

        if ($userId) {
            $query->where('user_id', $userId);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $words = $query->get();
        $this->stats['total_checked'] += $words->count();

        foreach ($words as $word) {
            $lowerWord = mb_strtolower($word->word, 'UTF-8');
            $suggestedLemma = $this->computeLemma($lowerWord);

            // If lemma equals word, it's probably genuinely uninflected (e.g., "series")
            if ($suggestedLemma === $lowerWord) {
                $this->stats['already_ok']++;
                continue;
            }

            $this->stats['candidates']++;
            $this->stats['examples'][] = [
                $word->word,
                $word->base_word,
                $suggestedLemma,
                $fix ? 'FIXED' : 'would fix',
            ];

            if ($fix) {
                $word->base_word = $suggestedLemma;
                $word->lemma = $suggestedLemma;
                $word->save();
                $this->stats['fixed']++;
            }
        }
    }

    /**
     * Reuse the same conservative lemmatization logic from TextBlockService.
     */
    private function computeLemma(string $surface): string
    {
        $lower = mb_strtolower($surface, 'UTF-8');

        if (mb_strlen($lower) < 3) {
            return $lower;
        }

        // Structural markers
        if (preg_match('/^(paragraph_break|newline|\[[a-z]\]|zz(para|newl|sect))/i', $lower)) {
            return $lower;
        }

        // 1. Irregular
        $irregular = $this->irregularLemma($lower);
        if ($irregular !== null) {
            return $this->ecdictSafe($irregular, $lower);
        }

        // 2. -ies → -y
        if (preg_match('/^(.+)ies$/u', $lower, $m) && mb_strlen($m[1]) >= 2) {
            $candidate = $m[1] . 'y';
            return $this->ecdictSafe($candidate, $lower);
        }

        // 3. -ves → -f / -fe
        if (preg_match('/^(.+)ves$/u', $lower, $m) && mb_strlen($m[1]) >= 1) {
            $candidate = $m[1] . 'f';
            if ($this->ecdictExists($candidate)) return $candidate;
            $candidate2 = $m[1] . 'fe';
            if ($this->ecdictExists($candidate2)) return $candidate2;
        }

        // 4. -ses/-xes/-zes/-ches/-shes → remove -es
        if (preg_match('/^(.+)([sxz]|[cs]h)es$/u', $lower, $m) && mb_strlen($m[1]) >= 1) {
            $candidate = $m[1] . $m[2];
            return $this->ecdictSafe($candidate, $lower);
        }

        // 5. -es → remove -s or keep -e
        if (preg_match('/^(.+)es$/u', $lower, $m) && mb_strlen($m[1]) >= 2) {
            $candidate = $m[1] . 'e';
            if ($this->ecdictExists($candidate)) return $candidate;
            $candidate2 = $m[1] . 'es';
            if ($this->ecdictExists($candidate2)) return $candidate2;
        }

        // 6. -s → remove -s (ECDICT verified only)
        if (preg_match('/^(.+)s$/u', $lower, $m) && mb_strlen($m[1]) >= 3) {
            $candidate = $m[1];
            if ($this->ecdictExists($candidate)) return $candidate;
        }

        // 7. -(n)ing
        if (preg_match('/^(.+)ing$/iu', $lower, $m)) {
            $stem = $m[1];
            if (mb_strlen($stem) >= 3 && mb_substr($stem, -1) === mb_substr($stem, -2, 1)) {
                // Check stem itself first (falling → fall, not fal)
                if ($this->ecdictExists($stem)) return $stem;
                $candidate = mb_substr($stem, 0, -1);
                if ($this->ecdictExists($candidate)) return $candidate;
            }
            $candidate = $stem . 'e';
            if ($this->ecdictExists($candidate)) return $candidate;
            if ($this->ecdictExists($stem)) return $stem;
        }

        // 8. -ed
        if (preg_match('/^(.+)ed$/iu', $lower, $m) && mb_strlen($m[1]) >= 2) {
            $stem = $m[1];
            if (mb_strlen($stem) >= 3 && mb_substr($stem, -1) === mb_substr($stem, -2, 1)) {
                // Check stem itself first (called → call, not cal)
                if ($this->ecdictExists($stem)) return $stem;
                $candidate = mb_substr($stem, 0, -1);
                if ($this->ecdictExists($candidate)) return $candidate;
            }
            $candidate = $stem . 'e';
            if ($this->ecdictExists($candidate)) return $candidate;
            if (preg_match('/^(.+)i$/u', $stem, $m2)) {
                $candidate2 = $m2[1] . 'y';
                if ($this->ecdictExists($candidate2)) return $candidate2;
            }
            if ($this->ecdictExists($stem)) return $stem;
        }

        return $lower;
    }

    private function irregularLemma(string $word): ?string
    {
        $map = [
            'am' => 'be', 'is' => 'be', 'are' => 'be', 'was' => 'be', 'were' => 'be',
            'been' => 'be', 'being' => 'be',
            'has' => 'have', 'had' => 'have', 'having' => 'have',
            'does' => 'do', 'did' => 'do', 'done' => 'do', 'doing' => 'do',
            'goes' => 'go', 'went' => 'go', 'gone' => 'go', 'going' => 'go',
            'says' => 'say', 'said' => 'say',
            'got' => 'get', 'gotten' => 'get',
            'made' => 'make', 'makes' => 'make',
            'knew' => 'know', 'known' => 'know',
            'thought' => 'think',
            'took' => 'take', 'taken' => 'take',
            'saw' => 'see', 'seen' => 'see',
            'came' => 'come',
            'gave' => 'give', 'given' => 'give',
            'found' => 'find',
            'told' => 'tell',
            'became' => 'become',
            'left' => 'leave',
            'felt' => 'feel',
            'brought' => 'bring',
            'began' => 'begin', 'begun' => 'begin',
            'kept' => 'keep',
            'held' => 'hold',
            'wrote' => 'write', 'written' => 'write',
            'stood' => 'stand',
            'heard' => 'hear',
            'meant' => 'mean',
            'met' => 'meet',
            'ran' => 'run',
            'paid' => 'pay',
            'sat' => 'sit',
            'spoke' => 'speak', 'spoken' => 'speak',
            'lay' => 'lie', 'lain' => 'lie',
            'led' => 'lead',
            'grew' => 'grow', 'grown' => 'grow',
            'lost' => 'lose',
            'fell' => 'fall', 'fallen' => 'fall',
            'sent' => 'send',
            'built' => 'build',
            'understood' => 'understand',
            'drew' => 'draw', 'drawn' => 'draw',
            'broke' => 'break', 'broken' => 'break',
            'spent' => 'spend',
            'rose' => 'rise', 'risen' => 'rise',
            'drove' => 'drive', 'driven' => 'drive',
            'bought' => 'buy',
            'wore' => 'wear', 'worn' => 'wear',
            'chose' => 'choose', 'chosen' => 'choose',
            'ate' => 'eat', 'eaten' => 'eat',
            'drank' => 'drink', 'drunk' => 'drink',
            'slept' => 'sleep',
            'sang' => 'sing', 'sung' => 'sing',
            'taught' => 'teach',
            'sold' => 'sell',
            'caught' => 'catch',
            'fought' => 'fight',
            'swam' => 'swim', 'swum' => 'swim',
            'flew' => 'fly', 'flown' => 'fly',
            'threw' => 'throw', 'thrown' => 'throw',
            'rode' => 'ride', 'ridden' => 'ride',
            'won' => 'win',
            'forgot' => 'forget', 'forgotten' => 'forget',
            'hung' => 'hang',
            'children' => 'child',
            'men' => 'man',
            'women' => 'woman',
            'people' => 'person',
            'teeth' => 'tooth',
            'feet' => 'foot',
            'mice' => 'mouse',
            'geese' => 'goose',
            'oxen' => 'ox',
            'lives' => 'life',
            'wives' => 'wife',
            'knives' => 'knife',
            'leaves' => 'leaf',
            'shelves' => 'shelf',
            'thieves' => 'thief',
            'wolves' => 'wolf',
            'halves' => 'half',
            'selves' => 'self',
        ];

        return $map[$word] ?? null;
    }

    private function ecdictExists(string $word): bool
    {
        static $cache = [];
        static $available = null;
        $key = mb_strtolower($word, 'UTF-8');
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        if ($available === null) {
            try {
                $available = DB::table('dict_en_ecdict_full')->exists();
            } catch (\Throwable $e) {
                $available = false;
            }
        }
        if (!$available) {
            $cache[$key] = true;
            return true;
        }
        try {
            $exists = DB::table('dict_en_ecdict_full')
                ->where('word', $key)
                ->exists();
            $cache[$key] = $exists;
            return $exists;
        } catch (\Throwable $e) {
            $cache[$key] = false;
            return false;
        }
    }

    private function ecdictSafe(string $candidate, string $fallback): string
    {
        return $this->ecdictExists($candidate) ? $candidate : $fallback;
    }
}
