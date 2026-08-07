<?php

namespace App\Services\Dictionaries;

use App\Data\Dictionaries\DictionaryHealthData;
use App\Models\Dictionary;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class DictionaryHealthService
{
    public const JMDICT_TARGET = 'dict_jp_jmdict';

    /** @var array<string, string[]> */
    private const JMDICT_SCHEMA = [
        'dict_jp_jmdict' => ['id', 'translations', 'conjugations'],
        'dict_jp_jmdict_words' => ['id', 'dict_jp_jmdict_id', 'word'],
        'dict_jp_jmdict_readings' => ['id', 'dict_jp_jmdict_id', 'reading', 'word_restrictions'],
        'dict_jp_kanji' => ['id', 'kanji', 'meanings', 'readings_on', 'readings_kun', 'grade', 'strokes', 'frequency', 'jlpt'],
        'dict_jp_kanji_radicals' => ['id', 'kanji', 'radicals'],
    ];

    /** @return array<int, DictionaryHealthData> keyed by dictionary id */
    public function classifyCollection(Collection $dictionaries): array
    {
        $duplicates = $dictionaries
            ->filter(fn (Dictionary $dictionary): bool => $dictionary->database_table_name !== 'API')
            ->groupBy('database_table_name')
            ->filter(fn (Collection $rows): bool => $rows->count() > 1)
            ->keys()
            ->all();

        $health = [];
        foreach ($dictionaries as $dictionary) {
            $health[(int) $dictionary->id] = $this->classify(
                $dictionary,
                in_array($dictionary->database_table_name, $duplicates, true),
            );
        }

        return $health;
    }

    public function classify(Dictionary $dictionary, bool $duplicateTarget = false): DictionaryHealthData
    {
        try {
            if ($dictionary->database_table_name === 'API') {
                return $this->healthyOrDisabled($dictionary);
            }

            if ($duplicateTarget) {
                return new DictionaryHealthData(
                    'duplicate_target',
                    'DICTIONARY_DUPLICATE_TARGET',
                    '多个词典配置指向同一份数据，需要修复。',
                    false,
                    true,
                );
            }

            if (! $this->isSafeTargetName((string) $dictionary->database_table_name)) {
                return new DictionaryHealthData(
                    'invalid_schema',
                    'DICTIONARY_SCHEMA_INVALID',
                    '词典数据结构异常，需要修复。',
                    false,
                    true,
                );
            }

            if ($dictionary->database_table_name === self::JMDICT_TARGET) {
                return $this->classifyJmdict($dictionary);
            }

            if (! Schema::hasTable($dictionary->database_table_name)) {
                return new DictionaryHealthData(
                    'missing_table',
                    'DICTIONARY_TABLE_MISSING',
                    '词典数据缺失，需要修复。',
                    false,
                    true,
                );
            }

            if (! Schema::hasColumns($dictionary->database_table_name, ['word', 'definitions'])) {
                return new DictionaryHealthData(
                    'invalid_schema',
                    'DICTIONARY_SCHEMA_INVALID',
                    '词典数据结构异常，需要修复。',
                    false,
                    true,
                );
            }

            return $this->healthyOrDisabled($dictionary);
        } catch (Throwable $exception) {
            report($exception);

            return new DictionaryHealthData(
                'unknown',
                'DICTIONARY_HEALTH_UNKNOWN',
                '词典状态暂时无法确认。',
                false,
                true,
            );
        }
    }

    /** @return string[] */
    public function jmdictTargets(): array
    {
        return array_keys(self::JMDICT_SCHEMA);
    }

    /** @return array<string, string[]> */
    public function jmdictSchema(): array
    {
        return self::JMDICT_SCHEMA;
    }

    private function classifyJmdict(Dictionary $dictionary): DictionaryHealthData
    {
        foreach (self::JMDICT_SCHEMA as $tableName => $columns) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumns($tableName, $columns)) {
                return new DictionaryHealthData(
                    'incomplete_group',
                    'DICTIONARY_GROUP_INCOMPLETE',
                    '日语词典数据组不完整，需要修复。',
                    false,
                    true,
                );
            }
        }

        return $this->healthyOrDisabled($dictionary);
    }

    private function healthyOrDisabled(Dictionary $dictionary): DictionaryHealthData
    {
        if (! (bool) $dictionary->enabled) {
            return new DictionaryHealthData(
                'disabled',
                'DICTIONARY_DISABLED',
                '词典已停用。',
                false,
                false,
            );
        }

        return new DictionaryHealthData(
            'healthy',
            'DICTIONARY_HEALTHY',
            '词典可用。',
            true,
            false,
        );
    }

    private function isSafeTargetName(string $target): bool
    {
        return preg_match('/\Adict_[a-z0-9_]+\z/', $target) === 1;
    }
}
