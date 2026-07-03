<?php

namespace App\Services;

use App\Models\Chapter;
use App\Models\ReadingInlineSenseConfirmation;
use App\Models\WordSense;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY writer for reading-inline-sense confirmations (ADR-0003).
 *
 * Safety contract:
 *  - Does NOT call ReviewLog::create / ReviewCardService::recordReview /
 *    ReviewCardService::resetCard / FsrsSchedulingService::schedule.
 *  - Does NOT modify any ReviewCard field (fsrs_state / fsrs_reps /
 *    fsrs_due_at / fsrs_stability / fsrs_difficulty / fsrs_lapses /
 *    fsrs_enabled / state / due_at).
 *  - Does NOT create WordSense.
 *  - Does NOT create ReviewCard.
 *  - Does NOT call AI.
 *  - Does NOT touch EncounteredWord lemma / stage.
 *
 * The only writes are INSERT / UPDATE on the `reading_inline_sense_confirmations`
 * table. No other table is mutated by this service.
 */
class ReadingInlineSenseConfirmationService
{
    /**
     * Persist (or update) the user's match / not_match choice for a
     * specific reading occurrence + candidate sense.
     *
     * @param array{
     *     user_id:int,
     *     language:string,
     *     chapter_id:int|null,
     *     sentence_index:int|null,
     *     sentence_hash:string|null,
     *     sentence_text:string|null,
     *     surface:string,
     *     lemma:string,
     *     word_sense_id:int,
     *     choice:string
     * } $data
     *
     * @return array{
     *     confirmation_id:int,
     *     choice:string,
     *     persisted:bool,
     *     safety_flags:array<string,bool>,
     *     updated_at:string|null
     * }
     */
    public function storeConfirmation(array $data): array
    {
        $userId = (int) $data['user_id'];
        $language = (string) $data['language'];
        $wordSenseId = (int) $data['word_sense_id'];
        $choice = (string) $data['choice'];

        // Defensive: only 'match' / 'not_match' allowed.
        if (!in_array($choice, [ReadingInlineSenseConfirmation::CHOICE_MATCH, ReadingInlineSenseConfirmation::CHOICE_NOT_MATCH], true)) {
            throw new \InvalidArgumentException('Invalid choice. Allowed: match, not_match.');
        }

        // Defensive: sense must exist and be confirmed for the current user/language.
        $sense = WordSense::query()
            ->where('id', $wordSenseId)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('status', WordSense::STATUS_CONFIRMED)
            ->first();
        if ($sense === null) {
            throw new \DomainException('WordSense not found or not confirmed for current user/language.');
        }

        // Defensive: chapter must belong to current user/language if provided.
        $chapterId = isset($data['chapter_id']) ? (int) $data['chapter_id'] : null;
        if ($chapterId !== null) {
            $chapter = Chapter::query()
                ->where('id', $chapterId)
                ->where('user_id', $userId)
                ->where('language', $language)
                ->first();
            if ($chapter === null) {
                throw new \DomainException('Chapter not found for current user/language.');
            }
        }

        $lemma = mb_strtolower(trim((string) $data['lemma']));
        $surface = trim((string) $data['surface']);
        if ($lemma === '' || $surface === '') {
            throw new \InvalidArgumentException('lemma and surface are required.');
        }

        $sentenceIndex = isset($data['sentence_index']) ? (int) $data['sentence_index'] : null;
        $sentenceHash = isset($data['sentence_hash']) ? (string) $data['sentence_hash'] : null;
        $sentenceText = isset($data['sentence_text']) ? (string) $data['sentence_text'] : null;

        // upsert: same occurrence + same sense → update choice instead of duplicate.
        $confirmation = DB::transaction(function () use (
            $userId, $language, $chapterId, $sentenceIndex, $sentenceHash, $sentenceText,
            $surface, $lemma, $wordSenseId, $choice
        ): ReadingInlineSenseConfirmation {
            $existing = ReadingInlineSenseConfirmation::query()
                ->where('user_id', $userId)
                ->where('language', $language)
                ->where('chapter_id', $chapterId)
                ->where('sentence_index', $sentenceIndex)
                ->where('surface', $surface)
                ->where('lemma', $lemma)
                ->where('word_sense_id', $wordSenseId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $existing->choice = $choice;
                $existing->source = ReadingInlineSenseConfirmation::SOURCE_READING_INLINE_PREVIEW;
                $existing->save();
                return $existing;
            }

            return ReadingInlineSenseConfirmation::create([
                'user_id' => $userId,
                'language' => $language,
                'chapter_id' => $chapterId,
                'sentence_index' => $sentenceIndex,
                'sentence_hash' => $sentenceHash,
                'sentence_text' => $sentenceText,
                'surface' => $surface,
                'lemma' => $lemma,
                'word_sense_id' => $wordSenseId,
                'choice' => $choice,
                'source' => ReadingInlineSenseConfirmation::SOURCE_READING_INLINE_PREVIEW,
            ]);
        });

        return [
            'confirmation_id' => $confirmation->id,
            'choice' => $confirmation->choice,
            'persisted' => true,
            'updated_at' => $confirmation->updated_at?->toISOString(),
            'safety_flags' => [
                'no_review_log_created' => true,
                'no_fsrs_changed' => true,
                'no_review_card_created' => true,
                'no_word_sense_created' => true,
                'no_ai_called' => true,
                'not_a_review_rating' => true,
            ],
        ];
    }

