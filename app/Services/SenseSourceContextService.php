<?php

namespace App\Services;

use App\Models\Chapter;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use Illuminate\Support\Facades\Log;

class SenseSourceContextService
{
    public function __construct(
        private SenseTokenPayloadService $senseTokenPayloadService,
    ) {
    }

    public function sourceContext(int $userId, string $language, int $senseId): array
    {
        $sense = WordSense::where('id', $senseId)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('status', WordSense::STATUS_CONFIRMED)
            ->firstOrFail();

        $sourceOccurrence = WordSenseOccurrence::query()
            ->where('user_id', $sense->user_id)
            ->where('language_id', $sense->language_id)
            ->where('word_sense_id', $sense->id)
            ->where('status', WordSenseOccurrence::STATUS_BOUND)
            ->whereNotNull('chapter_id')
            ->orderByRaw('CASE WHEN source = ? THEN 0 ELSE 1 END', [WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD])
            ->orderByDesc('id')
            ->first();

        $exampleOccurrence = WordSenseOccurrence::query()
            ->where('user_id', $sense->user_id)
            ->where('language_id', $sense->language_id)
            ->where('word_sense_id', $sense->id)
            ->where('status', WordSenseOccurrence::STATUS_BOUND)
            ->whereNotNull('sentence_en')
            ->where('sentence_en', '<>', '')
            ->orderByRaw('CASE WHEN source = ? THEN 0 ELSE 1 END', [WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD])
            ->orderByDesc('id')
            ->first();

        $occurrence = $sourceOccurrence ?: $exampleOccurrence;
        $chapterId = $sourceOccurrence?->chapter_id ?? $sense->source_chapter_id;
        $sentenceId = $sourceOccurrence?->sentence_id ?? $sense->sentence_id;
        $sentenceHash = $sourceOccurrence?->sentence_hash ?? $sense->sentence_hash;
        $exampleSentence = $exampleOccurrence?->sentence_en ?? $sense->example_sentence_en;
        $targetOccurrence = $exampleOccurrence ?: $sourceOccurrence;

        if ($chapterId) {
            $chapter = Chapter::query()
                ->where('id', $chapterId)
                ->where('user_id', $sense->user_id)
                ->where('language', $sense->language_id)
                ->first();

            if ($chapter) {
                $context = $this->sourceContextFromChapter(
                    $chapter,
                    $sense,
                    $targetOccurrence,
                    $sentenceId,
                    $sentenceHash,
                    $exampleSentence
                );

                if ($context) {
                    $result = [
                        'sense_id' => $sense->id,
                        'source_available' => true,
                        'source_kind' => 'chapter',
                        'chapter_id' => $chapter->id,
                        'chapter_title' => $chapter->name,
                        'sentence_id' => $sentenceId,
                        'sentence_hash' => $sentenceHash,
                        'context_tokens' => $context['tokens'],
                        'target_indexes' => $context['target_indexes'],
                        'fallback_message' => null,
                    ];
                    $this->logSourceContextResult($sense, $result, [
                        'has_example' => (bool) $exampleSentence,
                        'chapter_id_candidate' => $chapterId,
                    ]);
                    return $result;
                }
            }
        }

        $recovered = $this->recoverSourceContextFromExampleSentence($sense, $targetOccurrence, $exampleSentence);
        if ($recovered) {
            $this->logSourceContextResult($sense, $recovered, [
                'has_example' => (bool) $exampleSentence,
                'chapter_id_candidate' => $chapterId,
            ]);
            return $recovered;
        }

        $fuzzy = $this->recoverSourceContextByFuzzyMatch($sense, $targetOccurrence, $exampleSentence);
        if ($fuzzy) {
            $this->logSourceContextResult($sense, $fuzzy, [
                'has_example' => (bool) $exampleSentence,
                'chapter_id_candidate' => $chapterId,
            ]);
            return $fuzzy;
        }

        $fallback = $this->fallbackCardExampleSourceContext($sense, $targetOccurrence);
        if ($fallback) {
            $this->logSourceContextResult($sense, $fallback, [
                'has_example' => (bool) $exampleSentence,
                'chapter_id_candidate' => $chapterId,
            ]);
            return $fallback;
        }

        $empty = $this->emptySourceContext($sense->id, '暂无可用原文位置');
        $this->logSourceContextResult($sense, $empty, [
            'has_example' => (bool) $exampleSentence,
            'chapter_id_candidate' => $chapterId,
        ]);
        return $empty;
    }

