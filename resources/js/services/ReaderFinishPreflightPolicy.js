import {
    buildReaderFinishRequest,
    clearReadingSessionRecoveryId,
    loadReadingSessionRecoveryId,
    normalizeReaderFinishResult,
    saveReadingSessionRecoveryId,
} from './ReaderRecoveryPolicy.js';

// Kept as a compatibility export for existing Reader tests/imports. The name is
// historical: R3 never creates an ID on the client. It only returns a
// server-issued recovery pointer already stored for this chapter.
export function getOrCreateReadingSessionId(chapterId, storage = (typeof sessionStorage !== 'undefined' ? sessionStorage : null)) {
    return loadReadingSessionRecoveryId(chapterId, storage);
}

export function rememberServerReadingSessionId(chapterId, readingSessionId, storage = (typeof sessionStorage !== 'undefined' ? sessionStorage : null)) {
    return saveReadingSessionRecoveryId(chapterId, readingSessionId, storage);
}

export function forgetServerReadingSessionId(chapterId, storage = (typeof sessionStorage !== 'undefined' ? sessionStorage : null)) {
    return clearReadingSessionRecoveryId(chapterId, storage);
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

// R3 Finish authority lives on the server. This helper only adds the opaque
// server session and settlement mode to the legacy chapter-finish payload.
export function buildReaderFinishPreflightRequest(state, chapterId, basePayload = {}) {
    return buildReaderFinishRequest(
        { ...basePayload, chapter_id: Number(chapterId) },
        state?.readingSessionId || '',
        'preflight',
    );
}

export function normalizeReaderFinishPreflight(payload = {}) {
    const normalized = normalizeReaderFinishResult(payload);
    return {
        passiveGoodCount: normalized.passiveGoodCount,
        pendingConfirmationCount: normalized.unresolvedCount,
        unresolvedCount: normalized.unresolvedCount,
        unresolvedOccurrenceIds: normalized.unresolvedOccurrenceIds,
        excludedCount: normalized.excludedCount,
        alreadySettledCount: normalized.alreadySettledCount,
        canFinish: normalized.canCommit,
        canCommit: normalized.canCommit,
        completed: normalized.completed,
        preflightRequired: normalized.preflightRequired,
        message: payload.message || '',
        backendAvailable: true,
        raw: payload,
    };
}

export function readerFinishPreflightUnavailable(message = '无法确认本次阅读结算。服务器结果目前未知；已保留阅读会话，恢复网络后可安全重试。') {
    return {
        passiveGoodCount: 0,
        pendingConfirmationCount: 0,
        unresolvedCount: 0,
        unresolvedOccurrenceIds: [],
        excludedCount: 0,
        alreadySettledCount: 0,
        canFinish: false,
        canCommit: false,
        completed: false,
        preflightRequired: true,
        message,
        backendAvailable: false,
        outcomeUnknown: true,
        raw: null,
    };
}
