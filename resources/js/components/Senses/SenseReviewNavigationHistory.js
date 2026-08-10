const STORAGE_KEY = 'sense_review_navigation_history_v1';

function positiveCardId(value) {
    const id = typeof value === 'string' && /^\d+$/.test(value.trim())
        ? Number(value)
        : value;
    return typeof id === 'number' && Number.isInteger(id) && id > 0 ? id : null;
}

function positiveCardIds(ids) {
    if (!Array.isArray(ids)) return [];
    return ids.map(positiveCardId).filter(id => id !== null);
}

function defaultStorage() {
    try {
        return typeof sessionStorage !== 'undefined' ? sessionStorage : null;
    } catch (error) {
        return null;
    }
}

export function emptyReviewNavigationHistory(reviewSessionId) {
    return {
        reviewSessionId: String(reviewSessionId || ''),
        backCardIds: [],
        currentCardId: null,
        forwardCardIds: [],
    };
}

export function loadReviewNavigationHistory(reviewSessionId, storage = defaultStorage()) {
    const empty = emptyReviewNavigationHistory(reviewSessionId);
    if (!storage) return empty;
    try {
        const stored = JSON.parse(storage.getItem(STORAGE_KEY) || 'null');
        if (
            !stored
            || typeof stored !== 'object'
            || Array.isArray(stored)
            || stored.reviewSessionId !== empty.reviewSessionId
            || !Array.isArray(stored.backCardIds)
            || !Object.prototype.hasOwnProperty.call(stored, 'currentCardId')
            || !Array.isArray(stored.forwardCardIds)
        ) return empty;
        return {
            reviewSessionId: empty.reviewSessionId,
            backCardIds: positiveCardIds(stored.backCardIds),
            currentCardId: positiveCardId(stored.currentCardId),
            forwardCardIds: positiveCardIds(stored.forwardCardIds),
        };
    } catch (error) {
        return empty;
    }
}

export function saveReviewNavigationHistory(history, storage = defaultStorage()) {
    const normalized = saveShape(history);
    if (!storage) return normalized;
    try {
        storage.setItem(STORAGE_KEY, JSON.stringify(normalized));
    } catch (error) {
        // This state only remembers page position. ReviewLog/FSRS remains the
        // authority even when browser storage is unavailable.
    }
    return normalized;
}

export function recordRatedCard(history, ratedCardId) {
    const currentId = positiveCardId(ratedCardId);
    if (!currentId) return saveShape(history);
    return {
        ...saveShape(history),
        backCardIds: [...positiveCardIds(history?.backCardIds), currentId],
        currentCardId: null,
        forwardCardIds: [],
    };
}

export function moveNavigationBack(history, targetCardId, currentCardId) {
    const normalized = saveShape(history);
    const targetId = positiveCardId(targetCardId);
    const currentId = positiveCardId(currentCardId);
    if (!targetId || normalized.backCardIds[normalized.backCardIds.length - 1] !== targetId) return normalized;
    const backCardIds = normalized.backCardIds.slice(0, -1);
    const forwardCardIds = currentId
        ? [...normalized.forwardCardIds, currentId]
        : normalized.forwardCardIds;
    return { ...normalized, backCardIds, currentCardId: targetId, forwardCardIds };
}

export function moveNavigationForward(history, targetCardId, currentCardId) {
    const normalized = saveShape(history);
    const targetId = positiveCardId(targetCardId);
    const currentId = positiveCardId(currentCardId);
    if (!targetId || normalized.forwardCardIds[normalized.forwardCardIds.length - 1] !== targetId) return normalized;
    const forwardCardIds = normalized.forwardCardIds.slice(0, -1);
    const backCardIds = currentId
        ? [...normalized.backCardIds, currentId]
        : normalized.backCardIds;
    return { ...normalized, backCardIds, currentCardId: targetId, forwardCardIds };
}

export function setNavigationCurrentCard(history, cardId) {
    return { ...saveShape(history), currentCardId: positiveCardId(cardId) };
}

function saveShape(history) {
    return {
        reviewSessionId: String(history?.reviewSessionId || ''),
        backCardIds: positiveCardIds(history?.backCardIds),
        currentCardId: positiveCardId(history?.currentCardId),
        forwardCardIds: positiveCardIds(history?.forwardCardIds),
    };
}
