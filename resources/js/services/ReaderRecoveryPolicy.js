const SESSION_KEY_PREFIX = 'linguacafe-reading-session-id';
const MANUAL_SENSE_CONTINUATION_KEY_PREFIX = 'linguacafe-reader-manual-sense-continuation';
const EXPLICIT_RATING_RETRY_KEY_PREFIX = 'linguacafe-reader-explicit-rating-retry';

function positiveInteger(value) {
    const number = Number(value);
    return Number.isInteger(number) && number > 0 ? number : null;
}

function nonNegativeInteger(value) {
    const number = Number(value);
    return Number.isInteger(number) && number >= 0 ? number : null;
}

function text(value) {
    return typeof value === 'string' ? value.trim() : '';
}

function normalizeUuid(value) {
    const normalized = text(value).toLowerCase();
    return /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(normalized)
        ? normalized
        : '';
}

function defaultCryptoSource() {
    return typeof globalThis !== 'undefined' && globalThis.crypto ? globalThis.crypto : null;
}

export function createReaderActionId(cryptoSource = defaultCryptoSource()) {
    if (!cryptoSource) return '';
    if (typeof cryptoSource.randomUUID === 'function') {
        try {
            const generated = normalizeUuid(cryptoSource.randomUUID());
            if (generated) return generated;
        } catch (_) {
            // Fall through to getRandomValues when randomUUID is unavailable at runtime.
        }
    }
    if (typeof cryptoSource.getRandomValues !== 'function') return '';
    try {
        const bytes = new Uint8Array(16);
        cryptoSource.getRandomValues(bytes);
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        const hex = Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('');
        return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
    } catch (_) {
        return '';
    }
}

export function createReaderRequestId(cryptoSource = defaultCryptoSource()) {
    return createReaderActionId(cryptoSource);
}

export function readerExplicitRatingCommandMatchesSession(command = {}, readingSessionId = '', sourceRevision = '') {
    const payload = command.payload && typeof command.payload === 'object' ? command.payload : {};
    return Boolean(
        positiveInteger(command.reviewCardId)
        && text(command.occurrenceId)
        && text(command.occurrenceId) === text(payload.occurrence_id)
        && text(payload.reading_session_id)
        && text(payload.reading_session_id) === text(readingSessionId)
        && text(command.sourceRevision)
        && text(command.sourceRevision) === text(sourceRevision),
    );
}

export function buildReaderExplicitRatingActionCommand(
    command = {},
    readingActionId = '',
    cryptoSource = defaultCryptoSource(),
) {
    const payload = command.payload && typeof command.payload === 'object' ? command.payload : {};
    const rawExistingActionId = text(payload.reading_action_id);
    const rawRecoveredActionId = text(readingActionId);
    const existingActionId = rawExistingActionId ? normalizeUuid(rawExistingActionId) : '';
    const recoveredActionId = rawRecoveredActionId ? normalizeUuid(rawRecoveredActionId) : '';
    if ((rawExistingActionId && !existingActionId) || (rawRecoveredActionId && !recoveredActionId)) return null;
    const actionId = existingActionId || recoveredActionId || createReaderActionId(cryptoSource);
    if (!actionId) return null;
    return {
        ...command,
        payload: {
            ...payload,
            reading_action_id: actionId,
        },
    };
}

export function readerExplicitActionConflictCode(responseData = {}) {
    const code = text(responseData && (responseData.error_code || responseData.code));
    return ['READING_EXPLICIT_ACTION_UNDONE', 'READING_EXPLICIT_ACTION_ACTIVE'].includes(code) ? code : '';
}

export function readerRecoveryStorageKey(chapterId) {
    const id = positiveInteger(chapterId);
    return id ? `${SESSION_KEY_PREFIX}:${id}` : '';
}

export function loadReadingSessionRecoveryId(
    chapterId,
    storage = (typeof sessionStorage !== 'undefined' ? sessionStorage : null),
) {
    const key = readerRecoveryStorageKey(chapterId);
    if (!key || !storage) return '';
    return text(storage.getItem(key));
}

