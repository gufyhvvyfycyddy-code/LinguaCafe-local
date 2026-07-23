<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;

class AiStudyCardSourceBindingService
{
    public function bind(WordSense $sense, ?ReviewCard $card, array $candidate): array
    {
        $word = (string) ($candidate['word'] ?? '');
        $surface = (string) ($candidate['surface'] ?? $word);
        $chapterId = $candidate['chapter_id'] ?? null;
        $sentenceId = $candidate['sentence_id'] ?? null;
        $sentenceText = (string) ($candidate['sentence_text'] ?? '');
        $textBlockIndex = $candidate['text_block_index'] ?? null;
        $sentenceIndex = $candidate['sentence_index'] ?? null;
        $occurrenceCreated = false;
        $occurrenceId = null;
        $occurrenceReason = null;
        $effectiveSentenceId = $sentenceId;

        if ($sentenceText === '') {
            $occurrenceReason = 'no_sentence_text';
        } elseif ($chapterId === null) {
            $occurrenceReason = 'no_chapter_id';
        } elseif ($textBlockIndex === null
            && $sentenceIndex === null
            && ($sentenceId === null || $sentenceId === '')) {
            $occurrenceReason = 'insufficient_source_info';
        } else {
            if ($effectiveSentenceId === null || $effectiveSentenceId === '') {
                $textBlock = $textBlockIndex ?? 0;
                $sentence = $sentenceIndex ?? 0;
                $normalizedWord = mb_strtolower(trim($word), 'UTF-8');
                $effectiveSentenceId = 'ai-study-card:'
                    . $chapterId . ':' . $textBlock . ':' . $sentence . ':' . $normalizedWord;
                $occurrenceReason = 'synthetic_sentence_id';
            } else {
                $occurrenceReason = 'explicit_sentence_id';
            }

            $occurrence = WordSenseOccurrence::updateOrCreate(
                [
                    'user_id' => $sense->user_id,
                    'language_id' => $sense->language_id,
                    'word_sense_id' => $sense->id,
                    'chapter_id' => $chapterId,
                    'sentence_id' => (string) $effectiveSentenceId,
                    'surface' => $surface,
                    'source' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
                ],
                [
                    'language' => $sense->language,
                    'review_card_id' => $card?->id,
                    'sentence_en' => $sentenceText,
                    'sentence_zh' => null,
                    'type' => WordSenseOccurrence::TYPE_WORD,
                    'lemma' => $sense->lemma,
                    'pos' => $sense->pos,
                    'decision' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
                    'confidence' => 1.0,
                    'evidence' => ['source' => 'ai_study_card_confirmed_candidate'],
                    'auto_fsrs_allowed' => true,
                    'status' => WordSenseOccurrence::STATUS_BOUND,
                    'raw_payload' => [
                        'sense_zh' => $sense->sense_zh,
                        'sense_en' => $sense->sense_en,
                        'aliases_zh' => $sense->aliases_zh ?: [],
                        'collocations' => $sense->collocations ?: [],
                        'confirmed_from' => 'ai_study_card_candidate',
                        'sentence_id_source' => $occurrenceReason,
                    ],
                ]
            );
            $occurrenceCreated = true;
            $occurrenceId = $occurrence->id;
        }

        return [
            'occurrence_created' => $occurrenceCreated,
            'occurrence_id' => $occurrenceId,
            'occurrence_reason' => $occurrenceReason,
            'effective_sentence_id' => $effectiveSentenceId,
            'source_binding_status' => $this->status($occurrenceCreated, $occurrenceReason),
        ];
    }

    private function status(bool $occurrenceCreated, ?string $reason): string
    {
        if ($occurrenceCreated) {
            return $reason === 'synthetic_sentence_id'
                ? '来源已绑定（合成 sentence_id）'
                : '来源已绑定';
        }

        return '来源信息不足，已创建卡片但未绑定来源';
    }
}
