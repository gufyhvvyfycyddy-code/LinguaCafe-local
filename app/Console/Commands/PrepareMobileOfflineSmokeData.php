<?php

namespace App\Console\Commands;

use App\Models\MediaReference;
use App\Models\User;
use App\Models\WordSense;
use App\Services\MediaAssetService;
use App\Services\WordSenseService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class PrepareMobileOfflineSmokeData extends Command
{
    protected $signature = 'smoke:mobile-offline-data
        {--email= : Existing testing user email}
        {--marker= : Optional marker suffix}
        {--audio-path= : Real M4A audio fixture path}
        {--json : Print JSON summary only}';

    protected $description = 'Prepare one due Sense Review card with canonical offline audio for Mobile offline acceptance.';

    public function handle(
        WordSenseService $wordSenseService,
        MediaAssetService $mediaAssetService,
    ): int {
        if (!app()->environment('testing')) {
            $this->error('APP_ENV must be testing.');
            return self::FAILURE;
        }

        $database = strtolower((string) DB::connection()->getDatabaseName());
        $sentinel = (string) (getenv('LINGUACAFE_TEST_SENTINEL') ?: '');
        if ($database === ''
            || !str_contains($database, 'test')
            || !str_starts_with($sentinel, '__testing_acceptance_sentinel_')
            || !DB::table('migrations')->where('migration', $sentinel)->exists()) {
            $this->error('A dedicated testing database with a live PAB sentinel is required.');
            return self::FAILURE;
        }

        $email = trim((string) $this->option('email'));
        $audioPath = realpath((string) $this->option('audio-path'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid --email option is required.');
            return self::FAILURE;
        }
        if ($audioPath === false || !is_file($audioPath) || !is_readable($audioPath)) {
            $this->error('A readable --audio-path fixture is required.');
            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();
        if (!$user || $user->selected_language !== 'english') {
            $this->error('An existing English testing user is required.');
            return self::FAILURE;
        }

        $marker = $this->normalizeMarker((string) ($this->option('marker') ?: 'h10_ios_offline'));
        $lemma = 'offline';

        $result = DB::transaction(function () use (
            $user,
            $marker,
            $lemma,
            $audioPath,
            $wordSenseService,
            $mediaAssetService,
        ) {
            $sense = $wordSenseService->createSense([
                'user_id' => $user->id,
                'language' => 'english',
                'language_id' => 'english',
                'lemma' => $lemma,
                'surface_form' => $lemma,
                'pos' => 'noun',
                'sense_key' => hash('sha256', "{$user->id}|english|{$marker}|offline"),
                'sense_zh' => '离线验收词义',
                'sense_en' => 'a deterministic Sense used only by the H10 iOS offline acceptance',
                'aliases_zh' => [],
                'collocations' => [],
                'example_sentence_en' => "The {$lemma} card remains available without the server.",
                'example_sentence_zh' => '该离线验收卡在服务器不可达时仍可使用。',
                'is_context_specific' => true,
                'status' => WordSense::STATUS_CONFIRMED,
            ]);

            $card = $wordSenseService->enrollConfirmedSense(
                $sense,
                WordSense::LEARNING_ORIGIN_NON_READING,
            );
            if ($card === null) {
                throw new \RuntimeException('IOS_OFFLINE_REVIEW_CARD_CREATE_FAILED');
            }
            $card->fsrs_due_at = Carbon::now('UTC')->subDays(30);
            $card->save();

            $manifest = $mediaAssetService->attach(
                $sense,
                new UploadedFile($audioPath, basename($audioPath), null, null, true),
                MediaReference::ROLE_WORD_PRONUNCIATION,
                null,
                'owned',
                'H10 iOS testing fixture',
            );
            $audio = collect($manifest)
                ->firstWhere('role', MediaReference::ROLE_WORD_PRONUNCIATION);
            if (!is_array($audio)) {
                throw new \RuntimeException('IOS_OFFLINE_MEDIA_BINDING_FAILED');
            }

            return [
                'marker' => $marker,
                'user_id' => $user->id,
                'word_sense_id' => $sense->id,
                'review_card_id' => $card->id,
                'lemma' => $lemma,
                'initial_fsrs_state' => $card->fsrs_state,
                'initial_fsrs_reps' => (int) $card->fsrs_reps,
                'audio_asset_id' => $audio['asset_id'],
                'audio_sha256' => $audio['sha256'],
            ];
        });

        if ($result['initial_fsrs_reps'] !== 0) {
            $this->error('Offline acceptance card must start with zero FSRS reps.');
            return self::FAILURE;
        }

        $encoded = json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        if ($this->option('json')) {
            $this->line($encoded);
            return self::SUCCESS;
        }

        $this->info('Mobile offline smoke data prepared.');
        $this->line($encoded);
        return self::SUCCESS;
    }

    private function normalizeMarker(string $marker): string
    {
        $marker = strtolower(trim($marker));
        $marker = preg_replace('/[^a-z0-9_]+/', '_', $marker) ?: 'h10_ios_offline';
        return trim($marker, '_') ?: 'h10_ios_offline';
    }
}