export function saveReadingSessionRecoveryId(
    chapterId,
    readingSessionId,
    storage = (typeof sessionStorage !== 'undefined' ? sessionStorage : null),
) {
    const key = readerRecoveryStorageKey(chapterId);
    const sessionId = text(readingSessionId);
    if (!key || !sessionId || !storage) return false;
    storage.setItem(key, sessionId);
    return true;
}

export function clearReadingSessionRecoveryId(
    chapterId,
    storage = (typeof sessionStorage !== 'undefined' ? sessionStorage : null),
) {
    const key = readerRecoveryStorageKey(chapterId);
    if (!key || !storage) return false;
    storage.removeItem(key);
    return true;
}

export function readerManualSenseContinuationStorageKey(chapterId) {
    const id = positiveInteger(chapterId);
    return id ? `${MANUAL_SENSE_CONTINUATION_KEY_PREFIX}:${id}` : '';
}

function normalizeManualSenseContinuation(value = {}) {
    const occurrenceId = text(value.occurrenceId);
    const rating = text(value.rating);
    if (!occurrenceId || !['again', 'hard', 'good', 'easy'].includes(rating)) return null;
    const senseId = positiveInteger(value.senseId);
    const reviewCardId = positiveInteger(value.reviewCardId);
    const outcomeUnknown = value.outcomeUnknown === true;
    if (!outcomeUnknown && (!senseId || !reviewCardId)) return null;
    const rawReadingActionId = text(value.readingActionId);
    const readingActionId = rawReadingActionId ? normalizeUuid(rawReadingActionId) : '';
    if (rawReadingActionId && !readingActionId) return null;
    const readingSessionId = text(value.readingSessionId);
    const sourceRevision = text(value.sourceRevision);
    if (readingActionId && (!readingSessionId || !sourceRevision)) return null;
    const form = value.form && typeof value.form === 'object'
        ? {
            pos: text(value.form.pos),
            sense_zh: text(value.form.sense_zh),
            sense_en: text(value.form.sense_en),
        }
        : null;
    return {
        occurrenceId,
        rating,
        senseId,
        reviewCardId,
        outcomeUnknown,
        sourceRevision,
        readingActionId,
        readingSessionId,
        form,
    };
}

export function loadReaderManualSenseContinuation(
    chapterId,
    storage = (typeof sessionStorage !== 'undefined' ? sessionStorage : null),
) {
    const key = readerManualSenseContinuationStorageKey(chapterId);
    if (!key || !storage) return null;
    try {
        const raw = storage.getItem(key);
        if (!raw) return null;
        return normalizeManualSenseContinuation(JSON.parse(raw));
    } catch (_) {
        return null;
    }
}

export function saveReaderManualSenseContinuation(
    chapterId,
    continuation,
    storage = (typeof sessionStorage !== 'undefined' ? sessionStorage : null),
) {
    const key = readerManualSenseContinuationStorageKey(chapterId);
    const normalized = normalizeManualSenseContinuation(continuation);
    if (!key || !storage || !normalized) return false;
    storage.setItem(key, JSON.stringify(normalized));
    return true;
}

export function clearReaderManualSenseContinuation(
    chapterId,
    storage = (typeof sessionStorage !== 'undefined' ? sessionStorage : null),
) {
    const key = readerManualSenseContinuationStorageKey(chapterId);
    if (!key || !storage) return false;
    storage.removeItem(key);
    return true;
}

export function readerExplicitRatingRetryStorageKey(chapterId) {
    const id = positiveInteger(chapterId);
    return id ? `${EXPLICIT_RATING_RETRY_KEY_PREFIX}:${id}` : '';
}

function normalizeExplicitRatingRetry(command = {}) {
    const reviewCardId = positiveInteger(command.reviewCardId);
    const wordSenseId = positiveInteger(command.wordSenseId);
    const occurrenceId = text(command.occurrenceId);
    const sourceRevision = text(command.sourceRevision);
    const payload = command.payload && typeof command.payload === 'object' ? command.payload : {};
    const rating = text(payload.rating);
    const readingSessionId = text(payload.reading_session_id);
    const payloadOccurrenceId = text(payload.occurrence_id);
    const readingActionId = normalizeUuid(payload.reading_action_id);
    if (!reviewCardId || !occurrenceId || !sourceRevision || !readingSessionId || payloadOccurrenceId !== occurrenceId
        || !['again', 'hard', 'good', 'easy'].includes(rating) || !readingActionId) {
        return null;
    }
    return {
        reviewCardId,
        wordSenseId,
        occurrenceId,
        sourceRevision,
        payload: {
            rating,
            reading_session_id: readingSessionId,
            occurrence_id: occurrenceId,
            reading_action_id: readingActionId,
        },
    };
}

