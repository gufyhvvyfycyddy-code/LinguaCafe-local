<?php

namespace App\Services;

use App\Models\ReviewCard;
use Illuminate\Support\Carbon;

/**
 * SenseReviewLeechRewritePackageService
 *
 * ADR-0011
 *
 * Generates a structured "rewrite prompt package" for a leech sense card.
 * The package is designed to be copied by the user to an external AI
 * (ChatGPT, Claude, etc.) for improving the example sentence, Chinese
 * definition, or disambiguation clues.
 *
 * CRITICAL SAFETY RULES (ADR-0011 Section 5):
 *  - Does NOT call any AI provider (no HTTP, no provider-preview).
 *  - Does NOT create WordSense.
 *  - Does NOT create ReviewCard.
 *  - Does NOT create ReviewLog.
 *  - Does NOT modify any database record.
 *  - The package is a READ-ONLY output.
 *
 * Schema: sense-leech-rewrite-package-v1
 */
class SenseReviewLeechRewritePackageService
{
    public const SCHEMA_VERSION = 'sense-leech-rewrite-package-v1';

    /**
     * Build the rewrite package for a single card.
     *
     * @param  ReviewCard $card
     * @param  array      $feedback     Learning feedback descriptor.
     * @param  array      $leechDescriptor Leech descriptor from SenseReviewLeechPolicy.
     * @param  array      $lifecycleDescriptor Lifecycle descriptor.
     * @param  Carbon|null $now
     * @return array{
     *     schema_version: string,
     *     package: array,
     *     markdown: string,
     *     json: array,
     *     provider_called: bool,
     *     card_created: bool,
     *     review_log_created: bool,
     * }
     */
    public function buildPackage(
        ReviewCard $card,
        array $feedback,
        array $leechDescriptor,
        array $lifecycleDescriptor,
        ?Carbon $now = null
    ): array {
        $now = $now ?? Carbon::now();
        $sense = $card->sense;

        $package = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => $now->toIso8601String(),
            'review_card_id' => $card->id,
            'word_sense_id' => $card->target_id,
            'lemma' => $sense?->lemma ?? '',
            'part_of_speech' => $sense?->part_of_speech ?? '',
            'sense_zh' => $sense?->sense_zh ?? '',
            'sense_en' => $sense?->sense_en ?? '',
            'current_example' => $sense?->example_sentence_en ?? '',
            'source_context' => $this->buildSourceContext($card, $sense),
            'recent_review_summary' => $this->buildReviewSummary($feedback),
            'forgetting_reasons' => $leechDescriptor['reasons'] ?? [],
            'leech_status' => $leechDescriptor['status'] ?? 'stable',
            'leech_severity' => $leechDescriptor['severity'] ?? 0,
            'user_goal' => $this->buildUserGoal(),
            'output_contract' => $this->buildOutputContract(),
            'safety_rules' => $this->buildSafetyRules(),
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'package' => $package,
            'markdown' => $this->toMarkdown($package),
            'json' => $package,
            'provider_called' => false,
            'card_created' => false,
            'review_log_created' => false,
        ];
    }

    /**
     * Build packages for multiple cards (batch).
     *
     * @param  array  $cardsData  Array of {card, feedback, leechDescriptor, lifecycleDescriptor}
     * @param  Carbon|null $now
     * @return array{
     *     packages: list<array>,
     *     failed: list<array{card_id: int, error: string}>,
     *     provider_called: bool,
     *     card_created: bool,
     *     review_log_created: bool,
     * }
     */
    public function buildPackagesBatch(array $cardsData, ?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now();
        $packages = [];
        $failed = [];

        foreach ($cardsData as $entry) {
            try {
                $pkg = $this->buildPackage(
                    $entry['card'],
                    $entry['feedback'] ?? [],
                    $entry['leechDescriptor'] ?? [],
                    $entry['lifecycleDescriptor'] ?? [],
                    $now
                );
                $packages[] = $pkg;
            } catch (\Throwable $e) {
                $failed[] = [
                    'card_id' => $entry['card']->id ?? 0,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'packages' => $packages,
            'failed' => $failed,
            'provider_called' => false,
            'card_created' => false,
            'review_log_created' => false,
        ];
    }

    /**
     * Build source context (chapter/sentence info if available).
     */
    private function buildSourceContext(ReviewCard $card, $sense): array
    {
        return [
            'chapter_id' => $sense?->chapter_id ?? null,
            'source_text' => $sense?->example_sentence_en ?? '',
            'note' => 'Source context from the original learning material.',
        ];
    }

    /**
     * Build the recent review summary from the feedback descriptor.
     */
    private function buildReviewSummary(array $feedback): array
    {
        return [
            'total_reviews' => $feedback['total_reviews'] ?? 0,
            'again_count' => $feedback['forget_count'] ?? 0,
            'hard_count' => $feedback['hard_count'] ?? 0,
            'good_count' => $feedback['good_count'] ?? 0,
            'easy_count' => $feedback['easy_count'] ?? 0,
            'forget_rate' => $feedback['forgetting_pattern']['forget_rate'] ?? 0,
            'trend' => $feedback['forgetting_pattern']['trend'] ?? 'insufficient',
            'recent_reviews' => $feedback['recent_reviews'] ?? [],
        ];
    }

    /**
     * Build the user goal section.
     */
    private function buildUserGoal(): array
    {
        return [
            'primary' => 'Improve the example sentence and/or Chinese definition so the sense is easier to remember.',
            'secondary' => 'Add disambiguation clues that distinguish this sense from similar ones.',
            'constraints' => [
                'Keep the lemma and part_of_speech unchanged.',
                'Preserve the original meaning — do not create a new sense.',
                'The example should be natural and memorable.',
            ],
        ];
    }

    /**
     * Build the output contract for the external AI.
     */
    private function buildOutputContract(): array
    {
        return [
            'format' => 'json',
            'fields' => [
                'improved_example_sentence' => 'A clearer, more memorable example sentence in English.',
                'improved_sense_zh' => 'An improved Chinese definition (optional, only if current is unclear).',
                'disambiguation_clues' => 'Clues to distinguish this sense from similar ones.',
                'explanation' => 'Brief explanation of why this is easier to remember.',
            ],
        ];
    }

    /**
     * Build the safety rules section.
     */
    private function buildSafetyRules(): array
    {
        return [
            'do_not_create_new_senses' => 'Do not create new word senses that the user has not confirmed.',
            'do_not_create_review_cards' => 'Do not create review cards automatically.',
            'do_not_modify_fsrs' => 'Do not modify FSRS scheduling parameters.',
            'do_not_call_external_apis' => 'This package is processed by the user manually, not by LinguaCafe.',
            'output_should_help' => 'Output should help the user rewrite examples, add disambiguation clues, and improve Chinese definitions.',
        ];
    }

    /**
     * Convert the package to Markdown for easy copying.
     */
    private function toMarkdown(array $package): string
    {
        $lines = [];
        $lines[] = '# Sense Leech Rewrite Package';
        $lines[] = '';
        $lines[] = '**Schema Version:** ' . ($package['schema_version'] ?? '');
        $lines[] = '**Generated At:** ' . ($package['generated_at'] ?? '');
        $lines[] = '**Leech Status:** ' . ($package['leech_status'] ?? '') . ' (severity: ' . ($package['leech_severity'] ?? 0) . ')';
        $lines[] = '';
        $lines[] = '## Word Sense';
        $lines[] = '';
        $lines[] = '- **Lemma:** ' . ($package['lemma'] ?? '');
        $lines[] = '- **Part of Speech:** ' . ($package['part_of_speech'] ?? '');
        $lines[] = '- **Chinese Definition:** ' . ($package['sense_zh'] ?? '');
        $lines[] = '- **English Definition:** ' . ($package['sense_en'] ?? '');
        $lines[] = '- **Current Example:** ' . ($package['current_example'] ?? '');
        $lines[] = '';
        $lines[] = '## Review History Summary';
        $lines[] = '';
        $summary = $package['recent_review_summary'] ?? [];
        $lines[] = '- Total reviews: ' . ($summary['total_reviews'] ?? 0);
        $lines[] = '- Again (forgot): ' . ($summary['again_count'] ?? 0);
        $lines[] = '- Hard: ' . ($summary['hard_count'] ?? 0);
        $lines[] = '- Good: ' . ($summary['good_count'] ?? 0);
        $lines[] = '- Easy: ' . ($summary['easy_count'] ?? 0);
        $lines[] = '- Forget rate: ' . (($summary['forget_rate'] ?? 0) * 100) . '%';
        $lines[] = '- Trend: ' . ($summary['trend'] ?? 'insufficient');
        $lines[] = '';
        $lines[] = '## Forgetting Reasons';
        $lines[] = '';
        foreach (($package['forgetting_reasons'] ?? []) as $reason) {
            $lines[] = '- ' . $reason;
        }
        $lines[] = '';
        $lines[] = '## User Goal';
        $lines[] = '';
        $goal = $package['user_goal'] ?? [];
        $lines[] = ($goal['primary'] ?? '');
        $lines[] = '';
        if (!empty($goal['constraints'])) {
            $lines[] = '### Constraints';
            foreach ($goal['constraints'] as $constraint) {
                $lines[] = '- ' . $constraint;
            }
            $lines[] = '';
        }
        $lines[] = '## Output Contract';
        $lines[] = '';
        $contract = $package['output_contract'] ?? [];
        $lines[] = 'Please output JSON with the following fields:';
        $lines[] = '';
        foreach (($contract['fields'] ?? []) as $field => $desc) {
            $lines[] = '- **' . $field . ':** ' . $desc;
        }
        $lines[] = '';
        $lines[] = '## Safety Rules';
        $lines[] = '';
        $rules = $package['safety_rules'] ?? [];
        foreach ($rules as $key => $desc) {
            $lines[] = '- **' . $key . ':** ' . $desc;
        }
        $lines[] = '';
        $lines[] = '---';
        $lines[] = '*This package was generated by LinguaCafe. LinguaCafe did NOT call any AI provider. Please copy this to your external AI tool manually.*';

        return implode("\n", $lines);
    }
}
