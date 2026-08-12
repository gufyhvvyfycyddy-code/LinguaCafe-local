<?php

namespace App\Console\Commands;

use App\Models\LegacyWordCardMigrationRun;
use App\Services\LegacyWordCardMigrationRecoveryService;
use Illuminate\Console\Command;
use Throwable;

class MigrateLegacyWordCards extends Command
{
    protected $signature = 'reviews:migrate-legacy-word-cards {--user_id=} {--language=} {--apply} {--rollback=}';

    protected $description = 'Plan, apply, or roll back the controlled legacy word-card migration.';

    public function handle(LegacyWordCardMigrationRecoveryService $recovery): int
    {
        $userIdOption = $this->option('user_id');
        $languageOption = $this->option('language');
        $apply = (bool) $this->option('apply');
        $rollbackOption = $this->option('rollback');
        $hasRollback = $this->input->hasParameterOption('--rollback');
        $hasUserId = $this->input->hasParameterOption('--user_id');
        $hasLanguage = $this->input->hasParameterOption('--language');

        if ($apply && $hasRollback) {
            return $this->refuse('The --apply and --rollback modes are mutually exclusive.');
        }

        if ($hasRollback) {
            if ($hasUserId || $hasLanguage) {
                return $this->refuse('Rollback cannot be combined with --user_id or --language.');
            }

            $runId = $this->positiveInteger($rollbackOption);
            if ($runId === null) {
                return $this->refuse('--rollback must be a positive run ID.');
            }

            if (!$this->isTestingEnvironment()) {
                return $this->refuse('Legacy word-card migration rollback is allowed only in the testing environment.');
            }

            try {
                $run = $recovery->rollback($runId);
            } catch (Throwable $exception) {
                return $this->refuse($exception->getMessage());
            }

            $this->writeRunJson($run);

            return self::SUCCESS;
        }

        $userId = $this->positiveInteger($userIdOption);
        $language = is_string($languageOption) ? $languageOption : '';
        if ($userId === null || !preg_match('/^[a-z][a-z0-9_-]*$/', $language)) {
            return $this->refuse('A positive --user_id and a lower-case --language identifier are required.');
        }

        if ($apply && !$this->isTestingEnvironment()) {
            return $this->refuse('Legacy word-card migration apply is allowed only in the testing environment.');
        }

        try {
            $plan = $recovery->plan($userId, $language);

            if (!$apply) {
                $this->line($this->encodeJson($plan));

                return self::SUCCESS;
            }

            $run = $recovery->apply($plan);
        } catch (Throwable $exception) {
            return $this->refuse($exception->getMessage());
        }

        $this->writeRunJson($run);

        return self::SUCCESS;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (!is_scalar($value) || $value === '') {
            return null;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $validated === false ? null : (int) $validated;
    }

    private function isTestingEnvironment(): bool
    {
        return app()->environment() === 'testing';
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }

    private function writeRunJson(LegacyWordCardMigrationRun $run): void
    {
        $run->loadMissing('items');
        $this->line($this->encodeJson([
            'run_id' => (int) $run->id,
            'run_uuid' => (string) $run->run_uuid,
            'state' => (string) $run->state,
            'backup_id' => (string) $run->backup_id,
            'filters' => $run->filters,
            'counts' => $run->counts,
            'item_ids' => $run->items->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
        ]));
    }

    private function encodeJson(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
