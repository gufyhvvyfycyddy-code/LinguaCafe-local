<?php

namespace App\Services;

use App\Models\WordSense;

class WordSenseContentVersionService
{
    public const PREFIX = 'sha256:';

    public function version(WordSense $sense): string
    {
        return self::PREFIX . hash('sha256', json_encode(
            $this->snapshot($sense),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    public function snapshot(WordSense $sense): array
    {
        return [
            'id' => (int) $sense->id,
            'status' => $sense->status,
            'pos' => $sense->pos,
            'sense_zh' => $sense->sense_zh,
            'sense_en' => $sense->sense_en,
            'example_sentence_en' => $sense->example_sentence_en,
            'example_sentence_zh' => $sense->example_sentence_zh,
            'aliases_zh' => array_values($sense->aliases_zh ?: []),
            'collocations' => array_values($sense->collocations ?: []),
        ];
    }
}
