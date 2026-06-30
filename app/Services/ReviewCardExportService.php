<?php

namespace App\Services;

use Illuminate\Support\Collection;

class ReviewCardExportService
{
    public const EXPORT_LIMIT = 5000;

    public const EXPORT_FIELDS = [
        'review_card_id',
        'word_sense_id',
        'lemma',
        'surface_form',
        'pos',
        'sense_zh',
        'sense_en',
        'example_sentence_en',
        'example_sentence_zh',
        'aliases_zh',
        'collocations',
        'source_chapter_id',
        'source_chapter_title',
        'source_kind',
        'fsrs_state',
        'fsrs_due_at',
        'fsrs_stability',
        'fsrs_difficulty',
        'fsrs_reps',
        'fsrs_lapses',
        'fsrs_last_reviewed_at',
        'fsrs_enabled',
        'missing_definition',
        'missing_example',
        'missing_source',
    ];

    public function resolveFields(array $requestedFields): array
    {
        $selectedFields = [];
        if (!empty($requestedFields)) {
            $validFields = array_intersect($requestedFields, self::EXPORT_FIELDS);
            if (empty($validFields)) {
                return [
                    'selectedFields' => [],
                    'error' => [
                        'message' => '请选择至少一个有效导出字段。',
                        'allowed_fields' => self::EXPORT_FIELDS,
                    ],
                ];
            }
            $selectedFields = array_values($validFields);
        } else {
            $selectedFields = self::EXPORT_FIELDS;
        }
        return [
            'selectedFields' => $selectedFields,
            'error' => null,
        ];
    }

    public function buildJsonExportData(Collection $items, array $selectedFields, array $filters, string $language): array
    {
        $items = $this->applyFieldSelection($items, $selectedFields);
        return [
            'exported_at' => now()->toISOString(),
            'language' => $language,
            'filters' => $filters,
            'fields' => $selectedFields,
            'count' => $items->count(),
            'items' => $items,
        ];
    }

    public function buildAnkiTsv(Collection $items): string
    {
        $headers = ['Front', 'Back', 'Lemma', 'Surface', 'POS', 'SenseZh', 'SenseEn', 'ExampleEn', 'ExampleZh', 'AliasesZh', 'Collocations', 'Source', 'FsrsState'];
        $lines = [];
        $lines[] = implode("\t", $headers);

        foreach ($items as $item) {
            $lemma = $this->tsvEscape($item['lemma'] ?? '');
            $surface = $this->tsvEscape($item['surface_form'] ?? '');
            $pos = $this->tsvEscape($item['pos'] ?? '');
            $senseZh = $this->tsvEscape($item['sense_zh'] ?? '');
            $senseEn = $this->tsvEscape($item['sense_en'] ?? '');
            $exampleEn = $this->tsvEscape($item['example_sentence_en'] ?? '');
            $exampleZh = $this->tsvEscape($item['example_sentence_zh'] ?? '');
            $aliasesZh = $this->tsvEscape($this->joinArray($item['aliases_zh'] ?? []));
            $collocations = $this->tsvEscape($this->joinArray($item['collocations'] ?? []));
            $source = $this->tsvEscape($item['source_chapter_title'] ?? '');
            $fsrsState = $this->tsvEscape($item['fsrs_state'] ?? '');

            $exampleEnHtml = $this->htmlEscape($exampleEn);
            $lemmaHtml = $this->htmlEscape($lemma);
            $surfaceHtml = $this->htmlEscape($surface);
            $posHtml = $this->htmlEscape($pos);
            $senseZhHtml = $this->htmlEscape($senseZh);
            $senseEnHtml = $this->htmlEscape($senseEn);
            $exampleZhHtml = $this->htmlEscape($exampleZh);
            $aliasesZhHtml = $this->htmlEscape($aliasesZh);
            $collocationsHtml = $this->htmlEscape($collocations);
            $sourceHtml = $this->htmlEscape($source);

            $front = $this->tsvEscape(
                $exampleEnHtml . "<br><br> <strong>" . $lemmaHtml . "</strong> / " . $surfaceHtml . " / " . $posHtml
            );
            $back = $this->tsvEscape(
                "<strong>中文释义</strong><br>" . $senseZhHtml . "<br><br> <strong>英文释义</strong><br>" . $senseEnHtml
                . "<br><br> <strong>例句翻译</strong><br>" . $exampleZhHtml
                . "<br><br> <strong>近义译法</strong><br>" . $aliasesZhHtml
                . "<br><br> <strong>搭配</strong><br>" . $collocationsHtml
                . "<br><br> <strong>来源</strong><br>" . $sourceHtml
            );

            $lines[] = implode("\t", [
                $front, $back, $lemma, $surface, $pos, $senseZh, $senseEn,
                $exampleEn, $exampleZh, $aliasesZh, $collocations, $source, $fsrsState,
            ]);
        }

        return implode("\n", $lines);
    }

    public function buildCsv(Collection $items, array $selectedFields): string
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $selectedFields);

        foreach ($items as $item) {
            $row = [];
            foreach ($selectedFields as $field) {
                $row[] = $this->csvCellValue($item[$field] ?? null);
            }
            fputcsv($stream, $row);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        return $csv;
    }

    private function applyFieldSelection(Collection $items, array $selectedFields): Collection
    {
        return $items->map(fn ($item) => array_intersect_key($item, array_flip($selectedFields)));
    }

    private function csvCellValue($value): string
    {
        if ($value === null) return '';
        if (is_array($value)) $value = $this->joinArray($value);
        $value = (string) $value;
        $trimmed = ltrim($value);
        if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@', "\t", "\r", "\n"], true)) {
            $value = "'" . $value;
        }
        return $value;
    }

    private function tsvEscape(?string $value): string
    {
        if ($value === null) return '';
        return str_replace(["\t", "\r", "\n"], [' ', ' ', ' '], $value);
    }

    private function htmlEscape(?string $value): string
    {
        if ($value === null) return '';
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function joinArray(?array $arr): string
    {
        if (empty($arr)) return '';
        return implode('；', $arr);
    }
}