    /**
     * Read-only: return persisted confirmations for the given occurrence
     * key + candidate sense ids, keyed by word_sense_id.
     *
     * Used by the preview endpoint to echo the user's prior choices.
     *
     * @param list<int> $wordSenseIds
     * @return array<int, array{
     *     confirmation_id:int,
     *     word_sense_id:int,
     *     choice:string,
     *     updated_at:string|null
     * }>
     */
    public function listConfirmationsForOccurrence(
        int $userId,
        string $language,
        ?int $chapterId,
        ?int $sentenceIndex,
        string $surface,
        string $lemma,
        array $wordSenseIds = []
    ): array {
        if (empty($wordSenseIds)) {
            return [];
        }

        $query = ReadingInlineSenseConfirmation::query()
            ->where('user_id', $userId)
            ->where('language', $language)
            ->where('chapter_id', $chapterId)
            ->where('sentence_index', $sentenceIndex)
            ->where('surface', trim($surface))
            ->where('lemma', mb_strtolower(trim($lemma)))
            ->whereIn('word_sense_id', $wordSenseIds);

        $rows = $query->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->word_sense_id] = [
                'confirmation_id' => (int) $row->id,
                'word_sense_id' => (int) $row->word_sense_id,
                'choice' => $row->choice,
                'updated_at' => $row->updated_at?->toISOString(),
            ];
        }
        return $result;
    }

    /**
     * Read-only: return a per-sense usage summary aggregated across ALL
     * occurrences for the given candidate sense ids.
     *
     * Returned per sense_id:
     *  - match_count:       total match confirmations across all occurrences
     *  - not_match_count:   total not_match confirmations across all occurrences
     *  - last_choice:       the most recent choice ('match' | 'not_match' | null)
     *  - last_confirmed_at: ISO timestamp of the most recent confirmation (or null)
     *  - has_any_confirmation: true when at least one confirmation exists
     *  - recent_examples:   up to 3 recent confirmation rows (occurrence-level),
     *                        each with surface / lemma / choice / chapter_id /
     *                        sentence_index / updated_at. Only the current
     *                        user/language. Does NOT leak other users.
     *
     * Safety contract:
     *  - This method is strictly read-only. It does NOT write any table.
     *  - It does NOT call ReviewLog / FSRS / AI / WordSense / ReviewCard writes.
     *  - It is isolated by user_id + language.
     *
     * @param list<int> $wordSenseIds
     * @return array<int, array{
     *     match_count:int,
     *     not_match_count:int,
     *     last_choice:string|null,
     *     last_confirmed_at:string|null,
     *     has_any_confirmation:bool,
     *     recent_examples:list<array{
     *         surface:string,
     *         lemma:string,
     *         choice:string,
     *         chapter_id:int|null,
     *         sentence_index:int|null,
     *         updated_at:string|null
     *     }>
     * }>
     */
    public function summaryForSenseCandidates(
        int $userId,
        string $language,
        array $wordSenseIds
    ): array {
        if (empty($wordSenseIds)) {
            return [];
        }

        // Aggregate counts + last choice per sense.
        $rows = ReadingInlineSenseConfirmation::query()
            ->where('user_id', $userId)
            ->where('language', $language)
            ->whereIn('word_sense_id', $wordSenseIds)
            ->orderBy('updated_at', 'desc')
            ->get();

        $result = [];
        foreach ($wordSenseIds as $sid) {
            $result[(int) $sid] = [
                'match_count' => 0,
                'not_match_count' => 0,
                'last_choice' => null,
                'last_confirmed_at' => null,
                'has_any_confirmation' => false,
                'recent_examples' => [],
            ];
        }

        foreach ($rows as $row) {
            $sid = (int) $row->word_sense_id;
            if (!isset($result[$sid])) {
                // Defensive: sense id not requested — skip (shouldn't happen due to whereIn).
                continue;
            }
            $entry = &$result[$sid];
            if ($row->choice === ReadingInlineSenseConfirmation::CHOICE_MATCH) {
                $entry['match_count']++;
            } elseif ($row->choice === ReadingInlineSenseConfirmation::CHOICE_NOT_MATCH) {
                $entry['not_match_count']++;
            }
            $entry['has_any_confirmation'] = true;
            // Rows are ordered by updated_at desc, so the first row encountered
            // for each sense is the most recent.
            if ($entry['last_choice'] === null) {
                $entry['last_choice'] = $row->choice;
                $entry['last_confirmed_at'] = $row->updated_at?->toISOString();
            }
            // Collect up to 3 recent examples per sense.
            if (count($entry['recent_examples']) < 3) {
                $entry['recent_examples'][] = [
                    'surface' => $row->surface,
                    'lemma' => $row->lemma,
                    'choice' => $row->choice,
                    'chapter_id' => $row->chapter_id !== null ? (int) $row->chapter_id : null,
                    'sentence_index' => $row->sentence_index !== null ? (int) $row->sentence_index : null,
                    'updated_at' => $row->updated_at?->toISOString(),
                ];
            }
        }
        unset($entry);

        return $result;
    }
}
