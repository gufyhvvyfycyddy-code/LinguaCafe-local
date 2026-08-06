<?php

namespace App\Services;

/**
 * Fixed, dependency-free renderers for the server-defined M14 report.
 */
class StatisticsExportService
{
    public function csv(array $report): string
    {
        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, ['section', 'metric', 'label', 'value', 'unit']);
        foreach ($this->rows($report) as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);
        return "\xEF\xBB\xBF" . $csv;
    }

    public function pdf(array $report): string
    {
        $lines = [
            'LinguaCafe Statistics V3',
            'Generated: ' . ($report['generated_at'] ?? ''),
            'Period: ' . ($report['scope']['period_days'] ?? 30) . ' days',
            'Scoped cards: ' . ($report['scope']['card_count'] ?? 0),
            '',
        ];
        foreach ($this->rows($report) as $row) {
            $lines[] = implode(' | ', array_filter([
                $row[0],
                $row[2],
                (string) $row[3] . ($row[4] !== '' ? ' ' . $row[4] : ''),
            ], fn ($value) => $value !== ''));
        }

        return $this->buildPdf($lines);
    }

    private function rows(array $report): array
    {
        $rows = [];
        $summaryLabels = [
            'due_today' => 'Due today',
            'reviews' => 'Reviews in period',
            'retention' => 'True retention',
            'average_seconds' => 'Average answer time',
        ];
        foreach ($report['summary_cards'] ?? [] as $metric) {
            $unit = $metric['unit'] ?? '';
            $rows[] = [
                'summary',
                $metric['key'],
                $summaryLabels[$metric['key']] ?? $metric['key'],
                $metric['value'] ?? '',
                $unit === '秒' ? 'seconds' : $unit,
            ];
        }
        foreach (($report['future_due']['horizons'] ?? []) as $days => $value) {
            $rows[] = ['future_due', "days_{$days}", "{$days} days", $value, 'cards'];
        }
        foreach (($report['card_states'] ?? []) as $state => $value) {
            $rows[] = ['card_states', $state, $state, $value, 'cards'];
        }
        foreach (['total_seconds', 'average_seconds', 'timed_reviews', 'total_reviews'] as $metric) {
            $rows[] = ['review_time', $metric, $metric, $report['review_time'][$metric] ?? '', str_contains($metric, 'seconds') ? 'seconds' : 'reviews'];
        }
        foreach ($report['ratings'] ?? [] as $rating) {
            $rows[] = ['ratings', 'rating_' . $rating['rating'], $rating['label'], $rating['count'], 'reviews'];
        }
        foreach (['passed', 'failed', 'total', 'rate_percent'] as $metric) {
            $rows[] = ['true_retention', $metric, $metric, $report['true_retention'][$metric] ?? '', $metric === 'rate_percent' ? '%' : 'reviews'];
        }
        foreach (($report['reading_conversion'] ?? []) as $metric => $value) {
            $rows[] = ['reading_conversion', $metric, $metric, $value ?? '', str_contains($metric, 'percent') ? '%' : ''];
        }
        return array_map(function (array $row): array {
            if ($row[3] === '' || $row[3] === null) {
                $row[3] = 'N/A';
                $row[4] = '';
            }
            return $row;
        }, $rows);
    }

    private function buildPdf(array $lines): string
    {
        $pages = array_chunk($lines, 45);
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $pageIds = [];
        foreach ($pages as $index => $_page) {
            $pageIds[] = 4 + ($index * 2);
        }
        $kids = implode(' ', array_map(fn ($id) => "{$id} 0 R", $pageIds));
        $objects[2] = "<< /Type /Pages /Kids [{$kids}] /Count " . count($pages) . ' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        foreach ($pages as $index => $pageLines) {
            $pageId = 4 + ($index * 2);
            $contentId = $pageId + 1;
            $commands = [];
            foreach ($pageLines as $lineIndex => $line) {
                $fontSize = $lineIndex === 0 && $index === 0 ? 16 : 10;
                $y = 744 - ($lineIndex * 15);
                $commands[] = sprintf(
                    'BT /F1 %d Tf 54 %d Td (%s) Tj ET',
                    $fontSize,
                    $y,
                    $this->pdfText((string) $line),
                );
            }
            $commands[] = sprintf(
                'BT /F1 9 Tf 530 30 Td (Page %d/%d) Tj ET',
                $index + 1,
                count($pages),
            );
            $stream = implode("\n", $commands);
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents {$contentId} 0 R >>";
            $objects[$contentId] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}\nendstream";
        }

        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= count($objects); $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $pdf .= 'trailer' . "\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xref}\n%%EOF\n";
        return $pdf;
    }

    private function pdfText(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $ascii = $ascii === false ? '' : $ascii;
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ascii);
    }
}