    private function emptySourceContext(int $senseId, string $message): array
    {
        return [
            'sense_id' => $senseId,
            'source_available' => false,
            'source_kind' => null,
            'chapter_id' => null,
            'chapter_title' => null,
            'sentence_id' => null,
            'sentence_hash' => null,
            'context_tokens' => [],
            'target_indexes' => [],
            'fallback_message' => $message,
        ];
    }

    private function fallbackCardExampleSourceContext(WordSense $sense, ?WordSenseOccurrence $occurrence = null): ?array
    {
        if (!$sense->example_sentence_en && (!$occurrence || !$occurrence->sentence_en)) {
            return null;
        }

        $sentence = $occurrence?->sentence_en ?: $sense->example_sentence_en;

        $tokens = $this->senseTokenPayloadService->syntheticSentenceTokens($sentence, $sense, $occurrence);

        if (empty($tokens)) {
            return null;
        }

        $targetIndexes = [];
        foreach ($tokens as $index => $token) {
            if (!empty($token['is_target'])) {
                $targetIndexes[] = $index;
            }
        }

        return [
            'sense_id' => $sense->id,
            'source_available' => true,
            'source_kind' => 'card_example',
            'chapter_id' => null,
            'chapter_title' => null,
            'sentence_id' => $occurrence?->sentence_id ?? $sense->sentence_id,
            'sentence_hash' => $occurrence?->sentence_hash ?? $sense->sentence_hash,
            'context_tokens' => $tokens,
            'target_indexes' => $targetIndexes,
            'fallback_message' => '未找到原章节位置，以下为复习卡保存的例句。',
        ];
    }

    private function recoverSourceContextFromExampleSentence(
        WordSense $sense,
        ?WordSenseOccurrence $occurrence,
        ?string $exampleSentence
    ): ?array {
        if (!$exampleSentence) {
            return null;
        }

        $chapters = Chapter::query()
            ->where('user_id', $sense->user_id)
            ->where('language', $sense->language_id)
            ->orderByDesc('id')
            ->get();

        $targetNormalized = $this->senseTokenPayloadService->normalizeSentenceText($exampleSentence);

        foreach ($chapters as $chapter) {
            $titleNormalized = $this->senseTokenPayloadService->normalizeSentenceText($chapter->name);
            if ($titleNormalized === $targetNormalized) {
                $tokens = $this->senseTokenPayloadService->syntheticSentenceTokens($exampleSentence, $sense, $occurrence);
                $targetIndexes = [];
                foreach ($tokens as $index => $token) {
                    if (!empty($token['is_target'])) {
                        $targetIndexes[] = $index;
                    }
                }

                $this->writeBackRecoveredSource($sense, $occurrence, $chapter->id, null);

                return [
                    'sense_id' => $sense->id,
                    'source_available' => true,
                    'source_kind' => 'chapter_title',
                    'chapter_id' => $chapter->id,
                    'chapter_title' => $chapter->name,
                    'sentence_id' => null,
                    'sentence_hash' => null,
                    'context_tokens' => $tokens,
                    'target_indexes' => $targetIndexes,
                    'fallback_message' => '该例句来自章节标题。',
                ];
            }

            $words = $this->senseTokenPayloadService->flattenProcessedWords($chapter->getProcessedText());
            if (empty($words)) {
                continue;
            }

            $groups = $this->groupTokensBySentenceWithIndexes($words);
            $targetKey = null;

            foreach ($groups as $key => $entries) {
                $rawWords = array_map(fn ($entry) => $entry['word'], $entries);
                $groupText = $this->senseTokenPayloadService->tokensToSentenceText($rawWords);
                $groupNormalized = $this->senseTokenPayloadService->normalizeSentenceText($groupText);

                if ($groupNormalized === $targetNormalized) {
                    $targetKey = (string) $key;
                    break;
                }
            }

            if ($targetKey === null) {
                continue;
            }

            $entries = $this->contextEntriesAroundGroup($groups, $targetKey);

            $contextTokens = [];
            $targetIndexes = [];

            foreach ($entries as $localIndex => $entry) {
                $token = $this->simplifyContextToken($entry['word'], $localIndex, $sense, $occurrence, $entry['is_source_sentence'] ?? false);
                if ($token['is_target']) {
                    $targetIndexes[] = $localIndex;
                }
                $contextTokens[] = $token;
            }

            if (empty($contextTokens)) {
                continue;
            }

            $this->writeBackRecoveredSource($sense, $occurrence, $chapter->id, $targetKey);

            return [
                'sense_id' => $sense->id,
                'source_available' => true,
                'source_kind' => 'chapter_recovered',
                'chapter_id' => $chapter->id,
                'chapter_title' => $chapter->name,
                'sentence_id' => $targetKey,
                'sentence_hash' => null,
                'context_tokens' => $contextTokens,
                'target_indexes' => $targetIndexes,
                'fallback_message' => '已根据复习卡例句定位到原章节。',
            ];
        }

        return null;
    }

