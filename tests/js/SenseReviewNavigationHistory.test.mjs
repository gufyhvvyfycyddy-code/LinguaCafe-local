import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import {
    emptyReviewNavigationHistory,
    loadReviewNavigationHistory,
    moveNavigationBack,
    moveNavigationForward,
    recordRatedCard,
    saveReviewNavigationHistory,
    setNavigationCurrentCard,
} from '../../resources/js/components/Senses/SenseReviewNavigationHistory.js';

function memoryStorage(initial = {}) {
    const values = new Map(Object.entries(initial));
    return {
        getItem(key) { return values.has(key) ? values.get(key) : null; },
        setItem(key, value) { values.set(key, String(value)); },
        removeItem(key) { values.delete(key); },
    };
}

const helperSource = readFileSync(
    new URL('../../resources/js/components/Senses/SenseReviewNavigationHistory.js', import.meta.url),
    'utf8',
);

test('navigation history is scoped to the current review session and survives refresh', () => {
    const storage = memoryStorage();
    let history = emptyReviewNavigationHistory('session-a');
    history = setNavigationCurrentCard(history, 10);
    history = recordRatedCard(history, 10);
    history = setNavigationCurrentCard(history, 20);
    saveReviewNavigationHistory(history, storage);

    assert.deepEqual(loadReviewNavigationHistory('session-a', storage), history);
    assert.deepEqual(loadReviewNavigationHistory('session-b', storage), emptyReviewNavigationHistory('session-b'));
});

test('malformed storage safely becomes empty navigation history', () => {
    const badJson = memoryStorage({ sense_review_navigation_history_v1: '{bad' });
    assert.deepEqual(loadReviewNavigationHistory('session-a', badJson), emptyReviewNavigationHistory('session-a'));

    const badShape = memoryStorage({
        sense_review_navigation_history_v1: JSON.stringify({
            reviewSessionId: 'session-a',
            backCardIds: '10',
            currentCardId: 20,
            forwardCardIds: [],
        }),
    });
    assert.deepEqual(loadReviewNavigationHistory('session-a', badShape), emptyReviewNavigationHistory('session-a'));
});

test('successful ratings append to back history and clear the forward branch', () => {
    let history = emptyReviewNavigationHistory('session-a');
    history = setNavigationCurrentCard(history, 10);
    history = recordRatedCard(history, 10);
    history = setNavigationCurrentCard(history, 20);
    history = { ...history, forwardCardIds: [30] };
    history = recordRatedCard(history, 20);
    assert.deepEqual(history.backCardIds, [10, 20]);
    assert.equal(history.currentCardId, null);
    assert.deepEqual(history.forwardCardIds, []);
});

test('back then forward then back moves only card ids without creating a second learning state', () => {
    let history = {
        reviewSessionId: 'session-a',
        backCardIds: [10, 20],
        currentCardId: 30,
        forwardCardIds: [],
    };

    history = moveNavigationBack(history, 20, 30);
    assert.deepEqual(history, {
        reviewSessionId: 'session-a',
        backCardIds: [10],
        currentCardId: 20,
        forwardCardIds: [30],
    });

    history = moveNavigationForward(history, 30, 20);
    assert.deepEqual(history, {
        reviewSessionId: 'session-a',
        backCardIds: [10, 20],
        currentCardId: 30,
        forwardCardIds: [],
    });

    history = moveNavigationBack(history, 20, 30);
    assert.equal(history.currentCardId, 20);
    assert.deepEqual(history.backCardIds, [10]);
    assert.deepEqual(history.forwardCardIds, [30]);
});

test('invalid card ids are removed when navigation state is saved', () => {
    const storage = memoryStorage();
    const saved = saveReviewNavigationHistory({
        reviewSessionId: 'session-a',
        backCardIds: [1, '2', 0, 'bad', true, [5]],
        currentCardId: '3',
        forwardCardIds: [-1, 4, { id: 6 }],
    }, storage);
    assert.deepEqual(saved.backCardIds, [1, 2]);
    assert.equal(saved.currentCardId, 3);
    assert.deepEqual(saved.forwardCardIds, [4]);
});

test('unavailable sessionStorage never blocks review navigation', () => {
    const previous = Object.getOwnPropertyDescriptor(globalThis, 'sessionStorage');
    Object.defineProperty(globalThis, 'sessionStorage', {
        configurable: true,
        get() { throw new Error('storage blocked'); },
    });

    try {
        const empty = emptyReviewNavigationHistory('session-a');
        assert.deepEqual(loadReviewNavigationHistory('session-a'), empty);
        assert.deepEqual(saveReviewNavigationHistory({
            reviewSessionId: 'session-a',
            backCardIds: [10],
            currentCardId: 20,
            forwardCardIds: [30],
        }), {
            reviewSessionId: 'session-a',
            backCardIds: [10],
            currentCardId: 20,
            forwardCardIds: [30],
        });
    } finally {
        if (previous) {
            Object.defineProperty(globalThis, 'sessionStorage', previous);
        } else {
            delete globalThis.sessionStorage;
        }
    }
});

test('helper stays navigation-only with no learning or network dependency', () => {
    assert.doesNotMatch(helperSource, /\baxios\b/);
    assert.doesNotMatch(helperSource, /\blocalStorage\b/);
    assert.doesNotMatch(helperSource, /\breview_log_id\b/i);
    assert.doesNotMatch(helperSource, /\bfsrs_[a-z_]+\b/i);
    assert.doesNotMatch(helperSource, /\/reviews\/|review-actions|\/undo\b/);
});
