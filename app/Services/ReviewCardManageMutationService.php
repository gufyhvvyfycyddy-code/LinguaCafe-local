<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Models\WordSense;
use App\Services\WordSenseService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReviewCardManageMutationService
{
    /**
     * Whitelist of WordSense text fields allowed for normal edit.
     */
    public const EDITABLE_FIELDS = [
        'pos',
        'sense_zh',
        'sense_en',
        'example_sentence_en',
        'example_sentence_zh',
        'aliases_zh',
        'collocations',
    ];

    /**
     * Shared private helper: find a sense review card owned by the current user/language
     * with a confirmed WordSense. Used by both bulkSetEnabled and bulkDestroy.
     * Returns null (no abort/exception) for skipped counting.
     *
     * @param int $id ReviewCard id
     * @param int $userId
     * @param string $language
     * @return ReviewCard|null
     */
    private function findManageableSenseCardForMutation(int $id, int $userId, string $language): ?ReviewCard
    {
        return ReviewCard::query()
            ->where('id', $id)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('target_type', ReviewCard::TARGET_SENSE)
            ->whereHas('sense', function ($q) use ($userId, $language) {
                $q->where('user_id', $userId)
                    ->where('language_id', $language)
                    ->where('status', WordSense::STATUS_CONFIRMED);
            })
            ->first();
    }

    /**
     * Toggle fsrs_enabled on a sense review card.
     * Does NOT write WordSense, ReviewLog, or EncounteredWord.
     */
    public function setEnabled(ReviewCard $card, bool $enabled): ReviewCard
    {
        $card->fsrs_enabled = $enabled;
        $card->save();

        return $card;
    }

    /**
     * Set fsrs_due_at = now() on a sense review card.
     * Does NOT auto-enable fsrs_enabled.
     * Does NOT write WordSense, ReviewLog, or EncounteredWord.
     */
    public function setDueNow(ReviewCard $card): ReviewCard
    {
        $card->fsrs_due_at = Carbon::now();
        $card->save();

        return $card;
    }

    /**
     * Bulk archive or restore sense review cards.
     * Uses shared findManageableSenseCardForMutation helper.
     * Returns ['affected' => int, 'skipped' => int].
     * Controller handles 422 for empty ids, message assembly, and response shape.
     */
    public function bulkSetEnabled(array $ids, bool $enabled, int $userId, string $language): array
    {
        $affected = 0;
        $skipped = 0;

        DB::transaction(function () use ($ids, $enabled, $userId, $language, &$affected, &$skipped) {
            foreach ($ids as $id) {
                $card = $this->findManageableSenseCardForMutation($id, $userId, $language);

                if (!$card) {
                    $skipped++;
                    continue;
                }

                $card->fsrs_enabled = $enabled;
                $card->save();
                $affected++;
            }
        });

        return ['affected' => $affected, 'skipped' => $skipped];
    }

    /**
     * Bulk permanently delete sense review cards.
     * Iterates through ids, filters via findManageableSenseCardForMutation,
     * then calls WordSenseService::removeSenseFromReviewSystem for each valid card.
     * Returns ['deleted' => int, 'skipped' => int].
     * Controller handles 422 for empty ids, message assembly, and response shape.
     *
     * Core delete semantics (ReviewLog preserve, WordSense rejected, EncounteredWord
     * conditional restore) are unchanged — delegated to WordSenseService.
     */
    public function bulkDestroy(array $ids, int $userId, string $language, WordSenseService $wordSenseService): array
    {
        $deleted = 0;
        $skipped = 0;

        DB::transaction(function () use ($ids, $userId, $language, $wordSenseService, &$deleted, &$skipped) {
            foreach ($ids as $id) {
                $card = $this->findManageableSenseCardForMutation($id, $userId, $language);

                if (!$card) {
                    $skipped++;
                    continue;
                }

                $sense = WordSense::find($card->target_id);
                if (!$sense) {
                    $skipped++;
                    continue;
                }

                $wordSenseService->removeSenseFromReviewSystem($sense, true);
                $deleted++;
            }
        });

        return ['deleted' => $deleted, 'skipped' => $skipped];
    }

    /**
     * Update WordSense text fields from a PATCH request.
     * Only EDITABLE_FIELDS are allowed. aliases_zh / collocations are
     * normalized via normalizeArray().
     * Does NOT write ReviewCard, ReviewLog, or EncounteredWord.
     */
    public function updateSenseTextFields(WordSense $sense, Request $request): WordSense
    {
        foreach (self::EDITABLE_FIELDS as $field) {
            if ($request->has($field)) {
                $value = $request->input($field);

                // Normalize array fields: accept comma-separated strings or arrays
                if (in_array($field, ['aliases_zh', 'collocations'], true)) {
                    $value = $this->normalizeArray($value);
                }

                $sense->{$field} = $value;
            }
        }

        $sense->save();

        return $sense;
    }

    /**
     * Normalize a value to an array of trimmed, non-empty strings.
     * Accepts arrays or comma-separated strings.
     */
    private function normalizeArray(mixed $values): array
    {
        if (!is_array($values)) {
            $values = explode(',', (string) $values);
        }

        return array_values(array_filter(
            array_map(fn ($value) => trim((string) $value), $values),
            fn ($value) => $value !== ''
        ));
    }
}