export function loadReaderExplicitRatingRetry(
    chapterId,
    storage = (typeof sessionStorage !== 'undefined' ? sessionStorage : null),
) {
    const key = readerExplicitRatingRetryStorageKey(chapterId);
    if (!key || !storage) return null;
    try {
        const raw = storage.getItem(key);
        if (!raw) return null;
        return normalizeExplicitRatingRetry(JSON.parse(raw));
    } catch (_) {
        return null;
    }
}

export function saveReaderExplicitRatingRetry(
    chapterId,
    command,
    storage = (typeof sessionStorage !== 'undefined' ? sessionStorage : null),
) {
    const key = readerExplicitRatingRetryStorageKey(chapterId);
    const normalized = normalizeExplicitRatingRetry(command);
    if (!key || !storage || !normalized) return false;
    try {
        storage.setItem(key, JSON.stringify(normalized));
        return true;
    } catch (_) {
        return false;
    }
}

export function clearReaderExplicitRatingRetry(
    chapterId,
    storage = (typeof sessionStorage !== 'undefined' ? sessionStorage : null),
) {
    const key = readerExplicitRatingRetryStorageKey(chapterId);
    if (!key || !storage) return false;
    try {
        storage.removeItem(key);
        return true;
    } catch (_) {
        return false;
    }
}

function normalizeCandidate(candidate = {}) {
    const wordSenseId = positiveInteger(candidate.word_sense_id ?? candidate.sense_id);
    const reviewCardId = positiveInteger(candidate.review_card_id);
    if (!wordSenseId) return null;
    return {
        ...candidate,
        word_sense_id: wordSenseId,
        sense_id: wordSenseId,
        review_card_id: reviewCardId,
    };
}

export function normalizeReadingTarget(target = {}) {
    const occurrenceId = text(target.occurrence_id);
    const start = nonNegativeInteger(target.start_word_index);
    const end = nonNegativeInteger(target.end_word_index);
    if (!occurrenceId || start === null || end === null || end < start) return null;
    const kind = target.kind === 'phrase' ? 'phrase' : 'word';
    const candidates = (Array.isArray(target.candidate_word_senses) ? target.candidate_word_senses : [])
        .map(normalizeCandidate)
        .filter(Boolean);
    const candidateIds = new Set(
        (Array.isArray(target.candidate_word_sense_ids) ? target.candidate_word_sense_ids : [])
            .map(positiveInteger)
            .filter(Boolean),
    );
    for (const candidate of candidates) candidateIds.add(candidate.word_sense_id);
    return {
        ...target,
        occurrence_id: occurrenceId,
        kind,
        start_word_index: start,
        end_word_index: end,
        sentence_index: nonNegativeInteger(target.sentence_index),
        candidate_word_sense_ids: [...candidateIds],
        candidate_word_senses: kind === 'word' ? candidates : [],
    };
}

export function normalizeReadingSessionResponse(payload = {}, expectedChapterId = null) {
    const chapterId = positiveInteger(payload.chapter_id);
    const expected = positiveInteger(expectedChapterId);
    const readingSessionId = text(payload.reading_session_id);
    const sourceRevision = text(payload.source_revision);
    if (!readingSessionId || !sourceRevision || !chapterId || (expected && chapterId !== expected)) return null;
    if (payload && payload.completed === true) {
        return {
            completed: true,
            readingSessionId,
            sourceRevision,
            targets: [],
            raw: payload,
        };
    }
    return {
        completed: false,
        readingSessionId,
        sourceRevision,
        targets: (Array.isArray(payload.reading_targets) ? payload.reading_targets : [])
            .map(normalizeReadingTarget)
            .filter(Boolean),
        resumed: payload.resumed === true,
        raw: payload,
    };
}

