export const READER_INLINE_RATINGS = Object.freeze(['again', 'hard', 'good', 'easy']);

export function createReaderInlineSenseReviewState(occurrence = null) {
    return {
        occurrence,
        showAnswer: false,
        pendingRating: null,
        selectedWordSenseId: null,
        busy: false,
        error: '',
    };
}

export function revealReaderInlineSenseAnswer(state) {
    if (!state || !state.occurrence) return createReaderInlineSenseReviewState();
    return { ...state, showAnswer: true, pendingRating: null };
}

export function chooseReaderInlineRating(state, rating) {
    if (!state || !state.occurrence || !state.showAnswer || !READER_INLINE_RATINGS.includes(rating)) {
        return state;
    }
    return { ...state, pendingRating: rating };
}

export function chooseReaderInlineSense(state, wordSenseId) {
    if (!state || !state.occurrence) return state;
    const id = Number(wordSenseId);
    if (!Number.isInteger(id) || id <= 0) return { ...state, selectedWordSenseId: null };
    return { ...state, selectedWordSenseId: id };
}

export function clearReaderInlinePendingRating(state) {
    if (!state) return createReaderInlineSenseReviewState();
    return { ...state, showAnswer: false, pendingRating: null, selectedWordSenseId: null, busy: false, error: '' };
}

export function replaceReaderInlineOccurrence(state, occurrence) {
    if (state?.occurrence?.occurrence_id === occurrence?.occurrence_id) return state;
    return createReaderInlineSenseReviewState(occurrence || null);
}

export function buildReaderInlineOfficialRatingCommand(state, candidates = [], readingSessionId = '') {
    const occurrenceId = state?.occurrence?.occurrence_id || '';
    if (!occurrenceId || !readingSessionId || !state.showAnswer || !READER_INLINE_RATINGS.includes(state.pendingRating)) return null;
    const selectedId = Number(state.selectedWordSenseId);
    const candidate = (Array.isArray(candidates) ? candidates : []).find(
        item => Number(item.word_sense_id || item.sense_id) === selectedId,
    );
    const reviewCardId = Number(candidate && candidate.review_card_id);
    if (!candidate || !Number.isInteger(reviewCardId) || reviewCardId <= 0 || candidate.fsrs_enabled === false) return null;

    return {
        reviewCardId,
        wordSenseId: selectedId,
        payload: {
            rating: state.pendingRating,
            reading_session_id: readingSessionId,
            occurrence_id: occurrenceId,
        },
        occurrenceId,
    };
}
