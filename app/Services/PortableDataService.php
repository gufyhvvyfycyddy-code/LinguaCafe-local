<?php

namespace App\Services;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\MediaAsset;
use App\Models\MediaReference;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\WordSense;
use App\Models\WordSenseTag;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use ZipArchive;

class PortableDataService
{
    public const CONTENT_FORMAT = 'linguacafe-wordsense-content';
    public const PACKAGE_FORMAT = 'linguacafe-portable-data';
    public const FORMAT_VERSION = 1;
    public const MAX_UPLOAD_BYTES = 26214400;
    public const MAX_MEDIA_PACKAGE_BYTES = 20971520;
    public const PREVIEW_TTL_MINUTES = 20;

    public const CONTENT_FIELDS = [
        'external_id', 'surface_form', 'lemma', 'pos', 'sense_zh', 'sense_en',
        'example_sentence_en', 'example_sentence_zh', 'source', 'tags',
        'fsrs_state', 'fsrs_due_at', 'fsrs_stability', 'fsrs_difficulty',
        'fsrs_reps', 'fsrs_lapses', 'fsrs_last_reviewed_at',
    ];

    public function __construct(
        private AnkiWordSensePackageService $anki,
        private BackupService $backups,
        private PortableDataImportPlanService $importPlan,
        private MediaAssetService $media,
    ) {}

    public function contentEnvelope(
        array $items,
        int $userId,
        string $language,
        bool $includeScheduling = false,
    ): array
    {
        return [
            'format' => self::CONTENT_FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'exported_at' => now()->toISOString(),
            'scope' => [
                'user_fingerprint' => $this->importPlan->portableUserFingerprint($userId),
                'language' => $language,
            ],
            'fields' => self::CONTENT_FIELDS,
            'count' => count($items),
            'include_scheduling' => $includeScheduling,
            'items' => array_map(
                fn (array $item) => $this->normalizeExportItem(
                    $item,
                    $includeScheduling,
                    $this->importPlan->portableOrigin($userId),
                ),
                $items,
            ),
        ];
    }

    /** @return array{path:string,count:int,sha256:string} */
    public function buildFullPackage(
        array $items,
        int $userId,
        string $language,
        bool $includeMedia = false,
    ): array
    {
        $directory = storage_path('app/temp/portable-' . bin2hex(random_bytes(12)));
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the portable export workspace.');
        }
        $packagePath = $directory . DIRECTORY_SEPARATOR . 'linguacafe-portable-data.lcpkg';

        try {
            $content = $this->contentEnvelope($items, $userId, $language, true);
            $articles = $this->articleEnvelope($userId, $language);
            $settings = $this->settingsEnvelope($userId);
            $history = $this->historyEnvelope($userId, $language);
            $files = [
                'content.json' => $this->encode($content),
                'articles.json' => $this->encode($articles),
                'settings.json' => $this->encode($settings),
                'history.json' => $this->encode($history),
            ];
            $mediaFiles = [];
            $mediaEnvelope = null;
            if ($includeMedia) {
                [$mediaEnvelope, $mediaFiles] = $this->mediaEnvelope($userId, $language);
                $files['media.json'] = $this->encode($mediaEnvelope);
            }
            $fileManifest = array_map(fn (string $bytes) => [
                'sha256' => hash('sha256', $bytes),
                'size_bytes' => strlen($bytes),
            ], $files);
            foreach ($mediaFiles as $name => $mediaFile) {
                $fileManifest[$name] = [
                    'sha256' => $mediaFile['sha256'],
                    'size_bytes' => $mediaFile['size_bytes'],
                ];
            }
            $manifest = [
                'format' => self::PACKAGE_FORMAT,
                'format_version' => self::FORMAT_VERSION,
                'schema' => $includeMedia ? 'm18-portable-media-v1' : 'm16-portable-v1',
                'include_media' => $includeMedia,
                'exported_at' => now()->toISOString(),
                'scope' => [
                    'user_fingerprint' => $this->importPlan->portableUserFingerprint($userId),
                    'language' => $language,
                ],
                'files' => $fileManifest,
                'counts' => [
                    'content' => count($content['items']),
                    'books' => count($articles['books']),
                    'chapters' => $this->articleChapterCount($articles['books']),
                    'review_logs' => count($history['items']),
                    'media_assets' => count($mediaEnvelope['assets'] ?? []),
                    'media_references' => count($mediaEnvelope['references'] ?? []),
                ],
            ];
            $files = ['manifest.json' => $this->encode($manifest)] + $files;

            $zip = new ZipArchive();
            if ($zip->open($packagePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to create the portable package.');
            }
            try {
                foreach ($files as $name => $bytes) {
                    if (! $zip->addFromString($name, $bytes)) {
                        throw new RuntimeException('Unable to write the portable package.');
                    }
                }
                foreach ($mediaFiles as $name => $mediaFile) {
                    if (! $zip->addFile($mediaFile['path'], $name)) {
                        throw new RuntimeException('Unable to write media into the portable package.');
                    }
                }
            } finally {
                $zip->close();
            }
            if ($includeMedia && filesize($packagePath) > self::MAX_UPLOAD_BYTES) {
                throw new RuntimeException('Portable package exceeds the 25 MiB V1 import limit.');
            }

            return [
                'path' => $packagePath,
                'count' => count($items),
                'sha256' => hash_file('sha256', $packagePath),
                'media_count' => count($mediaEnvelope['assets'] ?? []),
            ];
        } catch (Throwable $exception) {
            $this->cleanupPackage($packagePath);
            throw $exception;
        }
    }

