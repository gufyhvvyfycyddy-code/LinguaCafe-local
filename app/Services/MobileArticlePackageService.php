<?php

namespace App\Services;

use App\Enums\ChapterProcessingStatusEnum;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\ChapterAiReadingAssist;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use JsonException;
use Throwable;

class MobileArticlePackageService
{
    public const SCHEMA_VERSION = 'mobile_download_package_v1';
    public const DEFAULT_TOKEN_LIMIT = 500;
    public const MAX_TOKEN_LIMIT = 1000;
    public const MAX_SHARD_BYTES = 1572864;
    public const DEFAULT_CHAPTERS_PER_PAGE = 50;
    public const MAX_CHAPTERS_PER_PAGE = 100;

    public function __construct(private WordSenseContentVersionService $wordSenseVersion)
    {
    }

    public function listForUser(int $userId, string $language, int $page, int $perPage): array
    {
        $paginator = Book::query()
            ->where('user_id', $userId)
            ->where('language', $language)
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'items' => $paginator->getCollection()
                ->map(function (Book $book) use ($userId, $language) {
                    $manifest = $this->buildManifest($book, $userId, $language);

                    return [
                        'schema_version' => $manifest['schema_version'],
                        'package_type' => $manifest['package_type'],
                        'book' => $manifest['book'],
                        'content_version' => $manifest['content_version'],
                        'content_checksum' => $manifest['content_checksum'],
                        'chapter_count' => $manifest['chapter_count'],
                        'manifest_endpoint' => "/api/v1/mobile/article-packages/{$book->id}",
                    ];
                })
                ->values()
                ->all(),
            'pagination' => $this->pagination($paginator),
        ];
    }

    public function manifestForUser(
        int $bookId,
        int $userId,
        string $language,
        int $chapterPage = 1,
        int $chaptersPerPage = self::DEFAULT_CHAPTERS_PER_PAGE,
    ): ?array {
        $book = $this->findBook($bookId, $userId, $language);

        return $book
            ? $this->buildManifest($book, $userId, $language, $chapterPage, $chaptersPerPage)
            : null;
    }

    public function chapterShardForUser(
        int $bookId,
        int $chapterId,
        int $userId,
        string $language,
        ?string $cursor,
        int $tokenLimit,
    ): ?array {
        $book = $this->findBook($bookId, $userId, $language);
        if (!$book) {
            return null;
        }

        $chapter = Chapter::query()
            ->where('id', $chapterId)
            ->where('book_id', $book->id)
            ->where('user_id', $userId)
            ->where('language', $language)
            ->where('processing_status', ChapterProcessingStatusEnum::PROCESSED->value)
            ->first();
        if (!$chapter) {
            return null;
        }

        $assist = $this->assistForChapter($chapter, $userId, $language);
        $summaries = $this->senseSummaries(collect([$chapter]), $userId, $language)[$chapter->id] ?? [];
        $descriptor = $this->chapterDescriptor($chapter, $assist, $summaries);
        $offset = $this->decodeArticleCursor(
            $cursor,
            $book->id,
            $chapter->id,
            $descriptor['content_checksum'],
        );
        $tokens = $this->tokens($chapter);
        if ($offset > count($tokens)) {
            throw new InvalidMobilePackageCursorException();
        }

        $limit = min($tokenLimit, self::MAX_TOKEN_LIMIT);
        $count = min($limit, count($tokens) - $offset);
        $payload = null;

        while ($count >= 0) {
            $slice = array_slice($tokens, $offset, $count);
            $payload = $this->buildShardPayload(
                $book,
                $chapter,
                $descriptor,
                $assist,
                $summaries,
                $slice,
                $offset,
                count($tokens),
            );

            if ($this->encodedBytes($payload) <= self::MAX_SHARD_BYTES - 4096) {
                break;
            }

            if ($count <= 1) {
                throw new InvalidMobilePackageSourceException(
                    'A source token exceeds the mobile package payload limit.',
                );
            }

            $count = intdiv($count, 2);
        }

        $nextOffset = $offset + count($payload['tokens']);
        $payload['next_cursor'] = $nextOffset < count($tokens)
            ? $this->encodeCursor([
                'v' => 1,
                'type' => 'article',
                'book_id' => $book->id,
                'chapter_id' => $chapter->id,
                'chapter_checksum' => $descriptor['content_checksum'],
                'offset' => $nextOffset,
            ])
            : null;
        $payload['has_more'] = $payload['next_cursor'] !== null;
        $payload['payload_bytes'] = 0;
        $payload['payload_bytes'] = $this->encodedBytes($payload);

        return $payload;
    }

    private function buildManifest(
        Book $book,
        int $userId,
        string $language,
        int $chapterPage = 1,
        int $chaptersPerPage = self::DEFAULT_CHAPTERS_PER_PAGE,
    ): array {
        $chapters = Chapter::query()
            ->where('book_id', $book->id)
            ->where('user_id', $userId)
            ->where('language', $language)
            ->where('processing_status', ChapterProcessingStatusEnum::PROCESSED->value)
            ->orderBy('id')
            ->get();
        $assists = ChapterAiReadingAssist::query()
            ->where('user_id', $userId)
            ->where('language', $language)
            ->whereIn('chapter_id', $chapters->pluck('id'))
            ->get()
            ->keyBy('chapter_id');
        $summaries = $this->senseSummaries($chapters, $userId, $language);
        $descriptors = $chapters->map(
            fn (Chapter $chapter) => $this->chapterDescriptor(
                $chapter,
                $assists->get($chapter->id),
                $summaries[$chapter->id] ?? [],
            ),
        )->values()->all();

        $checksum = $this->checksum([
            'schema_version' => self::SCHEMA_VERSION,
            'book' => [
                'id' => $book->id,
                'name' => $book->name,
                'language' => $book->language,
                'cover_image' => $book->cover_image,
            ],
            'chapters' => array_map(
                fn (array $chapter) => [
                    'chapter_id' => $chapter['chapter_id'],
                    'content_checksum' => $chapter['content_checksum'],
                ],
                $descriptors,
            ),
        ]);

        $chapterCount = count($descriptors);
        $lastPage = max(1, (int) ceil($chapterCount / $chaptersPerPage));
        $chapterSlice = array_slice(
            $descriptors,
            ($chapterPage - 1) * $chaptersPerPage,
            $chaptersPerPage,
        );

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'package_type' => 'article',
            'book' => [
                'book_id' => $book->id,
                'name' => $book->name,
                'language' => $book->language,
                'cover_image' => $book->cover_image,
            ],
            'content_version' => 'sha256:' . $checksum,
            'content_checksum' => $checksum,
            'invalidation' => [
                'strategy' => 'replace_when_version_differs',
                'merge_supported' => false,
            ],
            'chapter_count' => $chapterCount,
            'chapters' => $chapterSlice,
            'chapter_pagination' => [
                'current_page' => $chapterPage,
                'last_page' => $lastPage,
                'per_page' => $chaptersPerPage,
                'total' => $chapterCount,
            ],
        ];
    }

    private function chapterDescriptor(
        Chapter $chapter,
        ?ChapterAiReadingAssist $assist,
        array $summaries,
    ): array {
        $checksum = $this->checksum([
            'chapter' => [
                'id' => $chapter->id,
                'book_id' => $chapter->book_id,
                'name' => $chapter->name,
                'language' => $chapter->language,
                'word_count' => $chapter->word_count,
                'raw_text_checksum' => hash('sha256', (string) $chapter->raw_text),
                'processed_text_checksum' => hash('sha256', (string) $chapter->processed_text),
                'subtitle_timestamps' => $chapter->subtitle_timestamps,
            ],
            'reading_assist' => $assist ? [
                'schema_version' => $assist->schema_version,
                'sentence_translations' => $assist->sentence_translations ?? [],
            ] : null,
            'sense_summaries' => $summaries,
        ]);

        return [
            'chapter_id' => $chapter->id,
            'name' => $chapter->name,
            'word_count' => (int) $chapter->word_count,
            'token_count' => count($this->tokens($chapter)),
            'content_version' => 'sha256:' . $checksum,
            'content_checksum' => $checksum,
            'token_endpoint' => "/api/v1/mobile/article-packages/{$chapter->book_id}/chapters/{$chapter->id}",
        ];
    }

    private function buildShardPayload(
        Book $book,
        Chapter $chapter,
        array $descriptor,
        ?ChapterAiReadingAssist $assist,
        array $summaries,
        array $tokens,
        int $offset,
        int $totalTokens,
    ): array {
        $sentenceKeys = [];
        foreach ($tokens as $token) {
            if ($token['source_sentence_identity'] !== null) {
                $sentenceKeys[(string) $token['source_sentence_identity']] = true;
            }
        }

        $translations = array_values(array_filter(
            $assist?->sentence_translations ?? [],
            fn (array $translation) => isset(
                $sentenceKeys[(string) ($translation['sentence_index'] ?? '')],
            ),
        ));
        $shardSummaries = array_values(array_filter(
            $summaries,
            fn (array $summary) => (
                $summary['source_sentence_identity'] === null
                    ? $offset === 0
                    : isset($sentenceKeys[(string) $summary['source_sentence_identity']])
            ),
        ));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'package_type' => 'article_chapter_shard',
            'book' => [
                'book_id' => $book->id,
                'name' => $book->name,
                'language' => $book->language,
            ],
            'chapter' => [
                'chapter_id' => $chapter->id,
                'name' => $chapter->name,
                'word_count' => (int) $chapter->word_count,
                'content_version' => $descriptor['content_version'],
                'content_checksum' => $descriptor['content_checksum'],
                'subtitle_timestamps' => $this->decodeJsonArray($chapter->subtitle_timestamps),
            ],
            'offset' => $offset,
            'token_count' => count($tokens),
            'total_tokens' => $totalTokens,
            'tokens' => $tokens,
            'sentence_translations' => $translations,
            'sense_summaries' => $shardSummaries,
        ];
    }

    private function tokens(Chapter $chapter): array
    {
        $compressed = (string) $chapter->processed_text;
        $maxBytes = max(1, (int) config('article_health.max_processed_text_bytes', 8 * 1024 * 1024));
        $expanded = @gzuncompress($compressed, $maxBytes + 1);
        if ($expanded === false || strlen($expanded) > $maxBytes) {
            throw new InvalidMobilePackageSourceException();
        }

        try {
            $decoded = json_decode($expanded, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidMobilePackageSourceException();
        }

        $rawTokens = [];
        $this->flattenTokens($decoded, $rawTokens);
        $tokens = [];
        $derivedSection = 0;
        foreach ($rawTokens as $index => $raw) {
            $word = is_array($raw) ? (object) $raw : $raw;
            $sourceSentence = $word->sentence_index ?? $word->si ?? $word->sentence_id ?? 0;
            $sourceSection = $word->section_index ?? $word->section_id ?? $derivedSection;
            $isStructure = (bool) ($word->is_structure ?? false)
                || in_array(($word->word ?? ''), ['NEWLINE', 'PARAGRAPH_BREAK'], true);
            $tokens[] = [
                'position' => $index,
                'token_identity' => "chapter:{$chapter->id}:token:{$index}",
                'source_sentence_identity' => $sourceSentence,
                'sentence_identity' => "chapter:{$chapter->id}:sentence:{$sourceSentence}",
                'source_section_identity' => $sourceSection,
                'section_identity' => "chapter:{$chapter->id}:section:{$sourceSection}",
                'word' => (string) ($word->word ?? ''),
                'lemma' => isset($word->lemma) ? (string) $word->lemma : null,
                'reading' => isset($word->reading) ? (string) $word->reading : null,
                'lemma_reading' => isset($word->lemma_reading) ? (string) $word->lemma_reading : null,
                'pos' => isset($word->pos) ? (string) $word->pos : null,
                'is_structure' => $isStructure,
                'space_after' => (bool) ($word->spaceAfter ?? $word->space_after ?? true),
                'phrase_ids' => array_values((array) ($word->phrase_ids ?? [])),
            ];

            if (($word->word ?? null) === 'PARAGRAPH_BREAK'
                || preg_match('/^\[[A-Z]\]$/', (string) ($word->word ?? '')) === 1) {
                $derivedSection++;
            }
        }

        return $tokens;
    }

    private function flattenTokens(mixed $node, array &$tokens): void
    {
        if (is_object($node) && property_exists($node, 'word')) {
            $tokens[] = $node;
            return;
        }

        if (is_array($node) || is_object($node)) {
            foreach ((array) $node as $child) {
                $this->flattenTokens($child, $tokens);
            }
        }
    }

    private function senseSummaries(Collection $chapters, int $userId, string $language): array
    {
        if ($chapters->isEmpty()) {
            return [];
        }

        $rows = WordSenseOccurrence::query()
            ->join('word_senses', 'word_senses.id', '=', 'word_sense_occurrences.word_sense_id')
            ->where('word_sense_occurrences.user_id', $userId)
            ->where('word_sense_occurrences.language_id', $language)
            ->where('word_sense_occurrences.status', WordSenseOccurrence::STATUS_BOUND)
            ->whereIn('word_sense_occurrences.chapter_id', $chapters->pluck('id'))
            ->where('word_senses.user_id', $userId)
            ->where('word_senses.language_id', $language)
            ->where('word_senses.status', WordSense::STATUS_CONFIRMED)
            ->orderBy('word_sense_occurrences.chapter_id')
            ->orderBy('word_sense_occurrences.id')
            ->get([
                'word_sense_occurrences.id as occurrence_id',
                'word_sense_occurrences.chapter_id',
                'word_sense_occurrences.sentence_id',
                'word_sense_occurrences.surface',
                'word_sense_occurrences.lemma as occurrence_lemma',
                'word_sense_occurrences.pos as occurrence_pos',
                'word_senses.id as word_sense_id',
                'word_senses.lemma',
                'word_senses.surface_form',
                'word_senses.pos',
                'word_senses.sense_zh',
                'word_senses.sense_en',
            ]);

        $grouped = [];
        $senseModels = WordSense::query()
            ->whereIn('id', $rows->pluck('word_sense_id')->unique()->values())
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('status', WordSense::STATUS_CONFIRMED)
            ->get()
            ->keyBy('id');
        foreach ($rows as $row) {
            $senseModel = $senseModels->get($row->word_sense_id);
            $grouped[$row->chapter_id][] = [
                'occurrence_id' => (int) $row->occurrence_id,
                'word_sense_id' => (int) $row->word_sense_id,
                'word_sense_version' => $senseModel
                    ? $this->wordSenseVersion->version($senseModel)
                    : null,
                'source_sentence_identity' => $row->sentence_id,
                'sentence_identity' => $row->sentence_id === null
                    ? null
                    : "chapter:{$row->chapter_id}:sentence:{$row->sentence_id}",
                'surface' => $row->surface,
                'lemma' => $row->lemma ?? $row->occurrence_lemma,
                'pos' => $row->pos ?? $row->occurrence_pos,
                'sense_zh' => $row->sense_zh,
                'sense_en' => $row->sense_en,
            ];
        }

        return $grouped;
    }

    private function assistForChapter(
        Chapter $chapter,
        int $userId,
        string $language,
    ): ?ChapterAiReadingAssist {
        return ChapterAiReadingAssist::query()
            ->where('chapter_id', $chapter->id)
            ->where('user_id', $userId)
            ->where('language', $language)
            ->first();
    }

    private function findBook(int $bookId, int $userId, string $language): ?Book
    {
        return Book::query()
            ->where('id', $bookId)
            ->where('user_id', $userId)
            ->where('language', $language)
            ->first();
    }

    private function decodeArticleCursor(
        ?string $cursor,
        int $bookId,
        int $chapterId,
        string $chapterChecksum,
    ): int {
        if ($cursor === null || $cursor === '') {
            return 0;
        }

        $data = $this->decodeCursor($cursor);
        if (($data['v'] ?? null) !== 1
            || ($data['type'] ?? null) !== 'article'
            || (int) ($data['book_id'] ?? 0) !== $bookId
            || (int) ($data['chapter_id'] ?? 0) !== $chapterId
            || !is_int($data['offset'] ?? null)
            || $data['offset'] < 0) {
            throw new InvalidMobilePackageCursorException();
        }
        if (!hash_equals($chapterChecksum, (string) ($data['chapter_checksum'] ?? ''))) {
            throw new InvalidMobilePackageCursorException(
                'The article changed after this cursor was issued.',
                'ARTICLE_PACKAGE_CHANGED',
                409,
            );
        }

        return $data['offset'];
    }

    private function encodeCursor(array $data): string
    {
        return Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function decodeCursor(string $cursor): array
    {
        try {
            $decoded = json_decode(Crypt::decryptString($cursor), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new InvalidMobilePackageCursorException();
        }

        if (!is_array($decoded)) {
            throw new InvalidMobilePackageCursorException();
        }

        return $decoded;
    }

    private function checksum(array $value): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalize($child);
        }

        return $value;
    }

    private function decodeJsonArray(?string $json): array
    {
        if (!$json) {
            return [];
        }

        try {
            $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidMobilePackageSourceException();
        }

        return is_array($value) ? $value : [];
    }

    private function encodedBytes(array $payload): int
    {
        return strlen(json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    private function pagination(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
