<?php

namespace App\Console\Commands;

use App\Services\LearnedSenseExportService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class MakeGptSensePackage extends Command
{
    protected $signature = 'senses:make-gpt-package
        {--user_id=}
        {--language=}
        {--input=}
        {--output=}
        {--format=md}
        {--max_senses=1000}
        {--include_examples=true}
        {--confidence_threshold=0.90}';

    protected $description = 'Create a GPT work package for sense mapping.';

    public function handle(LearnedSenseExportService $exportService): int
    {
        $userId = (int) $this->option('user_id');
        $language = (string) $this->option('language');
        $input = (string) $this->option('input');
        $output = (string) $this->option('output');
        $format = (string) $this->option('format');
        $maxSenses = (int) $this->option('max_senses');
        $includeExamples = filter_var($this->option('include_examples'), FILTER_VALIDATE_BOOLEAN);
        $confidenceThreshold = (float) $this->option('confidence_threshold');

        if ($userId <= 0 || $language === '' || $input === '' || $output === '') {
            $this->error('The --user_id, --language, --input, and --output options are required.');

            return self::FAILURE;
        }

        if (!in_array($format, ['md', 'json'], true)) {
            $this->error('The --format option must be md or json.');

            return self::FAILURE;
        }

        $inputPath = $this->resolvePath($input);
        if (!is_file($inputPath)) {
            $this->error('Input file does not exist.');

            return self::FAILURE;
        }

        $material = file_get_contents($inputPath);
        $learned = $exportService->payload($userId, $language, $maxSenses);
        if (!$includeExamples) {
            $learned['senses'] = array_map(function (array $sense) {
                $sense['example_sentences'] = [];

                return $sense;
            }, $learned['senses']);
        }

        $package = [
            'package_schema_version' => 1,
            'created_at' => Carbon::now()->toIso8601String(),
            'user_id' => $userId,
            'language' => $language,
            'instructions' => $this->instructions($confidenceThreshold),
            'output_schema' => $this->outputSchema($language),
            'learned_senses_limit' => $maxSenses,
            'learned_senses_total_available' => $learned['total_available'],
            'learned_senses_truncated' => $learned['total_available'] > count($learned['senses']),
            'learned_senses' => $learned['senses'],
            'new_material' => $material,
        ];

        $content = $format === 'json'
            ? json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            : $this->markdown($package, $confidenceThreshold);

        $outputPath = $this->resolvePath($output);
        $directory = dirname($outputPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($outputPath, $content);

        $this->info("Created GPT sense package at {$output}.");

        return self::SUCCESS;
    }

    private function markdown(array $package, float $confidenceThreshold): string
    {
        $truncation = $package['learned_senses_truncated']
            ? "\n\n> Learned senses were truncated at {$package['learned_senses_limit']} of {$package['learned_senses_total_available']}. Increase --max_senses or split by lemma before asking GPT."
            : '';

        return implode("\n\n", [
            '# GPT Sense Mapping Work Package',
            '## Task',
            $package['instructions'],
            '## Judgment Rules',
            $this->rules($confidenceThreshold),
            '## Required Output Schema',
            '```json' . "\n" . json_encode($package['output_schema'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n" . '```',
            '## Learned Senses' . $truncation,
            '```json' . "\n" . json_encode($package['learned_senses'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n" . '```',
            '## New English Material',
            "```text\n{$package['new_material']}\n```",
        ]) . "\n";
    }

    private function instructions(float $confidenceThreshold): string
    {
        return "You need to read the new English material and decide whether words or phrases in it match the learned sense table. Output strict JSON only. Do not output explanations. Do not output Markdown. Do not omit schema_version. Use confidence >= {$confidenceThreshold} only when the contextual match is strong.";
    }

    private function rules(float $confidenceThreshold): string
    {
        return implode("\n", [
            '- Prefer matching an existing sense_id when the contextual meaning clearly matches.',
            '- Do not match only because Chinese glosses look similar.',
            '- Use lemma, part of speech, sense_en, sense_zh, aliases_zh, collocations, example_sentences, and sentence context together.',
            '- If the Chinese gloss is similar but the English meaning differs, use new_sense or uncertain.',
            '- If the same English word has a different meaning in this sentence, use new_sense or uncertain.',
            '- Prefer phrase matches before single-word matches.',
            '- If uncertain, use uncertain instead of forcing a match.',
            "- When confidence < {$confidenceThreshold}, auto_fsrs_allowed must be false.",
            '- phrase_match is only a marker for now and will not enter FSRS.',
            '- Use ignore for items that do not need learning or processing.',
        ]);
    }

    private function outputSchema(string $language): array
    {
        return [
            'schema_version' => 1,
            'document_id' => 'new-material',
            'language' => $language,
            'sentences' => [
                [
                    'sentence_id' => 's001',
                    'en' => 'They charge a fee.',
                    'zh' => '',
                    'matches' => [
                        [
                            'type' => 'word',
                            'surface' => 'charge',
                            'lemma' => 'charge',
                            'pos' => 'verb',
                            'decision' => 'match_existing_sense',
                            'matched_sense_id' => 123,
                            'sense_key' => 'charge-money',
                            'sense_zh' => 'charge money',
                            'sense_en' => 'to ask for money as a price',
                            'confidence' => 0.95,
                            'evidence' => 'The context says a fee is requested.',
                            'auto_fsrs_allowed' => true,
                        ],
                    ],
                ],
            ],
            'allowed_decisions' => [
                'match_existing_sense',
                'new_sense',
                'uncertain',
                'ignore',
                'phrase_match',
            ],
        ];
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }
}
