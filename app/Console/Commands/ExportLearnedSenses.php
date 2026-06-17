<?php

namespace App\Console\Commands;

use App\Models\ReviewCard;
use App\Models\WordSense;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ExportLearnedSenses extends Command
{
    protected $signature = 'senses:export-learned {--user_id=} {--language=} {--output=}';

    protected $description = 'Export confirmed learned word senses for one user and language.';

    public function handle(): int
    {
        $userId = (int) $this->option('user_id');
        $language = (string) $this->option('language');
        $output = (string) $this->option('output');

        if ($userId <= 0 || $language === '' || $output === '') {
            $this->error('The --user_id, --language, and --output options are required.');

            return self::FAILURE;
        }

        $senses = WordSense::query()
            ->select('word_senses.*')
            ->join('review_cards', function ($join) {
                $join->on('review_cards.target_id', '=', 'word_senses.id')
                    ->where('review_cards.target_type', ReviewCard::TARGET_SENSE);
            })
            ->where('word_senses.user_id', $userId)
            ->where('word_senses.language_id', $language)
            ->where('word_senses.status', WordSense::STATUS_CONFIRMED)
            ->with('reviewCard')
            ->orderBy('word_senses.lemma')
            ->orderBy('word_senses.id')
            ->get();

        $payload = [
            'schema_version' => 1,
            'exported_at' => Carbon::now()->toIso8601String(),
            'user_id' => $userId,
            'language' => $language,
            'senses' => $senses->map(fn (WordSense $sense) => $this->serializeSense($sense))->values()->all(),
        ];

        $path = base_path($output);
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info("Exported {$senses->count()} learned senses to {$output}.");

        return self::SUCCESS;
    }

    private function serializeSense(WordSense $sense): array
    {
        $card = $sense->reviewCard;

        return [
            'sense_id' => $sense->id,
            'lemma' => $sense->lemma,
            'surface_examples' => array_values(array_filter([$sense->surface_form])),
            'pos' => $sense->pos,
            'sense_key' => $sense->sense_key,
            'sense_zh' => $sense->sense_zh,
            'aliases_zh' => $sense->aliases_zh ?: [],
            'sense_en' => $sense->sense_en,
            'collocations' => $sense->collocations ?: [],
            'example_sentences' => array_values(array_filter([
                [
                    'en' => $sense->example_sentence_en,
                    'zh' => $sense->example_sentence_zh,
                ],
            ], fn ($sentence) => $sentence['en'] !== null || $sentence['zh'] !== null)),
            'fsrs_state' => $card?->fsrs_state,
            'learned_status' => $card && $card->fsrs_reps > 0 ? 'reviewed' : 'scheduled',
        ];
    }
}
