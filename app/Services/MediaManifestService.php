<?php

namespace App\Services;

use App\Models\MediaReference;

class MediaManifestService
{
    public function forSense(int $userId, string $language, int $senseId): array
    {
        return $this->forSenseIds($userId, $language, [$senseId])[$senseId] ?? [];
    }

    public function forSenseIds(int $userId, string $language, array $senseIds): array
    {
        $senseIds = array_values(array_unique(array_map('intval', $senseIds)));
        if ($senseIds === []) {
            return [];
        }

        $map = array_fill_keys($senseIds, []);
        $references = MediaReference::query()
            ->join('media_assets', 'media_assets.id', '=', 'media_references.media_asset_id')
            ->where('media_references.user_id', $userId)
            ->where('media_references.language_id', $language)
            ->whereIn('media_references.word_sense_id', $senseIds)
            ->whereNull('media_assets.deleted_at')
            ->orderBy('media_references.id')
            ->select([
                'media_references.word_sense_id',
                'media_references.public_id as reference_public_id',
                'media_references.role',
                'media_references.slot_key',
                'media_references.source_text',
                'media_assets.public_id as asset_public_id',
                'media_assets.sha256',
                'media_assets.mime_type',
                'media_assets.extension',
                'media_assets.size_bytes',
                'media_assets.original_name',
                'media_assets.source_kind',
                'media_assets.copyright_status',
                'media_assets.copyright_source',
            ])
            ->get();

        foreach ($references as $reference) {
            $map[$reference->word_sense_id][] = [
                'reference_id' => $reference->reference_public_id,
                'asset_id' => $reference->asset_public_id,
                'role' => $reference->role,
                'slot_key' => $reference->slot_key,
                'source_text' => $reference->source_text,
                'sha256' => $reference->sha256,
                'mime_type' => $reference->mime_type,
                'extension' => $reference->extension,
                'size_bytes' => (int) $reference->size_bytes,
                'original_name' => $reference->original_name,
                'source_kind' => $reference->source_kind,
                'copyright_status' => $reference->copyright_status,
                'copyright_source' => $reference->copyright_source,
                'download_path' => '/media/assets/' . $reference->asset_public_id,
            ];
        }

        return $map;
    }

    public static function slotKey(string $role, ?string $sentence): string
    {
        return $role === MediaReference::ROLE_WORD_PRONUNCIATION
            ? hash('sha256', 'word')
            : hash('sha256', trim((string) $sentence));
    }
}
