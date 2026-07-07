<?php

namespace App\Http\Controllers;

use App\Services\ReadingInlineSenseConfirmationService;
use App\Services\WordSenseKnownSenseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ReadingInlineSenseConfirmationController extends Controller
{
    public function __construct(
        private ReadingInlineSenseConfirmationService $inlineConfirmationService,
        private WordSenseKnownSenseService $knownSenseService,
    )
    {
    }

    /**
     * Persist (or update) the user's match / not_match choice for a
     * reading-inline sense candidate (ADR-0003).
     *
     * This endpoint is the ONLY write entrypoint for the
     * `reading_inline_sense_confirmations` table. It does NOT write
     * ReviewLog, does NOT change FSRS, does NOT create WordSense /
     * ReviewCard, does NOT call AI. The choice is NOT a review rating.
     */
    public function storeInlineConfirmation(Request $request)
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        $data = $request->validate([
            'language' => ['sometimes', 'string'],
            'lemma' => ['required', 'string'],
            'surface' => ['required', 'string'],
            'chapter_id' => ['nullable', 'integer'],
            'sentence_index' => ['nullable', 'integer'],
            'sentence_hash' => ['nullable', 'string'],
            'sentence_text' => ['nullable', 'string'],
            'word_sense_id' => ['required', 'integer'],
            'choice' => ['required', Rule::in(['match', 'not_match'])],
        ]);

        if (isset($data['language']) && $data['language'] !== $language) {
            abort(403, 'Language does not match the selected language.');
        }

        $result = $this->inlineConfirmationService->storeConfirmation([
            'user_id' => $userId,
            'language' => $language,
            'chapter_id' => $data['chapter_id'] ?? null,
            'sentence_index' => $data['sentence_index'] ?? null,
            'sentence_hash' => $data['sentence_hash'] ?? null,
            'sentence_text' => $data['sentence_text'] ?? null,
            'surface' => $data['surface'],
            'lemma' => $data['lemma'],
            'word_sense_id' => $data['word_sense_id'],
            'choice' => $data['choice'],
        ]);

        // Also return the updated preview payload so the frontend can refresh
        // the echoed persisted_choice without a second round-trip.
        $previewPayload = $this->knownSenseService->previewInlineSenseCandidates(
            $userId,
            $language,
            $data['lemma'],
            $data['surface'],
            $data['sentence_text'] ?? '',
            $data['chapter_id'] ?? null,
            $data['sentence_index'] ?? null
        );

        return response()->json([
            'confirmation_id' => $result['confirmation_id'],
            'choice' => $result['choice'],
            'persisted' => $result['persisted'],
            'updated_at' => $result['updated_at'],
            'safety_flags' => $result['safety_flags'],
            'updated_preview' => $previewPayload,
            'undo_token' => $result['undo_token'],
            'undo_expires_at' => $result['undo_expires_at'],
            'undo_hint' => $result['undo_hint'],
        ]);
    }

    /**
     * Read-only management list: return the current user's reading-inline
     * confirmations with filters + WordSense / Chapter summary
     * (ADR-0003 Management Surface Layer).
     *
     * This endpoint is READ-ONLY. It does NOT write any table. It does NOT
     * call ReviewLog / FSRS / AI. It is isolated by user + language.
     */
    public function listInlineConfirmations(Request $request)
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        if ($request->query('language') && $request->query('language') !== $language) {
            abort(403, 'Language does not match the selected language.');
        }

        $filters = [
            'choice' => $request->query('choice', 'all'),
            'lemma' => $request->query('lemma'),
            'surface' => $request->query('surface'),
            'word_sense_id' => $request->query('word_sense_id') !== null ? (int) $request->query('word_sense_id') : null,
            'chapter_id' => $request->query('chapter_id') !== null ? (int) $request->query('chapter_id') : null,
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'per_page' => (int) $request->query('per_page', 20),
        ];

        return response()->json(
            $this->inlineConfirmationService->listConfirmationsForManagement($userId, $language, $filters)
        );
    }

    /**
     * Revoke (delete) a single reading-inline confirmation owned by the
     * current user + current language (ADR-0003 Management Surface Layer).
     *
     * This endpoint ONLY deletes a row in `reading_inline_sense_confirmations`.
     * It does NOT delete WordSense / ReviewCard / ReviewLog / EncounteredWord.
     * It does NOT call ReviewLog::create / FSRS / AI. It is NOT a review rating.
     *
     * Returns a backend-signed `undo_token` so the user can press Ctrl+Z
     * to restore the revoked row (OpenCode-ReadingInlineConfirmationUndoHotkey-800-1).
     */
    public function revokeInlineConfirmation(int $id)
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        $result = $this->inlineConfirmationService->revokeConfirmation($userId, $language, $id);

        return response()->json($result);
    }

    /**
     * Undo the most recent reading-inline-confirmation action described
     * by a backend-signed undo token
     * (OpenCode-ReadingInlineConfirmationUndoHotkey-800-1, ADR-0003 Undo Hotkey Layer).
     *
     * The token is returned by `POST /senses/inline-confirmation` (store)
     * or `DELETE /senses/inline-confirmations/{id}` (revoke) and is short-lived.
     *
     * This endpoint ONLY performs INSERT / UPDATE / DELETE on the
     * `reading_inline_sense_confirmations` table. It does NOT write
     * ReviewLog, does NOT change FSRS, does NOT create / delete
     * WordSense / ReviewCard, does NOT call AI. It is NOT a review rating.
     */
    public function undoInlineConfirmation(Request $request)
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        $data = $request->validate([
            'undo_token' => ['required', 'string'],
            // Optional: lemma / surface / chapter_id / sentence_index allow
            // the backend to also return an updated preview payload for the
            // reading page so the frontend can refresh without a second
            // round-trip.
            'lemma' => ['sometimes', 'string'],
            'surface' => ['sometimes', 'string'],
            'sentence' => ['sometimes', 'string'],
            'chapter_id' => ['sometimes', 'nullable', 'integer'],
            'sentence_index' => ['sometimes', 'nullable', 'integer'],
        ]);

        $result = $this->inlineConfirmationService->undoLastInlineConfirmationAction(
            $userId,
            $language,
            (string) $data['undo_token']
        );

        // If the caller passed lemma/surface/sentence/chapter/sentence_index,
        // also return an updated preview payload so the reading page can
        // refresh the echoed persisted_choice without a second round-trip.
        if (isset($data['lemma']) && $data['lemma'] !== '') {
            $previewPayload = $this->knownSenseService->previewInlineSenseCandidates(
                $userId,
                $language,
                (string) $data['lemma'],
                (string) ($data['surface'] ?? ''),
                (string) ($data['sentence'] ?? ''),
                isset($data['chapter_id']) ? (int) $data['chapter_id'] : null,
                isset($data['sentence_index']) ? (int) $data['sentence_index'] : null
            );
            $result['updated_preview'] = $previewPayload;
        }

        return response()->json($result);
    }
}
