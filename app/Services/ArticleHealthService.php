<?php

namespace App\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ArticleHealthService
{
    /**
     * @return array<string, mixed>
     */
    public function report(int $userId, string $language, ?int $bookId = null): array
    {
        $book = null;
        if ($bookId !== null) {
            $book = DB::table('books')
                ->where('id', $bookId)
                ->where('user_id', $userId)
                ->where('language', $language)
                ->first(['id', 'name']);
            if ($book === null) {
                throw (new ModelNotFoundException())->setModel('Book', [$bookId]);
            }
        }

        $findings = [];
        $checks = [
            'tokenizer' => $this->tokenizerCheck($findings),
            'chapter_positions' => ['status' => 'not_configured'],
        ];
        $scanLimit = max(1, min(10000, (int) config('article_health.scan_limit', 1000)));
        $sampleLimit = max(1, min(100, (int) config('article_health.sample_limit', 20)));
        $truncated = false;

        $this->inspectBooks($userId, $language, $bookId, $scanLimit, $findings, $truncated);
        $this->inspectChapters(
            $userId,
            $language,
            $bookId,
            $scanLimit,
            $findings,
            $checks,
            $truncated,
        );
        $this->inspectReferences($userId, $language, $bookId, $sampleLimit, $findings);
        if ($bookId === null) {
            $this->inspectFallbackRatio($userId, $language, $findings);
            $this->inspectVocabulary(
                $userId,
                $language,
                $scanLimit,
                $sampleLimit,
                $findings,
                $truncated,
            );
        }

        if ($truncated) {
            $this->addFinding(
                $findings,
                'ARTICLE_HEALTH_SCAN_TRUNCATED',
                'info',
                'readiness',
                null,
                null,
                1,
                '部分健康检查已达到扫描上限；结果仅反映已扫描的数据。',
                ['scan_limit' => $scanLimit],
            );
        }

        usort($findings, fn (array $left, array $right): int => [
            $this->severityRank($left['severity']),
            $left['code'],
            $left['entity_id'] ?? 0,
        ] <=> [
            $this->severityRank($right['severity']),
            $right['code'],
            $right['entity_id'] ?? 0,
        ]);

        $summary = ['total' => count($findings), 'critical' => 0, 'warning' => 0, 'info' => 0];
        foreach ($findings as $finding) {
            $summary[$finding['severity']]++;
        }

        return [
            'generated_at' => now('UTC')->toIso8601String(),
            'scope' => array_filter([
                'language' => $language,
                'book_id' => $bookId,
                'book_name' => $book?->name,
            ], fn (mixed $value): bool => $value !== null),
            'status' => $summary['critical'] > 0
                ? 'critical'
                : ($summary['warning'] > 0 ? 'warning' : 'healthy'),
            'summary' => $summary,
            'checks' => $checks,
            'findings' => $findings,
            'scan' => [
                'limit' => $scanLimit,
                'truncated' => $truncated,
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @return array{status: string}
     */
    private function tokenizerCheck(array &$findings): array
    {
        $configured = trim((string) config('article_health.tokenizer_url', ''));
        if ($configured === '') {
            $this->addFinding(
                $findings,
                'ARTICLE_TOKENIZER_NOT_CONFIGURED',
                'info',
                'infrastructure',
                null,
                null,
                1,
                'Tokenizer 尚未配置；英文导入将依赖项目的 fallback 路径。',
            );

            return ['status' => 'not_configured'];
        }

        $baseUrl = str_starts_with($configured, 'http://')
            || str_starts_with($configured, 'https://')
            ? rtrim($configured, '/')
            : 'http://' . rtrim($configured, '/') . ':8678';

        try {
            $response = Http::timeout(max(
                1,
                min(10, (int) config('article_health.tokenizer_timeout_seconds', 3)),
            ))->get($baseUrl . '/tokenizer/health');
            $payload = $response->json();
            if ($response->successful()
                && is_array($payload)
                && in_array(($payload['status'] ?? null), ['ok', 'healthy', 'ready'], true)) {
                return ['status' => 'available'];
            }
        } catch (Throwable) {
            // Optional integration failures become a stable unavailable finding.
        }

        $this->addFinding(
            $findings,
            'ARTICLE_TOKENIZER_UNAVAILABLE',
            'warning',
            'infrastructure',
            null,
            null,
            1,
            'Tokenizer 当前不可用；健康报告仍可读取，英文导入会使用既有降级策略。',
        );

        return ['status' => 'unavailable'];
    }

    private function scopeOccurrenceQuery(
        Builder $query,
        int $userId,
        string $language,
        ?int $bookId,
    ): Builder {
        if ($bookId === null) {
            return $query;
        }

        return $query->whereExists(function (Builder $scope) use ($userId, $language, $bookId): void {
            $scope->selectRaw('1')
                ->from('chapters as scoped_chapters')
                ->whereColumn('scoped_chapters.id', 'occurrences.chapter_id')
                ->where('scoped_chapters.user_id', $userId)
                ->where('scoped_chapters.language', $language)
                ->where('scoped_chapters.book_id', $bookId);
        });
    }

    /**
     * @param list<array<string, mixed>> $findings
     */
    private function inspectBooks(
        int $userId,
        string $language,
        ?int $bookId,
        int $limit,
        array &$findings,
        bool &$truncated,
    ): void {
        $books = DB::table('books as books')
            ->where('books.user_id', $userId)
            ->where('books.language', $language)
            ->when($bookId !== null, fn (Builder $query) => $query->where('books.id', $bookId))
            ->whereNotExists(function (Builder $query) use ($userId, $language): void {
                $query->selectRaw('1')
                    ->from('chapters')
                    ->whereColumn('chapters.book_id', 'books.id')
                    ->where('chapters.user_id', $userId)
                    ->where('chapters.language', $language);
            })
            ->orderBy('books.id')
            ->limit($limit + 1)
            ->pluck('books.id');

        if ($books->count() > $limit) {
            $truncated = true;
            $books = $books->take($limit);
        }

        foreach ($books as $bookId) {
            $this->addFinding(
                $findings,
                'ARTICLE_BOOK_EMPTY',
                'warning',
                'content',
                'book',
                (int) $bookId,
                1,
                '阅读材料没有任何当前用户与语言范围内的章节。',
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @param array<string, array{status: string}> $checks
     */
    private function inspectChapters(
        int $userId,
        string $language,
        ?int $bookId,
        int $limit,
        array &$findings,
        array &$checks,
        bool &$truncated,
    ): void {
        $chapters = DB::table('chapters')
            ->where('user_id', $userId)
            ->where('language', $language)
            ->when($bookId !== null, fn (Builder $query) => $query->where('book_id', $bookId))
            ->orderBy('id')
            ->limit($limit + 1)
            ->get([
                'id',
                'book_id',
                'raw_text',
                'processed_text',
                'processing_status',
                'word_count',
            ]);

        if ($chapters->count() > $limit) {
            $truncated = true;
            $chapters = $chapters->take($limit);
        }

        foreach ($chapters as $chapter) {
            $chapterId = (int) $chapter->id;
            if (trim((string) $chapter->raw_text) === '' && (int) $chapter->word_count < 1) {
                $this->addFinding(
                    $findings,
                    'ARTICLE_CHAPTER_EMPTY',
                    'warning',
                    'content',
                    'chapter',
                    $chapterId,
                    1,
                    '章节没有可读取的原文或词数。',
                    ['book_id' => (int) $chapter->book_id],
                );
            }

            if ($chapter->processing_status === 'failed') {
                $this->addFinding(
                    $findings,
                    'ARTICLE_TOKENIZATION_FAILED',
                    'warning',
                    'readiness',
                    'chapter',
                    $chapterId,
                    1,
                    '章节的 tokenizer 处理状态为失败。',
                    ['book_id' => (int) $chapter->book_id],
                );
                continue;
            }
            if ($chapter->processing_status !== 'processed') {
                $this->addFinding(
                    $findings,
                    'ARTICLE_TOKENIZATION_PENDING',
                    'info',
                    'readiness',
                    'chapter',
                    $chapterId,
                    1,
                    '章节尚未完成 tokenizer 处理。',
                    ['book_id' => (int) $chapter->book_id],
                );
                continue;
            }

            $decoded = $this->decodeProcessedText($chapter->processed_text);
            if ($decoded === null) {
                $this->addFinding(
                    $findings,
                    'ARTICLE_TEXT_BLOCK_INVALID',
                    'warning',
                    'content',
                    'chapter',
                    $chapterId,
                    1,
                    '章节的 processed_text 无法安全解码。',
                    ['book_id' => (int) $chapter->book_id],
                );
            } elseif (! $this->containsWordToken($decoded)) {
                $this->addFinding(
                    $findings,
                    'ARTICLE_TEXT_BLOCK_EMPTY',
                    'warning',
                    'content',
                    'chapter',
                    $chapterId,
                    1,
                    '章节已标记处理完成，但没有可读取的 token/text block。',
                    ['book_id' => (int) $chapter->book_id],
                );
            }
        }

        if (! Schema::hasColumn('chapters', 'position')) {
            return;
        }

        $checks['chapter_positions'] = ['status' => 'available'];
        $duplicates = DB::table('chapters')
            ->where('user_id', $userId)
            ->where('language', $language)
            ->when($bookId !== null, fn (Builder $query) => $query->where('book_id', $bookId))
            ->select(['book_id', 'position'])
            ->selectRaw('COUNT(*) as duplicate_count')
            ->groupBy(['book_id', 'position'])
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('book_id')
            ->orderBy('position')
            ->get();

        foreach ($duplicates as $duplicate) {
            $this->addFinding(
                $findings,
                'ARTICLE_CHAPTER_POSITION_DUPLICATE',
                'warning',
                'structure',
                'book',
                (int) $duplicate->book_id,
                (int) $duplicate->duplicate_count,
                '同一阅读材料包含重复章节位置。',
                ['position' => (int) $duplicate->position],
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $findings
     */
    private function inspectReferences(
        int $userId,
        string $language,
        ?int $bookId,
        int $sampleLimit,
        array &$findings,
    ): void {
        if ($bookId === null) {
            $this->invalidReferenceFinding(
                DB::table('word_sense_occurrences as occurrences')
                ->where('occurrences.user_id', $userId)
                ->where('occurrences.language', $language)
                ->whereNotNull('occurrences.chapter_id')
                ->whereNotExists(function (Builder $query) use ($userId, $language): void {
                    $query->selectRaw('1')
                        ->from('chapters')
                        ->whereColumn('chapters.id', 'occurrences.chapter_id')
                        ->where('chapters.user_id', $userId)
                        ->where('chapters.language', $language);
                }),
            'ARTICLE_OCCURRENCE_CHAPTER_INVALID',
            '发生记录引用的章节不存在或不属于当前用户/语言。',
            $sampleLimit,
                $findings,
            );
        }
        $this->invalidReferenceFinding(
            $this->scopeOccurrenceQuery(
                DB::table('word_sense_occurrences as occurrences')
                    ->where('occurrences.user_id', $userId)
                    ->where('occurrences.language', $language)
                    ->whereNotNull('occurrences.word_sense_id')
                    ->whereNotExists(function (Builder $query) use ($userId, $language): void {
                        $query->selectRaw('1')
                            ->from('word_senses')
                            ->whereColumn('word_senses.id', 'occurrences.word_sense_id')
                            ->where('word_senses.user_id', $userId)
                            ->where('word_senses.language', $language);
                    }),
                $userId,
                $language,
                $bookId,
            ),
            'ARTICLE_OCCURRENCE_SENSE_INVALID',
            '发生记录引用的词义不存在或不属于当前用户/语言。',
            $sampleLimit,
            $findings,
        );
        $this->invalidReferenceFinding(
            $this->scopeOccurrenceQuery(
                DB::table('word_sense_occurrences as occurrences')
                    ->where('occurrences.user_id', $userId)
                    ->where('occurrences.language', $language)
                    ->whereNotNull('occurrences.review_card_id')
                    ->whereNotExists(function (Builder $query) use ($userId, $language): void {
                        $query->selectRaw('1')
                            ->from('review_cards')
                            ->whereColumn('review_cards.id', 'occurrences.review_card_id')
                            ->where('review_cards.user_id', $userId)
                            ->where('review_cards.language', $language);
                    }),
                $userId,
                $language,
                $bookId,
            ),
            'ARTICLE_OCCURRENCE_CARD_INVALID',
            '发生记录引用的复习卡不存在或不属于当前用户/语言。',
            $sampleLimit,
            $findings,
        );

        $invalidSenseSources = DB::table('word_senses as senses')
            ->where('senses.user_id', $userId)
            ->where('senses.language', $language)
            ->whereNotNull('senses.source_chapter_id')
            ->whereNotExists(function (Builder $query) use ($userId, $language): void {
                $query->selectRaw('1')
                    ->from('chapters')
                    ->whereColumn('chapters.id', 'senses.source_chapter_id')
                    ->where('chapters.user_id', $userId)
                    ->where('chapters.language', $language);
            });
        if ($bookId !== null) {
            $invalidSenseSources->whereExists(function (Builder $query) use ($userId, $language, $bookId): void {
                $query->selectRaw('1')
                    ->from('word_sense_occurrences as scoped_occurrences')
                    ->join('chapters as scoped_chapters', 'scoped_chapters.id', '=', 'scoped_occurrences.chapter_id')
                    ->whereColumn('scoped_occurrences.word_sense_id', 'senses.id')
                    ->where('scoped_occurrences.user_id', $userId)
                    ->where('scoped_occurrences.language', $language)
                    ->where('scoped_chapters.user_id', $userId)
                    ->where('scoped_chapters.language', $language)
                    ->where('scoped_chapters.book_id', $bookId);
            });
        }
        $count = (clone $invalidSenseSources)->count();
        if ($count > 0) {
            $this->addFinding(
                $findings,
                'ARTICLE_SENSE_SOURCE_CHAPTER_INVALID',
                'warning',
                'source',
                'word_sense',
                null,
                $count,
                '词义来源章节不存在或不属于当前用户/语言。',
                [
                    'sample_ids' => (clone $invalidSenseSources)
                        ->orderBy('senses.id')
                        ->limit($sampleLimit)
                        ->pluck('senses.id')
                        ->map(fn ($id): int => (int) $id)
                        ->all(),
                ],
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $findings
     */
    private function invalidReferenceFinding(
        Builder $query,
        string $code,
        string $message,
        int $sampleLimit,
        array &$findings,
    ): void {
        $count = (clone $query)->count();
        if ($count < 1) {
            return;
        }

        $this->addFinding(
            $findings,
            $code,
            'warning',
            'source',
            'word_sense_occurrence',
            null,
            $count,
            $message,
            [
                'sample_ids' => (clone $query)
                    ->orderBy('occurrences.id')
                    ->limit($sampleLimit)
                    ->pluck('occurrences.id')
                    ->map(fn ($id): int => (int) $id)
                    ->all(),
            ],
        );
    }

    /**
     * @param list<array<string, mixed>> $findings
     */
    private function inspectFallbackRatio(int $userId, string $language, array &$findings): void
    {
        $eligible = DB::table('word_senses as senses')
            ->where('senses.user_id', $userId)
            ->where('senses.language', $language)
            ->where('senses.status', 'confirmed')
            ->whereNotNull('senses.example_sentence_en')
            ->whereRaw("TRIM(senses.example_sentence_en) <> ''");
        $total = (clone $eligible)->count();
        $minimum = max(1, (int) config('article_health.fallback_minimum_senses', 10));
        if ($total < $minimum) {
            return;
        }

        $fallback = (clone $eligible)
            ->whereNotExists(function (Builder $query) use ($userId, $language): void {
                $query->selectRaw('1')
                    ->from('chapters')
                    ->whereColumn('chapters.id', 'senses.source_chapter_id')
                    ->where('chapters.user_id', $userId)
                    ->where('chapters.language', $language);
            })
            ->whereNotExists(function (Builder $query) use ($userId, $language): void {
                $query->selectRaw('1')
                    ->from('word_sense_occurrences as occurrences')
                    ->join('chapters', 'chapters.id', '=', 'occurrences.chapter_id')
                    ->whereColumn('occurrences.word_sense_id', 'senses.id')
                    ->where('occurrences.user_id', $userId)
                    ->where('occurrences.language', $language)
                    ->where('chapters.user_id', $userId)
                    ->where('chapters.language', $language);
            })
            ->count();
        $ratio = $fallback / $total;
        if ($ratio <= max(
            0.0,
            min(1.0, (float) config('article_health.fallback_warning_ratio', 0.25)),
        )) {
            return;
        }

        $this->addFinding(
            $findings,
            'ARTICLE_SOURCE_FALLBACK_EXCESSIVE',
            'warning',
            'source',
            'word_sense',
            null,
            $fallback,
            '较多词义只能使用保存例句，无法定位到当前范围内的原章节。',
            [
                'eligible_count' => $total,
                'fallback_count' => $fallback,
                'fallback_ratio' => round($ratio, 4),
            ],
        );
    }

    /**
     * @param list<array<string, mixed>> $findings
     */
    private function inspectVocabulary(
        int $userId,
        string $language,
        int $limit,
        int $sampleLimit,
        array &$findings,
        bool &$truncated,
    ): void {
        $words = DB::table('encountered_words')
            ->where('user_id', $userId)
            ->where('language', $language)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get(['id', 'word', 'lemma']);

        if ($words->count() > $limit) {
            $truncated = true;
            $words = $words->take($limit);
        }

        $polluted = $words->filter(function (object $word): bool {
            foreach ([(string) $word->word, (string) $word->lemma] as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '') {
                    continue;
                }
                if (preg_match('~^(?:https?://|www\.)~i', $candidate)
                    || filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false
                    || preg_match('~^(?:[A-Za-z]:[\\\\/]|/[^/]|\\\\\\\\)~', $candidate)) {
                    return true;
                }
            }

            return false;
        })->values();

        if ($polluted->isEmpty()) {
            return;
        }

        $this->addFinding(
            $findings,
            'ARTICLE_VOCABULARY_POLLUTION',
            'warning',
            'vocabulary',
            'encountered_word',
            null,
            $polluted->count(),
            '词汇数据中出现 URL、邮箱或路径样式的不可学习条目。',
            [
                'sample_ids' => $polluted
                    ->take($sampleLimit)
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->all(),
            ],
        );
    }

    private function decodeProcessedText(mixed $payload): mixed
    {
        if (! is_string($payload) || $payload === '') {
            return null;
        }

        $json = @gzuncompress(
            $payload,
            max(
                1024,
                min(
                    64 * 1024 * 1024,
                    (int) config('article_health.max_processed_text_bytes', 8 * 1024 * 1024),
                ),
            ),
        );
        if (! is_string($json)) {
            return null;
        }

        $decoded = json_decode($json, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private function containsWordToken(mixed $node): bool
    {
        if (! is_array($node)) {
            return false;
        }

        if (isset($node['word']) && is_string($node['word']) && trim($node['word']) !== '') {
            return true;
        }

        foreach ($node as $child) {
            if ($this->containsWordToken($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @param array<string, mixed> $metadata
     */
    private function addFinding(
        array &$findings,
        string $code,
        string $severity,
        string $category,
        ?string $entityType,
        ?int $entityId,
        int $count,
        string $message,
        array $metadata = [],
    ): void {
        $findings[] = [
            'code' => $code,
            'severity' => $severity,
            'category' => $category,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'count' => $count,
            'message' => $message,
            'metadata' => $metadata,
        ];
    }

    private function severityRank(string $severity): int
    {
        return match ($severity) {
            'critical' => 0,
            'warning' => 1,
            default => 2,
        };
    }
}
