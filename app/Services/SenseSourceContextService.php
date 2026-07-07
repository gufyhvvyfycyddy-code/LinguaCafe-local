<?php

namespace App\Services;

use App\Models\WordSense;
use App\Models\WordSenseOccurrence;

/**
 * Public facade for sense source context.
 *
 * Orchestrates the fallback chain: chapter → chapter_recovered → chapter_title
 * → chapter_fuzzy → chapter_fuzzy_title → card_example → unavailable.
 *
 * Query/location logic is delegated to SenseSourceContextResolverService.
 * Token rendering is delegated to SenseTokenPayloadService.
 */
class SenseSourceContextService
{
    public function __construct(
        private SenseSourceContextResolverService $resolver,
        private SenseTokenPayloadService $senseTokenPayloadService,
    ) {
    }

    public function sourceContext(int $userId, string $language, int $senseId): array
    {
        $sense = $this->resolver->resolveSense($userId, $language, $senseId);
        $sourceOccurrence = $this->resolver->resolveSourceOccurrence($sense);
        $exampleOccurrence = $this->resolver->resolveExampleOccurrence($sense);

        $occurrence = $sourceOccurrence ?: $exampleOccurrence;
        $chapterId = $sourceOccurrence?->chapter_id ?? $sense->source_chapter_id;
        $sentenceId = $sourceOccurrence?->sentence_id ?? $sense->sentence_id;
        $sentenceHash = $sourceOccurrence?->sentence_hash ?? $sense->sentence_hash;
        $exampleSentence = $exampleOccurrence?->sentence_en ?? $sense->example_sentence_en;
        $targetOccurrence = $exampleOccurrence ?: $sourceOccurrence;

        // 1. Try direct chapter source
        if ($chapterId) {
            $chapter = $this->resolver->findChapterById($chapterId, $sense->user_id, $sense->language_id);
            if ($chapter) {
                $context = $this->sourceContextFromChapter($chapter, $sense, $targetOccurrence, $sentenceId, $sentenceHash, $exampleSentence);
                if ($context) {
                    $result = $this->buildChapterResult($sense, $chapter, $sentenceId, $sentenceHash, $context);
                    $this->resolver->logSourceContextResult($sense, $result, [
                        'has_example' => (bool) $exampleSentence,
                        'chapter_id_candidate' => $chapterId,
                    ]);
                    return $result;
                }
            }
        }

        // 2. Try recovered source (exact match example sentence in chapters)
        $recovered = $this->recoverSourceContextFromExampleSentence($sense, $targetOccurrence, $exampleSentence);
        if ($recovered) {
            $this->resolver->logSourceContextResult($sense, $recovered, [
                'has_example' => (bool) $exampleSentence,
                'chapter_id_candidate' => $chapterId,
            ]);
            return $recovered;
        }

        // 3. Try fuzzy match
        $fuzzy = $this->recoverSourceContextByFuzzyMatch($sense, $targetOccurrence, $exampleSentence);
        if ($fuzzy) {
            $this->resolver->logSourceContextResult($sense, $fuzzy, [
                'has_example' => (bool) $exampleSentence,
                'chapter_id_candidate' => $chapterId,
            ]);
            return $fuzzy;
        }

        // 4. Fallback to card example
        $fallback = $this->fallbackCardExampleSourceContext($sense, $targetOccurrence);
        if ($fallback) {
            $this->resolver->logSourceContextResult($sense, $fallback, [
                'has_example' => (bool) $exampleSentence,
                'chapter_id_candidate' => $chapterId,
            ]);
            return $fallback;
        }

        // 5. Unavailable
        $empty = $this->emptySourceContext($sense->id);
        $this->resolver->logSourceContextResult($sense, $empty, [
            'has_example' => (bool) $exampleSentence,
            'chapter_id_candidate' => $chapterId,
        ]);
        return $empty;
    }

