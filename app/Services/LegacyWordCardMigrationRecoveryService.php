<?php

namespace App\Services;

use App\Models\LegacyWordCardMigrationItem;
use App\Models\LegacyWordCardMigrationRun;
use App\Models\ReviewCard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class LegacyWordCardMigrationRecoveryService
{
    public const PLAN_SCHEMA_VERSION = 'legacy_word_card_migration_plan_v1';

    private const DEPENDENCY_TABLES = [
        'review_logs',
        'review_card_state_events',
        'reschedule_snapshot_items',
        'operations',
        'reading_session_interactions',
        'reading_session_card_settlements',
        'word_sense_occurrences',
    ];

    public function __construct(
        private LegacyWordCardMigrationClassifier $classifier,
        private BackupService $backups,
        private ReviewCardOperationSnapshotService $snapshots,
    ) {}

    public function plan(?int $userId = null, ?string $language = null): array
    {
        return $this->planFromReport($this->classifier->classify($userId, $language));
    }

    public function apply(array $plan): LegacyWordCardMigrationRun
    {
        $this->validatePlan($plan);

        $existing = LegacyWordCardMigrationRun::query()
            ->where('plan_fingerprint', $plan['plan_fingerprint'])
            ->first();
        if ($existing !== null) {
            if ($existing->state === LegacyWordCardMigrationRun::STATE_APPLIED) {
                return $existing;
            }

            throw new RuntimeException('This migration plan was already rolled back.');
        }

        $this->assertPlanCurrent($plan);
        $apply = function (callable $createBackup) use ($plan) {
            $existing = LegacyWordCardMigrationRun::query()
                ->where('plan_fingerprint', $plan['plan_fingerprint'])
                ->first();
            if ($existing !== null && $existing->state === LegacyWordCardMigrationRun::STATE_APPLIED) {
                return $existing;
            }
            if (LegacyWordCardMigrationItem::query()
                ->whereIn('legacy_review_card_id', collect($plan['items'])->pluck('legacy_review_card_id'))
                ->exists()) {
                throw new RuntimeException('A planned legacy card already has a migration ledger item.');
            }

            $protectedBackupIds = LegacyWordCardMigrationRun::query()
                ->where('state', LegacyWordCardMigrationRun::STATE_APPLIED)
                ->pluck('backup_id')
                ->all();
            $createdBackup = $createBackup($protectedBackupIds);
            $backupId = (string) ($createdBackup['backup_id'] ?? '');
            $inspection = $this->backups->inspectBackup($backupId);
            $manifest = $inspection['manifest'] ?? [];

            if (($manifest['backup_id'] ?? null) !== $backupId
                || ! is_string($inspection['manifest_sha256'] ?? null)
                || ! is_string($manifest['sha256'] ?? null)) {
                throw new RuntimeException('The safety backup inspection is incomplete.');
            }

            return DB::transaction(function () use ($plan, $backupId, $inspection, $manifest) {
                $cardRows = collect($plan['items']);
                $this->lockClassificationEvidence($cardRows);

                $currentReport = $this->assertPlanCurrent($plan);
                $currentRows = collect($currentReport['cards'])
                    ->keyBy(fn (array $row): int => (int) $row['review_card']['id']);

                $run = LegacyWordCardMigrationRun::create([
                    'run_uuid' => (string) Str::uuid(),
                    'schema_version' => self::PLAN_SCHEMA_VERSION,
                    'classifier_schema_version' => $plan['classifier_schema_version'],
                    'report_fingerprint' => $plan['report_fingerprint'],
                    'plan_fingerprint' => $plan['plan_fingerprint'],
                    'backup_id' => $backupId,
                    'backup_manifest_sha256' => $inspection['manifest_sha256'],
                    'backup_payload_sha256' => $manifest['sha256'],
                    'filters' => $plan['filters'],
                    'counts' => $plan['counts'],
                    'state' => LegacyWordCardMigrationRun::STATE_APPLIED,
                    'applied_at' => now(),
                ]);

                foreach ($plan['items'] as $plannedItem) {
                    $classification = $currentRows[(int) $plannedItem['legacy_review_card_id']] ?? null;
                    if ($classification === null
                        || $classification['classification'] !== 'unique_mapping'
                        || (int) $classification['selected_word_sense_id'] !== (int) $plannedItem['word_sense_id']) {
                        throw new RuntimeException('A planned legacy card is no longer uniquely mapped.');
                    }

                    $legacyCard = ReviewCard::query()->find($plannedItem['legacy_review_card_id']);
                    if ($legacyCard === null
                        || $legacyCard->target_type !== ReviewCard::TARGET_WORD
                        || (int) $legacyCard->user_id !== (int) $classification['review_card']['user_id']
                        || (string) $legacyCard->language_id !== (string) $classification['review_card']['language_id']
                        || (int) $legacyCard->target_id !== (int) $classification['review_card']['target_id']) {
                        throw new RuntimeException('A planned legacy card changed during migration.');
                    }

                    $senseCards = ReviewCard::query()
                        ->where('user_id', $legacyCard->user_id)
                        ->where('language_id', $legacyCard->language_id)
                        ->where('target_type', ReviewCard::TARGET_SENSE)
                        ->where('target_id', $plannedItem['word_sense_id'])
                        ->get();
                    if ($senseCards->count() > 1) {
                        throw new RuntimeException('Multiple sense cards exist for a selected WordSense.');
                    }

                    $senseCard = $senseCards->first();
                    if ($senseCard !== null
                        && ((int) $senseCard->user_id !== (int) $legacyCard->user_id
                            || (string) $senseCard->language_id !== (string) $legacyCard->language_id)) {
                        throw new RuntimeException('The existing sense card is outside the legacy card scope.');
                    }

                    $beforeLegacy = $this->captureCard($legacyCard);
                    $createdSenseCard = $senseCard === null;
                    $beforeSense = $senseCard !== null ? $this->captureCard($senseCard) : null;

                    if ($senseCard === null) {
                        $senseCard = new ReviewCard([
                            'user_id' => $legacyCard->user_id,
                            'language_id' => $legacyCard->language_id,
                            'language' => $legacyCard->language,
                            'target_type' => ReviewCard::TARGET_SENSE,
                            'target_id' => $plannedItem['word_sense_id'],
                        ]);
                        $this->restoreCard($senseCard, $beforeLegacy);
                        $senseCard->save();
                    }

                    $afterSense = $this->captureCard($senseCard);
                    $legacyCard->lifecycle_state = ReviewCard::LIFECYCLE_ARCHIVED;
                    $legacyCard->buried_until = null;
                    $legacyCard->lifecycle_version = (int) $legacyCard->lifecycle_version + 1;
                    $legacyCard->lifecycle_changed_at = now();
                    $legacyCard->fsrs_enabled = false;
                    $legacyCard->save();
                    $afterLegacy = $this->captureCard($legacyCard);

                    LegacyWordCardMigrationItem::create([
                        'run_id' => $run->id,
                        'legacy_review_card_id' => $legacyCard->id,
                        'encountered_word_id' => $legacyCard->target_id,
                        'word_sense_id' => $plannedItem['word_sense_id'],
                        'sense_review_card_id' => $senseCard->id,
                        'user_id' => $legacyCard->user_id,
                        'language_id' => $legacyCard->language_id,
                        'created_sense_card' => $createdSenseCard,
                        'classification' => $classification['classification'],
                        'primary_reason_code' => $classification['primary_reason_code'],
                        'reason_codes' => $classification['reason_codes'],
                        'before_classification_evidence' => $classification,
                        'before_classification_fingerprint' => $this->fingerprint($classification),
                        'before_legacy_snapshot' => $beforeLegacy,
                        'before_legacy_fingerprint' => $this->snapshotFingerprint($beforeLegacy),
                        'after_legacy_snapshot' => $afterLegacy,
                        'after_legacy_fingerprint' => $this->snapshotFingerprint($afterLegacy),
                        'before_sense_snapshot' => $beforeSense,
                        'before_sense_fingerprint' => $beforeSense !== null
                            ? $this->snapshotFingerprint($beforeSense)
                            : null,
                        'after_sense_snapshot' => $afterSense,
                        'after_sense_fingerprint' => $this->snapshotFingerprint($afterSense),
                    ]);
                }

                $afterRows = collect($this->classifier->classify(
                    $plan['filters']['user_id'],
                    $plan['filters']['language'],
                )['cards'])->keyBy(fn (array $row): int => (int) $row['review_card']['id']);
                foreach ($run->items()->get() as $item) {
                    $afterEvidence = $afterRows[(int) $item->legacy_review_card_id] ?? null;
                    if (! is_array($afterEvidence)) {
                        throw new RuntimeException('The migrated legacy card disappeared from classification evidence.');
                    }
                    $item->after_classification_evidence = $afterEvidence;
                    $item->after_classification_fingerprint = $this->fingerprint($afterEvidence);
                    $item->save();
                }

                return $run->fresh('items');
            }, 3);
        };

        return $this->backups->withExclusiveOperation($apply);
    }

    public function rollback(int $runId): LegacyWordCardMigrationRun
    {
        $run = LegacyWordCardMigrationRun::query()->findOrFail($runId);
        if ($run->state === LegacyWordCardMigrationRun::STATE_ROLLED_BACK) {
            return $run;
        }

        return DB::transaction(function () use ($runId) {
            $run = LegacyWordCardMigrationRun::query()->lockForUpdate()->findOrFail($runId);
            if ($run->state === LegacyWordCardMigrationRun::STATE_ROLLED_BACK) {
                return $run;
            }

            $items = LegacyWordCardMigrationItem::query()
                ->where('run_id', $run->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($items->isEmpty()) {
                throw new RuntimeException('Rollback refused because the applied ledger is incomplete.');
            }
            $cardRows = $items->map(fn (LegacyWordCardMigrationItem $item): array => [
                'legacy_review_card_id' => (int) $item->legacy_review_card_id,
                'encountered_word_id' => (int) $item->encountered_word_id,
                'word_sense_id' => (int) $item->word_sense_id,
            ]);
            $this->lockClassificationEvidence($cardRows);
            $legacyCards = ReviewCard::query()
                ->whereIn('id', $items->pluck('legacy_review_card_id')->all())
                ->get()
                ->keyBy('id');
            $senseCards = ReviewCard::query()
                ->whereIn('id', $items->pluck('sense_review_card_id')->all())
                ->get()
                ->keyBy('id');
            $currentEvidence = collect($this->classifier->classify(
                $run->filters['user_id'],
                $run->filters['language'],
            )['cards'])->keyBy(fn (array $row): int => (int) $row['review_card']['id']);

            foreach ($items as $item) {
                $legacyCard = $legacyCards->get($item->legacy_review_card_id);
                $senseCard = $senseCards->get($item->sense_review_card_id);
                $afterEvidence = $currentEvidence[(int) $item->legacy_review_card_id] ?? null;
                if (! $this->hasExpectedIdentity(
                    $legacyCard,
                    ReviewCard::TARGET_WORD,
                    $item->encountered_word_id,
                ) || ! $this->hasExpectedIdentity(
                    $senseCard,
                    ReviewCard::TARGET_SENSE,
                    $item->word_sense_id,
                ) || (int) $legacyCard->user_id !== (int) $senseCard->user_id
                    || (int) $legacyCard->user_id !== (int) $item->user_id
                    || (string) $legacyCard->language_id !== (string) $senseCard->language_id
                    || (string) $legacyCard->language_id !== (string) $item->language_id
                    || ! is_array($item->before_classification_evidence)
                    || ! is_array($item->after_classification_evidence)
                    || ! is_array($afterEvidence)
                    || ! hash_equals(
                        $item->before_classification_fingerprint,
                        $this->fingerprint($item->before_classification_evidence),
                    )
                    || ! hash_equals(
                        $item->after_classification_fingerprint,
                        $this->fingerprint($item->after_classification_evidence),
                    )
                    || ! hash_equals(
                        $item->after_classification_fingerprint,
                        $this->fingerprint($afterEvidence),
                    )
                    || ! hash_equals($item->before_legacy_fingerprint, $this->snapshotFingerprint($item->before_legacy_snapshot))
                    || ! hash_equals($item->after_legacy_fingerprint, $this->snapshotFingerprint($item->after_legacy_snapshot))
                    || ! hash_equals($item->after_sense_fingerprint, $this->snapshotFingerprint($item->after_sense_snapshot))
                    || ! $this->cardMatches($legacyCard, $item->after_legacy_snapshot)
                    || ! $this->cardMatches($senseCard, $item->after_sense_snapshot)) {
                    throw new RuntimeException('Rollback refused because a migrated card drifted.');
                }
                if (! $item->created_sense_card
                    && (! hash_equals($item->before_sense_fingerprint, $this->snapshotFingerprint($item->before_sense_snapshot))
                        || ! $this->cardMatches($senseCard, $item->before_sense_snapshot))) {
                    throw new RuntimeException('Rollback refused because a reused sense card changed.');
                }
                if ($item->created_sense_card && $this->hasDependencies((int) $senseCard->id)) {
                    throw new RuntimeException('Rollback refused because a created sense card has dependencies.');
                }
            }

            foreach ($items as $item) {
                $legacyCard = $legacyCards->get($item->legacy_review_card_id);
                if ($item->created_sense_card) {
                    $senseCards->get($item->sense_review_card_id)->delete();
                }
                $this->restoreCard($legacyCard, $item->before_legacy_snapshot);
                $legacyCard->created_at = $item->before_legacy_snapshot['created_at'];
                $legacyCard->updated_at = $item->before_legacy_snapshot['updated_at'];
                $legacyCard->timestamps = false;
                $legacyCard->save();
                $legacyCard->timestamps = true;
            }

            $run->state = LegacyWordCardMigrationRun::STATE_ROLLED_BACK;
            $run->rolled_back_at = now();
            $run->save();

            return $run->fresh('items');
        }, 3);
    }

    private function planFromReport(array $report): array
    {
        $plan = [
            'schema_version' => self::PLAN_SCHEMA_VERSION,
            'classifier_schema_version' => $report['schema_version'],
            'report_fingerprint' => $this->fingerprint($report),
            'filters' => $report['filters'],
            'counts' => $report['counts'],
            'items' => [],
        ];
        foreach ($report['cards'] as $card) {
            if ($card['classification'] === 'unique_mapping') {
                $plan['items'][] = [
                    'legacy_review_card_id' => (int) $card['review_card']['id'],
                    'encountered_word_id' => (int) $card['review_card']['target_id'],
                    'word_sense_id' => (int) $card['selected_word_sense_id'],
                ];
            }
        }
        $plan['plan_fingerprint'] = $this->fingerprint($plan);

        return $plan;
    }

    private function assertPlanCurrent(array $plan): array
    {
        $report = $this->classifier->classify(
            $plan['filters']['user_id'],
            $plan['filters']['language'],
        );
        $currentPlan = $this->planFromReport($report);
        if (! hash_equals($plan['report_fingerprint'], $currentPlan['report_fingerprint'])
            || ! hash_equals($plan['plan_fingerprint'], $currentPlan['plan_fingerprint'])) {
            throw new RuntimeException('The legacy word-card migration plan is stale.');
        }

        return $report;
    }

    private function validatePlan(array $plan): void
    {
        if (($plan['schema_version'] ?? null) !== self::PLAN_SCHEMA_VERSION
            || ! is_string($plan['classifier_schema_version'] ?? null)
            || $plan['classifier_schema_version'] === ''
            || ! is_string($plan['report_fingerprint'] ?? null)
            || ! is_array($plan['filters'] ?? null)
            || ! array_key_exists('user_id', $plan['filters'])
            || ! array_key_exists('language', $plan['filters'])
            || ! is_array($plan['counts'] ?? null)
            || ! is_array($plan['items'] ?? null)
            || ! is_string($plan['plan_fingerprint'] ?? null)) {
            throw new RuntimeException('The legacy word-card migration plan is invalid.');
        }

        if (! preg_match('/^[a-f0-9]{64}$/', $plan['report_fingerprint'])
            || ! preg_match('/^[a-f0-9]{64}$/', $plan['plan_fingerprint'])
            || $plan['items'] === []) {
            throw new RuntimeException('The legacy word-card migration plan is empty or malformed.');
        }
        $legacyIds = [];
        foreach ($plan['items'] as $item) {
            if (! is_array($item)
                || array_keys($item) !== ['legacy_review_card_id', 'encountered_word_id', 'word_sense_id']) {
                throw new RuntimeException('A migration plan item is malformed.');
            }
            foreach ($item as $value) {
                if (! is_int($value) || $value < 1) {
                    throw new RuntimeException('Migration plan IDs must be positive integers.');
                }
            }
            if (isset($legacyIds[$item['legacy_review_card_id']])) {
                throw new RuntimeException('A migration plan contains duplicate legacy cards.');
            }
            $legacyIds[$item['legacy_review_card_id']] = true;
        }

        $fingerprinted = $plan;
        unset($fingerprinted['plan_fingerprint']);
        if (! hash_equals($plan['plan_fingerprint'], $this->fingerprint($fingerprinted))) {
            throw new RuntimeException('The legacy word-card migration plan fingerprint is invalid.');
        }
    }

    private function fingerprint(array $value): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function snapshotFingerprint(array $snapshot): string
    {
        return $this->fingerprint($snapshot);
    }

    private function captureCard(ReviewCard $card): array
    {
        return [
            'identity' => [
                'user_id' => (int) $card->user_id,
                'language_id' => (string) $card->language_id,
                'language' => (string) $card->language,
                'target_type' => (string) $card->target_type,
                'target_id' => (int) $card->target_id,
            ],
            'operation' => $this->snapshots->capture($card),
            'marker' => (int) $card->marker,
            'created_at' => $card->getRawOriginal('created_at'),
            'updated_at' => $card->getRawOriginal('updated_at'),
        ];
    }

    private function cardMatches(ReviewCard $card, array $snapshot): bool
    {
        $identity = $snapshot['identity'] ?? null;
        $operation = $snapshot['operation'] ?? null;
        if (! is_array($identity) || ! is_array($operation)) {
            return false;
        }
        try {
            $this->snapshots->validate($operation);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return (int) ($identity['user_id'] ?? 0) === (int) $card->user_id
            && (string) ($identity['language_id'] ?? '') === (string) $card->language_id
            && (string) ($identity['language'] ?? '') === (string) $card->language
            && (string) ($identity['target_type'] ?? '') === (string) $card->target_type
            && (int) ($identity['target_id'] ?? 0) === (int) $card->target_id
            && is_int($snapshot['marker'] ?? null)
            && array_key_exists('created_at', $snapshot)
            && array_key_exists('updated_at', $snapshot)
            && hash_equals(
                $this->fingerprint($operation),
                $this->fingerprint($this->snapshots->capture($card)),
            )
            && (int) $card->marker === $snapshot['marker']
            && $card->getRawOriginal('created_at') === $snapshot['created_at']
            && $card->getRawOriginal('updated_at') === $snapshot['updated_at'];
    }

    private function restoreCard(ReviewCard $card, array $snapshot): void
    {
        if (! is_array($snapshot['identity'] ?? null)
            || ! is_array($snapshot['operation'] ?? null)
            || ! is_int($snapshot['marker'] ?? null)
            || ! array_key_exists('created_at', $snapshot)
            || ! array_key_exists('updated_at', $snapshot)) {
            throw new RuntimeException('The migration card snapshot is invalid.');
        }
        $this->snapshots->restore($card, $snapshot['operation']);
        $card->marker = $snapshot['marker'];
    }

    private function hasExpectedIdentity(?ReviewCard $card, string $targetType, int $targetId): bool
    {
        return $card !== null
            && $card->target_type === $targetType
            && (int) $card->target_id === $targetId;
    }

    private function lockClassificationEvidence(Collection $cardRows): void
    {
        ReviewCard::query()
            ->whereIn('id', $cardRows->pluck('legacy_review_card_id')->all())
            ->lockForUpdate()
            ->get();
        DB::table('encountered_words')
            ->whereIn('id', $cardRows->pluck('encountered_word_id')->all())
            ->lockForUpdate()
            ->get();
        $reviewLogs = DB::table('review_logs')
            ->whereIn('review_card_id', $cardRows->pluck('legacy_review_card_id')->all())
            ->lockForUpdate()
            ->get();
        DB::table('operations')
            ->where(function ($query) use ($cardRows, $reviewLogs) {
                $query->whereIn('review_card_id', $cardRows->pluck('legacy_review_card_id')->all())
                    ->orWhereIn('review_log_id', $reviewLogs->pluck('id')->all());
            })
            ->lockForUpdate()
            ->get();
        foreach ([
            'review_card_state_events',
            'reschedule_snapshot_items',
            'reading_session_interactions',
            'reading_session_card_settlements',
        ] as $dependencyTable) {
            DB::table($dependencyTable)
                ->whereIn('review_card_id', $cardRows->pluck('legacy_review_card_id')->all())
                ->lockForUpdate()
                ->get();
        }
        $occurrences = DB::table('word_sense_occurrences')
            ->whereIn('review_card_id', $cardRows->pluck('legacy_review_card_id')->all())
            ->lockForUpdate()
            ->get();
        $candidateSenses = DB::table('word_senses')
            ->where(function ($query) use ($cardRows, $occurrences) {
                $query->whereIn('id', $cardRows->pluck('word_sense_id')->all())
                    ->orWhereIn('id', $occurrences->pluck('word_sense_id')->filter()->all())
                    ->orWhereIn('encountered_word_id', $cardRows->pluck('encountered_word_id')->all());
            })
            ->lockForUpdate()
            ->get();
        ReviewCard::query()
            ->where('target_type', ReviewCard::TARGET_SENSE)
            ->whereIn('target_id', $candidateSenses->pluck('id')->all())
            ->lockForUpdate()
            ->get();
    }

    private function hasDependencies(int $reviewCardId): bool
    {
        foreach (self::DEPENDENCY_TABLES as $table) {
            if (DB::table($table)->where('review_card_id', $reviewCardId)->exists()) {
                return true;
            }
        }

        return false;
    }
}
