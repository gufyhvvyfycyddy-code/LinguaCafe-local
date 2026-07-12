<?php

namespace App\Services;

use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;

class ReviewCardManageItemSerializerService
{
    /**
     * Map a collection of ReviewCards to the unified item array format.
     * Batch-fetches occurrence chapters and chapter names for performance.
     */
    public function buildItems($cards, int $userId, string $language)
    {
        // Collect all sense IDs
        $senseIds = $cards->pluck('target_id')->filter()->unique()->values()->toArray();

        // Batch-fetch occurrence chapters for all senses at once
        $occurrenceChapters = [];
        if (!empty($senseIds)) {
            $occurrenceChapters = WordSenseOccurrence::query()
                ->select('word_sense_id', 'chapter_id')
                ->whereIn('word_sense_id', $senseIds)
                ->where('user_id', $userId)
                ->where('language_id', $language)
                ->where('status', WordSenseOccurrence::STATUS_BOUND)
                ->whereNotNull('chapter_id')
                ->get()
                ->groupBy('word_sense_id')
                ->map(fn($group) => $group->first()->chapter_id)
                ->toArray();
        }

        // Collect all relevant chapter IDs and fetch chapters in one query
        $chapterIds = collect();
        foreach ($cards as $card) {
            $sense = $card->sense;
            if ($sense) {
                if ($sense->source_chapter_id) {
                    $chapterIds->push($sense->source_chapter_id);
                }
            }
            $sid = $card->target_id;
            if (isset($occurrenceChapters[$sid])) {
                $chapterIds->push($occurrenceChapters[$sid]);
            }
        }
        $chapterIds = $chapterIds->filter()->unique()->values()->toArray();

        $chapters = [];
        if (!empty($chapterIds)) {
            $chapters = Chapter::query()
                ->whereIn('id', $chapterIds)
                ->where('user_id', $userId)
                ->where('language', $language)
                ->pluck('name', 'id')
                ->toArray();
        }

        // Map items
        return $cards->map(function (ReviewCard $card) use ($chapters, $occurrenceChapters) {
            $sense = $card->sense;
            $senseId = $card->target_id;

            if (!$sense) {
                return null;
            }

            $occChapterId = $occurrenceChapters[$senseId] ?? null;
            $sourceChapterId = $sense->source_chapter_id ?? $occChapterId;

            $sourceKind = $this->inferSourceKind(
                $sense->source_chapter_id,
                $occChapterId,
                $sense->example_sentence_en
            );

            $sourceChapterTitle = null;
            if ($sourceChapterId && isset($chapters[$sourceChapterId])) {
                $sourceChapterTitle = $chapters[$sourceChapterId];
            }

            $displayStatus = $this->inferDisplayStatus($sourceChapterId, $occChapterId, $sense->example_sentence_en);

            return [
                'review_card_id' => $card->id,
                'word_sense_id' => $senseId,
                'lemma' => $sense->lemma,
                'surface_form' => $sense->surface_form,
                'pos' => $sense->pos,
                'sense_zh' => $sense->sense_zh,
                'sense_en' => $sense->sense_en,
                'example_sentence_en' => $sense->example_sentence_en,
                'example_sentence_zh' => $sense->example_sentence_zh,
                'source_chapter_id' => $sourceChapterId,
                'source_chapter_title' => $sourceChapterTitle,
                'source_kind' => $sourceKind,
                // New additive fields for display consistency
                'source_display_status' => $displayStatus,
                'source_display_label' => $this->displayLabel($displayStatus, $sourceChapterTitle),
                'fsrs_state' => $card->fsrs_state,
                'fsrs_due_at' => optional($card->fsrs_due_at)->toISOString(),
                'fsrs_stability' => $card->fsrs_stability,
                'fsrs_difficulty' => $card->fsrs_difficulty,
                'fsrs_reps' => $card->fsrs_reps,
                'fsrs_lapses' => $card->fsrs_lapses,
                'fsrs_last_reviewed_at' => optional($card->fsrs_last_reviewed_at)->toISOString(),
                'aliases_zh' => $sense->aliases_zh ?: [],
                'collocations' => $sense->collocations ?: [],
                'fsrs_enabled' => $card->fsrs_enabled,
                // ADR-0010: lifecycle fields (no audit metadata exposed)
                'lifecycle_state' => $card->lifecycle_state,
                'buried_until' => optional($card->buried_until)->toISOString(),
                'lifecycle_changed_at' => optional($card->lifecycle_changed_at)->toISOString(),
                'missing_definition' => empty($sense->sense_zh) && empty($sense->sense_en),
                'missing_example' => empty($sense->example_sentence_en),
                'missing_source' => empty($sense->source_chapter_id) && empty($occChapterId),
            ];
        })->filter()->values();
    }