export function findReadingTargetForOpenedSelection(targets = [], opened = {}) {
    const start = nonNegativeInteger(opened.start_word_index);
    const end = nonNegativeInteger(opened.end_word_index);
    if (start === null || end === null) return null;
    return (Array.isArray(targets) ? targets : []).find(target => (
        target
        && target.kind === 'word'
        && target.start_word_index === start
        && target.end_word_index === end
    )) || null;
}

export function filterCandidatesToReadingTarget(target, candidates = []) {
    if (!target || target.kind !== 'word') return [];
    const allowed = new Set((target.candidate_word_sense_ids || []).map(positiveInteger).filter(Boolean));
    if (!allowed.size) return [];
    return (Array.isArray(candidates) ? candidates : [])
        .map(normalizeCandidate)
        .filter(candidate => candidate && allowed.has(candidate.word_sense_id));
}

export function buildReadingInteractionRequest(readingSessionId, occurrenceId, interactionType = 'opened') {
    const sessionId = text(readingSessionId);
    const occurrence = text(occurrenceId);
    if (!sessionId || !occurrence || !['opened', 'helped'].includes(interactionType)) return null;
    return {
        reading_session_id: sessionId,
        interaction_type: interactionType,
        occurrence_id: occurrence,
    };
}

export function normalizeReaderUnfamiliarSnapshot(payload = {}) {
    const snapshotVersion = text(payload.snapshot_version);
    const targets = (Array.isArray(payload.targets) ? payload.targets : [])
        .map(target => {
            const start = nonNegativeInteger(target.start_word_index);
            const end = nonNegativeInteger(target.end_word_index);
            if (start === null || end === null || end < start) return null;
            return {
                ...target,
                kind: target.kind === 'phrase' ? 'phrase' : 'word',
                start_word_index: start,
                end_word_index: end,
            };
        })
        .filter(Boolean);
    return { snapshotVersion, targets };
}

export function normalizeReaderEvidencePage(payload = {}) {
    if (!Array.isArray(payload.items)) return null;
    const sourceRevision = text(payload.source_revision);
    if (!sourceRevision) return null;
    const total = nonNegativeInteger(payload.total);
    const offset = nonNegativeInteger(payload.offset);
    const limit = positiveInteger(payload.limit);
    if (total === null || offset === null || !limit || typeof payload.has_more !== 'boolean') return null;
    if (offset > total || payload.items.length > limit || offset + payload.items.length > total) return null;
    const nextOffset = payload.has_more ? nonNegativeInteger(payload.next_offset) : null;
    if (payload.has_more && (nextOffset === null || nextOffset <= offset || nextOffset !== offset + payload.items.length)) return null;
    if (!payload.has_more && offset + payload.items.length !== total) return null;
    return {
        items: payload.items,
        sourceRevision,
        total,
        offset,
        limit,
        hasMore: payload.has_more,
        nextOffset,
    };
}

export function buildReaderFinishRequest(basePayload = {}, readingSessionId, settlementMode) {
    const sessionId = text(readingSessionId);
    if (!sessionId || !['preflight', 'commit'].includes(settlementMode)) return null;
    return {
        ...basePayload,
        reading_session_id: sessionId,
        settlement_mode: settlementMode,
    };
}

export function normalizeReaderFinishResult(payload = {}) {
    const completed = payload.completed === true;
    const unresolvedCount = Math.max(0, Number(payload.unresolved_count || 0) || 0);
    return {
        completed,
        preflightRequired: payload.preflight_required === true,
        canCommit: payload.can_commit === true,
        alreadyCompleted: payload.already_completed === true,
        settlementMode: payload.settlement_mode || '',
        passiveGoodCount: Math.max(0, Number(payload.passive_good_count || payload.planned_passive_good_count || 0) || 0),
        unresolvedCount,
        excludedCount: Math.max(0, Number(payload.excluded_count || 0) || 0),
        alreadySettledCount: Math.max(0, Number(payload.already_settled_count || 0) || 0),
        unresolvedOccurrenceIds: Array.isArray(payload.unresolved_occurrence_ids) ? payload.unresolved_occurrence_ids : [],
        conflictCodes: Array.isArray(payload.conflict_codes) ? payload.conflict_codes : [],
        chapterId: positiveInteger(payload.chapter_id),
        readingSessionId: text(payload.reading_session_id),
        sourceRevision: text(payload.source_revision),
        raw: payload,
    };
}
