<?php

namespace App\Console\Commands;

use App\Models\EncounteredWord;
use App\Models\UserStudyBaseRule;
use App\Services\TextBlockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StudyBaseDoctor extends Command
{
    protected $signature = 'study-base:doctor
                            {--language=english : Language code to scan}
                            {--user_id= : Filter by user ID (default: all)}
                            {--fix : Apply study_base corrections (dry-run only without this flag)}
                            {--limit= : Limit suggestions to N entries}
                            {--include-derivational : Also suggest derivational simplifications (-ly, -ness, etc.)}
                            {--fix-bad-lemmas : Scan and fix known bad lemmas (opene→open, cal→call, etc.)}';

    protected $description = 'Diagnose and optionally fix study_base (learning base) in encountered_words.
Scans for words where study_base is missing or could be improved,
suggests corrections, and applies them with --fix.';

    private array $stats = [
        'total_checked' => 0,
        'already_ok' => 0,
        'candidates' => 0,
        'skipped_user_rule' => 0,
        'fixed' => 0,
        'high_confidence' => 0,
        'low_confidence' => 0,
        'examples' => [],
    ];

    // Words whose -ly form should NOT be auto-simplified
    private const LY_EXCLUSIONS = [
        'hardly', 'nearly', 'early', 'only', 'fully', 'barely',
        'rarely', 'badly', 'mostly', 'namely', 'surely', 'truly',
        'wholly', 'lively', 'timely', 'friendly', 'lovely', 'likely',
    ];

    // Words whose study_base should NOT differ from the surface
    private const KNOWN_NON_DERIVATIONAL = [
        'news', 'series', 'species', 'means', 'lens', 'lens',
    ];

    /**
     * Tier 1: Known bad lemmas produced by the old PHP fallback (pre-Commit-1 fix).
     * These are hardcoded stem+'e' errors on regular -ed/-ing verbs, and
     * double-consonant errors (called→cal instead of call).
     *
     * --fix WILL repair these automatically.
     */
    private const KNOWN_BAD_LEMMAS = [
        'opene'   => 'open',
        'reporte' => 'report',
        'walke'   => 'walk',
        'looke'   => 'look',
        'worke'   => 'work',
        'talke'   => 'talk',
        'likee'   => 'like',
        'makee'   => 'make',
        'takee'   => 'take',
        'givee'   => 'give',
        'havee'   => 'have',
        'livee'   => 'live',
        'lovee'   => 'love',
        'comee'   => 'come',
        'caree'   => 'care',
        'sharee'  => 'share',
        'placee'  => 'place',
        'usee'    => 'use',
        'movee'   => 'move',
        'reade'   => 'read',
        'deale'   => 'deal',
        'feele'   => 'feel',
        'nee'     => 'need',
        'cal'     => 'call',
        'calle'   => 'call',
        'fel'     => 'fell',
        'fal'     => 'fall',
        'sel'     => 'sell',
    ];

    // Track suspicious (Tier 2) lemmas for reporting
    private array $suspiciousLemmas = [];

    public function handle(): int
    {
        $language = $this->option('language') ?: 'english';
        $userId = $this->option('user_id') ? (int) $this->option('user_id') : null;
        $fix = (bool) $this->option('fix');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $includeDerivational = (bool) $this->option('include-derivational');
        $fixBadLemmas = (bool) $this->option('fix-bad-lemmas');

        $this->info('=== Study Base Doctor ===');
        $this->info("Language: {$language}");
        $this->info("Mode: " . ($fix ? 'FIX (will write to DB)' : 'DRY-RUN (no writes)'));
        if ($userId) {
            $this->info("User ID: {$userId}");
        }
        if ($limit) {
            $this->info("Limit: {$limit} suggestions");
        }
        if ($includeDerivational) {
            $this->info("Including derivational suggestions (-ly, -ness, etc.)");
        }
        if ($fixBadLemmas) {
            $this->info("Scanning for known bad lemmas (opene→open, cal→call, etc.)");
        }
        $this->newLine();

        if (!in_array($language, ['english'], true)) {
            $this->warn("Study base doctor currently only supports English.");
            return 1;
        }

        // Check ECDICT availability
        $ecdictAvailable = $this->checkEcdict();
        $this->info("ECDICT available: " . ($ecdictAvailable ? 'YES' : 'NO (will use conservative rules only)'));
        $this->newLine();

        if ($fix && !$ecdictAvailable) {
            // --fix-bad-lemmas with Tier 1 (hardcoded) can run without ECDICT.
            // Phases 1-3 and Tier 2 suspicious detection still need ECDICT.
            if ($fixBadLemmas) {
                $this->warn('ECDICT is not available. Only Tier 1 (hardcoded known bad lemmas) will be fixed.');
                $this->warn('Tier 2 (suspicious pattern detection) will be skipped.');
            } else {
                $this->error('ECDICT is not available. --fix is blocked to prevent incorrect batch modifications.');
                $this->info('Run `php artisan dictionary:import-ecdict` first, or use dry-run mode.');
                return 2;
            }
        }

        // Phase 1: High-confidence fixes (missing study_base, study_base == word)
        $this->scanMissingStudyBase($language, $userId, $fix, $limit);

        // Phase 2: study_base == word with morphological suffixes (higher confidence)
        $this->scanSuspiciousEquals($language, $userId, $fix, $limit);

        // Phase 3: Derivational suggestions (low confidence, only with --include-derivational)
        if ($includeDerivational) {
            $this->scanDerivational($language, $userId, $fix, $limit);
        }

        // Phase 4: Known bad lemma repair (Tier 1 auto-fix, Tier 2 report only)
        if ($fixBadLemmas) {
            $this->scanBadLemmas($language, $userId, $fix, $limit);
        }

        // Summary
        $this->newLine();
        $this->info('=== Summary ===');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total checked', $this->stats['total_checked']],
                ['Already OK', $this->stats['already_ok']],
                ['Skipped (user rule exists)', $this->stats['skipped_user_rule']],
                ['High-confidence suggestions', $this->stats['high_confidence']],
                ['Low-confidence suggestions', $this->stats['low_confidence']],
                ['Candidates for fix', $this->stats['candidates']],
                ['Fixed' . ($fix ? '' : ' (would fix)'), $this->stats['fixed']],
            ]
        );

        if (!empty($this->stats['examples'])) {
            $this->newLine();
            $this->info('=== Examples ===');
            $this->table(
                ['Word', 'Current study_base', 'Suggested', 'Confidence', 'Action'],
                array_slice($this->stats['examples'], 0, 30)
            );
        }

        if (!$fix && $this->stats['candidates'] > 0) {
            $this->newLine();
            $this->info('Run with --fix to apply these corrections.');
        }

        return 0;
    }

    /**
     * Phase 1: Missing or empty study_base.
     */
    private function scanMissingStudyBase(string $language, ?int $userId, bool $fix, ?int $limit): void
    {
        $this->info('--- Phase 1: Missing study_base ---');

        $query = EncounteredWord::where('language', $language)
            ->where(function ($q) {
                $q->whereNull('study_base')->orWhere('study_base', '');
            })
            ->where('stage', '<>', 1)
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
            $surface = mb_strtolower($word->word, 'UTF-8');
            $baseWord = mb_strtolower($word->base_word ?: '', 'UTF-8');

            // Default study_base = base_word (fallback to surface for safety)
            $suggested = $baseWord !== '' ? $baseWord : $surface;

            // Skip if user already has a rule
            if ($this->hasUserRule($word->user_id, $language, $surface)) {
                $this->stats['skipped_user_rule']++;
                continue;
            }

            if ($suggested === $surface) {
                $this->stats['already_ok']++;
                continue;
            }

            $this->stats['candidates']++;
            $this->stats['high_confidence']++;
            $this->stats['examples'][] = [
                $word->word,
                '(empty)',
                $suggested,
                'HIGH',
                $fix ? 'FIXED' : 'would fix',
            ];

            if ($fix) {
                $word->study_base = $suggested;
                $word->save();
                $this->stats['fixed']++;
            }
        }
    }

    /**
     * Phase 2: study_base == word but word looks like it has morphological inflection.
     */
    private function scanSuspiciousEquals(string $language, ?int $userId, bool $fix, ?int $limit): void
    {
        $this->info('--- Phase 2: study_base == word (suspicious) ---');

        $query = EncounteredWord::where('language', $language)
            ->whereRaw('study_base = word')
            ->where('stage', '<>', 1)
            ->whereNotNull('study_base')
            ->where('study_base', '<>', '')
            ->whereRaw('LENGTH(word) >= 4')
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
            $surface = mb_strtolower($word->word, 'UTF-8');
            $baseWordLemma = mb_strtolower($word->base_word ?: $surface, 'UTF-8');

            // Get grammatical lemma suggestion from applyEnglishLemma-style logic
            $suggested = $this->computeLemma($surface);

            // Only suggest if lemma differs from surface
            if ($suggested === $surface || $suggested === $baseWordLemma) {
                $this->stats['already_ok']++;
                continue;
            }

            // Skip if user has a rule
            if ($this->hasUserRule($word->user_id, $language, $surface)) {
                $this->stats['skipped_user_rule']++;
                continue;
            }

            // Skip known non-derivational words
            if (in_array($surface, self::KNOWN_NON_DERIVATIONAL, true)) {
                $this->stats['already_ok']++;
                continue;
            }

            $this->stats['candidates']++;
            $this->stats['high_confidence']++;
            $this->stats['examples'][] = [
                $word->word,
                $word->study_base,
                $suggested,
                'HIGH',
                $fix ? 'FIXED' : 'would fix',
            ];

            if ($fix) {
                $word->study_base = $suggested;
                $word->save();
                $this->stats['fixed']++;
            }
        }
    }

    /**
     * Phase 3: Derivational suffix suggestions (low confidence).
     * Only runs when --include-derivational is set.
     */
    private function scanDerivational(string $language, ?int $userId, bool $fix, ?int $limit): void
    {
        $this->info('--- Phase 3: Derivational suffix suggestions ---');

        $query = EncounteredWord::where('language', $language)
            ->where('stage', '<>', 1)
            ->whereNotNull('study_base')
            ->where('study_base', '<>', '')
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
            $surface = mb_strtolower($word->word, 'UTF-8');

            $suggested = $this->computeDerivationalBase($surface);
            if ($suggested === null || $suggested === $surface) {
                continue;
            }

            // Skip if user has a rule
            if ($this->hasUserRule($word->user_id, $language, $surface)) {
                $this->stats['skipped_user_rule']++;
                continue;
            }

            $this->stats['candidates']++;
            $this->stats['low_confidence']++;
            $this->stats['examples'][] = [
                $word->word,
                $word->study_base,
                $suggested,
                'LOW — review before fixing',
                $fix ? 'FIXED' : 'would suggest',
            ];

            if ($fix) {
                $word->study_base = $suggested;
                $word->save();
                $this->stats['fixed']++;
            }
        }
    }

    /**
     * Grammatical lemmatization — high-confidence inflectional rules.
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

        // Irregular
        $irregular = $this->irregularLemma($lower);
        if ($irregular !== null) {
            return $this->ecdictSafe($irregular, $lower);
        }

        // -ies → -y
        if (preg_match('/^(.+)ies$/u', $lower, $m) && mb_strlen($m[1]) >= 2) {
            return $this->ecdictSafe($m[1] . 'y', $lower);
        }

        // -ves → -f / -fe
        if (preg_match('/^(.+)ves$/u', $lower, $m) && mb_strlen($m[1]) >= 1) {
            if ($this->ecdictExists($m[1] . 'f')) return $m[1] . 'f';
            if ($this->ecdictExists($m[1] . 'fe')) return $m[1] . 'fe';
        }

        // -ses/-xes/-zes/-ches/-shes
        if (preg_match('/^(.+)([sxz]|[cs]h)es$/u', $lower, $m) && mb_strlen($m[1]) >= 1) {
            return $this->ecdictSafe($m[1] . $m[2], $lower);
        }

        // -es
        if (preg_match('/^(.+)es$/u', $lower, $m) && mb_strlen($m[1]) >= 2) {
            if ($this->ecdictExists($m[1] . 'e')) return $m[1] . 'e';
            if ($this->ecdictExists($m[1])) return $m[1];
            return $this->ecdictSafe($m[1] . 'e', $lower);
        }

        // -s
        if (preg_match('/^(.+)s$/u', $lower, $m) && mb_strlen($m[1]) >= 3) {
            if ($this->ecdictExists($m[1])) return $m[1];
        }

        // -ing (with ll/ss/zz → bare stem, others → de-double)
        if (preg_match('/^(.+)ing$/iu', $lower, $m)) {
            $stem = $m[1];
            if (mb_strlen($stem) >= 3 && mb_substr($stem, -1) === mb_substr($stem, -2, 1)) {
                $lastChar = mb_substr($stem, -1);
                if ($this->ecdictExists($stem)) return $stem;
                $deDouble = mb_substr($stem, 0, -1);
                if ($this->ecdictExists($deDouble)) return $deDouble;
                // Conservative fallback
                if (in_array($lastChar, ['l', 's', 'z'], true)) return $stem;
                return $deDouble;
            }
            // No double consonant: try bare stem FIRST, then +e
            // (bare stem prevents reading→reade when "reade" exists in ECDICT)
            if ($this->ecdictExists($stem)) return $stem;
            if ($this->ecdictExists($stem . 'e')) return $stem . 'e';
        }

        // -ed (with ll/ss/zz → bare stem, others → de-double)
        if (preg_match('/^(.+)ed$/iu', $lower, $m) && mb_strlen($m[1]) >= 2) {
            $stem = $m[1];
            if (mb_strlen($stem) >= 3 && mb_substr($stem, -1) === mb_substr($stem, -2, 1)) {
                $lastChar = mb_substr($stem, -1);
                if ($this->ecdictExists($stem)) return $stem;
                $deDouble = mb_substr($stem, 0, -1);
                if ($this->ecdictExists($deDouble)) return $deDouble;
                if (in_array($lastChar, ['l', 's', 'z'], true)) return $stem;
                return $deDouble;
            }
            // No double consonant: try bare stem FIRST, then -ied→-y, then +e
            // (bare stem prevents opened→opene when "opene" exists in ECDICT)
            if ($this->ecdictExists($stem)) return $stem;
            if (preg_match('/^(.+)i$/u', $stem, $m2)) {
                if ($this->ecdictExists($m2[1] . 'y')) return $m2[1] . 'y';
            }
            if ($this->ecdictExists($stem . 'e')) return $stem . 'e';
        }

        return $lower;
    }

    /**
     * Derivational base computation — low confidence suggestions.
     * Returns null if no derivational simplification is applicable.
     */
    private function computeDerivationalBase(string $surface): ?string
    {
        $lower = mb_strtolower($surface, 'UTF-8');

        // -ly adverb → adjective (but exclude exceptions)
        if (preg_match('/^(.+)ly$/u', $lower, $m) && mb_strlen($m[1]) >= 3) {
            $candidate = $m[1];
            // Handle -ily → -y (happily → happy, easily → easy)
            if (preg_match('/^(.+)i$/u', $candidate, $m2) && mb_strlen($m2[1]) >= 3) {
                $candidateY = $m2[1] . 'y';
                if ($this->ecdictExists($candidateY) && !in_array($lower, self::LY_EXCLUSIONS, true)) {
                    return $candidateY;
                }
            }
            if ($this->ecdictExists($candidate) && !in_array($lower, self::LY_EXCLUSIONS, true)) {
                return $candidate;
            }
        }

        // -ness → adjective
        if (preg_match('/^(.+)ness$/u', $lower, $m) && mb_strlen($m[1]) >= 3) {
            $stem = $m[1];
            // -iness → -y (happiness → happy)
            if (preg_match('/^(.+)i$/u', $stem, $m2) && mb_strlen($m2[1]) >= 3) {
                $candidate = $m2[1] . 'y';
                if ($this->ecdictExists($candidate)) return $candidate;
            }
            if ($this->ecdictExists($stem)) return $stem;
        }

        // -ment → verb (movement → move)
        if (preg_match('/^(.+)ment$/u', $lower, $m) && mb_strlen($m[1]) >= 3) {
            if ($this->ecdictExists($m[1])) return $m[1];
            if ($this->ecdictExists($m[1] . 'e')) return $m[1] . 'e';
        }

        // -tion/-sion → verb (creation → create, decision → decide)
        if (preg_match('/^(.+)(tion|sion)$/u', $lower, $m) && mb_strlen($m[1]) >= 3) {
            $stem = $m[1];
            // Common patterns
            if (preg_match('/^(.+)a$/u', $stem, $m2) && $this->ecdictExists($m2[1] . 'e')) {
                return $m2[1] . 'e'; // creation → create
            }
            if ($this->ecdictExists($stem . 'e')) return $stem . 'e'; // decision → decide (not exact but close)
        }

        return null;
    }

    /**
     * Irregular English verb/noun mapping.
     */
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
            'men' => 'man', 'women' => 'woman',
            'people' => 'person',
            'teeth' => 'tooth', 'feet' => 'foot',
            'mice' => 'mouse', 'geese' => 'goose', 'oxen' => 'ox',
            'lives' => 'life', 'wives' => 'wife', 'knives' => 'knife',
            'leaves' => 'leaf', 'shelves' => 'shelf', 'thieves' => 'thief',
            'wolves' => 'wolf', 'halves' => 'half', 'selves' => 'self',
        ];

        return $map[$word] ?? null;
    }

    private function checkEcdict(): bool
    {
        static $available = null;
        if ($available === null) {
            try {
                $count = DB::table('dict_en_ecdict_full')->count();
                $available = $count >= 100000;
            } catch (\Throwable $e) {
                $available = false;
            }
        }
        return $available;
    }

    private function ecdictExists(string $word): bool
    {
        static $cache = [];
        $key = mb_strtolower($word, 'UTF-8');
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        if (!$this->checkEcdict()) {
            $cache[$key] = false;
            return false;
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

    private function hasUserRule(int $userId, string $language, string $surface): bool
    {
        return UserStudyBaseRule::where('user_id', $userId)
            ->where('language', $language)
            ->where('surface', $surface)
            ->exists();
    }

    /**
     * Phase 4: Known bad lemma repair.
     *
     * Tier 1 (KNOWN_BAD_LEMMAS): hardcoded stem+'e' errors, double-consonant errors.
     *   --fix WILL repair these automatically.
     *
     * Tier 2 (suspicious patterns): lemma matches stem+'e' pattern where surface ends
     *   in -ed or -ing. These are REPORTED ONLY, never auto-fixed.
     *
     * Constraints:
     *   - Skips words with existing user_study_base_rules.
     *   - Only updates base_word, lemma, study_base (not word, stage, review data).
     *   - Does not delete or merge WordSenses.
     */
    private function scanBadLemmas(string $language, ?int $userId, bool $fix, ?int $limit): void
    {
        $this->info('--- Phase 4: Known bad lemma repair ---');

        $badLemmaKeys = array_keys(self::KNOWN_BAD_LEMMAS);

        $query = EncounteredWord::where('language', $language)
            ->where('stage', '<>', 1)
            ->orderBy('id');

        if ($userId) {
            $query->where('user_id', $userId);
        }
        if ($limit !== null) {
            $query->limit($limit);
        }

        $words = $query->get();

        $tier1Fixed = 0;
        $tier1Skipped = 0;
        $tier2Found = 0;

        foreach ($words as $word) {
            $surface = mb_strtolower(trim($word->word), 'UTF-8');
            $currentLemma = mb_strtolower(trim($word->lemma ?? ''), 'UTF-8');
            $currentStudyBase = $word->study_base
                ? mb_strtolower(trim($word->study_base), 'UTF-8')
                : '';

            // Skip if user has a custom rule — they already handled this
            if ($this->hasUserRule($word->user_id, $language, $surface)) {
                continue;
            }

            // Skip if study_base was manually set to something different from both
            // surface and base_word (user intentionally customized it)
            if ($currentStudyBase !== ''
                && $currentStudyBase !== $currentLemma
                && $currentStudyBase !== $surface) {
                continue;
            }

            $needsFix = null;
            $isTier1 = false;

            // Tier 1: Check against hardcoded known bad lemmas
            if (array_key_exists($currentLemma, self::KNOWN_BAD_LEMMAS)) {
                $needsFix = self::KNOWN_BAD_LEMMAS[$currentLemma];
                $isTier1 = true;
            }
            // Also check base_word
            $currentBaseWord = mb_strtolower(trim($word->base_word ?? ''), 'UTF-8');
            if ($needsFix === null && $currentBaseWord !== $currentLemma
                && array_key_exists($currentBaseWord, self::KNOWN_BAD_LEMMAS)) {
                $needsFix = self::KNOWN_BAD_LEMMAS[$currentBaseWord];
                $isTier1 = true;
            }

            // Tier 2: Suspicious pattern detection (stem+'e' on -ed/-ing surface)
            if ($needsFix === null) {
                $suspicion = $this->detectSuspiciousLemma($surface, $currentLemma);
                if ($suspicion !== null) {
                    $this->suspiciousLemmas[] = [
                        'id' => $word->id,
                        'word' => $surface,
                        'current_lemma' => $currentLemma,
                        'suggested' => $suspicion,
                        'pattern' => 'stem+e',
                    ];
                    $tier2Found++;
                }
            }

            // Apply Tier 1 fix
            if ($isTier1 && $needsFix !== null) {
                if ($fix) {
                    $word->lemma = $needsFix;
                    $word->base_word = $needsFix;
                    // Only update study_base if it matches the old bad lemma
                    if ($currentStudyBase === $currentLemma
                        || $currentStudyBase === '' || $currentStudyBase === null) {
                        $word->study_base = $needsFix;
                    }
                    $word->save();
                    $tier1Fixed++;
                    $this->info("  FIXED: #{$word->id} {$surface} {$currentLemma}→{$needsFix}");
                } else {
                    $tier1Fixed++;
                    $this->line("  [dry-run] #{$word->id} {$surface} {$currentLemma}→{$needsFix}");
                }
            } elseif ($isTier1) {
                $tier1Skipped++;
            }
        }

        // Report
        $this->newLine();
        if ($tier1Fixed > 0) {
            $this->info("Tier 1 (known bad lemmas): {$tier1Fixed} " . ($fix ? 'fixed.' : 'would fix (dry-run).'));
            if (!$fix) {
                $this->info('  Run with --fix to apply these corrections.');
            }
        } else {
            $this->info('Tier 1 (known bad lemmas): none found.');
        }

        if ($tier2Found > 0) {
            $this->warn("Tier 2 (suspicious patterns): {$tier2Found} found — REPORT ONLY, not auto-fixed.");
            $this->newLine();
            $this->line('  These words match a stem+e pattern that MAY be wrong.');
            $this->line('  Review them manually before applying any fixes.');
            $this->line('');
            foreach (array_slice($this->suspiciousLemmas, 0, 20) as $s) {
                $this->line("    #{$s['id']} {$s['word']} → current={$s['current_lemma']}, suggested={$s['suggested']}");
            }
            if (count($this->suspiciousLemmas) > 20) {
                $this->line('    ... and ' . (count($this->suspiciousLemmas) - 20) . ' more.');
            }
            $this->newLine();
            $this->info('  To manually fix a suspicious lemma, edit the word in the reading page UI,');
            $this->info('  or update the DB directly with a verified correct lemma.');
        }
    }

    /**
     * Tier 2 detection: check if a lemma looks like a stem+'e' error.
     * Returns the suggested correction (bare stem) or null.
     */
    private function detectSuspiciousLemma(string $surface, string $lemma): ?string
    {
        $surfaceLen = mb_strlen($surface);
        $lemmaLen = mb_strlen($lemma);

        // Pattern: surface ends in -ed, lemma = surface[:-2] + 'e'
        // e.g., "reported" → stem="report" + "e" = "reporte"
        if (preg_match('/^(.+)(ed|ED)$/u', $surface, $m) && mb_strlen($m[1]) >= 2) {
            $stem = $m[1];
            // Check if lemma is stem + 'e' AND stem itself is a known ECDICT word
            if ($lemma === $stem . 'e' && $lemmaLen === $surfaceLen - 1) {
                if ($this->ecdictExists($stem)) {
                    return $stem;  // bare stem is in ECDICT → likely correct
                }
            }
            // Check if lemma is shorter than expected: e.g., "called" → "cal"
            if ($lemmaLen < mb_strlen($stem) && !$this->ecdictExists($lemma)) {
                if ($this->ecdictExists($stem)) {
                    return $stem;
                }
            }
        }

        // Pattern: surface ends in -ing, lemma = surface[:-3] + 'e'
        if (preg_match('/^(.+)(ing|ING)$/u', $surface, $m) && mb_strlen($m[1]) >= 2) {
            $stem = $m[1];
            if ($lemma === $stem . 'e' && $lemmaLen === $surfaceLen - 2) {
                if ($this->ecdictExists($stem)) {
                    return $stem;
                }
            }
        }

        return null;
    }
}
