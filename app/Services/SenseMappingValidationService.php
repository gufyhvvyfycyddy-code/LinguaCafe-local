<?php

namespace App\Services;

use App\Models\WordSense;

class SenseMappingValidationService
{
    private const SUPPORTED_SCHEMA_VERSIONS = [1, '1', '1.0'];

    private const DECISIONS = [
        'match_existing_sense',
        'new_sense',
        'uncertain',
        'ignore',
        'phrase_match',
    ];

    public function validateFile(string $path, int $userId, string $language): array
    {
        $resolvedPath = $this->resolvePath($path);
        if (!is_file($resolvedPath)) {
            return $this->result([], ['Mapping file does not exist.']);
        }

        $decoded = json_decode(file_get_contents($resolvedPath), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->result([], ['Invalid JSON: ' . json_last_error_msg()]);
        }

        return $this->validatePayload($decoded, $userId, $language);
    }

    public function validatePayload(array $decoded, int $userId, string $language): array
    {
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
            if (!is_array($item)) {
                $errors[] = "Item {$itemIndex}: item must be an object.";
                continue;
            }

            $itemErrors = $this->validateItem($item, $itemIndex, $userId, $language, $autoBindCandidates, $needsConfirmation);
            if ($itemErrors === []) {
                $validItems++;
            }

            $errors = array_merge($errors, $itemErrors);
        }

        return [
            'items' => $items,
            'summary' => $this->summary(count($items), $validItems, $autoBindCandidates, $needsConfirmation, $errors),
            'errors' => $errors,
            'valid' => $errors === [],
        ];
    }

    private function extractItems(array $decoded): array
    {
        if (isset($decoded['items']) && is_array($decoded['items'])) {
            return $decoded['items'];
        }

        if (isset($decoded['sentences']) && is_array($decoded['sentences'])) {
            return $decoded['sentences'];
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

    private function result(array $items, array $errors): array
    {
        return [
            'items' => $items,
            'summary' => $this->summary(count($items), 0, 0, 0, $errors),
            'errors' => $errors,
            'valid' => false,
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
