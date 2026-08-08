const SESSION_KEY_PREFIX = 'linguacafe-reading-session-id';
const MANUAL_SENSE_CONTINUATION_KEY_PREFIX = 'linguacafe-reader-manual-sense-continuation';

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
        sourceRevision: text(value.sourceRevision),
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

export function readerOutcomeUnknown(message = '请求已发出，但服务器结果暂时无法确认。已保留本次阅读会话；恢复网络后可安全重试同一操作。') {
    return {
        outcomeUnknown: true,
        message,
    };
}

export function createReaderRequestId() {
    if (typeof crypto !== 'undefined' && crypto && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, character => {
        const random = Math.random() * 16 | 0;
        const value = character === 'x' ? random : (random & 0x3 | 0x8);
        return value.toString(16);
    });
}