    /**
     * Build a list of distinct source contexts for a sense.
     *
     * Used by the review page source dialog to support multi-source
     * navigation ("来源 1 / N"). Each source corresponds to a distinct
     * chapter_id from the sense's bound occurrences. When no chapter-based
     * source is available, falls back to a single sourceContext entry
     * (which may itself be the card_example fallback or unavailable).
     *
     * At most 3 sources are returned to keep the dialog fast.
     *
     * SenseSourceContextFollowDisplayedOccurrence-1000-7:
     * When $preferredOccurrenceId is provided, the service attempts to
     * build a source context for THAT occurrence first and places it at
     * sources[0]. The preferred occurrence must pass strict validation
     * (owned by user, correct language, correct sense, status=bound, has
     * chapter or sentence). On any failure, the call silently falls back
     * to the original multi-source logic and reports
     * preferred_occurrence_status = 'invalid' / 'fallback'.
     *
     * Payload contract:
     *   - preferred_occurrence_status: 'matched' | 'invalid' | 'fallback'
     *     | 'unavailable'
     *     'invalid' covers not found, wrong owner, wrong language, wrong
     *     sense, and non-bound occurrences.
     *     'unavailable' is returned when no preferred occurrence id was
     *     supplied (preserves the old contract for callers that don't care).
     */
    public function sourceContextList(int $userId, string $language, int $senseId, ?int $preferredOccurrenceId = null): array
    {
        $sense = $this->resolver->resolveSense($userId, $language, $senseId);

        // Keep PHP-level unique('chapter_id') for cross-database compatibility
        // (SQLite test env does not support MySQL's GROUP BY + orderByRaw
        // combination reliably), but cap the raw fetch at a smaller window
        // to reduce wasted rows and batch-load chapters to eliminate N+1.
        $occurrences = WordSenseOccurrence::query()
            ->where('user_id', $sense->user_id)
            ->where('language_id', $sense->language_id)
            ->where('word_sense_id', $sense->id)
            ->where('status', WordSenseOccurrence::STATUS_BOUND)
            ->whereNotNull('chapter_id')
            ->orderByRaw('CASE WHEN source = ? THEN 0 ELSE 1 END', [WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD])
            ->orderByDesc('id')
            ->limit(12)
            ->get()
            ->unique('chapter_id')
            ->take(3);

        // Batch-load all chapters referenced by the selected occurrences in
        // one query instead of one findChapterById() call per source.
        $chapterIds = $occurrences->pluck('chapter_id')->unique()->values()->all();
        $chaptersById = [];
        if (!empty($chapterIds)) {
            $chaptersById = \App\Models\Chapter::query()
                ->whereIn('id', $chapterIds)
                ->where('user_id', $sense->user_id)
                ->where('language', $sense->language_id)
                ->get()
                ->keyBy('id')
                ->all();
        }

        $sources = [];
        $usedOccurrenceIds = [];
        $usedChapterIds = [];

        // SenseSourceContextFollowDisplayedOccurrence-1000-7: try preferred
        // occurrence first so it lands at sources[0].
        // Status map:
        //   unavailable — no preferred id supplied (old callers)
        //   invalid     — preferred id supplied but not found / wrong owner
        //                 / wrong language / wrong sense / not bound
        //   fallback    — preferred is valid but has no usable chapter
        //   matched     — preferred resolved and placed at sources[0]
        $preferredStatus = 'unavailable';
        if ($preferredOccurrenceId !== null) {
            $preferred = $this->resolvePreferredOccurrence($sense, $preferredOccurrenceId);

            if ($preferred === null) {
                // Does not exist OR fails any ownership/scope/status check.
                $preferredStatus = 'invalid';
            } elseif (!$preferred->chapter_id) {
                // Exists and is bound to the user's sense, but has no
                // chapter to locate the original text. Keep fallback.
                $preferredStatus = 'fallback';
            } else {
                $chapter = $chaptersById[$preferred->chapter_id]
                    ?? $this->resolver->findChapterById($preferred->chapter_id, $sense->user_id, $sense->language_id);

                $context = $chapter
                    ? $this->sourceContextFromChapter(
                        $chapter,
                        $sense,
                        $preferred,
                        $preferred->sentence_id,
                        $preferred->sentence_hash,
                        $preferred->sentence_en,
                    )
                    : null;

                if ($chapter && $context !== null) {
                    $result = $this->buildChapterResult(
                        $sense,
                        $chapter,
                        $preferred->sentence_id,
                        $preferred->sentence_hash,
                        $context,
                    );
                    $result['occurrence_id'] = $preferred->id;
                    $result['source_sentence_en'] = $preferred->sentence_en;
                    $sources[] = $result;
                    $usedOccurrenceIds[] = $preferred->id;
                    $usedChapterIds[] = $preferred->chapter_id;
                    $preferredStatus = 'matched';
                } else {
                    // Chapter missing or sentence not found inside it.
                    $preferredStatus = 'fallback';
                }
            }
        }

        foreach ($occurrences as $occurrence) {
            if (in_array($occurrence->id, $usedOccurrenceIds, true)) {
                // Do not duplicate the preferred occurrence in the carousel.
                continue;
            }
            if (in_array($occurrence->chapter_id, $usedChapterIds, true)) {
                // Do not show another occurrence that shares the preferred's
                // chapter — the carousel keys visually by chapter, so showing
                // the same chapter twice would be confusing.
                continue;
            }
            $chapter = $chaptersById[$occurrence->chapter_id] ?? null;
            if (!$chapter) {
                continue;
            }

            $context = $this->sourceContextFromChapter(
                $chapter,
                $sense,
                $occurrence,
                $occurrence->sentence_id,
                $occurrence->sentence_hash,
                $occurrence->sentence_en,
            );

            if ($context === null) {
                continue;
            }

            $result = $this->buildChapterResult(
                $sense,
                $chapter,
                $occurrence->sentence_id,
                $occurrence->sentence_hash,
                $context,
            );
            $result['occurrence_id'] = $occurrence->id;
            $result['source_sentence_en'] = $occurrence->sentence_en;
            $sources[] = $result;
        }

        if (empty($sources)) {
            // No chapter-based sources — fall back to the existing single
            // sourceContext flow so the dialog still shows whatever fallback
            // (card_example / unavailable) is appropriate. If we reach here,
            // $preferredStatus is one of invalid / fallback / unavailable
            // (matched would have populated $sources above).
            $primary = $this->sourceContext($userId, $language, $senseId);
            return [
                'sense_id' => $sense->id,
                'sources' => [$primary],
                'count' => 1,
                'preferred_occurrence_status' => $preferredStatus,
            ];
        }

        return [
            'sense_id' => $sense->id,
            'sources' => $sources,
            'count' => count($sources),
            'preferred_occurrence_status' => $preferredStatus,
        ];
    }

