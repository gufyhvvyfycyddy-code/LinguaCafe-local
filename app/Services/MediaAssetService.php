<?php

namespace App\Services;

use App\Models\MediaAsset;
use App\Models\MediaReference;
use App\Models\WordSense;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MediaAssetService
{
    private const MIME_EXTENSIONS = [
        'audio/mpeg' => 'mp3',
        'audio/mp3' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
    ];

    public function __construct(private SafeFilePathService $safePaths) {}

    public function attach(
        WordSense $sense,
        UploadedFile $file,
        string $role,
        ?string $sentence,
        string $copyrightStatus,
        ?string $copyrightSource,
        ?string $verifiedPortableMime = null,
    ): array {
        return Cache::lock('media-asset-user-' . $sense->user_id, 30)->block(10, fn () => $this->attachLocked(
            $sense,
            $file,
            $role,
            $sentence,
            $copyrightStatus,
            $copyrightSource,
            $verifiedPortableMime,
        ));
    }

    private function attachLocked(
        WordSense $sense,
        UploadedFile $file,
        string $role,
        ?string $sentence,
        string $copyrightStatus,
        ?string $copyrightSource,
        ?string $verifiedPortableMime,
    ): array {
        $this->assertRoleAndSentence($role, $sentence);
        $size = (int) $file->getSize();
        $max = (int) config('media.max_upload_bytes');
        if (! $file->isValid() || $size < 1 || $size > $max) {
            throw ValidationException::withMessages(['file' => '音频文件无效或超过 10 MiB。']);
        }
        $path = $file->getRealPath();
        $mime = strtolower((string) ($verifiedPortableMime ?? $file->getMimeType()));
        $extension = self::MIME_EXTENSIONS[$mime] ?? null;
        if ($extension === null) {
            throw ValidationException::withMessages(['file' => '首版只支持真实 MP3 或 M4A 音频。']);
        }
        if ($verifiedPortableMime !== null && (! is_string($path) || ! $this->matchesAudioSignature($path, $mime))) {
            throw ValidationException::withMessages(['file' => '便携包音频签名与声明格式不一致。']);
        }
        $sha = is_string($path) ? hash_file('sha256', $path) : false;
        if (! is_string($sha)) {
            throw ValidationException::withMessages(['file' => '无法计算音频完整性哈希。']);
        }

        $asset = MediaAsset::withTrashed()
            ->where('user_id', $sense->user_id)
            ->where('language_id', $sense->language_id)
            ->where('sha256', $sha)
            ->first();
        $addsBytes = $asset === null || $asset->trashed();
        if ($addsBytes) {
            $used = (int) MediaAsset::query()->where('user_id', $sense->user_id)->sum('size_bytes');
            if ($used + $size > (int) config('media.user_quota_bytes')) {
                throw ValidationException::withMessages(['file' => '媒体存储配额不足。']);
            }
        }

        $directory = 'user-' . $sense->user_id;
        $storageName = $sha . '.' . $extension;
        $originalName = preg_replace('/[\x00-\x1F\x7F]/', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))) ?: 'audio.' . $extension;
        $originalName = mb_substr($originalName, 0, 255);
        $storedNewFile = false;
        $disk = Storage::disk((string) config('media.disk'));
        if (! $disk->exists($directory . '/' . $storageName)) {
            $stream = fopen($path, 'rb');
            if ($stream === false || ! $disk->put($directory . '/' . $storageName, $stream)) {
                if (is_resource($stream)) fclose($stream);
                throw ValidationException::withMessages(['file' => '音频文件无法安全保存。']);
            }
            if (is_resource($stream)) fclose($stream);
            $storedNewFile = true;
        }

        try {
            return DB::transaction(function () use (
                $asset, $sense, $file, $role, $sentence, $copyrightStatus,
                $copyrightSource, $sha, $mime, $extension, $size, $storageName, $originalName,
            ) {
                if ($asset === null) {
                    $asset = MediaAsset::create([
                        'public_id' => (string) Str::uuid(),
                        'user_id' => $sense->user_id,
                        'language_id' => $sense->language_id,
                        'sha256' => $sha,
                        'storage_name' => $storageName,
                        'original_name' => $originalName,
                        'mime_type' => $mime,
                        'extension' => $extension,
                        'size_bytes' => $size,
                        'source_kind' => 'user_upload',
                        'copyright_status' => $copyrightStatus,
                        'copyright_source' => $copyrightSource,
                    ]);
                } else {
                    $asset->restore();
                    $asset->fill([
                        'retained_until' => null,
                        'original_name' => $originalName,
                        'copyright_status' => $copyrightStatus,
                        'copyright_source' => $copyrightSource,
                    ])->save();
                }

                $slotKey = MediaManifestService::slotKey($role, $sentence);
                $current = MediaReference::query()
                    ->where('user_id', $sense->user_id)
                    ->where('language_id', $sense->language_id)
                    ->where('word_sense_id', $sense->id)
                    ->where('role', $role)
                    ->where('slot_key', $slotKey)
                    ->first();
                if ($current !== null && $current->media_asset_id !== $asset->id) {
                    $oldAssetId = $current->media_asset_id;
                    $current->delete();
                    $this->retainIfOrphaned($oldAssetId);
                    $current = null;
                }
                if ($current === null) {
                    $current = MediaReference::create([
                        'public_id' => (string) Str::uuid(),
                        'user_id' => $sense->user_id,
                        'language_id' => $sense->language_id,
                        'media_asset_id' => $asset->id,
                        'word_sense_id' => $sense->id,
                        'role' => $role,
                        'slot_key' => $slotKey,
                        'source_text' => $role === MediaReference::ROLE_EXAMPLE_AUDIO ? trim((string) $sentence) : null,
                    ]);
                }

                return (new MediaManifestService())->forSense(
                    (int) $sense->user_id,
                    (string) $sense->language_id,
                    (int) $sense->id,
                );
            });
        } catch (\Throwable $exception) {
            if ($storedNewFile && ! MediaAsset::withTrashed()->where('user_id', $sense->user_id)->where('sha256', $sha)->exists()) {
                $disk->delete($directory . '/' . $storageName);
            }
            throw $exception;
        }
    }

    public function remove(string $referenceId, int $userId, string $language): void
    {
        DB::transaction(function () use ($referenceId, $userId, $language) {
            $reference = MediaReference::query()
                ->where('public_id', $referenceId)
                ->where('user_id', $userId)
                ->where('language_id', $language)
                ->firstOrFail();
            $assetId = $reference->media_asset_id;
            $reference->delete();
            $this->retainIfOrphaned($assetId);
        });
    }

    public function download(string $assetId, int $userId, string $language): array
    {
        $asset = MediaAsset::query()
            ->where('public_id', $assetId)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->whereHas('references', fn ($query) => $query
                ->where('user_id', $userId)->where('language_id', $language))
            ->firstOrFail();
        $root = Storage::disk((string) config('media.disk'))->path('user-' . $userId);
        $path = $this->safePaths->resolveExistingDirectChild($root, $asset->storage_name);
        $asset->forceFill(['last_accessed_at' => now()])->saveQuietly();
        return ['asset' => $asset, 'path' => $path];
    }

    public function check(int $userId, string $language): array
    {
        $disk = Storage::disk((string) config('media.disk'));
        $assets = MediaAsset::withTrashed()
            ->where('user_id', $userId)->where('language_id', $language)
            ->withCount('references')
            ->orderBy('id')->get();
        $missing = [];
        $orphaned = [];
        $incompatible = [];
        foreach ($assets as $asset) {
            if (! $disk->exists('user-' . $userId . '/' . $asset->storage_name)) {
                $missing[] = $asset->public_id;
            }
            if ($asset->references_count === 0) {
                $orphaned[] = $asset->public_id;
            }
            if (! isset(self::MIME_EXTENSIONS[$asset->mime_type])
                || self::MIME_EXTENSIONS[$asset->mime_type] !== $asset->extension) {
                $incompatible[] = $asset->public_id;
            }
        }
        $duplicates = $assets->groupBy('sha256')->filter(fn ($group) => $group->count() > 1)
            ->map(fn ($group) => $group->pluck('public_id')->values()->all())->values()->all();
        $trackedNames = array_fill_keys($assets->pluck('storage_name')->all(), true);
        $untracked = [];
        foreach ($disk->files('user-' . $userId) as $storedPath) {
            $name = basename(str_replace('\\', '/', $storedPath));
            if (! isset($trackedNames[$name])) {
                $untracked[] = $name;
            }
        }
        sort($untracked);

        return [
            'generated_at' => now()->toISOString(),
            'missing' => $missing,
            'orphaned' => $orphaned,
            'duplicates' => $duplicates,
            'incompatible' => $incompatible,
            'untracked_files' => $untracked,
            'counts' => [
                'assets' => $assets->count(),
                'missing' => count($missing),
                'orphaned' => count($orphaned),
                'duplicate_groups' => count($duplicates),
                'incompatible' => count($incompatible),
                'untracked_files' => count($untracked),
            ],
        ];
    }

    private function retainIfOrphaned(int $assetId): void
    {
        if (MediaReference::query()->where('media_asset_id', $assetId)->exists()) {
            return;
        }
        $asset = MediaAsset::query()->find($assetId);
        if ($asset !== null) {
            $asset->retained_until = now()->addDays((int) config('media.retention_days'));
            $asset->save();
            $asset->delete();
        }
    }

    private function assertRoleAndSentence(string $role, ?string $sentence): void
    {
        if (! in_array($role, MediaReference::ROLES, true)) {
            throw ValidationException::withMessages(['role' => '不支持的媒体角色。']);
        }
        if ($role === MediaReference::ROLE_EXAMPLE_AUDIO && trim((string) $sentence) === '') {
            throw ValidationException::withMessages(['sentence' => '例句音频必须绑定非空英文例句。']);
        }
    }

    private function matchesAudioSignature(string $path, string $mime): bool
    {
        $header = file_get_contents($path, false, null, 0, 16);
        if (! is_string($header)) {
            return false;
        }
        if (in_array($mime, ['audio/mpeg', 'audio/mp3'], true)) {
            return str_starts_with($header, 'ID3')
                || (strlen($header) >= 2 && ord($header[0]) === 0xFF && (ord($header[1]) & 0xE0) === 0xE0);
        }
        return in_array($mime, ['audio/mp4', 'audio/x-m4a'], true)
            && strlen($header) >= 12
            && substr($header, 4, 4) === 'ftyp';
    }
}