    public function preview(UploadedFile $upload, int $userId, string $language): array
    {
        if (! $upload->isValid() || $upload->getSize() > self::MAX_UPLOAD_BYTES) {
            throw ValidationException::withMessages(['file' => '文件无效或超过 25 MiB。']);
        }

        $extension = strtolower($upload->getClientOriginalExtension());
        $path = $upload->getRealPath();
        $package = ['kind' => $extension, 'articles' => [], 'settings' => [], 'history' => [], 'media' => ['assets' => [], 'references' => []]];
        try {
            if ($extension === 'apkg') {
                $items = $this->anki->parse($path);
                $package['kind'] = 'apkg';
            } elseif ($extension === 'json') {
                $items = $this->parseJson((string) file_get_contents($path));
                $package['kind'] = 'json';
            } elseif ($extension === 'csv') {
                $items = $this->parseCsv($path);
                $package['kind'] = 'csv';
            } elseif ($extension === 'lcpkg') {
                $package = $this->parseFullPackage($path, $language);
                $items = $package['items'];
            } else {
                throw new InvalidArgumentException('只支持 .apkg、.json、.csv 或 .lcpkg。');
            }
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        if (count($items) > ReviewCardExportService::EXPORT_LIMIT) {
            throw ValidationException::withMessages(['file' => '内容条目超过 5,000 条。']);
        }

        $restoreScheduling = $package['kind'] === 'lcpkg';
        try {
            $classified = $this->importPlan->classify($items, $userId, $language, $restoreScheduling);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }
        $token = (string) Str::uuid();
        $payload = [
            'user_id' => $userId,
            'language' => $language,
            'kind' => $package['kind'],
            'restore_scheduling' => $restoreScheduling,
            'items' => $items,
            'articles' => $package['articles'] ?? [],
            'settings' => $package['settings'] ?? [],
            'history' => $package['history'] ?? [],
            'media' => $package['media'] ?? ['assets' => [], 'references' => []],
            'source_sha256' => hash_file('sha256', $path),
            'database_fingerprint' => $this->importPlan->databaseFingerprint(
                $classified,
                $package['articles'] ?? [],
                $package['settings'] ?? [],
                $userId,
                $language,
            ),
            'created_at' => now()->toISOString(),
        ];
        Cache::put($this->cacheKey($token), $payload, now()->addMinutes(self::PREVIEW_TTL_MINUTES));

        $counts = array_count_values(array_column($classified, 'action'));
        return [
            'preview_token' => $token,
            'source_kind' => $package['kind'],
            'expires_in_seconds' => self::PREVIEW_TTL_MINUTES * 60,
            'counts' => [
                'create' => $counts['create'] ?? 0,
                'update' => $counts['update'] ?? 0,
                'skip' => $counts['skip'] ?? 0,
                'conflict' => $counts['conflict'] ?? 0,
                'articles' => $this->articleChapterCount($package['articles'] ?? []),
                'settings' => count($package['settings'] ?? []),
                'history' => count($package['history'] ?? []),
                'media_assets' => count($package['media']['assets'] ?? []),
                'media_references' => count($package['media']['references'] ?? []),
            ],
            'items' => array_slice($classified, 0, 100),
            'can_apply' => ($counts['conflict'] ?? 0) === 0,
        ];
    }

    public function apply(string $token, int $userId, string $language): array
    {
        $lock = Cache::lock('portable-data-apply:' . $token, 120);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['preview_token' => '该预览正在应用，请勿重复提交。']);
        }
        try {
            return $this->applyLocked($token, $userId, $language);
        } finally {
            $lock->release();
        }
    }

    private function applyLocked(string $token, int $userId, string $language): array
    {
        $payload = Cache::get($this->cacheKey($token));
        if (! is_array($payload) || ($payload['user_id'] ?? null) !== $userId
            || ($payload['language'] ?? null) !== $language) {
            throw ValidationException::withMessages(['preview_token' => '预览已过期或不属于当前用户。']);
        }

        $restoreScheduling = (bool) ($payload['restore_scheduling'] ?? false);
        try {
            $classified = $this->importPlan->classify($payload['items'], $userId, $language, $restoreScheduling);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['preview_token' => $exception->getMessage()]);
        }
        if (! hash_equals(
            (string) $payload['database_fingerprint'],
            $this->importPlan->databaseFingerprint(
                $classified,
                $payload['articles'],
                $payload['settings'],
                $userId,
                $language,
            ),
        )) {
            throw ValidationException::withMessages(['preview_token' => '目标数据已变化，请重新预览。']);
        }
        if (collect($classified)->contains(fn (array $item) => $item['action'] === 'conflict')) {
            throw ValidationException::withMessages(['preview_token' => '预览包含冲突，不能自动应用。']);
        }
        $incomingAssets = $payload['media']['assets'] ?? [];
        if (is_array($incomingAssets) && $incomingAssets !== []) {
            $activeHashes = MediaAsset::query()
                ->where('user_id', $userId)
                ->where('language_id', $language)
                ->whereIn('sha256', array_column($incomingAssets, 'sha256'))
                ->pluck('sha256')->all();
            $incomingBytes = array_sum(array_map(
                fn (array $asset) => in_array($asset['sha256'], $activeHashes, true)
                    ? 0
                    : (int) $asset['size_bytes'],
                $incomingAssets,
            ));
            $usedBytes = (int) MediaAsset::query()->where('user_id', $userId)->sum('size_bytes');
            if ($usedBytes + $incomingBytes > (int) config('media.user_quota_bytes')) {
                throw ValidationException::withMessages(['preview_token' => '目标用户媒体配额不足，未创建恢复点。']);
            }
        }

        $backup = $this->backups->createBackup();
        $result = DB::transaction(function () use ($payload, $userId, $language, $restoreScheduling) {
            $classified = $this->importPlan->classify(
                $payload['items'],
                $userId,
                $language,
                $restoreScheduling,
                true,
            );
            if (collect($classified)->contains(fn (array $item) => $item['action'] === 'conflict')
                || ! hash_equals(
                    (string) $payload['database_fingerprint'],
                    $this->importPlan->databaseFingerprint(
                        $classified,
                        $payload['articles'],
                        $payload['settings'],
                        $userId,
                        $language,
                        true,
                    ),
                )) {
                throw ValidationException::withMessages([
                    'preview_token' => '目标数据在恢复点创建期间发生变化，请重新预览。',
                ]);
            }
            $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'articles' => 0, 'settings' => 0, 'history' => 0, 'history_skipped' => 0, 'media_references' => 0];
            $senseMap = [];
            foreach ($classified as $entry) {
                if ($entry['action'] === 'skip') {
                    $senseMap[$entry['item']['external_id']] = $entry['target_id'];
                    $counts['skipped']++;
                    continue;
                }
                $senseMap[$entry['item']['external_id']] = $this->writeContentItem(
                    $entry,
                    $userId,
                    $language,
                    $restoreScheduling,
                );
                $counts[$entry['action'] === 'create' ? 'created' : 'updated']++;
            }
            foreach ($payload['articles'] as $bookPayload) {
                $counts['articles'] += $this->writeArticle($bookPayload, $userId, $language);
            }
            foreach ($payload['settings'] as $settingPayload) {
                $this->writeSetting($settingPayload, $userId);
                $counts['settings']++;
            }
            foreach ($payload['history'] as $historyPayload) {
                $this->writeHistory($historyPayload, $senseMap, $userId, $language)
                    ? $counts['history']++
                    : $counts['history_skipped']++;
            }
            $counts['media_references'] = $this->restoreMedia(
                $payload['media'] ?? ['assets' => [], 'references' => []],
                $senseMap,
                $userId,
                $language,
            );
            return $counts;
        });

        Cache::forget($this->cacheKey($token));
        return $result + ['backup_id' => $backup['backup_id'], 'source_kind' => $payload['kind']];
    }

    public function cleanupPackage(string $packagePath): void
    {
        $directory = dirname($packagePath);
        $normalized = str_replace('\\', '/', $directory);
        if (! str_starts_with($normalized, str_replace('\\', '/', storage_path('app/temp/portable-')))) {
            return;
        }
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            @unlink($directory . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($directory);
    }

    /** @return array<int,array<string,mixed>> */
    private function parseJson(string $bytes): array
    {
        try {
            $data = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new InvalidArgumentException('JSON 无法解析。');
        }
        if (! is_array($data) || ($data['format'] ?? null) !== self::CONTENT_FORMAT
            || ($data['format_version'] ?? null) !== self::FORMAT_VERSION
            || ! is_array($data['items'] ?? null)) {
            throw new InvalidArgumentException('JSON 不是 LinguaCafe WordSense Content V1。');
        }
        return array_map(fn ($item) => $this->validateImportItem($item), $data['items']);
    }

    /** @return array<int,array<string,mixed>> */
    private function parseCsv(string $path): array
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new InvalidArgumentException('CSV 无法读取。');
        }
        try {
            $header = fgetcsv($stream);
            if (! is_array($header)) {
                throw new InvalidArgumentException('CSV 没有表头。');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            if ($header !== self::CONTENT_FIELDS) {
                throw new InvalidArgumentException('CSV 表头必须完全匹配 LinguaCafe Content V1。');
            }
            $items = [];
            while (($row = fgetcsv($stream)) !== false) {
                if (count($row) !== count($header)) {
                    throw new InvalidArgumentException('CSV 存在列数不一致的记录。');
                }
                $item = array_combine($header, $row);
                $item['tags'] = preg_split('/\s*,\s*/u', (string) $item['tags'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $items[] = $this->validateImportItem($item);
                if (count($items) > ReviewCardExportService::EXPORT_LIMIT) {
                    throw new InvalidArgumentException('CSV 条目超过 5,000 条。');
                }
            }
            return $items;
        } finally {
            fclose($stream);
        }
    }

    /** @return array{0:array,1:array<string,array{path:string,sha256:string,size_bytes:int}>} */
    private function mediaEnvelope(int $userId, string $language): array
    {
        $origin = $this->importPlan->portableOrigin($userId);
        $references = MediaReference::query()
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->with('asset')
            ->orderBy('id')
            ->get();
        $assets = [];
        $portableReferences = [];
        $files = [];
        $totalBytes = 0;

        foreach ($references as $reference) {
            $asset = $reference->asset;
            if ($asset === null || $asset->trashed()) {
                throw new RuntimeException('Portable export found an unavailable media asset.');
            }
            $assetKey = 'sha256:' . $asset->sha256;
            $fileName = 'media/' . $asset->sha256 . '.' . $asset->extension;
            if (! isset($assets[$assetKey])) {
                $download = $this->media->download($asset->public_id, $userId, $language);
                if (! hash_equals($asset->sha256, (string) hash_file('sha256', $download['path']))) {
                    throw new RuntimeException('Portable export media checksum mismatch.');
                }
                $totalBytes += (int) $asset->size_bytes;
                if ($totalBytes > self::MAX_MEDIA_PACKAGE_BYTES) {
                    throw new RuntimeException('Portable export media exceeds the 40 MiB V1 package limit.');
                }
                $assets[$assetKey] = [
                    'asset_key' => $assetKey,
                    'file' => $fileName,
                    'sha256' => $asset->sha256,
                    'mime_type' => $asset->mime_type,
                    'extension' => $asset->extension,
                    'size_bytes' => (int) $asset->size_bytes,
                    'original_name' => $asset->original_name,
                    'source_kind' => $asset->source_kind,
                    'copyright_status' => $asset->copyright_status,
                    'copyright_source' => $asset->copyright_source,
                ];
                $files[$fileName] = [
                    'path' => $download['path'],
                    'sha256' => $asset->sha256,
                    'size_bytes' => (int) $asset->size_bytes,
                ];
            }
            $portableReferences[] = [
                'sense_external_id' => 'lc-sense:' . $origin . ':' . $reference->word_sense_id,
                'asset_key' => $assetKey,
                'role' => $reference->role,
                'slot_key' => $reference->slot_key,
                'sentence' => $reference->role === MediaReference::ROLE_EXAMPLE_AUDIO
                    ? $reference->source_text
                    : null,
            ];
        }

        return [[
            'format' => 'linguacafe-media',
            'format_version' => self::FORMAT_VERSION,
            'assets' => array_values($assets),
            'references' => $portableReferences,
        ], $files];
    }

    private function parseFullPackage(string $path, string $language): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('LinguaCafe 数据包无法读取。');
        }
        $coreNames = ['manifest.json', 'content.json', 'articles.json', 'settings.json', 'history.json'];
        $files = [];
        try {
            if ($zip->numFiles < count($coreNames) || $zip->numFiles > 5006) {
                throw new InvalidArgumentException('LinguaCafe 数据包条目数量不正确。');
            }
            $total = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = (string) ($stat['name'] ?? '');
                $validName = in_array($name, [...$coreNames, 'media.json'], true)
                    || preg_match('/^media\/[a-f0-9]{64}\.(mp3|m4a)$/', $name) === 1;
                if (! $validName || isset($files[$name])) {
                    throw new InvalidArgumentException('LinguaCafe 数据包包含未知或重复条目。');
                }
                $size = (int) ($stat['size'] ?? 0);
                if ($size > AnkiWordSensePackageService::MAX_ENTRY_BYTES
                    || ($total += $size) > 52428800) {
                    throw new InvalidArgumentException('LinguaCafe 数据包解压内容超限。');
                }
                $files[$name] = $zip->getFromIndex($index);
                if (! is_string($files[$name])) {
                    throw new InvalidArgumentException('LinguaCafe 数据包条目损坏。');
                }
            }
        } finally {
            $zip->close();
        }

        try {
            $manifest = json_decode($files['manifest.json'], true, 512, JSON_THROW_ON_ERROR);
            $schema = $manifest['schema'] ?? null;
            if (($manifest['format'] ?? null) !== self::PACKAGE_FORMAT
                || ($manifest['format_version'] ?? null) !== self::FORMAT_VERSION
                || ! in_array($schema, ['m16-portable-v1', 'm18-portable-media-v1'], true)
                || ($manifest['scope']['language'] ?? null) !== $language) {
                throw new InvalidArgumentException('LinguaCafe 数据包版本或语言范围不兼容。');
            }
            $actualNames = array_keys($files);
            sort($actualNames);
            $expectedCore = $coreNames;
            sort($expectedCore);
            if ($schema === 'm16-portable-v1' && $actualNames !== $expectedCore) {
                throw new InvalidArgumentException('旧版 LinguaCafe 数据包不得隐式包含媒体。');
            }
            if ($schema === 'm18-portable-media-v1' && ! isset($files['media.json'])) {
                throw new InvalidArgumentException('媒体数据包缺少 media.json。');
            }
            $payloadNames = array_values(array_diff($actualNames, ['manifest.json']));
            $manifestNames = array_keys(is_array($manifest['files'] ?? null) ? $manifest['files'] : []);
            sort($payloadNames);
            sort($manifestNames);
            if ($manifestNames !== $payloadNames) {
                throw new InvalidArgumentException('LinguaCafe 数据包 manifest 文件清单不正确。');
            }
            foreach ($payloadNames as $name) {
                $expected = $manifest['files'][$name] ?? null;
                if (! is_array($expected)
                    || ($expected['size_bytes'] ?? null) !== strlen($files[$name])
                    || ! hash_equals((string) ($expected['sha256'] ?? ''), hash('sha256', $files[$name]))) {
                    throw new InvalidArgumentException('LinguaCafe 数据包 checksum 校验失败。');
                }
            }
            $content = json_decode($files['content.json'], true, 512, JSON_THROW_ON_ERROR);
            $articles = json_decode($files['articles.json'], true, 512, JSON_THROW_ON_ERROR);
            $settings = json_decode($files['settings.json'], true, 512, JSON_THROW_ON_ERROR);
            $history = json_decode($files['history.json'], true, 512, JSON_THROW_ON_ERROR);
            $media = $schema === 'm18-portable-media-v1'
                ? json_decode($files['media.json'], true, 512, JSON_THROW_ON_ERROR)
                : ['format' => 'linguacafe-media', 'format_version' => self::FORMAT_VERSION, 'assets' => [], 'references' => []];
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new InvalidArgumentException('LinguaCafe 数据包 JSON 损坏。');
        }

        $items = $this->parseJson($files['content.json']);
        if (($articles['format'] ?? null) !== 'linguacafe-articles'
            || ($articles['format_version'] ?? null) !== self::FORMAT_VERSION
            || ($articles['language'] ?? null) !== $language
            || ! is_array($articles['books'] ?? null)
            || count($articles['books']) > 2000
            || ($settings['format'] ?? null) !== 'linguacafe-settings'
            || ($settings['format_version'] ?? null) !== self::FORMAT_VERSION
            || ! is_array($settings['items'] ?? null)
            || ($history['format'] ?? null) !== 'linguacafe-review-history'
            || ($history['format_version'] ?? null) !== self::FORMAT_VERSION
            || ! is_array($history['items'] ?? null)
            || count($history['items']) > 100000) {
            throw new InvalidArgumentException('LinguaCafe 数据包逻辑数据结构不兼容。');
        }
        $chapterCount = 0;
        foreach ($articles['books'] as $book) {
            if (! is_array($book) || trim((string) ($book['name'] ?? '')) === ''
                || mb_strlen((string) $book['name']) > 255
                || ! is_array($book['chapters'] ?? null)) {
                throw new InvalidArgumentException('LinguaCafe 数据包文章结构无效。');
            }
            foreach ($book['chapters'] as $chapter) {
                $chapterCount++;
                if ($chapterCount > 2000 || ! is_array($chapter)
                    || trim((string) ($chapter['name'] ?? '')) === ''
                    || mb_strlen((string) $chapter['name']) > 255
                    || ! $this->validArticleCounter($chapter['word_count'] ?? 0)
                    || ! $this->validArticleCounter($chapter['read_count'] ?? 0)
                    || mb_strlen((string) ($chapter['raw_text'] ?? '')) > 5000000) {
                    throw new InvalidArgumentException('LinguaCafe 数据包章节结构或数量无效。');
                }
            }
        }
        foreach ($settings['items'] as $setting) {
            if (! is_array($setting)
                || ! $this->portableSettingName((string) ($setting['name'] ?? ''))
                || strlen((string) ($setting['value'] ?? '')) > 1000000) {
                throw new InvalidArgumentException('LinguaCafe 数据包包含不可移植设置。');
            }
        }
        foreach ($history['items'] as $historyItem) {
            $this->validateHistoryItem($historyItem);
        }
        $contentIds = array_fill_keys(array_column($items, 'external_id'), true);
        foreach ($history['items'] as $historyItem) {
            if (! isset($contentIds[$historyItem['sense_external_id']])) {
                throw new InvalidArgumentException('复习历史引用了数据包中不存在的 Sense Card。');
            }
        }
        if (($media['format'] ?? null) !== 'linguacafe-media'
            || ($media['format_version'] ?? null) !== self::FORMAT_VERSION
            || ! is_array($media['assets'] ?? null)
            || ! is_array($media['references'] ?? null)
            || count($media['assets']) > 5000
            || count($media['references']) > 10000) {
            throw new InvalidArgumentException('LinguaCafe 媒体清单结构不兼容。');
        }
        $mediaAssets = [];
        $mediaFileNames = [];
        foreach ($media['assets'] as $asset) {
            if (! is_array($asset)) {
                throw new InvalidArgumentException('LinguaCafe 媒体资产校验失败。');
            }
            $sha = (string) ($asset['sha256'] ?? '');
            $extension = (string) ($asset['extension'] ?? '');
            $mime = (string) ($asset['mime_type'] ?? '');
            $fileName = (string) ($asset['file'] ?? '');
            $assetKey = (string) ($asset['asset_key'] ?? '');
            $expectedMime = $extension === 'mp3' ? 'audio/mpeg' : ($extension === 'm4a' ? 'audio/mp4' : '');
            if (! preg_match('/^[a-f0-9]{64}$/', $sha)
                || ! in_array($extension, ['mp3', 'm4a'], true)
                || $mime !== $expectedMime
                || $assetKey !== 'sha256:' . $sha
                || $fileName !== 'media/' . $sha . '.' . $extension
                || isset($mediaAssets[$assetKey])
                || isset($mediaFileNames[$fileName])
                || ! isset($files[$fileName])
                || ($asset['size_bytes'] ?? null) !== strlen($files[$fileName])
                || ! hash_equals($sha, hash('sha256', $files[$fileName]))
                || strlen((string) ($asset['original_name'] ?? '')) > 255
                || ! in_array((string) ($asset['copyright_status'] ?? ''), ['owned', 'licensed', 'public_domain', 'unknown'], true)
                || strlen((string) ($asset['copyright_source'] ?? '')) > 512) {
                throw new InvalidArgumentException('LinguaCafe 媒体资产校验失败。');
            }
            $asset['bytes'] = $files[$fileName];
            $mediaAssets[$assetKey] = $asset;
            $mediaFileNames[$fileName] = true;
        }
        $actualMediaFiles = array_values(array_filter(
            array_keys($files),
            fn (string $name) => str_starts_with($name, 'media/'),
        ));
        sort($actualMediaFiles);
        $declaredMediaFiles = array_keys($mediaFileNames);
        sort($declaredMediaFiles);
        if ($actualMediaFiles !== $declaredMediaFiles) {
            throw new InvalidArgumentException('LinguaCafe 媒体文件与清单不一致。');
        }
        $portableMediaReferences = [];
        foreach ($media['references'] as $reference) {
            if (! is_array($reference)) {
                throw new InvalidArgumentException('LinguaCafe 媒体引用校验失败。');
            }
            $role = (string) ($reference['role'] ?? '');
            $sentence = $reference['sentence'] ?? null;
            if (! isset($contentIds[$reference['sense_external_id'] ?? ''])
                || ! isset($mediaAssets[$reference['asset_key'] ?? ''])
                || ! in_array($role, MediaReference::ROLES, true)
                || ! is_string($reference['slot_key'] ?? null)
                || ! hash_equals(
                    MediaManifestService::slotKey($role, is_string($sentence) ? $sentence : null),
                    $reference['slot_key'],
                )) {
                throw new InvalidArgumentException('LinguaCafe 媒体引用校验失败。');
            }
            $portableMediaReferences[] = $reference;
        }
        if (($manifest['counts']['content'] ?? null) !== count($items)
            || ($manifest['counts']['books'] ?? null) !== count($articles['books'])
            || ($manifest['counts']['chapters'] ?? null) !== $chapterCount
            || ($manifest['counts']['review_logs'] ?? null) !== count($history['items'])
            || (($manifest['schema'] ?? null) === 'm18-portable-media-v1'
                && (($manifest['counts']['media_assets'] ?? null) !== count($mediaAssets)
                    || ($manifest['counts']['media_references'] ?? null) !== count($portableMediaReferences)))) {
            throw new InvalidArgumentException('LinguaCafe 数据包 manifest 计数不正确。');
        }
        return [
            'kind' => 'lcpkg',
            'items' => $items,
            'articles' => $articles['books'],
            'settings' => $settings['items'],
            'history' => $history['items'],
            'media' => [
                'assets' => $mediaAssets,
                'references' => $portableMediaReferences,
            ],
        ];
    }

    private function restoreMedia(
        array $media,
        array $senseMap,
        int $userId,
        string $language,
    ): int {
        $assets = is_array($media['assets'] ?? null) ? $media['assets'] : [];
        $references = is_array($media['references'] ?? null) ? $media['references'] : [];
        $restored = 0;
        foreach ($references as $reference) {
            $senseId = $senseMap[$reference['sense_external_id']] ?? null;
            $asset = $assets[$reference['asset_key']] ?? null;
            if (! is_int($senseId) || ! is_array($asset) || ! is_string($asset['bytes'] ?? null)) {
                throw ValidationException::withMessages(['preview_token' => '媒体恢复映射已失效，请重新预览。']);
            }
            $sense = WordSense::query()
                ->where('user_id', $userId)
                ->where('language_id', $language)
                ->findOrFail($senseId);
            $temporaryBase = tempnam(sys_get_temp_dir(), 'linguacafe-m18-');
            $temporary = is_string($temporaryBase) ? $temporaryBase . '.' . $asset['extension'] : false;
            if ($temporary === false
                || ! rename($temporaryBase, $temporary)
                || file_put_contents($temporary, $asset['bytes']) !== strlen($asset['bytes'])) {
                if (is_string($temporaryBase)) @unlink($temporaryBase);
                if (is_string($temporary)) @unlink($temporary);
                throw ValidationException::withMessages(['preview_token' => '媒体恢复临时文件创建失败。']);
            }
            try {
                $upload = new UploadedFile(
                    $temporary,
                    (string) $asset['original_name'],
                    (string) $asset['mime_type'],
                    null,
                    true,
                );
                $this->media->attach(
                    $sense,
                    $upload,
                    (string) $reference['role'],
                    is_string($reference['sentence'] ?? null) ? $reference['sentence'] : null,
                    (string) $asset['copyright_status'],
                    is_string($asset['copyright_source'] ?? null) ? $asset['copyright_source'] : null,
                    (string) $asset['mime_type'],
                );
                $restored++;
            } finally {
                @unlink($temporary);
            }
        }
        return $restored;
    }

    private function writeContentItem(
        array $entry,
        int $userId,
        string $language,
        bool $restoreScheduling,
    ): int
    {
        $item = $entry['item'];
        $sense = $entry['target_id']
            ? WordSense::query()->where('user_id', $userId)->where('language_id', $language)->lockForUpdate()->findOrFail($entry['target_id'])
            : new WordSense(['user_id' => $userId, 'language' => $language, 'language_id' => $language]);
        $sense->fill([
            'surface_form' => $item['surface_form'], 'lemma' => $item['lemma'], 'pos' => $item['pos'],
            'sense_zh' => $item['sense_zh'], 'sense_en' => $item['sense_en'],
            'example_sentence_en' => $item['example_sentence_en'],
            'example_sentence_zh' => $item['example_sentence_zh'],
            'aliases_zh' => $sense->exists ? ($sense->aliases_zh ?: []) : [],
            'collocations' => $sense->exists ? ($sense->collocations ?: []) : [],
            'is_context_specific' => $sense->exists ? (bool) $sense->is_context_specific : false,
            'status' => WordSense::STATUS_CONFIRMED,
        ]);
        if (! $sense->exists) {
            $sense->sense_key = $this->importPlan->portableSenseKey($userId, $language, $item['external_id']);
        }
        $sense->save();

        $card = ReviewCard::query()->firstOrNew([
            'user_id' => $userId, 'language_id' => $language,
            'target_type' => ReviewCard::TARGET_SENSE, 'target_id' => $sense->id,
        ]);
        if (! $card->exists) {
            $card->fill([
                'language' => $language, 'fsrs_state' => 'new', 'fsrs_step_index' => 0,
                'fsrs_due_at' => now(), 'fsrs_stability' => 0, 'fsrs_difficulty' => 5,
                'fsrs_reps' => 0, 'fsrs_lapses' => 0, 'fsrs_enabled' => true,
                'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE, 'lifecycle_version' => 1,
            ]);
        }
        if ($restoreScheduling) {
            $schedule = $this->importPlan->validatedSchedule($item);
            $card->fill($schedule);
        }
        $card->save();

        $tagIds = [];
        foreach ($item['tags'] as $name) {
            $normalized = mb_strtolower(trim((string) $name), 'UTF-8');
            if ($normalized === '') {
                continue;
            }
            $tag = WordSenseTag::query()->firstOrCreate(
                ['user_id' => $userId, 'language_id' => $language, 'normalized_name' => $normalized],
                ['name' => trim((string) $name)],
            );
            $tagIds[] = $tag->id;
        }
        $sense->tags()->sync($tagIds);
        return (int) $sense->id;
    }

    private function writeArticle(array $payload, int $userId, string $language): int
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '' || ! is_array($payload['chapters'] ?? null)) {
            throw new InvalidArgumentException('文章数据结构无效。');
        }
        $book = Book::query()->firstOrCreate(
            ['user_id' => $userId, 'language' => $language, 'name' => mb_substr($name, 0, 255)],
            ['cover_image' => null],
        );
        $count = 0;
        foreach ($payload['chapters'] as $chapterPayload) {
            $chapterName = trim((string) ($chapterPayload['name'] ?? ''));
            if ($chapterName === '') {
                throw new InvalidArgumentException('章节名称不能为空。');
            }
            $chapter = Chapter::query()->firstOrNew([
                'user_id' => $userId,
                'book_id' => $book->id,
                'language' => $language,
                'name' => mb_substr($chapterName, 0, 255),
            ]);
            $chapter->raw_text = (string) ($chapterPayload['raw_text'] ?? '');
            $chapter->word_count = max(0, (int) ($chapterPayload['word_count'] ?? 0));
            $chapter->read_count = max(0, (int) ($chapterPayload['read_count'] ?? 0));
            if (! $chapter->exists) {
                $chapter->unique_words = '';
                $chapter->subtitle_timestamps = '';
                $chapter->type = 'text';
                $chapter->processing_status = 'unprocessed';
            }
            $chapter->save();
            $count++;
        }
        return $count;
    }

    private function writeSetting(array $payload, int $userId): void
    {
        $name = (string) ($payload['name'] ?? '');
        if (! $this->portableSettingName($name)) {
            throw new InvalidArgumentException('数据包包含不可移植设置。');
        }
        $setting = Setting::query()->where('user_id', $userId)->where('name', $name)->first();
        if (! $setting) {
            $setting = new Setting();
            $setting->user_id = $userId;
            $setting->name = $name;
        }
        $setting->value = (string) ($payload['value'] ?? 'null');
        $setting->save();
    }

    private function writeHistory(
        array $payload,
        array $senseMap,
        int $userId,
        string $language,
    ): bool
    {
        $payload = $this->validateHistoryItem($payload);
        $senseId = $senseMap[$payload['sense_external_id']] ?? null;
        $sense = WordSense::query()
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->find($senseId);
        $card = $sense?->reviewCard;
        if (! $card || (int) $card->user_id !== $userId || (string) $card->language_id !== $language) {
            throw new InvalidArgumentException('复习历史无法映射到当前用户的 Sense Card。');
        }

        $duplicate = ReviewLog::query()
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('review_card_id', $card->id)
            ->where('rating', $payload['rating'])
            ->where('reviewed_at', Carbon::parse($payload['reviewed_at']))
            ->where('source', 'restore:' . $payload['source'])
            ->exists();
        if ($duplicate) {
            return false;
        }

        ReviewLog::forceCreate([
            'user_id' => $userId,
            'language' => $language,
            'language_id' => $language,
            'review_card_id' => $card->id,
            'rating' => $payload['rating'],
            'reviewed_at' => Carbon::parse($payload['reviewed_at']),
            'review_duration_ms' => $payload['review_duration_ms'],
            'previous_state' => $payload['previous_state'],
            'new_state' => $payload['new_state'],
            'previous_due_at' => $payload['previous_due_at'] ? Carbon::parse($payload['previous_due_at']) : null,
            'new_due_at' => $payload['new_due_at'] ? Carbon::parse($payload['new_due_at']) : null,
            'previous_stability' => $payload['previous_stability'],
            'new_stability' => $payload['new_stability'],
            'previous_difficulty' => $payload['previous_difficulty'],
            'new_difficulty' => $payload['new_difficulty'],
            'source' => 'restore:' . $payload['source'],
            'undone_at' => $payload['undone_at'] ? Carbon::parse($payload['undone_at']) : null,
        ]);
        return true;
    }

    private function articleEnvelope(int $userId, string $language): array
    {
        $books = Book::query()->where('user_id', $userId)->where('language', $language)
            ->orderBy('id')->get()->map(function (Book $book) use ($userId, $language) {
                return [
                    'external_id' => 'lc-book:' . $book->id,
                    'name' => $book->name,
                    'chapters' => Chapter::query()->where('user_id', $userId)
                        ->where('language', $language)->where('book_id', $book->id)
                        ->orderBy('id')->get()->map(fn (Chapter $chapter) => [
                            'external_id' => 'lc-chapter:' . $chapter->id,
                            'name' => $chapter->name,
                            'raw_text' => $chapter->raw_text,
                            'word_count' => (int) $chapter->word_count,
                            'read_count' => (int) $chapter->read_count,
                        ])->all(),
                ];
            })->all();
        return ['format' => 'linguacafe-articles', 'format_version' => self::FORMAT_VERSION, 'language' => $language, 'books' => $books];
    }

    private function settingsEnvelope(int $userId): array
    {
        $items = Setting::query()->where('user_id', $userId)->orderBy('name')->get()
            ->filter(fn (Setting $setting) => $this->portableSettingName($setting->name))
            ->map(fn (Setting $setting) => ['name' => $setting->name, 'value' => $setting->value])->values()->all();
        return ['format' => 'linguacafe-settings', 'format_version' => self::FORMAT_VERSION, 'items' => $items];
    }

    private function historyEnvelope(int $userId, string $language): array
    {
        $origin = $this->importPlan->portableOrigin($userId);
        $items = ReviewLog::query()
            ->where('review_logs.user_id', $userId)
            ->where('review_logs.language_id', $language)
            ->join('review_cards', function ($join) use ($userId, $language) {
                $join->on('review_cards.id', '=', 'review_logs.review_card_id')
                    ->where('review_cards.user_id', $userId)
                    ->where('review_cards.language_id', $language)
                    ->where('review_cards.target_type', ReviewCard::TARGET_SENSE);
            })
            ->orderBy('review_logs.id')
            ->limit(100001)
            ->get([
                'review_logs.*',
                'review_cards.target_id as portable_sense_id',
            ]);
        if ($items->count() > 100000) {
            throw new InvalidArgumentException('复习历史超过全量数据包的 100,000 条上限。');
        }
        return [
            'format' => 'linguacafe-review-history',
            'format_version' => self::FORMAT_VERSION,
            'items' => $items->map(fn ($log) => [
                'external_id' => 'lc-review-log:' . $origin . ':' . $log->id,
                'sense_external_id' => 'lc-sense:' . $origin . ':' . $log->portable_sense_id,
                'rating' => $log->rating,
                'reviewed_at' => optional($log->reviewed_at)->toISOString(),
                'review_duration_ms' => $log->review_duration_ms,
                'previous_state' => $log->previous_state,
                'new_state' => $log->new_state,
                'previous_due_at' => optional($log->previous_due_at)->toISOString(),
                'new_due_at' => optional($log->new_due_at)->toISOString(),
                'previous_stability' => $log->previous_stability,
                'new_stability' => $log->new_stability,
                'previous_difficulty' => $log->previous_difficulty,
                'new_difficulty' => $log->new_difficulty,
                'source' => preg_replace(
                    '/^(?:restore:)+/',
                    '',
                    (string) ($log->source ?: 'legacy_unknown'),
                ),
                'undone_at' => optional($log->undone_at)->toISOString(),
            ])->all(),
        ];
    }

    private function validateHistoryItem(mixed $item): array
    {
        if (! is_array($item)
            || ! preg_match('/^lc-review-log:[a-f0-9]{16}:\d{1,20}$/', (string) ($item['external_id'] ?? ''))
            || ! preg_match('/^lc-sense:[a-f0-9]{16}:\d{1,20}$/', (string) ($item['sense_external_id'] ?? ''))
            || ! in_array($item['rating'] ?? null, ['again', 'hard', 'good', 'easy', 'reset'], true)
            || ! preg_match('/^[a-z0-9_:-]{1,24}$/', (string) ($item['source'] ?? ''))) {
            throw new InvalidArgumentException('复习历史记录标识、评分或来源无效。');
        }
        foreach (['reviewed_at', 'previous_due_at', 'new_due_at', 'undone_at'] as $field) {
            if (($item[$field] ?? null) !== null && ($item[$field] ?? '') !== '') {
                try {
                    Carbon::parse((string) $item[$field]);
                } catch (Throwable) {
                    throw new InvalidArgumentException('复习历史包含无效日期。');
                }
            }
        }
        if (empty($item['reviewed_at'])) {
            throw new InvalidArgumentException('复习历史缺少 reviewed_at。');
        }
        if (($item['previous_state'] ?? null) !== null
            && ! in_array($item['previous_state'], ['new', 'learning', 'review', 'relearning'], true)) {
            throw new InvalidArgumentException('复习历史包含无效 previous FSRS state。');
        }
        if (! in_array($item['new_state'] ?? null, ['new', 'learning', 'review', 'relearning'], true)) {
            throw new InvalidArgumentException('复习历史包含无效 new FSRS state。');
        }
        $duration = $item['review_duration_ms'] ?? null;
        if ($duration !== null && (! is_numeric($duration) || $duration < 0 || $duration > 3600000)) {
            throw new InvalidArgumentException('复习历史答题时长无效。');
        }
        foreach (['previous_stability', 'new_stability'] as $field) {
            if (($item[$field] ?? null) !== null
                && (! is_numeric($item[$field]) || $item[$field] < 0 || $item[$field] > 36500)) {
                throw new InvalidArgumentException('复习历史 stability 无效。');
            }
        }
        foreach (['previous_difficulty', 'new_difficulty'] as $field) {
            if (($item[$field] ?? null) !== null
                && (! is_numeric($item[$field]) || $item[$field] < 1 || $item[$field] > 10)) {
                throw new InvalidArgumentException('复习历史 difficulty 无效。');
            }
        }
        return $item;
    }

    private function portableSettingName(string $name): bool
    {
        return $name !== '' && strlen($name) <= 128
            && ! preg_match('/(?:key|token|secret|password|credential|host|endpoint|url)/i', $name);
    }

    private function validArticleCounter(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false
            && (int) $value >= 0
            && (int) $value <= 2147483647;
    }

    private function articleChapterCount(array $books): int
    {
        return array_sum(array_map(
            fn (array $book) => count($book['chapters'] ?? []),
            $books,
        ));
    }

    private function normalizeExportItem(
        array $item,
        bool $includeScheduling,
        string $origin,
    ): array
    {
        $normalized = [
            'external_id' => 'lc-sense:' . $origin . ':' . (int) ($item['word_sense_id'] ?? 0),
            'surface_form' => $item['surface_form'] ?? '',
            'lemma' => $item['lemma'] ?? '',
            'pos' => $item['pos'] ?? '',
            'sense_zh' => $item['sense_zh'] ?? '',
            'sense_en' => $item['sense_en'] ?? '',
            'example_sentence_en' => $item['example_sentence_en'] ?? '',
            'example_sentence_zh' => $item['example_sentence_zh'] ?? '',
            'source' => $item['source_chapter_title'] ?? '',
            'tags' => array_values(array_filter(array_map(fn ($tag) => is_array($tag) ? ($tag['name'] ?? '') : (string) $tag, (array) ($item['tags'] ?? [])))),
            'fsrs_state' => $includeScheduling ? ($item['fsrs_state'] ?? '') : '',
            'fsrs_due_at' => $includeScheduling ? ($item['fsrs_due_at'] ?? '') : '',
            'fsrs_stability' => $includeScheduling ? ($item['fsrs_stability'] ?? '') : '',
            'fsrs_difficulty' => $includeScheduling ? ($item['fsrs_difficulty'] ?? '') : '',
            'fsrs_reps' => $includeScheduling ? ($item['fsrs_reps'] ?? 0) : '',
            'fsrs_lapses' => $includeScheduling ? ($item['fsrs_lapses'] ?? 0) : '',
            'fsrs_last_reviewed_at' => $includeScheduling ? ($item['fsrs_last_reviewed_at'] ?? '') : '',
        ];
        return $this->validateImportItem($normalized);
    }

    private function validateImportItem(mixed $item): array
    {
        if (! is_array($item) || array_diff(self::CONTENT_FIELDS, array_keys($item))) {
            throw new InvalidArgumentException('内容记录缺少冻结字段。');
        }
        if (! preg_match('/^lc-sense:[a-f0-9]{16}:\d{1,20}$/', (string) $item['external_id'])) {
            throw new InvalidArgumentException('内容记录包含无效 LinguaCafeId。');
        }
        $fieldLimits = [
            'surface_form' => 255,
            'lemma' => 255,
            'pos' => 255,
            'sense_zh' => 20000,
            'sense_en' => 20000,
            'example_sentence_en' => 20000,
            'example_sentence_zh' => 20000,
            'source' => 20000,
        ];
        foreach ($fieldLimits as $field => $limit) {
            if (! is_scalar($item[$field]) || mb_strlen((string) $item[$field]) > $limit) {
                throw new InvalidArgumentException("内容字段 {$field} 无效或过长。");
            }
            $item[$field] = trim((string) $item[$field]);
        }
        if ($item['lemma'] === '' || $item['sense_zh'] === '') {
            throw new InvalidArgumentException('lemma 和中文释义不能为空。');
        }
        if (is_string($item['tags'])) {
            $item['tags'] = preg_split('/\s*,\s*/u', $item['tags'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (! is_array($item['tags']) || count($item['tags']) > 100) {
            throw new InvalidArgumentException('标签字段无效或超限。');
        }
        $item['tags'] = array_values(array_unique(array_filter(array_map(fn ($tag) => mb_substr(trim((string) $tag), 0, 80), $item['tags']))));
        return array_intersect_key($item, array_flip(self::CONTENT_FIELDS));
    }

    private function cacheKey(string $token): string
    {
        return 'portable-data-preview:' . $token;
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
