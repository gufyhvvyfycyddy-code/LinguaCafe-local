<?php

namespace App\Services;

use App\Models\EncounteredWord;
use App\Models\KnowledgeHygieneOperation;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class KnowledgeHygieneService
{
    public const REPLACE_FIELDS = [
        'sense_zh',
        'sense_en',
        'example_sentence_zh',
        'example_sentence_en',
    ];

    public const COLUMNS = [
        'lemma', 'surface_form', 'pos', 'sense_zh', 'sense_en',
        'example_sentence_en', 'source_chapter_title', 'tags', 'marker',
        'fsrs_state', 'fsrs_due_at', 'fsrs_stability', 'fsrs_difficulty',
        'fsrs_reps', 'fsrs_lapses', 'lifecycle_state',
    ];

    private const PREFERENCE_NAME = 'reviewBrowserV3Preferences';
    private const BATCH_LIMIT = 500;

    public function __construct(
        private ReviewCardManageQueryService $cardQueries,
        private WordSenseService $wordSenses,
        private BackupService $backups,
    ) {
    }

    public function preferences(int $userId): array
    {
        $setting = Setting::query()->where('user_id', $userId)
            ->where('name', self::PREFERENCE_NAME)->first();
        $decoded = $setting ? json_decode($setting->value, true) : null;
        return $this->normalizePreferences(is_array($decoded) ? $decoded : []);
    }

    public function savePreferences(int $userId, array $payload): array
    {
        $preferences = $this->normalizePreferences($payload, true);
        $setting = Setting::query()->where('user_id', $userId)
            ->where('name', self::PREFERENCE_NAME)->first();
        if (!$setting) {
            $setting = new Setting();
            $setting->user_id = $userId;
            $setting->name = self::PREFERENCE_NAME;
        }
        $setting->value = json_encode($preferences, JSON_UNESCAPED_UNICODE);
        $setting->save();
        return $preferences;
    }

    public function findReplacePreview(Request $request, int $userId, string $language): array
    {
        $field = (string) $request->input('field');
        $find = (string) $request->input('find');
        $replace = (string) $request->input('replace', '');
        if (!in_array($field, self::REPLACE_FIELDS, true) || $find === '') {
            throw ValidationException::withMessages(['field' => '请选择允许字段并填写非空查找文本。']);
        }
        if (mb_strlen($find) > 200 || mb_strlen($replace) > 500) {
            throw ValidationException::withMessages(['find' => '查找或替换文本过长。']);
        }

        $criteria = $this->cardQueries->parseCriteria($request);
        $cards = $this->cardQueries->buildFromCriteria($request, $criteria, $userId, $language)
            ->reorder('review_cards.id')->limit(self::BATCH_LIMIT + 1)->get();
        if ($cards->count() > self::BATCH_LIMIT) {
            throw ValidationException::withMessages(['scope' => '单次最多处理 500 张卡片，请缩小查询范围。']);
        }
        $rows = $cards->map(function (ReviewCard $card) use ($field, $find, $replace) {
            $before = (string) ($card->sense->{$field} ?? '');
            if (!str_contains($before, $find)) {
                return null;
            }
            return [
                'review_card_id' => (int) $card->id,
                'word_sense_id' => (int) $card->target_id,
                'lemma' => $card->sense->lemma,
                'field' => $field,
                'before' => $before,
                'after' => str_replace($find, $replace, $before),
            ];
        })->filter()->values()->all();

        $fingerprint = $this->fingerprint([
            'type' => KnowledgeHygieneOperation::TYPE_FIND_REPLACE,
            'user_id' => $userId,
            'language' => $language,
            'rows' => $rows,
        ]);
        return [
            'field' => $field,
            'find' => $find,
            'replace' => $replace,
            'affected' => count($rows),
            'rows' => $rows,
            'preview_fingerprint' => $fingerprint,
        ];
    }

    public function applyFindReplace(Request $request, int $userId, string $language): KnowledgeHygieneOperation
    {
        $preview = $this->findReplacePreview($request, $userId, $language);
        if (!hash_equals($preview['preview_fingerprint'], (string) $request->input('preview_fingerprint'))) {
            throw ValidationException::withMessages(['preview_fingerprint' => '预览已过期，请重新预览。']);
        }

        return DB::transaction(function () use ($preview, $userId, $language) {
            foreach ($preview['rows'] as $row) {
                $sense = WordSense::query()->where('id', $row['word_sense_id'])
                    ->where('user_id', $userId)->where('language_id', $language)
                    ->where('status', WordSense::STATUS_CONFIRMED)->lockForUpdate()->firstOrFail();
                if ((string) $sense->{$row['field']} !== $row['before']) {
                    throw ValidationException::withMessages(['preview_fingerprint' => '数据已变化，请重新预览。']);
                }
                $sense->{$row['field']} = $row['after'];
                $sense->save();
            }
            return $this->record(
                KnowledgeHygieneOperation::TYPE_FIND_REPLACE,
                $userId,
                $language,
                array_column($preview['rows'], 'word_sense_id'),
                $preview['rows'],
                $preview['rows'],
                $preview['preview_fingerprint'],
                ['field' => $preview['field'], 'affected' => $preview['affected']],
            );
        });
    }

    public function duplicateCandidates(Request $request, int $userId, string $language): array
    {
        $criteria = $this->cardQueries->parseCriteria($request);
        $cards = $this->cardQueries->buildFromCriteria($request, $criteria, $userId, $language)
            ->reorder('review_cards.id')->limit(self::BATCH_LIMIT)->get();
        $rows = [];
        for ($left = 0; $left < $cards->count(); $left++) {
            for ($right = $left + 1; $right < $cards->count(); $right++) {
                $a = $cards[$left]->sense;
                $b = $cards[$right]->sense;
                if ($this->normalized($a->lemma) !== $this->normalized($b->lemma)
                    || $this->normalized($a->pos) !== $this->normalized($b->pos)) {
                    continue;
                }
                $classification = $this->classifyDuplicate($a, $b);
                $rows[] = [
                    'classification' => $classification,
                    'primary_candidate' => $this->senseDescriptor($cards[$left]),
                    'duplicate_candidate' => $this->senseDescriptor($cards[$right]),
                    'requires_human_confirmation' => true,
                ];
            }
        }
        return ['items' => array_slice($rows, 0, 100), 'scanned_cards' => $cards->count()];
    }

    public function mergePreview(int $primaryCardId, int $duplicateCardId, int $userId, string $language): array
    {
        [$primaryCard, $primary] = $this->manageable($primaryCardId, $userId, $language);
        [$duplicateCard, $duplicate] = $this->manageable($duplicateCardId, $userId, $language);
        abort_if($primaryCard->is($duplicateCard), 422, '主卡与重复卡不能相同。');
        if ($this->normalized($primary->lemma) !== $this->normalized($duplicate->lemma)
            || $this->normalized($primary->pos) !== $this->normalized($duplicate->pos)) {
            throw ValidationException::withMessages(['duplicate_review_card_id' => '只能合并 lemma 与词性相同的重复词义。']);
        }
        $snapshot = $this->mergeSnapshot($primaryCard, $primary, $duplicateCard, $duplicate);
        return [
            'primary' => $this->senseDescriptor($primaryCard),
            'duplicate' => $this->senseDescriptor($duplicateCard),
            'classification' => $this->classifyDuplicate($primary, $duplicate),
            'impact' => [
                'occurrences_rebound' => count($snapshot['duplicate_occurrence_ids']),
                'review_logs_rebound' => count($snapshot['duplicate_review_log_ids']),
                'primary_schedule_preserved' => true,
                'duplicate_card_removed' => true,
                'duplicate_sense_rejected' => true,
                'automatic_backup_required' => true,
            ],
            'preview_fingerprint' => $this->mergeFingerprint($snapshot, $userId, $language),
        ];
    }

    public function applyMerge(
        int $primaryCardId,
        int $duplicateCardId,
        string $previewFingerprint,
        int $userId,
        string $language,
    ): KnowledgeHygieneOperation {
        $preview = $this->mergePreview($primaryCardId, $duplicateCardId, $userId, $language);
        if (!hash_equals($preview['preview_fingerprint'], $previewFingerprint)) {
            throw ValidationException::withMessages(['preview_fingerprint' => '合并预览已过期。']);
        }
        $backup = $this->backups->createBackup();

        return DB::transaction(function () use ($primaryCardId, $duplicateCardId, $preview, $backup, $userId, $language) {
            [$primaryCard, $primary] = $this->manageable($primaryCardId, $userId, $language, true);
            [$duplicateCard, $duplicate] = $this->manageable($duplicateCardId, $userId, $language, true);
            $before = $this->mergeSnapshot($primaryCard, $primary, $duplicateCard, $duplicate);
            if (!hash_equals($preview['preview_fingerprint'], $this->mergeFingerprint($before, $userId, $language))) {
                throw ValidationException::withMessages(['preview_fingerprint' => '合并预览已过期。']);
            }
            $primary->tags()->syncWithoutDetaching($before['duplicate_tag_ids']);
            WordSenseOccurrence::query()->whereIn('id', $before['duplicate_occurrence_ids'])
                ->update(['word_sense_id' => $primary->id, 'review_card_id' => $primaryCard->id]);
            ReviewLog::query()->whereIn('id', $before['duplicate_review_log_ids'])
                ->update(['review_card_id' => $primaryCard->id]);
            $duplicate->status = WordSense::STATUS_REJECTED;
            $duplicate->save();
            $duplicateCard->delete();

            return $this->record(
                KnowledgeHygieneOperation::TYPE_MERGE,
                $userId,
                $language,
                [$primary->id, $duplicate->id],
                $before,
                [
                    'primary_card_id' => $primaryCard->id,
                    'duplicate_card_deleted' => true,
                    'primary_tag_ids' => $primary->tags()->pluck('word_sense_tags.id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
                ],
                $preview['preview_fingerprint'],
                ['backup_id' => $backup['backup_id'] ?? null, 'classification' => $preview['classification']],
            );
        });
    }

    public function safeDelete(ReviewCard $card, WordSense $sense, int $userId, string $language): KnowledgeHygieneOperation
    {
        $before = $this->deleteSnapshot($card, $sense);
        $fingerprint = $this->fingerprint([
            'type' => KnowledgeHygieneOperation::TYPE_SAFE_DELETE,
            'user_id' => $userId,
            'language' => $language,
            'snapshot' => $before,
        ]);
        return DB::transaction(function () use ($card, $sense, $userId, $language, $before, $fingerprint) {
            $this->wordSenses->removeSenseFromReviewSystem($sense, true);
            return $this->record(
                KnowledgeHygieneOperation::TYPE_SAFE_DELETE,
                $userId,
                $language,
                [$sense->id],
                $before,
                ['review_card_deleted' => true, 'sense_status' => WordSense::STATUS_REJECTED],
                $fingerprint,
                ['lemma' => $sense->lemma],
            );
        });
    }

    public function recentDeletes(int $userId, string $language): array
    {
        return KnowledgeHygieneOperation::query()
            ->where('user_id', $userId)->where('language_id', $language)
            ->where('operation_type', KnowledgeHygieneOperation::TYPE_SAFE_DELETE)
            ->where('created_at', '>=', now()->subDays(30))
            ->latest()->limit(100)->get()
            ->map(fn ($operation) => [
                'operation_id' => $operation->operation_id,
                'status' => $operation->status,
                'word_sense_id' => $operation->subject_ids[0] ?? null,
                'lemma' => $operation->metadata['lemma'] ?? null,
                'deleted_at' => $operation->created_at->toISOString(),
                'can_restore' => $operation->status === KnowledgeHygieneOperation::STATUS_APPLIED,
            ])->all();
    }

    public function undo(string $operationId, int $userId, string $language): KnowledgeHygieneOperation
    {
        return DB::transaction(function () use ($operationId, $userId, $language) {
            $operation = KnowledgeHygieneOperation::query()
                ->where('operation_id', $operationId)->where('user_id', $userId)
                ->where('language_id', $language)->lockForUpdate()->firstOrFail();
            if ($operation->status !== KnowledgeHygieneOperation::STATUS_APPLIED) {
                throw ValidationException::withMessages(['operation_id' => '该操作已经撤销。']);
            }
            match ($operation->operation_type) {
                KnowledgeHygieneOperation::TYPE_FIND_REPLACE => $this->undoFindReplace($operation),
                KnowledgeHygieneOperation::TYPE_SAFE_DELETE => $this->undoDelete($operation),
                KnowledgeHygieneOperation::TYPE_MERGE => $this->undoMerge($operation),
                default => throw ValidationException::withMessages(['operation_id' => '不支持撤销该操作。']),
            };
            $operation->status = KnowledgeHygieneOperation::STATUS_UNDONE;
            $operation->undone_at = now();
            $operation->save();
            return $operation->refresh();
        });
    }

    private function undoFindReplace(KnowledgeHygieneOperation $operation): void
    {
        foreach ($operation->before_snapshot as $row) {
            $sense = WordSense::query()->where('id', $row['word_sense_id'])
                ->where('user_id', $operation->user_id)->where('language_id', $operation->language_id)
                ->lockForUpdate()->firstOrFail();
            if ((string) $sense->{$row['field']} !== $row['after']) {
                throw ValidationException::withMessages(['operation_id' => '字段已有新修改，不能覆盖撤销。']);
            }
            $sense->{$row['field']} = $row['before'];
            $sense->save();
        }
    }

    private function undoDelete(KnowledgeHygieneOperation $operation): void
    {
        $snapshot = $operation->before_snapshot;
        $sense = WordSense::query()->where('id', $snapshot['sense_id'])
            ->where('user_id', $operation->user_id)->where('language_id', $operation->language_id)
            ->lockForUpdate()->firstOrFail();
        if ($sense->status !== WordSense::STATUS_REJECTED
            || ReviewCard::query()->where('target_type', ReviewCard::TARGET_SENSE)->where('target_id', $sense->id)->exists()) {
            throw ValidationException::withMessages(['operation_id' => '词义或卡片已有新状态，不能恢复。']);
        }
        $sense->status = $snapshot['sense_status'];
        $sense->save();
        $card = new ReviewCard();
        $card->forceFill($snapshot['card']);
        $card->save();
        foreach ($snapshot['occurrences'] as $row) {
            WordSenseOccurrence::query()->where('id', $row['id'])
                ->where('user_id', $operation->user_id)->where('language_id', $operation->language_id)
                ->update(['review_card_id' => $row['review_card_id'], 'auto_fsrs_allowed' => $row['auto_fsrs_allowed']]);
        }
        if ($snapshot['encountered_word']) {
            EncounteredWord::query()->where('id', $snapshot['encountered_word']['id'])
                ->where('user_id', $operation->user_id)->where('language', $operation->language_id)
                ->update(['stage' => $snapshot['encountered_word']['stage']]);
        }
    }

    private function undoMerge(KnowledgeHygieneOperation $operation): void
    {
        $snapshot = $operation->before_snapshot;
        $primary = WordSense::query()->where('id', $snapshot['primary_sense_id'])
            ->where('user_id', $operation->user_id)->where('language_id', $operation->language_id)
            ->lockForUpdate()->firstOrFail();
        $duplicate = WordSense::query()->where('id', $snapshot['duplicate_sense_id'])
            ->where('user_id', $operation->user_id)->where('language_id', $operation->language_id)
            ->lockForUpdate()->firstOrFail();
        $primaryCard = ReviewCard::query()->whereKey($snapshot['primary_card_id'])
            ->where('user_id', $operation->user_id)->where('language_id', $operation->language_id)
            ->where('target_type', ReviewCard::TARGET_SENSE)->where('target_id', $primary->id)
            ->lockForUpdate()->first();
        $currentPrimaryTags = $primary->tags()->pluck('word_sense_tags.id')
            ->map(fn ($id) => (int) $id)->sort()->values()->all();
        $expectedPrimaryTags = collect($operation->after_snapshot['primary_tag_ids'] ?? [])
            ->map(fn ($id) => (int) $id)->sort()->values()->all();
        if (!$primaryCard
            || $currentPrimaryTags !== $expectedPrimaryTags
            || $duplicate->status !== WordSense::STATUS_REJECTED
            || ReviewCard::query()->whereKey($snapshot['duplicate_card']['id'])->exists()) {
            throw ValidationException::withMessages(['operation_id' => '合并结果已有新状态，不能撤销。']);
        }
        $duplicate->status = $snapshot['duplicate_sense_status'];
        $duplicate->save();
        $card = new ReviewCard();
        $card->forceFill($snapshot['duplicate_card']);
        $card->save();
        ReviewLog::query()->whereIn('id', $snapshot['duplicate_review_log_ids'])
            ->where('review_card_id', $snapshot['primary_card_id'])
            ->update(['review_card_id' => $card->id]);
        WordSenseOccurrence::query()->whereIn('id', $snapshot['duplicate_occurrence_ids'])
            ->where('word_sense_id', $primary->id)
            ->update(['word_sense_id' => $duplicate->id, 'review_card_id' => $card->id]);
        $primary->tags()->sync($snapshot['primary_tag_ids']);
        $duplicate->tags()->sync($snapshot['duplicate_tag_ids']);
    }

    private function manageable(int $cardId, int $userId, string $language, bool $lock = false): array
    {
        $query = ReviewCard::query()->with(['sense.tags'])
            ->whereKey($cardId)->where('user_id', $userId)->where('language_id', $language)
            ->where('target_type', ReviewCard::TARGET_SENSE)
            ->whereHas('sense', fn ($query) => $query->where('user_id', $userId)
                ->where('language_id', $language)->where('status', WordSense::STATUS_CONFIRMED));
        if ($lock) {
            $query->lockForUpdate();
        }
        $card = $query->firstOrFail();
        if ($lock) {
            $sense = WordSense::query()->with('tags')->whereKey($card->target_id)
                ->where('user_id', $userId)->where('language_id', $language)
                ->where('status', WordSense::STATUS_CONFIRMED)->lockForUpdate()->firstOrFail();
            $card->setRelation('sense', $sense);
        }
        return [$card, $card->sense];
    }

    private function mergeFingerprint(array $snapshot, int $userId, string $language): string
    {
        return $this->fingerprint([
            'type' => KnowledgeHygieneOperation::TYPE_MERGE,
            'user_id' => $userId,
            'language' => $language,
            'snapshot' => $snapshot,
        ]);
    }

    private function deleteSnapshot(ReviewCard $card, WordSense $sense): array
    {
        return [
            'sense_id' => (int) $sense->id,
            'sense_status' => $sense->status,
            'card' => $card->getAttributes(),
            'occurrences' => WordSenseOccurrence::query()->where('word_sense_id', $sense->id)
                ->where('user_id', $sense->user_id)->where('language_id', $sense->language_id)
                ->get(['id', 'review_card_id', 'auto_fsrs_allowed'])->toArray(),
            'encountered_word' => $sense->encountered_word_id
                ? EncounteredWord::query()->whereKey($sense->encountered_word_id)->first(['id', 'stage'])?->toArray()
                : null,
        ];
    }

    private function mergeSnapshot(ReviewCard $primaryCard, WordSense $primary, ReviewCard $duplicateCard, WordSense $duplicate): array
    {
        return [
            'primary_card_id' => (int) $primaryCard->id,
            'primary_sense_id' => (int) $primary->id,
            'duplicate_sense_id' => (int) $duplicate->id,
            'duplicate_sense_status' => $duplicate->status,
            'duplicate_card' => $duplicateCard->getAttributes(),
            'duplicate_occurrence_ids' => WordSenseOccurrence::query()
                ->where('word_sense_id', $duplicate->id)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'duplicate_review_log_ids' => ReviewLog::query()
                ->where('review_card_id', $duplicateCard->id)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'primary_tag_ids' => $primary->tags->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'duplicate_tag_ids' => $duplicate->tags->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ];
    }

    private function classifyDuplicate(WordSense $left, WordSense $right): string
    {
        $leftDefinition = $this->normalized(trim(($left->sense_zh ?? '') . ' ' . ($left->sense_en ?? '')));
        $rightDefinition = $this->normalized(trim(($right->sense_zh ?? '') . ' ' . ($right->sense_en ?? '')));
        if ($leftDefinition !== '' && $leftDefinition === $rightDefinition) {
            return 'exact_duplicate';
        }
        if ($leftDefinition === '' || $rightDefinition === '') {
            return 'needs_distinction';
        }
        $a = array_unique(preg_split('/\s+/u', $leftDefinition, -1, PREG_SPLIT_NO_EMPTY));
        $b = array_unique(preg_split('/\s+/u', $rightDefinition, -1, PREG_SPLIT_NO_EMPTY));
        $union = array_unique(array_merge($a, $b));
        $overlap = count(array_intersect($a, $b)) / max(1, count($union));
        return $overlap >= 0.6 ? 'possible_merge' : 'keep_separate';
    }

    private function senseDescriptor(ReviewCard $card): array
    {
        return [
            'review_card_id' => (int) $card->id,
            'word_sense_id' => (int) $card->target_id,
            'lemma' => $card->sense->lemma,
            'pos' => $card->sense->pos,
            'sense_zh' => $card->sense->sense_zh,
            'sense_en' => $card->sense->sense_en,
        ];
    }

    private function normalizePreferences(array $payload, bool $strict = false): array
    {
        $columns = array_values(array_unique(array_filter(
            $payload['columns'] ?? self::COLUMNS,
            fn ($column) => in_array($column, self::COLUMNS, true),
        )));
        if ($strict && $columns === []) {
            throw ValidationException::withMessages(['columns' => '至少保留一列。']);
        }
        $views = array_values(array_slice(array_filter(array_map(function ($view) {
            if (!is_array($view) || trim((string) ($view['name'] ?? '')) === '') {
                return null;
            }
            return [
                'name' => mb_substr(trim((string) $view['name']), 0, 80),
                'filter_state' => array_intersect_key($view['filter_state'] ?? [], array_flip([
                    'q', 'filter', 'sort_by', 'sort_dir', 'fsrs_states',
                    'due_range', 'reps_min', 'lapses_min', 'tag_ids',
                ])),
                'columns' => array_values(array_filter($view['columns'] ?? $columns, fn ($column) => in_array($column, self::COLUMNS, true))),
            ];
        }, $payload['views'] ?? [])), 0, 20));
        return ['columns' => $columns, 'views' => $views];
    }

    private function normalized(?string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value)));
    }

    private function fingerprint(array $payload): string
    {
        return hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), (string) config('app.key'));
    }

    private function record(
        string $type,
        int $userId,
        string $language,
        array $subjectIds,
        array $before,
        array $after,
        string $fingerprint,
        array $metadata,
    ): KnowledgeHygieneOperation {
        return KnowledgeHygieneOperation::create([
            'operation_id' => (string) Str::uuid(),
            'user_id' => $userId,
            'language_id' => $language,
            'operation_type' => $type,
            'status' => KnowledgeHygieneOperation::STATUS_APPLIED,
            'subject_ids' => array_values(array_map('intval', $subjectIds)),
            'before_snapshot' => $before,
            'after_snapshot' => $after,
            'preview_fingerprint' => $fingerprint,
            'metadata' => $metadata,
        ]);
    }
}
