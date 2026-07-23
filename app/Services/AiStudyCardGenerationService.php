<?php

namespace App\Services;

use App\Models\User;
use App\Models\WordSense;
use Illuminate\Support\Facades\DB;
use Throwable;

class AiStudyCardGenerationService
{
    public function __construct(
        private WordSenseService $wordSenseService,
        private AiStudyCardCandidateValidationService $candidateValidationService,
        private AiStudyCardSourceBindingService $sourceBindingService,
        private AiStudyCardPendingLifecycleService $pendingLifecycleService
    ) {
    }

    public function generate(User $user, array $confirmedItems, array $finalCandidatesPackage): array
    {
        $validationPreparation = $this->candidateValidationService->prepare(
            $user,
            $confirmedItems,
            $finalCandidatesPackage
        );
        if (!$validationPreparation['success']) {
            return $validationPreparation;
        }
        $validationContext = $validationPreparation['context'];
        $language = $user->selected_language;

        $created = [];
        $skipped = [];
        $duplicate = [];
        $failed = [];

        foreach ($confirmedItems as $confirmedItem) {
            try {
                $validation = $this->candidateValidationService->validate($confirmedItem, $validationContext);
                if (!$validation['success']) {
                    $skipped[] = $validation['skipped'];
                    continue;
                }
                $candidate = $validation['candidate'];
                $source = $candidate['source'];
                $word = $candidate['word'];
                $senseZh = $candidate['sense_zh'];
                $lemma = $candidate['lemma'];
                $surface = $candidate['surface'];
                $chapterId = $candidate['chapter_id'];
                $itemId = $candidate['item_id'];
                $sentenceId = $candidate['sentence_id'];
                $sentenceText = $candidate['sentence_text'];

                $result = DB::transaction(function () use (
                    $user,
                    $language,
                    $candidate,
                    $word,
                    $lemma,
                    $surface,
                    $senseZh,
                    $chapterId,
                    $sentenceId,
                    $sentenceText
                ) {
                    $senseData = [
                        'user_id' => $user->id,
                        'language' => $language,
                        'language_id' => $language,
                        'lemma' => $lemma,
                        'surface_form' => $surface,
                        'pos' => $candidate['pos'] ?? null,
                        'sense_zh' => $senseZh,
                        'sense_en' => $candidate['sense_en'],
                        'aliases_zh' => $candidate['aliases_zh'] ?? [],
                        'collocations' => $candidate['collocations'] ?? [],
                        'example_sentence_en' => $sentenceText !== '' ? $sentenceText : null,
                        'example_sentence_zh' => null,
                        'source_chapter_id' => $chapterId,
                        'sentence_id' => $sentenceId,
                        'status' => WordSense::STATUS_CONFIRMED,
                    ];

                    $sense = $this->wordSenseService->createOrFindSense($senseData);
                    $senseWasNewlyCreated = $sense->wasRecentlyCreated;
                    $senseNeededUpgrade = !$senseWasNewlyCreated
                        && $sense->status !== WordSense::STATUS_CONFIRMED;
                    if ($senseNeededUpgrade) {
                        $this->wordSenseService->confirmSense($sense);
                        $sense->refresh();
                    }

                    $card = $this->wordSenseService->createReviewCardForSense($sense);
                    $cardWasNewlyCreated = $card ? $card->wasRecentlyCreated : false;
                    $sourceBinding = $this->sourceBindingService->bind($sense, $card, $candidate);
                    $isCreated = $senseWasNewlyCreated || $senseNeededUpgrade || $cardWasNewlyCreated;

                    return array_merge([
                        'sense' => $sense,
                        'card' => $card,
                        'is_created' => $isCreated,
                    ], $sourceBinding);
                });

                $baseResult = [
                    'source' => $source,
                    'item_id' => $itemId,
                    'word' => $word,
                    'lemma' => $result['sense']->lemma,
                    'sense_id' => $result['sense']->id,
                    'review_card_id' => $result['card']?->id,
                    'occurrence_created' => $result['occurrence_created'],
                    'occurrence_id' => $result['occurrence_id'],
                    'source_binding_status' => $result['source_binding_status'],
                    'source_binding_reason' => $result['occurrence_reason'],
                ];

                if ($source === 'user_selected' && $itemId) {
                    $lifecycle = $this->pendingLifecycleService->markProcessed(
                        $user,
                        $language,
                        $itemId,
                        $result['is_created'] ? 'created' : 'duplicate'
                    );
                } else {
                    $lifecycle = $this->pendingLifecycleService->emptyInfo();
                }
                $baseResult = array_merge($baseResult, $lifecycle);

                if ($result['is_created']) {
                    $baseResult['is_new_sense'] = !$result['sense']->exists
                        || $result['sense']->wasRecentlyCreated;
                    $baseResult['is_new_card'] = $result['card']
                        ? $result['card']->wasRecentlyCreated
                        : false;
                    $created[] = $baseResult;
                } else {
                    $baseResult['reason'] = 'sense_and_card_already_exist';
                    $duplicate[] = $baseResult;
                }
            } catch (Throwable $exception) {
                $failedItemId = !empty($confirmedItem['item_id'])
                    ? (int) $confirmedItem['item_id']
                    : null;
                $failed[] = [
                    'source' => $confirmedItem['source'] ?? '',
                    'word' => $confirmedItem['word'] ?? '',
                    'reason' => 'exception: ' . $exception->getMessage(),
                    'pending_item_id' => $failedItemId,
                    'pending_item_status_before' => null,
                    'pending_item_status_after' => null,
                    'pending_item_processed' => false,
                    'pending_item_process_reason' => null,
                ];
            }
        }

        $summary = [
            'total' => count($confirmedItems),
            'created_count' => count($created),
            'skipped_count' => count($skipped),
            'duplicate_count' => count($duplicate),
            'failed_count' => count($failed),
        ];

        return [
            'success' => true,
            'message' => sprintf(
                '已生成 %d 张学习卡，跳过 %d 项，重复 %d 项，失败 %d 项。',
                $summary['created_count'],
                $summary['skipped_count'],
                $summary['duplicate_count'],
                $summary['failed_count']
            ),
            'results' => [
                'created' => $created,
                'skipped' => $skipped,
                'duplicate' => $duplicate,
                'failed' => $failed,
                'summary' => $summary,
            ],
            'safety_flags' => [
                'no_ai_called_by_linguacafe' => true,
                'ai_response_pasted_by_user' => true,
                'no_review_log_written' => true,
                'no_fsrs_rescheduled' => true,
                'no_legacy_word_card_created' => true,
                'user_confirmation_received' => true,
            ],
        ];
    }
}