    /**
     * Strictly resolve a preferred occurrence for the source-context-list
     * endpoint. Returns null when the occurrence does not exist OR fails
     * any ownership / scope / status check. Never throws — callers fall
     * back to the original multi-source list on null.
     *
     * Checks (SenseSourceContextFollowDisplayedOccurrence-1000-7 rule 2):
     *   - belongs to the sense's user_id
     *   - belongs to the sense's language_id
     *   - belongs to the sense's word_sense_id
     *   - status = bound
     */
    private function resolvePreferredOccurrence(WordSense $sense, int $occurrenceId): ?WordSenseOccurrence
    {
        return WordSenseOccurrence::query()
            ->where('id', $occurrenceId)
            ->where('user_id', $sense->user_id)
            ->where('language_id', $sense->language_id)
            ->where('word_sense_id', $sense->id)
            ->where('status', WordSenseOccurrence::STATUS_BOUND)
            ->first();
    }

    // ==================== Private/Internal helpers ====================

    private function sourceContextFromChapter(
        \App\Models\Chapter $chapter,
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

        $groups = $this->resolver->groupTokensBySentenceWithIndexes($words);
        $targetKey = $this->resolver->findSourceSentenceKey($groups, $sentenceId, $sentenceHash, $exampleSentence);
        if ($targetKey === null) {
            return null;
        }

        $entries = $this->resolver->contextEntriesAroundGroup($groups, $targetKey);
        $contextTokens = [];
        $targetIndexes = [];

        foreach ($entries as $localIndex => $entry) {
            $token = $this->buildContextToken($entry['word'], $localIndex, $sense, $occurrence, $entry['is_source_sentence'] ?? false);
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

    private function recoverSourceContextFromExampleSentence(
        WordSense $sense,
        ?WordSenseOccurrence $occurrence,
        ?string $exampleSentence
    ): ?array {
        if (!$exampleSentence) {
            return null;
        }

        $match = $this->resolver->findMatchingChapterByExampleText($sense, $exampleSentence);
        if ($match === null) {
            return null;
        }

        $chapter = $match['chapter'];
        $kind = $match['kind'];

        // Chapter title exact match
        if ($kind === 'chapter_title') {
            $this->resolver->writeBackRecoveredSource($sense, $occurrence, $chapter->id, null);

            $tokens = $this->senseTokenPayloadService->syntheticSentenceTokens($exampleSentence, $sense, $occurrence);
            $targetIndexes = $this->resolver->collectTargetIndexes($tokens);

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

        // Chapter body exact match
        $targetKey = $match['target_key'];
        $words = $this->senseTokenPayloadService->flattenProcessedWords($chapter->getProcessedText());
        $groups = $this->resolver->groupTokensBySentenceWithIndexes($words);
        $entries = $this->resolver->contextEntriesAroundGroup($groups, $targetKey);

        $contextTokens = [];
        $targetIndexes = [];

        foreach ($entries as $localIndex => $entry) {
            $token = $this->buildContextToken($entry['word'], $localIndex, $sense, $occurrence, $entry['is_source_sentence'] ?? false);
            if ($token['is_target']) {
                $targetIndexes[] = $localIndex;
            }
            $contextTokens[] = $token;
        }

        if (empty($contextTokens)) {
            return null;
        }

        $this->resolver->writeBackRecoveredSource($sense, $occurrence, $chapter->id, $targetKey);

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

    private function recoverSourceContextByFuzzyMatch(
        WordSense $sense,
        ?WordSenseOccurrence $occurrence,
        ?string $exampleSentence
    ): ?array {
        if (!$exampleSentence) {
            return null;
        }

        $best = $this->resolver->findMatchingChapterByFuzzyMatch($sense, $exampleSentence);
        if ($best === null) {
            return null;
        }

        $chapter = $best['chapter'];
        $kind = $best['kind'];
        $debugEnabled = \config('app.debug');

        // Chapter title fuzzy match
        if ($kind === 'chapter_title') {
            $this->resolver->writeBackRecoveredSource($sense, $occurrence, $chapter->id, null);

            $tokens = $this->senseTokenPayloadService->syntheticSentenceTokens($exampleSentence, $sense, $occurrence);
            $targetIndexes = $this->resolver->collectTargetIndexes($tokens);

            $result = [
                'sense_id' => $sense->id,
                'source_available' => true,
                'source_kind' => 'chapter_fuzzy_title',
                'chapter_id' => $chapter->id,
                'chapter_title' => $chapter->name,
                'sentence_id' => null,
                'sentence_hash' => null,
                'context_tokens' => $tokens,
                'target_indexes' => $targetIndexes,
                'fallback_message' => '已根据复习卡例句模糊定位到章节标题。',
            ];

            if ($debugEnabled) {
                $result['debug'] = [
                    'match_score' => $best['score'],
                    'match_kind' => $kind,
                    'contains_target' => $best['contains_target'],
                ];
            }

            return $result;
        }

        // Chapter body fuzzy match
        $words = $this->senseTokenPayloadService->flattenProcessedWords($chapter->getProcessedText());
        $groups = $this->resolver->groupTokensBySentenceWithIndexes($words);
        $entries = $this->resolver->contextEntriesAroundGroup($groups, $best['target_key']);

        $contextTokens = [];
        $targetIndexes = [];

        foreach ($entries as $localIndex => $entry) {
            $token = $this->buildContextToken($entry['word'], $localIndex, $sense, $occurrence, $entry['is_source_sentence'] ?? false);
            if ($token['is_target']) {
                $targetIndexes[] = $localIndex;
            }
            $contextTokens[] = $token;
        }

        if (empty($contextTokens)) {
            return null;
        }

        $this->resolver->writeBackRecoveredSource($sense, $occurrence, $chapter->id, $best['target_key']);

        $result = [
            'sense_id' => $sense->id,
            'source_available' => true,
            'source_kind' => 'chapter_fuzzy',
            'chapter_id' => $chapter->id,
            'chapter_title' => $chapter->name,
            'sentence_id' => $best['target_key'],
            'sentence_hash' => null,
            'context_tokens' => $contextTokens,
            'target_indexes' => $targetIndexes,
            'fallback_message' => '已根据复习卡例句模糊定位到原文位置。',
        ];

        if ($debugEnabled) {
            $result['debug'] = [
                'match_score' => $best['score'],
                'match_kind' => $kind,
                'contains_target' => $best['contains_target'],
            ];
        }

        return $result;
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

        $targetIndexes = $this->resolver->collectTargetIndexes($tokens);

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

    private function emptySourceContext(int $senseId): array
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
            'fallback_message' => '暂无可用原文位置',
        ];
    }

    private function buildChapterResult(
        WordSense $sense,
        \App\Models\Chapter $chapter,
        $sentenceId,
        $sentenceHash,
        array $context
    ): array {
        return [
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
    }

    private function buildContextToken($word, int $localIndex, WordSense $sense, ?WordSenseOccurrence $occurrence, bool $isSourceSentence = false): array
    {
        $token = $this->senseTokenPayloadService->simplifyToken($word, $localIndex);
        $token['is_source_sentence'] = $isSourceSentence;

        if ($this->senseTokenPayloadService->tokenMatchesSenseTarget($token['word'], $sense, $occurrence)) {
            $token['is_target'] = true;
        }

        return $token;
    }
}
