<?php

namespace App\Console\Commands;

use App\Models\ReviewCard;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\ReviewCardService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrepareSenseReviewSmokeData extends Command
{
    protected $signature = 'smoke:sense-review-data
        {--email= : Existing local test user email}
        {--language=english : Study language}
        {--marker= : Optional marker prefix for replay filtering}
        {--json : Print JSON summary only}';

    protected $description = 'Prepare marker data for the local SenseReview real-page smoke.';

    public function handle(ReviewCardService $reviewCardService): int
    {
        $email = trim((string) $this->option('email'));
        $language = strtolower(trim((string) $this->option('language'))) ?: 'english';
        $marker = $this->normalizeMarker((string) ($this->option('marker') ?: 'codex_sense_smoke_'.Carbon::now()->format('Ymd_His')));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid --email option is required.');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();
        if (!$user) {
            $this->error('User not found. Create or log into the local test account first.');

            return self::FAILURE;
        }

        $result = DB::transaction(function () use ($user, $language, $marker, $reviewCardService) {
            $reviewSense = $this->createSense($user, $language, $marker, 'review', [
                'sense_zh' => 'smoke review meaning',
                'sense_en' => 'meaning used by the SenseReview smoke',
                'example_sentence_en' => "The {$marker}_review marker appears in a fallback example.",
                'example_sentence_zh' => 'SenseReview smoke fallback example.',
                'status' => WordSense::STATUS_CONFIRMED,
            ]);

            $reviewCard = $reviewCardService->ensureSenseCard($reviewSense);
            $reviewCard->fill([
                'fsrs_state' => 'review',
                'fsrs_due_at' => Carbon::now()->subYears(5),
                'fsrs_stability' => 10.0,
                'fsrs_difficulty' => 5.0,
                'fsrs_reps' => 2,
                'fsrs_lapses' => 0,
                'fsrs_last_reviewed_at' => Carbon::now()->subDays(3),
                'fsrs_enabled' => true,
            ])->save();

            $this->createOccurrence($user, $language, $marker, 'review_source', [
                'word_sense_id' => $reviewSense->id,
                'review_card_id' => $reviewCard->id,
                'sentence_en' => $reviewSense->example_sentence_en,
                'sentence_zh' => $reviewSense->example_sentence_zh,
                'status' => WordSenseOccurrence::STATUS_BOUND,
                'decision' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
                'confidence' => 1.0,
                'auto_fsrs_allowed' => true,
                'source' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            ]);

            $confirmSense = $this->createSense($user, $language, $marker, 'confirm_target', [
                'sense_zh' => 'smoke confirm meaning',
                'sense_en' => 'existing sense for confirm smoke',
                'status' => WordSense::STATUS_AI_SUGGESTED,
            ]);
            $confirmOccurrence = $this->createOccurrence($user, $language, $marker, 'confirm', [
                'word_sense_id' => $confirmSense->id,
                'decision' => 'match_existing_sense',
                'confidence' => 0.86,
                'auto_fsrs_allowed' => true,
                'raw_payload' => [
                    'decision' => 'match_existing_sense',
                    'matched_sense_id' => $confirmSense->id,
                    'sense_zh' => $confirmSense->sense_zh,
                    'sense_en' => $confirmSense->sense_en,
                ],
            ]);

            $ignoreOccurrence = $this->createOccurrence($user, $language, $marker, 'ignore', [
                'decision' => 'uncertain',
                'confidence' => 0.42,
                'auto_fsrs_allowed' => false,
            ]);

            $rejectOccurrence = $this->createOccurrence($user, $language, $marker, 'reject', [
                'decision' => 'uncertain',
                'confidence' => 0.31,
                'auto_fsrs_allowed' => false,
            ]);

            $bindSense = $this->createSense($user, $language, $marker, 'bind_target', [
                'sense_zh' => 'smoke bind meaning',
                'sense_en' => 'candidate sense for rebind smoke',
                'status' => WordSense::STATUS_CONFIRMED,
            ]);
            $bindOccurrence = $this->createOccurrence($user, $language, $marker, 'bind', [
                'lemma' => $bindSense->lemma,
                'surface' => $bindSense->surface_form,
                'pos' => $bindSense->pos,
                'decision' => 'uncertain',
                'confidence' => 0.52,
                'auto_fsrs_allowed' => true,
                'raw_payload' => [
                    'decision' => 'uncertain',
                    'sense_zh' => 'bind this marker to an existing sense',
                    'sense_en' => 'bind smoke raw payload',
                ],
            ]);

            $createOccurrence = $this->createOccurrence($user, $language, $marker, 'create', [
                'decision' => 'new_sense',
                'confidence' => 0.91,
                'auto_fsrs_allowed' => true,
                'raw_payload' => [
                    'decision' => 'new_sense',
                    'sense_zh' => 'smoke created meaning',
                    'sense_en' => 'new sense created through the smoke page',
                    'aliases_zh' => ['smoke alias'],
                    'collocations' => ['smoke collocation'],
                ],
            ]);

            return [
                'marker' => $marker,
                'user_id' => $user->id,
                'language' => $language,
                'review_card_id' => $reviewCard->id,
                'review_lemma' => $reviewSense->lemma,
                'occurrences' => [
                    'confirm' => $confirmOccurrence->id,
                    'ignore' => $ignoreOccurrence->id,
                    'reject' => $rejectOccurrence->id,
                    'bind' => $bindOccurrence->id,
                    'create' => $createOccurrence->id,
                ],
                'bind_candidate_sense_id' => $bindSense->id,
            ];
        });

        $encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($this->option('json')) {
            $this->line($encoded);

            return self::SUCCESS;
        }

        $this->info('SenseReview smoke marker data prepared.');
        $this->line($encoded);

        return self::SUCCESS;
    }

    private function normalizeMarker(string $marker): string
    {
        $marker = strtolower(trim($marker));
        $marker = preg_replace('/[^a-z0-9_]+/', '_', $marker) ?: 'codex_sense_smoke';

        return trim($marker, '_') ?: 'codex_sense_smoke';
    }

    private function createSense(User $user, string $language, string $marker, string $suffix, array $overrides = []): WordSense
    {
        $lemma = $overrides['lemma'] ?? "{$marker}_{$suffix}";

        return WordSense::create(array_merge([
            'user_id' => $user->id,
            'language' => $language,
            'language_id' => $language,
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'verb',
            'sense_key' => hash('sha256', "{$user->id}|{$language}|{$marker}|{$suffix}|".uniqid('', true)),
            'sense_zh' => "smoke {$suffix} meaning",
            'sense_en' => "smoke {$suffix} sense",
            'aliases_zh' => [],
            'collocations' => ["{$marker} {$suffix}"],
            'example_sentence_en' => "This sentence contains {$lemma}.",
            'example_sentence_zh' => "Smoke sentence for {$lemma}.",
            'is_context_specific' => true,
            'status' => WordSense::STATUS_CONFIRMED,
        ], $overrides));
    }

    private function createOccurrence(User $user, string $language, string $marker, string $suffix, array $overrides = []): WordSenseOccurrence
    {
        $lemma = $overrides['lemma'] ?? "{$marker}_{$suffix}";

        return WordSenseOccurrence::create(array_merge([
            'user_id' => $user->id,
            'language' => $language,
            'language_id' => $language,
            'sentence_id' => "{$marker}_{$suffix}_sentence",
            'sentence_hash' => hash('sha256', "{$marker}|{$suffix}|sentence"),
            'sentence_en' => "The marker {$lemma} appears in this smoke sentence.",
            'sentence_zh' => "Smoke translation for {$lemma}.",
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => $lemma,
            'lemma' => $lemma,
            'pos' => 'verb',
            'decision' => 'uncertain',
            'confidence' => 0.5,
            'evidence' => [
                'marker' => $marker,
                'path' => $suffix,
            ],
            'auto_fsrs_allowed' => false,
            'status' => WordSenseOccurrence::STATUS_PENDING,
            'source' => WordSenseOccurrence::SOURCE_SENSE_MAPPING_IMPORT,
            'raw_payload' => [
                'decision' => 'uncertain',
                'sense_zh' => "smoke {$suffix} meaning",
                'sense_en' => "smoke {$suffix} sense",
            ],
        ], $overrides));
    }
}