    private function writeBackRecoveredSource(
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

    private function sourceContextFromChapter(
        Chapter $chapter,
        WordSense $sense,
        ?WordSenseOccurrence $occurrence,
        $sentenceId,
        $sentenceHash,
        ?string $exampleSentence
    ): ?array {
        $words = $this->senseTokenPayloadService->flattenProcessedWords($chapter->getProcessedText());

        if (empty($words)) {
            return null;
        }

        $groups = $this->groupTokensBySentenceWithIndexes($words);
        $targetKey = $this->findSourceSentenceKey($groups, $sentenceId, $sentenceHash, $exampleSentence);

        if ($targetKey === null) {
            return null;
        }

        $entries = $this->contextEntriesAroundGroup($groups, $targetKey);

        $contextTokens = [];
        $targetIndexes = [];

        foreach ($entries as $localIndex => $entry) {
            $token = $this->simplifyContextToken($entry['word'], $localIndex, $sense, $occurrence, $entry['is_source_sentence'] ?? false);
            if ($token['is_target']) {
                $targetIndexes[] = $localIndex;
            }
            $contextTokens[] = $token;
        }

        if (empty($contextTokens)) {
            return null;
        }

        return [
            'tokens' => $contextTokens,
            'target_indexes' => $targetIndexes,
        ];
    }

    private function groupTokensBySentenceWithIndexes(array $words): array
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

    private function findSourceSentenceKey(array $groups, $sentenceId, $sentenceHash, ?string $exampleSentence): ?string
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

    private function contextEntriesAroundGroup(array $groups, string $targetKey): array
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

    private function simplifyContextToken($word, int $localIndex, WordSense $sense, ?WordSenseOccurrence $occurrence, bool $isSourceSentence = false): array
    {
        $token = $this->senseTokenPayloadService->simplifyToken($word, $localIndex);

        $token['is_source_sentence'] = $isSourceSentence;

        if ($this->senseTokenPayloadService->tokenMatchesSenseTarget($token['word'], $sense, $occurrence)) {
            $token['is_target'] = true;
        }

        return $token;
    }

    // ==================== Fuzzy source recovery ====================

    private function recoverSourceContextByFuzzyMatch(
        WordSense $sense,
        ?WordSenseOccurrence $occurrence,
        ?string $exampleSentence
    ): ?array {
        if (!$exampleSentence) {
            return null;
        }

        $chapters = Chapter::query()
            ->where('user_id', $sense->user_id)
            ->where('language', $sense->language_id)
            ->orderByDesc('id')
            ->get();

        if ($chapters->isEmpty()) {
            return null;
        }

        $queryTokens = $this->meaningfulTextTokens($exampleSentence);
        $targetTerms = $this->targetTerms($sense, $occurrence);
        $queryTokensCount = count($queryTokens);

        $best = null;

        foreach ($chapters as $chapter) {
            $titleScore = $this->fuzzySourceScore($exampleSentence, $chapter->name, $targetTerms);
            if ($titleScore['score'] > ($best['score'] ?? 0)) {
                $best = [
                    'chapter' => $chapter,
                    'target_key' => null,
                    'entries' => null,
                    'kind' => 'chapter_title',
                    'score' => $titleScore['score'],
                    'contains_target' => $titleScore['contains_target'],
                    'text_preview' => mb_substr($chapter->name, 0, 120),
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
                        'entries' => $entries,
                        'kind' => 'chapter_fuzzy',
                        'score' => $candidateScore['score'],
                        'contains_target' => $candidateScore['contains_target'],
                        'text_preview' => mb_substr($groupText, 0, 120),
                    ];
                }
            }
        }

        if ($best === null || $best['score'] <= 0) {
            return null;
        }

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

        if ($best['kind'] === 'chapter_title') {
            $tokens = $this->senseTokenPayloadService->syntheticSentenceTokens($exampleSentence, $sense, $occurrence);
            $targetIndexes = $this->collectTargetIndexes($tokens);

            $this->writeBackRecoveredSource($sense, $occurrence, $best['chapter']->id, null);

            $result = [
                'sense_id' => $sense->id,
                'source_available' => true,
                'source_kind' => 'chapter_fuzzy_title',
                'chapter_id' => $best['chapter']->id,
                'chapter_title' => $best['chapter']->name,
                'sentence_id' => null,
                'sentence_hash' => null,
                'context_tokens' => $tokens,
                'target_indexes' => $targetIndexes,
                'fallback_message' => '已根据复习卡例句模糊定位到章节标题。',
            ];

            if (config('app.debug')) {
                $result['debug'] = [
                    'match_score' => $best['score'],
                    'match_kind' => $best['kind'],
                    'contains_target' => $best['contains_target'],
                    'text_preview' => $best['text_preview'],
                ];
            }

            return $result;
        }

        $groups = $this->groupTokensBySentenceWithIndexes(
            $this->senseTokenPayloadService->flattenProcessedWords($best['chapter']->getProcessedText())
        );

        $entries = $this->contextEntriesAroundGroup($groups, $best['target_key']);

        $contextTokens = [];
        $targetIndexes = [];

        foreach ($entries as $localIndex => $entry) {
            $token = $this->simplifyContextToken(
                $entry['word'],
                $localIndex,
                $sense,
                $occurrence,
                $entry['is_source_sentence'] ?? false
            );
            if ($token['is_target']) {
                $targetIndexes[] = $localIndex;
            }
            $contextTokens[] = $token;
        }

        if (empty($contextTokens)) {
            return null;
        }

        $this->writeBackRecoveredSource($sense, $occurrence, $best['chapter']->id, $best['target_key']);

        $result = [
            'sense_id' => $sense->id,
            'source_available' => true,
            'source_kind' => 'chapter_fuzzy',
            'chapter_id' => $best['chapter']->id,
            'chapter_title' => $best['chapter']->name,
            'sentence_id' => $best['target_key'],
            'sentence_hash' => null,
            'context_tokens' => $contextTokens,
            'target_indexes' => $targetIndexes,
            'fallback_message' => '已根据复习卡例句模糊定位到原文位置。',
        ];

        if (config('app.debug')) {
            $result['debug'] = [
                'match_score' => $best['score'],
                'match_kind' => $best['kind'],
                'contains_target' => $best['contains_target'],
                'text_preview' => $best['text_preview'],
            ];
        }

        return $result;
    }

    private function meaningfulTextTokens(string $text): array
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

        $tokens = array_values(array_filter($tokens, fn ($t) => !in_array($t, $stopwords, true)));

        return $tokens;
    }

    private function targetTerms(WordSense $sense, ?WordSenseOccurrence $occurrence): array
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

    private function fuzzySourceScore(string $query, string $candidate, array $targetTerms): array
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

    private function collectTargetIndexes(array $tokens): array
    {
        $indexes = [];
        foreach ($tokens as $index => $token) {
            if (!empty($token['is_target'])) {
                $indexes[] = $index;
            }
        }
        return $indexes;
    }

    private function logSourceContextResult(WordSense $sense, array $result, array $extra = []): void
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
}
