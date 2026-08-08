import assert from 'node:assert/strict';
import test from 'node:test';

import {
    buildReaderFinishPreflightRequest,
    createReaderReadingInteractionState,
    forgetServerReadingSessionId,
    getOrCreateReadingSessionId,
    markReaderOccurrenceOpened,
    markReaderOccurrenceReviewed,
    normalizeReaderFinishPreflight,
    readerFinishPreflightUnavailable,
    rememberServerReadingSessionId,
} from '../../resources/js/services/ReaderFinishPreflightPolicy.js';

function memoryStorage() {
    const values = new Map();
    return {
        values,
        getItem: key => values.get(key) || null,
        setItem: (key, value) => values.set(key, value),
        removeItem: key => values.delete(key),
    };
}

test('Reader never creates reading-session ids and only recovers a server-issued pointer', () => {
    const storage = memoryStorage();
    assert.equal(getOrCreateReadingSessionId(42, storage), '');
    assert.equal(storage.values.size, 0);

    assert.equal(rememberServerReadingSessionId(42, 'server-session-42', storage), true);
    assert.equal(getOrCreateReadingSessionId(42, storage), 'server-session-42');
    assert.equal(getOrCreateReadingSessionId(43, storage), '');
    assert.equal(storage.values.size, 1);

    assert.equal(forgetServerReadingSessionId(42, storage), true);
    assert.equal(getOrCreateReadingSessionId(42, storage), '');
});

test('legacy in-memory interaction helper remains duplicate-free but is not sent as authority', () => {
    let state = createReaderReadingInteractionState('server-session-1');
    state = markReaderOccurrenceOpened(state, 'occ-a');
    state = markReaderOccurrenceOpened(state, 'occ-a');
    state = markReaderOccurrenceReviewed(state, 'occ-a');
    state = markReaderOccurrenceReviewed(state, 'occ-b');
    assert.deepEqual(state.openedOccurrenceIds, ['occ-a', 'occ-b']);
    assert.deepEqual(state.reviewedOccurrenceIds, ['occ-a', 'occ-b']);
});

test('preflight request sends server session plus explicit settlement mode and keeps legacy chapter payload intact', () => {
    const state = createReaderReadingInteractionState('server-session-1');
    assert.deepEqual(buildReaderFinishPreflightRequest(state, 42, { uniqueWords: '[]' }), {
        uniqueWords: '[]',
        chapter_id: 42,
        reading_session_id: 'server-session-1',
        settlement_mode: 'preflight',
    });
});

test('preflight display consumes R3 server counts and can_commit', () => {
    const raw = {
        passive_good_count: 7,
        unresolved_count: 3,
        excluded_count: 2,
        already_settled_count: 1,
        can_commit: false,
        completed: false,
        preflight_required: true,
        unresolved_occurrence_ids: ['a', 'b', 'c'],
        conflict_codes: ['READING_FINISH_UNRESOLVED'],
    };
    const normalized = normalizeReaderFinishPreflight(raw);
    assert.equal(normalized.passiveGoodCount, 7);
    assert.equal(normalized.pendingConfirmationCount, 3);
    assert.equal(normalized.unresolvedCount, 3);
    assert.equal(normalized.excludedCount, 2);
    assert.equal(normalized.alreadySettledCount, 1);
    assert.equal(normalized.canFinish, false);
    assert.equal(normalized.canCommit, false);
    assert.equal(normalized.preflightRequired, true);
    assert.deepEqual(normalized.unresolvedOccurrenceIds, ['a', 'b', 'c']);
    assert.deepEqual(normalized.raw, raw);
});

test('unavailable preflight is outcome-unknown/fail-closed and does not claim no server write happened', () => {
    const fallback = readerFinishPreflightUnavailable();
    assert.equal(fallback.backendAvailable, false);
    assert.equal(fallback.canFinish, false);
    assert.equal(fallback.canCommit, false);
    assert.equal(fallback.passiveGoodCount, 0);
    assert.equal(fallback.outcomeUnknown, true);
    assert.match(fallback.message, /结果目前未知|无法确认/);
    assert.doesNotMatch(fallback.message, /没有被动评分被提交|什么都没有提交/);
});
