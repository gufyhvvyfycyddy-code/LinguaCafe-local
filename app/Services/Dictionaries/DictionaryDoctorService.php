<?php

namespace App\Services\Dictionaries;

use App\Models\Dictionary;
use Illuminate\Support\Facades\Schema;

final class DictionaryDoctorService
{
    public function __construct(private DictionaryHealthService $healthService)
    {
    }

    /** @return array<string, mixed> */
    public function inspect(): array
    {
        $dictionaries = Dictionary::query()->orderBy('id')->get();
        $healthById = $this->healthService->classifyCollection($dictionaries);

        $metadata = [];
        foreach ($dictionaries as $dictionary) {
            $metadata[] = [
                'id' => (int) $dictionary->id,
                'name' => (string) $dictionary->name,
                'type' => (string) $dictionary->type,
                'target' => (string) $dictionary->database_table_name,
                'source_language' => (string) $dictionary->source_language,
                'target_language' => (string) $dictionary->target_language,
                'enabled' => (bool) $dictionary->enabled,
                'health' => $healthById[(int) $dictionary->id]->toArray(),
                'suggested_action' => $this->suggestedAction(
                    $healthById[(int) $dictionary->id]->status,
                ),
            ];
        }

        $ownedTargets = $dictionaries
            ->where('database_table_name', '<>', 'API')
            ->pluck('database_table_name')
            ->all();
        $groupTargets = $this->healthService->jmdictTargets();
        $orphans = [];
        foreach (Schema::getTableListing() as $tableName) {
            if (
                ! str_starts_with($tableName, 'dict_')
                || in_array($tableName, $ownedTargets, true)
                || in_array($tableName, $groupTargets, true)
            ) {
                continue;
            }

            $orphans[] = [
                'target' => $tableName,
                'status' => 'metadata_missing',
                'code' => 'DICTIONARY_METADATA_MISSING',
                'message' => '发现未被词典配置引用的数据表。',
                'suggested_action' => 'inspect_manually',
            ];
        }
        usort($orphans, static fn (array $left, array $right): int => $left['target'] <=> $right['target']);

        $jmdictMetadata = $dictionaries->where('database_table_name', DictionaryHealthService::JMDICT_TARGET);
        $jmdictGroup = [
            'targets' => $groupTargets,
            'metadata_present' => $jmdictMetadata->isNotEmpty(),
            'status' => $jmdictMetadata->isNotEmpty()
                ? $healthById[(int) $jmdictMetadata->first()->id]->status
                : (collect($groupTargets)->contains(fn (string $target): bool => Schema::hasTable($target))
                    ? 'metadata_missing'
                    : 'unknown'),
        ];

        $evidence = [
            'metadata' => $metadata,
            'orphans' => $orphans,
            'jmdict_group' => $jmdictGroup,
        ];

        return [
            ...$evidence,
            'read_only' => true,
            'repair_available' => false,
            'evidence_hash' => hash(
                'sha256',
                json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function suggestedAction(string $status): string
    {
        return match ($status) {
            'healthy', 'disabled' => 'none',
            'missing_table', 'invalid_schema', 'duplicate_target', 'incomplete_group',
            'metadata_missing', 'conflicting_generation', 'unknown' => 'inspect_manually',
            default => 'inspect_manually',
        };
    }
}
