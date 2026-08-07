import assert from 'node:assert/strict';
import test from 'node:test';

import {
    buildReaderFinishPreflightRequest,
    createReaderReadingInteractionState,
    getOrCreateReadingSessionId,
    markReaderOccurrenceOpened,
    markReaderOccurrenceReviewed,
    normalizeReaderFinishPreflight,
    readerFinishPreflightUnavailable,
} from '../../resources/js/services/ReaderFinishPreflightPolicy.js';

test('reading session identity is stable per chapter and isolated between chapters', () => {
    const values = new Map();
    const storage = {
        getItem: key => values.get(key) || null,
        setItem: (key, value) => values.set(key, value),
    };
    const first = getOrCreateReadingSessionId(42, storage);
    assert.equal(getOrCreateReadingSessionId(42, storage), first);
    assert.notEqual(getOrCreateReadingSessionId(43, storage), first);
    assert.equal(values.size, 2);
});

test('reading interaction tracks explicit open/review exactly once per occurrence', () => {
    let state = createReaderReadingInteractionState('reading-session-1');
    state = markReaderOccurrenceOpened(state, 'occ-a');
    state = markReaderOccurrenceOpened(state, 'occ-a');
    state = markReaderOccurrenceReviewed(state, 'occ-a');
    state = markReaderOccurrenceReviewed(state, 'occ-b');
    assert.deepEqual(state.openedOccurrenceIds, ['occ-a', 'occ-b']);
    assert.deepEqual(state.reviewedOccurrenceIds, ['occ-a', 'occ-b']);
});

test('preflight request sends interaction evidence but never computes rating eligibility locally', () => {
    let state = createReaderReadingInteractionState('reading-session-1');
    state = markReaderOccurrenceOpened(state, 'occ-a');
    assert.deepEqual(buildReaderFinishPreflightRequest(state, 42), {
        chapter_id: 42,
        reading_session_id: 'reading-session-1',
        explicitly_opened_occurrence_ids: ['occ-a'],
        explicitly_reviewed_occurrence_ids: [],
    });
});

test('preflight display consumes server counts', () => {
    assert.deepEqual(normalizeReaderFinishPreflight({
        passive_good_count: 7,
        pending_confirmation_count: 3,
        excluded_count: 2,
        already_settled_count: 1,
        can_finish: true,
    }), {
        passiveGoodCount: 7,
        pendingConfirmationCount: 3,
        excludedCount: 2,
        alreadySettledCount: 1,
        canFinish: true,
        message: '',
        backendAvailable: true,
        raw: {
            passive_good_count: 7,
            pending_confirmation_count: 3,
            excluded_count: 2,
            already_settled_count: 1,
            can_finish: true,
        },
    });
});

test('missing Phase B backend preflight fails closed and never invents passive Good', () => {
    const fallback = readerFinishPreflightUnavailable();
    assert.equal(fallback.backendAvailable, false);
    assert.equal(fallback.canFinish, false);
    assert.equal(fallback.passiveGoodCount, 0);
    assert.match(fallback.message, /没有被动评分被提交/);
});
