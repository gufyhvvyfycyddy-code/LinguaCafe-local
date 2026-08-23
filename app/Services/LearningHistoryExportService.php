<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use RuntimeException;

class LearningHistoryExportService
{
    public const FORMATS = ['csv', 'txt', 'pdf'];

    public const FIELDS = [
        'event_key',
        'event_type',
        'event_source',
        'learning_origin',
        'occurred_at',
        'study_date',
        'word_sense_id',
        'review_card_id',
        'review_log_id',
        'rating',
        'lemma',
        'surface_form',
        'pos',
        'sense_zh',
        'sense_en',
        'source_accuracy',
        'source_occurrence_id',
        'chapter_id',
        'chapter_title',
        'sentence_id',
        'sentence_en',
        'current_fsrs_state',
        'current_fsrs_due_at',
        'current_stability',
        'current_difficulty',
        'current_reps',
        'current_lapses',
        'current_lifecycle_state',
        'current_state_as_of',
    ];

    public function __construct(private ReviewCardExportService $csvRenderer)
    {
    }

    /** @return array{content:string,mime:string,extension:string} */
    public function render(string $format, array $rows, array $meta): array
    {
        return match ($format) {
            'csv' => [
                'content' => $this->csvRenderer->buildCsv(new Collection($rows), self::FIELDS),
                'mime' => 'text/csv; charset=UTF-8',
                'extension' => 'csv',
            ],
            'txt' => [
                'content' => $this->txt($rows, $meta),
                'mime' => 'text/plain; charset=UTF-8',
                'extension' => 'txt',
            ],
            'pdf' => [
                'content' => $this->pdf($rows, $meta),
                'mime' => 'application/pdf',
                'extension' => 'pdf',
            ],
            default => throw new \InvalidArgumentException('Unsupported learning history export format.'),
        };
    }

    private function txt(array $rows, array $meta): string
    {
        $lines = [
            'LinguaCafe 学习历史',
            sprintf('日期：%s 至 %s', $meta['date_from'], $meta['date_to']),
            sprintf('学习时区：%s', $meta['study_timezone']),
            sprintf('筛选：%s', $meta['filter']),
            sprintf('当前状态截至：%s', $meta['current_state_as_of']),
            sprintf('事件数：%d', count($rows)),
            '',
        ];
        foreach ($rows as $index => $row) {
            $lines[] = sprintf(
                '[%d] %s | %s | %s',
                $index + 1,
                $row['event_key'],
                $row['event_type'] === 'learning_entry' ? '进入学习' : '复习 '.$row['event_source'],
                $row['occurred_at'],
            );
            $lines[] = sprintf('词义：%s%s', $row['lemma'] ?? '', $this->suffix($row['sense_zh'] ?? null));
            if (!empty($row['sentence_en'])) {
                $lines[] = '原句：'.$this->singleLine($row['sentence_en']);
            }
            $lines[] = sprintf(
                '来源：%s%s | 当前：%s / %s',
                $row['source_accuracy'],
                $this->suffix($row['chapter_title'] ?? null),
                $row['current_lifecycle_state'] ?? '无卡片',
                $row['current_fsrs_state'] ?? '无 FSRS 状态',
            );
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function pdf(array $rows, array $meta): string
    {
        $previousMemoryLimit = $this->raisePdfMemoryLimit();

        try {
            return $this->renderPdf($rows, $meta);
        } finally {
            gc_collect_cycles();
            if ($previousMemoryLimit !== null
                && memory_get_usage(true) < ini_parse_quantity($previousMemoryLimit)) {
                ini_set('memory_limit', $previousMemoryLimit);
            }
        }
    }

    private function renderPdf(array $rows, array $meta): string
    {
        $fontPath = public_path('default/fonts/DefaultNotoSansSC.ttf');
        $realFontPath = realpath($fontPath);
        if ($realFontPath === false || !is_readable($realFontPath)) {
            throw new RuntimeException('Learning history PDF font is unavailable.');
        }

        $fontCache = storage_path('framework/cache/dompdf');
        if (!is_dir($fontCache) && !mkdir($fontCache, 0775, true) && !is_dir($fontCache)) {
            throw new RuntimeException('Learning history PDF font cache could not be created.');
        }

        $fontDirectory = dirname($realFontPath);
        $fontUri = str_replace('\\', '/', $realFontPath);
        $options = new Options();
        $options->setAllowedProtocols(['file://']);
        $options->setChroot([$fontDirectory]);
        $options->setFontDir($fontCache);
        $options->setFontCache($fontCache);
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isFontSubsettingEnabled', true);
        $options->set('defaultFont', 'Noto Sans SC');
        $dompdf = new Dompdf($options);
        foreach (['normal', 'bold'] as $weight) {
            $families = $dompdf->getFontMetrics()->getFontFamilies();
            if (isset($families['noto sans sc'][$weight])) {
                continue;
            }
            if (!$dompdf->getFontMetrics()->registerFont([
                'family' => 'Noto Sans SC',
                'style' => 'normal',
                'weight' => $weight,
            ], $fontUri)) {
                throw new RuntimeException('Learning history PDF font could not be registered.');
            }
        }
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->loadHtml(view('exports.learning-history', [
            'rows' => $rows,
            'meta' => $meta,
        ])->render(), 'UTF-8');
        $dompdf->render();
        $dompdf->getCanvas()->page_text(
            500,
            817,
            '第 {PAGE_NUM} / {PAGE_COUNT} 页',
            $dompdf->getFontMetrics()->getFont('Noto Sans SC', 'normal'),
            8,
            [0.4, 0.44, 0.52],
        );

        return $dompdf->output();
    }

    private function raisePdfMemoryLimit(): ?string
    {
        $current = ini_get('memory_limit');
        if ($current === false || $current === '-1' || ini_parse_quantity($current) >= 256 * 1024 * 1024) {
            return null;
        }

        $previous = ini_set('memory_limit', '256M');
        if ($previous === false) {
            throw new RuntimeException('Learning history PDF requires a 256 MB PHP memory limit.');
        }

        return $previous;
    }

    private function suffix(?string $value): string
    {
        return $value === null || $value === '' ? '' : '｜'.$this->singleLine($value);
    }

    private function singleLine(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }
}
