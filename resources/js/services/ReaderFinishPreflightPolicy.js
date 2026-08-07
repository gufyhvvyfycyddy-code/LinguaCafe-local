const READING_SESSION_STORAGE_KEY_PREFIX = 'linguacafe-reading-session-id';

function randomSessionId() {
    if (typeof crypto !== 'undefined' && crypto && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    return `reading-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}

export function getOrCreateReadingSessionId(
    chapterId,
    storage = (typeof sessionStorage !== 'undefined' ? sessionStorage : null),
) {
    const normalizedChapterId = Number(chapterId);
    if (!Number.isInteger(normalizedChapterId) || normalizedChapterId <= 0) {
        return randomSessionId();
    }
    if (!storage) return randomSessionId();
    const key = `${READING_SESSION_STORAGE_KEY_PREFIX}:${normalizedChapterId}`;
    const existing = storage.getItem(key);
    if (existing) return existing;
    const created = randomSessionId();
    storage.setItem(key, created);
    return created;
}

export function createReaderReadingInteractionState(readingSessionId = '') {
    return {
        readingSessionId,
        openedOccurrenceIds: [],
        reviewedOccurrenceIds: [],
    };
}

function addUnique(list, value) {
    if (!value || list.includes(value)) return [...list];
    return [...list, value];
}

export function markReaderOccurrenceOpened(state, occurrenceId) {
    return {
        ...state,
        openedOccurrenceIds: addUnique(state?.openedOccurrenceIds || [], occurrenceId),
    };
}

export function markReaderOccurrenceReviewed(state, occurrenceId) {
    return {
        ...state,
        openedOccurrenceIds: addUnique(state?.openedOccurrenceIds || [], occurrenceId),
        reviewedOccurrenceIds: addUnique(state?.reviewedOccurrenceIds || [], occurrenceId),
    };
}

export function buildReaderFinishPreflightRequest(state, chapterId) {
    return {
        chapter_id: Number(chapterId),
        reading_session_id: state?.readingSessionId || '',
        explicitly_opened_occurrence_ids: [...(state?.openedOccurrenceIds || [])],
        explicitly_reviewed_occurrence_ids: [...(state?.reviewedOccurrenceIds || [])],
    };
}

export function normalizeReaderFinishPreflight(payload = {}) {
    const source = payload.preflight || payload;
    const count = value => {
        const number = Number(value || 0);
        return Number.isFinite(number) && number >= 0 ? number : 0;
    };
    return {
        passiveGoodCount: count(source.passive_good_count),
        pendingConfirmationCount: count(source.pending_confirmation_count || source.pending_count),
        excludedCount: count(source.excluded_count),
        alreadySettledCount: count(source.already_settled_count),
        canFinish: source.can_finish !== false,
        message: source.message || '',
        backendAvailable: true,
        raw: payload,
    };
}

export function readerFinishPreflightUnavailable(message = '无法确认本次阅读结算，请稍后重试。当前没有被动评分被提交。') {
    return {
        passiveGoodCount: 0,
        pendingConfirmationCount: 0,
        excludedCount: 0,
        alreadySettledCount: 0,
        canFinish: false,
        message,
        backendAvailable: false,
        raw: null,
    };
}