    /**
     * Serialize a single ReviewCard + WordSense into the unified item array format.
     * Used by update / enabled / dueNow / reset responses.
     */
    public function serializeCard(ReviewCard $card, WordSense $sense): array
    {
        $occurrenceChapterId = WordSenseOccurrence::query()
            ->where('word_sense_id', $sense->id)
            ->where('user_id', $sense->user_id)
            ->where('language_id', $sense->language_id)
            ->where('status', WordSenseOccurrence::STATUS_BOUND)
            ->whereNotNull('chapter_id')
            ->value('chapter_id');

        $sourceChapterId = $sense->source_chapter_id ?? $occurrenceChapterId;
        $sourceKind = $this->inferSourceKind($sense->source_chapter_id, $occurrenceChapterId, $sense->example_sentence_en);

        $sourceChapterTitle = null;
        if ($sourceChapterId) {
            $sourceChapterTitle = Chapter::query()
                ->where('id', $sourceChapterId)
                ->where('user_id', $sense->user_id)
                ->where('language', $sense->language_id)
                ->value('name');
        }

        $displayStatus = $this->inferDisplayStatus($sourceChapterId, $occurrenceChapterId, $sense->example_sentence_en);

        return [
            'review_card_id' => $card->id,
            'word_sense_id' => $sense->id,
            'lemma' => $sense->lemma,
            'surface_form' => $sense->surface_form,
            'pos' => $sense->pos,
            'sense_zh' => $sense->sense_zh,
            'sense_en' => $sense->sense_en,
            'example_sentence_en' => $sense->example_sentence_en,
            'example_sentence_zh' => $sense->example_sentence_zh,
            'aliases_zh' => $sense->aliases_zh ?: [],
            'collocations' => $sense->collocations ?: [],
            'source_chapter_id' => $sourceChapterId,
            'source_chapter_title' => $sourceChapterTitle,
            'source_kind' => $sourceKind,
            // New additive fields for display consistency
            'source_display_status' => $displayStatus,
            'source_display_label' => $this->displayLabel($displayStatus, $sourceChapterTitle),
            'fsrs_state' => $card->fsrs_state,
            'fsrs_due_at' => optional($card->fsrs_due_at)->toISOString(),
            'fsrs_stability' => $card->fsrs_stability,
            'fsrs_difficulty' => $card->fsrs_difficulty,
            'fsrs_reps' => $card->fsrs_reps,
            'fsrs_lapses' => $card->fsrs_lapses,
            'fsrs_last_reviewed_at' => optional($card->fsrs_last_reviewed_at)->toISOString(),
            'fsrs_enabled' => $card->fsrs_enabled,
            // ADR-0010: lifecycle fields (no audit metadata exposed)
            'lifecycle_state' => $card->lifecycle_state,
            'buried_until' => optional($card->buried_until)->toISOString(),
            'lifecycle_changed_at' => optional($card->lifecycle_changed_at)->toISOString(),
            'missing_definition' => empty($sense->sense_zh) && empty($sense->sense_en),
            'missing_example' => empty($sense->example_sentence_en),
            'missing_source' => empty($sense->source_chapter_id) && empty($occurrenceChapterId),
        ];
    }

    /**
     * Infer a display-friendly status string:
     *  - 'real_chapter' — has a real chapter source (source_chapter_id or occurrence)
     *  - 'card_example_only' — no real chapter, but has example_sentence_en
     *  - 'missing' — no source at all
     */
    private function inferDisplayStatus(?int $sourceChapterId, ?int $occurrenceChapterId, ?string $exampleSentenceEn): string
    {
        if ($sourceChapterId || $occurrenceChapterId) {
            return 'real_chapter';
        }
        if (!empty($exampleSentenceEn)) {
            return 'card_example_only';
        }
        return 'missing';
    }

    private function displayLabel(string $status, ?string $chapterTitle): string
    {
        return match ($status) {
            'real_chapter' => $chapterTitle ?: '已定位原文',
            'card_example_only' => '保存例句（未定位原章节）',
            'missing' => '缺溯源',
            default => $status,
        };
    }

    private function inferSourceKind(?int $sourceChapterId, ?int $occurrenceChapterId, ?string $exampleSentenceEn): string
    {
        if ($sourceChapterId) {
            return 'chapter';
        }
        if ($occurrenceChapterId) {
            return 'occurrence_chapter';
        }
        if (!empty($exampleSentenceEn)) {
            return 'card_example';
        }
        return 'missing';
    }
}
