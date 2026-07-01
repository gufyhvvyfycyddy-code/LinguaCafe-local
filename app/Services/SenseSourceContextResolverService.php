<?php

namespace App\Services;

use App\Models\Chapter;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use Illuminate\Support\Facades\Log;

/**
 * Resolver service for SenseSourceContext.
 *
 * Handles all query/location logic: finding WordSense/Occurrence/Chapter,
 * matching sentences (exact, recovered, fuzzy), determining fallback path,
 * and writing back recovered source data.
 *
 * Does NOT handle token rendering or response assembly — that stays in
 * SenseSourceContextService / SenseTokenPayloadService.
 */
class SenseSourceContextResolverService
{
    public function __construct(
        private SenseTokenPayloadService $senseTokenPayloadService,
    ) {
    }

    /**
     * Find a confirmed WordSense owned by the user/language.
     * Throws ModelNotFoundException if not found.
     */
    public function resolveSense(int $userId, string $language, int $senseId): WordSense
    {
        return WordSense::where('id', $senseId)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('status', WordSense::STATUS_CONFIRMED)
            ->firstOrFail();
    }

    /**
     * Find the best source occurrence (with chapter_id, ordered by manual source first).
     */
    public function resolveSourceOccurrence(WordSense $sense): ?WordSenseOccurrence
    {
        return WordSenseOccurrence::query()
            ->where('user_id', $sense->user_id)
            ->where('language_id', $sense->language_id)
            ->where('word_sense_id', $sense->id)
            ->where('status', WordSenseOccurrence::STATUS_BOUND)
            ->whereNotNull('chapter_id')
            ->orderByRaw('CASE WHEN source = ? THEN 0 ELSE 1 END', [WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Find the best example occurrence (with sentence_en).
     */
    public function resolveExampleOccurrence(WordSense $sense): ?WordSenseOccurrence
    {
        return WordSenseOccurrence::query()
            ->where('user_id', $sense->user_id)
            ->where('language_id', $sense->language_id)
            ->where('word_sense_id', $sense->id)
            ->where('status', WordSenseOccurrence::STATUS_BOUND)
            ->whereNotNull('sentence_en')
            ->where('sentence_en', '<>', '')
            ->orderByRaw('CASE WHEN source = ? THEN 0 ELSE 1 END', [WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Load a chapter by ID with user/language scope.
     */
    public function findChapterById(int $chapterId, int $userId, string $language): ?Chapter
    {
        return Chapter::query()
            ->where('id', $chapterId)
            ->where('user_id', $userId)
            ->where('language', $language)
            ->first();
    }

    /**
     * Group processed text tokens by sentence_index with global indexes.
     */
    public function groupTokensBySentenceWithIndexes(array $words): array
    {
        $groups = [];
        foreach ($words as $index => $word) {
            $wordObj = is_array($word) ? (object) $word : $word;
            $tokenWord = $wordObj->word ?? '';
            if ($tokenWord === 'NEWLINE' || $tokenWord === 'PARAGRAPH_BREAK') {
                continue;
            }
            $key = $wordObj->sentence_index ?? $wordObj->si ?? $wordObj->sentence_id ?? '__no_sentence__';
            $groups[(string) $key][] = [
                'word' => $wordObj,
                'global_index' => $index,
            ];
        }
        return $groups;
    }

    /**
     * Find the target sentence key in groups by sentence_id / sentence_hash / example text.
     */
    public function findSourceSentenceKey(array $groups, $sentenceId, $sentenceHash, ?string $exampleSentence): ?string
    {
        if ($sentenceId !== null) {
            foreach ($groups as $key => $entries) {
                foreach ($entries as $entry) {
                    $word = is_array($entry['word']) ? (object) $entry['word'] : $entry['word'];
                    if (
                        (isset($word->sentence_id) && (string) $word->sentence_id === (string) $sentenceId) ||
                        (isset($word->sentence_index) && (string) $word->sentence_index === (string) $sentenceId) ||
                        (isset($word->si) && (string) $word->si === (string) $sentenceId)
                    ) {
                        return (string) $key;
                    }
                }
            }
        }

        if ($sentenceHash !== null) {
            foreach ($groups as $key => $entries) {
                foreach ($entries as $entry) {
                    $word = is_array($entry['word']) ? (object) $entry['word'] : $entry['word'];
                    if (isset($word->sentence_hash) && (string) $word->sentence_hash === (string) $sentenceHash) {
                        return (string) $key;
                    }
                }
            }
        }

        if ($exampleSentence) {
            $targetNormalized = $this->senseTokenPayloadService->normalizeSentenceText($exampleSentence);
            foreach ($groups as $key => $entries) {
                $rawWords = array_map(fn ($entry) => $entry['word'], $entries);
                $groupText = $this->senseTokenPayloadService->tokensToSentenceText($rawWords);
                $groupNormalized = $this->senseTokenPayloadService->normalizeSentenceText($groupText);
                if ($groupNormalized === $targetNormalized) {
                    return (string) $key;
                }
            }
        }

        return null;
    }

    /**
     * Get context entries around a target group (radius=5 sentences).
     */
    public function contextEntriesAroundGroup(array $groups, string $targetKey): array
    {
        $keys = array_keys($groups);
        $pos = array_search($targetKey, $keys);
        if ($pos === false) {
            return [];
        }
        $radius = 5;
        $start = max(0, $pos - $radius);
        $end = min(count($keys) - 1, $pos + $radius);
        $result = [];
        for ($i = $start; $i <= $end; $i++) {
            foreach ($groups[$keys[$i]] as $entry) {
                $result[] = [
                    'word' => $entry['word'],
                    'global_index' => $entry['global_index'],
                    'group_key' => $keys[$i],
                    'is_source_sentence' => $keys[$i] == $targetKey,
                ];
            }
        }
        return $result;
    }

    /**
     * Try to find a chapter whose processed text contains the exact example sentence.
     */
    public function findMatchingChapterByExampleText(WordSense $sense, string $exampleSentence): ?array
    {
        $chapters = Chapter::query()
            ->where('user_id', $sense->user_id)
            ->where('language', $sense->language_id)
            ->orderByDesc('id')
            ->get();

        return $this->doFindMatchingChapter($chapters, $sense, $exampleSentence);
    }

    /**
     * Try to find a chapter whose processed text fuzzy-matches the example sentence.
     */
    public function findMatchingChapterByFuzzyMatch(WordSense $sense, string $exampleSentence): ?array
    {
        $chapters = Chapter::query()
            ->where('user_id', $sense->user_id)
            ->where('language', $sense->language_id)
            ->orderByDesc('id')
            ->get();

        if ($chapters->isEmpty()) {
            return null;
        }

        $targetTerms = $this->targetTerms($sense, null);
        $queryTokensCount = count($this->meaningfulTextTokens($exampleSentence));
        $best = null;

        foreach ($chapters as $chapter) {
            // Check chapter title
            $titleScore = $this->fuzzySourceScore($exampleSentence, $chapter->name, $targetTerms);
            if ($titleScore['score'] > ($best['score'] ?? 0)) {
                $best = [
                    'chapter' => $chapter,
                    'kind' => 'chapter_title',
                    'score' => $titleScore['score'],
                    'contains_target' => $titleScore['contains_target'],
                ];
            }

            $words = $this->senseTokenPayloadService->flattenProcessedWords($chapter->getProcessedText());
            if (empty($words)) {
                continue;
            }

            $groups = $this->groupTokensBySentenceWithIndexes($words);
            foreach ($groups as $key => $entries) {
                $rawWords = array_map(fn ($entry) => $entry['word'], $entries);
                $groupText = $this->senseTokenPayloadService->tokensToSentenceText($rawWords);
                $candidateScore = $this->fuzzySourceScore($exampleSentence, $groupText, $targetTerms);
                if ($candidateScore['score'] > ($best['score'] ?? 0)) {
                    $best = [
                        'chapter' => $chapter,
                        'target_key' => $key,
                        'kind' => 'chapter_fuzzy',
                        'score' => $candidateScore['score'],
                        'contains_target' => $candidateScore['contains_target'],
                        'entries' => $entries,
                    ];
                }
            }
        }

        if ($best === null || $best['score'] <= 0) {
            return null;
        }

        // Apply scoring thresholds
        $meetsThreshold = false;
        if ($best['contains_target']) {
            if ($best['score'] >= 0.55) {
                $meetsThreshold = true;
            }
        } else {
            if ($best['score'] >= 0.82) {
                $meetsThreshold = true;
            }
        }
        if ($queryTokensCount < 5 && (!$best['contains_target'] || $best['score'] < 0.75)) {
            $meetsThreshold = false;
        }

        if (!$meetsThreshold) {
            return null;
        }

        return $best;
    }

    /**
     * Write back recovered source chapter_id/sentence_id to WordSense and Occurrence.
     */
    public function writeBackRecoveredSource(
        WordSense $sense,
        ?WordSenseOccurrence $occurrence,
        int $chapterId,
        ?string $sentenceId
    ): void {
        try {
            $sense->source_chapter_id = $chapterId;
            $sense->sentence_id = $sentenceId;
            $sense->save();

            if ($occurrence) {
                $occurrence->chapter_id = $chapterId;
                $occurrence->sentence_id = $sentenceId;
                $occurrence->save();
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * Extract meaningful text tokens (lowercase, no stopwords).
     */
    public function meaningfulTextTokens(string $text): array
    {
        $text = mb_strtolower($text);
        $text = str_replace(
            ["\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}"],
            ["'", "'", '"', '"', '-', '-'],
            $text
        );

        preg_match_all('/[a-z0-9]+/u', $text, $matches);
        $tokens = $matches[0] ?? [];

        $stopwords = [
            'the', 'a', 'an', 'of', 'to', 'in', 'on', 'at', 'for',
            'and', 'or', 'but', 'is', 'are', 'was', 'were', 'be',
            'been', 'being', 'that', 'this', 'these', 'those', 'with',
            'as', 'by', 'from', 'it', 'its', 'into',
        ];

        return array_values(array_filter($tokens, fn ($t) => !in_array($t, $stopwords, true)));
    }

    /**
     * Get target terms for fuzzy matching from sense and occurrence.
     */
    public function targetTerms(WordSense $sense, ?WordSenseOccurrence $occurrence): array
    {
        $terms = [];
        if ($sense->surface_form) {
            $terms[] = $sense->surface_form;
        }
        if ($sense->lemma) {
            $terms[] = $sense->lemma;
        }
        if ($occurrence?->surface) {
            $terms[] = $occurrence->surface;
        }
        if ($occurrence?->lemma) {
            $terms[] = $occurrence->lemma;
        }

        $allTokens = [];
        foreach ($terms as $term) {
            $tokens = $this->meaningfulTextTokens($term);
            foreach ($tokens as $t) {
                $allTokens[$t] = true;
            }
        }

        return array_keys($allTokens);
    }

    /**
     * Calculate fuzzy similarity score between query and candidate text.
     */
    public function fuzzySourceScore(string $query, string $candidate, array $targetTerms): array
    {
        $queryTokens = $this->meaningfulTextTokens($query);
        $candidateTokens = $this->meaningfulTextTokens($candidate);

        if (empty($queryTokens) || empty($candidateTokens)) {
            return ['score' => 0.0, 'coverage' => 0.0, 'contains_target' => false];
        }

        $uniqueQuery = array_unique($queryTokens);
        $uniqueCandidate = array_unique($candidateTokens);

        $common = array_intersect($uniqueQuery, $uniqueCandidate);
        $coverage = count($common) / max(1, count($uniqueQuery));

        $containsTarget = false;
        foreach ($targetTerms as $term) {
            if (in_array($term, $candidateTokens, true)) {
                $containsTarget = true;
                break;
            }
        }

        $score = $coverage;
        if ($containsTarget) {
            $score += 0.25;
        }

        if ($this->senseTokenPayloadService->normalizeSentenceText($query) === $this->senseTokenPayloadService->normalizeSentenceText($candidate)) {
            $score = 2.0;
        }

        return [
            'score' => round($score, 4),
            'coverage' => round($coverage, 4),
            'contains_target' => $containsTarget,
        ];
    }

    /**
     * Collect indexes of tokens marked as target.
     */
    public function collectTargetIndexes(array $tokens): array
    {
        $indexes = [];
        foreach ($tokens as $index => $token) {
            if (!empty($token['is_target'])) {
                $indexes[] = $index;
            }
        }
        return $indexes;
    }

    /**
     * Log source context result (silently best-effort).
     */
    public function logSourceContextResult(WordSense $sense, array $result, array $extra = []): void
    {
        try {
            Log::info('sense_source_context', [
                'sense_id' => $sense->id,
                'user_id' => $sense->user_id,
                'language_id' => $sense->language_id,
                'lemma' => $sense->lemma,
                'surface_form' => $sense->surface_form,
                'source_available' => $result['source_available'] ?? null,
                'source_kind' => $result['source_kind'] ?? null,
                'chapter_id' => $result['chapter_id'] ?? null,
                'sentence_id' => $result['sentence_id'] ?? null,
                'target_count' => count($result['target_indexes'] ?? []),
            ] + $extra);
        } catch (\Throwable $e) {
        }
    }

    // ==================== Private helpers ====================

    private function doFindMatchingChapter(iterable $chapters, WordSense $sense, string $exampleSentence): ?array
    {
        $targetNormalized = $this->senseTokenPayloadService->normalizeSentenceText($exampleSentence);

        foreach ($chapters as $chapter) {
            // Check chapter title exact match
            $titleNormalized = $this->senseTokenPayloadService->normalizeSentenceText($chapter->name);
            if ($titleNormalized === $targetNormalized) {
                return ['chapter' => $chapter, 'kind' => 'chapter_title', 'target_key' => null];
            }

            // Check chapter body exact match
            $words = $this->senseTokenPayloadService->flattenProcessedWords($chapter->getProcessedText());
            if (empty($words)) {
                continue;
            }

            $groups = $this->groupTokensBySentenceWithIndexes($words);
            foreach ($groups as $key => $entries) {
                $rawWords = array_map(fn ($entry) => $entry['word'], $entries);
                $groupText = $this->senseTokenPayloadService->tokensToSentenceText($rawWords);
                $groupNormalized = $this->senseTokenPayloadService->normalizeSentenceText($groupText);

                if ($groupNormalized === $targetNormalized) {
                    return [
                        'chapter' => $chapter,
                        'kind' => 'chapter_recovered',
                        'target_key' => (string) $key,
                        'entries' => $entries,
                    ];
                }
            }
        }

        return null;
    }
}
