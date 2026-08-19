export const READER_INLINE_RATINGS = Object.freeze(['again', 'good']);

function readerIntentText(value) {
    return typeof value === 'string' ? value.trim() : '';
}

export function createReaderInlineReviewIntent(readingSessionId = '', sourceRevision = '', occurrence = null) {
    const occurrenceId = readerIntentText(occurrence && occurrence.occurrence_id);
    const sessionId = readerIntentText(readingSessionId);
    const revision = readerIntentText(sourceRevision);
    if (!sessionId || !revision || !occurrenceId) return null;
    return { readingSessionId: sessionId, sourceRevision: revision, occurrenceId };
}

export function readerInlineReviewIntentMatches(expected = null, current = null) {
    return Boolean(
        expected
        && current
        && expected.readingSessionId === current.readingSessionId
        && expected.sourceRevision === current.sourceRevision
        && expected.occurrenceId === current.occurrenceId,
    );
}

export function freezeReaderInlineRatingIntent(expected = null, current = null, rating = '') {
    if (!readerInlineReviewIntentMatches(expected, current) || !READER_INLINE_RATINGS.includes(rating)) return null;
    return { ...expected, rating };
}

export function readerInlineRatingIntentMatches(expected = null, current = null, rating = '') {
    return Boolean(
        readerInlineReviewIntentMatches(expected, current)
        && READER_INLINE_RATINGS.includes(expected.rating)
        && expected.rating === rating,
    );
}

export function resolveReaderInteractionAttempt(existing = null, readingSessionId = '', sourceRevision = '', pendingPromise = null) {
    const sameIdentity = Boolean(
        existing
        && readerIntentText(existing.sessionId) === readerIntentText(readingSessionId)
        && readerIntentText(existing.sourceRevision) === readerIntentText(sourceRevision),
    );
    if (sameIdentity && existing.status === 'acknowledged') return { kind: 'acknowledged', promise: null };
    if (sameIdentity && existing.status === 'pending' && pendingPromise) return { kind: 'pending', promise: pendingPromise };
    return { kind: 'write', promise: null };
}

export async function awaitReaderInlineOpenedBarrier(intent, recordOpened, getCurrentIntent) {
    if (!intent || typeof recordOpened !== 'function' || typeof getCurrentIntent !== 'function') return 'unconfirmed';
    try {
        const acknowledged = await recordOpened();
        if (acknowledged !== true) return 'unconfirmed';
        return readerInlineReviewIntentMatches(intent, getCurrentIntent()) ? 'acknowledged' : 'stale';
    } catch (_) {
        return 'unconfirmed';
    }
}

export function readerInlineOpenedFailureMessage(entry = null) {
    const code = readerIntentText(entry && entry.errorCode);
    if (code === 'READING_SESSION_NOT_ACTIVE') {
        return '本次阅读已经在服务器结束。已停止打开词义复习，请刷新本章后再开始新的阅读。';
    }
    if (code === 'READING_SESSION_STALE_SOURCE') {
        return '文章内容已经变化。当前词的位置已失效，请刷新本章后再继续词义复习。';
    }
    if (entry && entry.outcomeUnknown === true) {
        return '服务器还没有确认你正在复习这个词。已停止打开评分，请检查网络后重试。';
    }
    return '服务器没有确认当前词的复习状态。已停止打开评分，请重新点开这个词后重试。';
}

export function normalizeReaderManualSensePos(value) {
    const normalized = String(value || '').trim().toLowerCase();
    const aliases = {
        noun: 'noun', n: 'noun',
        verb: 'verb', v: 'verb',
        adjective: 'adjective', adj: 'adjective',
        adverb: 'adverb', adv: 'adverb',
        preposition: 'preposition', prep: 'preposition', adp: 'preposition',
        conjunction: 'conjunction', conj: 'conjunction', cconj: 'conjunction', sconj: 'conjunction',
        phrase: 'phrase',
        other: 'other',
    };
    return aliases[normalized] || 'other';
}

export function createReaderInlineSenseReviewState(occurrence = null) {
    return {
        occurrence,
        showAnswer: false,
        pendingRating: null,
        selectedWordSenseId: null,
        wasHelped: false,
        busy: false,
        error: '',
    };
}

export function revealReaderInlineSenseAnswer(state) {
    if (!state || !state.occurrence) return createReaderInlineSenseReviewState();
    return { ...state, showAnswer: true, pendingRating: null, wasHelped: true };
}

export function chooseReaderInlineRating(state, rating) {
    if (!state || !state.occurrence || !READER_INLINE_RATINGS.includes(rating)) {
        return state;
    }
    if (rating === 'good' && state.wasHelped) return state;
    return { ...state, showAnswer: true, pendingRating: rating };
}

export function chooseReaderInlineSense(state, wordSenseId) {
    if (!state || !state.occurrence) return state;
    const id = Number(wordSenseId);
    if (!Number.isInteger(id) || id <= 0) return { ...state, selectedWordSenseId: null };
    return { ...state, selectedWordSenseId: id };
}

export function clearReaderInlinePendingRating(state) {
    if (!state) return createReaderInlineSenseReviewState();
    return { ...state, showAnswer: false, pendingRating: null, selectedWordSenseId: null, wasHelped: false, busy: false, error: '' };
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
