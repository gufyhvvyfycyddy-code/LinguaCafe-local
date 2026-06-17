<?php

namespace App\Console\Commands;

use App\Models\WordSense;
use Illuminate\Console\Command;

class ValidateSenseMapping extends Command
{
    protected $signature = 'senses:validate-mapping {path} {--user_id=} {--language=}';

    protected $description = 'Validate a sense-mapping JSON file without importing it.';

    private const SUPPORTED_SCHEMA_VERSIONS = [1, '1', '1.0'];

    private const DECISIONS = [
        'match_existing_sense',
        'new_sense',
        'uncertain',
        'ignore',
        'phrase_match',
    ];

    public function handle(): int
    {
        $userId = (int) $this->option('user_id');
        $language = (string) $this->option('language');
        $path = base_path($this->argument('path'));

        if ($userId <= 0 || $language === '') {
            $this->error('The --user_id and --language options are required.');

            return self::FAILURE;
        }

        if (!is_file($path)) {
            $this->error('Mapping file does not exist.');

            return self::FAILURE;
        }

        $decoded = json_decode(file_get_contents($path), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->line(json_encode($this->summary(0, 0, 0, 0, ['Invalid JSON: ' . json_last_error_msg()]), JSON_PRETTY_PRINT));

            return self::FAILURE;
        }

        $schemaVersion = $decoded['schema_version'] ?? null;
        $items = $this->extractItems($decoded);
        $errors = [];

        if (!in_array($schemaVersion, self::SUPPORTED_SCHEMA_VERSIONS, true)) {
            $errors[] = 'Unsupported schema_version.';
        }

        $validItems = 0;
        $autoBindCandidates = 0;
        $needsConfirmation = 0;

        foreach ($items as $itemIndex => $item) {
            $itemErrors = $this->validateItem($item, $itemIndex, $userId, $language, $autoBindCandidates, $needsConfirmation);
            if ($itemErrors === []) {
                $validItems++;
            }

            $errors = array_merge($errors, $itemErrors);
        }

        $summary = $this->summary(count($items), $validItems, $autoBindCandidates, $needsConfirmation, $errors);
        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }

    private function extractItems(array $decoded): array
    {
        if (isset($decoded['items']) && is_array($decoded['items'])) {
            return $decoded['items'];
        }

        if (isset($decoded['sentence_id']) || isset($decoded['matches'])) {
            return [$decoded];
        }

        return [];
    }

    private function validateItem(array $item, int $itemIndex, int $userId, string $language, int &$autoBindCandidates, int &$needsConfirmation): array
    {
        $errors = [];
        foreach (['sentence_id', 'en', 'matches'] as $field) {
            if (!array_key_exists($field, $item)) {
                $errors[] = "Item {$itemIndex}: {$field} is required.";
            }
        }

        if (!isset($item['matches']) || !is_array($item['matches'])) {
            $errors[] = "Item {$itemIndex}: matches must be an array.";

            return $errors;
        }

        foreach ($item['matches'] as $matchIndex => $match) {
            $errors = array_merge($errors, $this->validateMatch($match, $itemIndex, $matchIndex, $userId, $language, $autoBindCandidates, $needsConfirmation));
        }

        return $errors;
    }

    private function validateMatch(mixed $match, int $itemIndex, int $matchIndex, int $userId, string $language, int &$autoBindCandidates, int &$needsConfirmation): array
    {
        $prefix = "Item {$itemIndex} match {$matchIndex}";
        if (!is_array($match)) {
            return ["{$prefix}: match must be an object."];
        }

        $errors = [];
        $decision = $match['decision'] ?? null;
        $confidence = $match['confidence'] ?? null;
        $autoFsrsAllowed = $match['auto_fsrs_allowed'] ?? null;

        if (!in_array($decision, self::DECISIONS, true)) {
            $errors[] = "{$prefix}: decision is invalid.";
        }

        if (!is_numeric($confidence) || $confidence < 0 || $confidence > 1) {
            $errors[] = "{$prefix}: confidence must be between 0 and 1.";
        }

        if (!is_bool($autoFsrsAllowed)) {
            $errors[] = "{$prefix}: auto_fsrs_allowed must be boolean.";
        }

        if (is_numeric($confidence) && $confidence < 0.90 && $autoFsrsAllowed === true) {
            $errors[] = "{$prefix}: auto_fsrs_allowed cannot be true when confidence is below 0.90.";
        }

        if ($decision === 'match_existing_sense') {
            if (!isset($match['matched_sense_id'])) {
                $errors[] = "{$prefix}: matched_sense_id is required for match_existing_sense.";
            } elseif (!$this->senseExistsForUserAndLanguage((int) $match['matched_sense_id'], $userId, $language)) {
                $errors[] = "{$prefix}: matched_sense_id does not belong to the current user and language.";
            }
        }

        if ($decision === 'new_sense' && empty($match['sense_zh']) && empty($match['sense_en'])) {
            $errors[] = "{$prefix}: new_sense requires sense_zh or sense_en.";
        }

        if ($autoFsrsAllowed === true && $errors === []) {
            $autoBindCandidates++;
        }

        if ($autoFsrsAllowed !== true || $decision === 'uncertain') {
            $needsConfirmation++;
        }

        return $errors;
    }

    private function senseExistsForUserAndLanguage(int $senseId, int $userId, string $language): bool
    {
        return WordSense::where('id', $senseId)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->exists();
    }

    private function summary(int $totalItems, int $validItems, int $autoBindCandidates, int $needsConfirmation, array $errors): array
    {
        return [
            'total_items' => $totalItems,
            'valid_items' => $validItems,
            'auto_bind_candidates' => $autoBindCandidates,
            'needs_confirmation' => $needsConfirmation,
            'errors' => $errors,
        ];
    }
}
