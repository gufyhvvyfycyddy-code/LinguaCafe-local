import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const coordinatorUrl = new URL(
    '../../resources/js/components/CustomStudy/CustomStudySessionCoordinator.js',
    import.meta.url,
);

assert.ok(
    existsSync(fileURLToPath(coordinatorUrl)),
    'Custom Study needs one executable session coordinator boundary.',
);

const {
    CustomStudySessionCoordinator,
    customStudyKeyboardAction,
} = await import(coordinatorUrl);

const tests = [];
function test(name, run) {
    tests.push({ name, run });
}

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => {
        resolve = res;
        reject = rej;
    });
    return { promise, resolve, reject };
}

function fakeStorage(initialToken = null) {
    const values = new Map();
    const calls = [];
    if (initialToken !== null) values.set('preview-token', initialToken);
    return {
        calls,
        getItem(key) { calls.push(['get', key]); return values.get(key) || null; },
        setItem(key, value) { calls.push(['set', key, value]); values.set(key, value); },
        removeItem(key) { calls.push(['remove', key]); values.delete(key); },
    };
}

function harness({ token = 'token-1', storedToken = null } = {}) {
    const answerRequests = [];
    const resumeRequests = [];
    const storage = fakeStorage(storedToken);
    const states = [];
    const transport = {
        answer(requestToken, rating) {
            const request = deferred();
            answerRequests.push({ token: requestToken, rating, ...request });
            return request.promise;
        },
        resume(requestToken) {
            const request = deferred();
            resumeRequests.push({ token: requestToken, ...request });
            return request.promise;
        },
    };
    const coordinator = new CustomStudySessionCoordinator({
        transport,
        storage,
        storageKey: 'preview-token',
        onState: state => states.push(state),
    });
    if (token !== null) {
        coordinator.open(token, {
            token,
            current_card: { review_card_id: 1 },
            summary: { total_count: 2 },
        });
    }
    return { coordinator, storage, states, answerRequests, resumeRequests };
}

test('first answer locks immediately and button/keyboard competition calls transport once', async () => {
    const h = harness();
    const first = h.coordinator.answer('again');
    const second = await h.coordinator.answer('good');
    assert.equal(h.coordinator.snapshot().mutationLocked, true);
    assert.equal(second, false);
    assert.equal(h.answerRequests.length, 1);
    assert.equal(h.answerRequests[0].token, 'token-1');
    h.answerRequests[0].resolve({ refreshed_token: 'token-2', current_card: { review_card_id: 2 }, summary: {} });
    assert.equal(await first, true);
    assert.equal(h.coordinator.snapshot().token, 'token-2');
    assert.equal(h.coordinator.snapshot().currentCard.review_card_id, 2);
    assert.ok(h.storage.calls.some(call => call[0] === 'set' && call[2] === 'token-2'));
});

test('failed answer never pretends success and releases the mutation lock', async () => {
    const h = harness();
    const request = h.coordinator.answer('hard');
    h.answerRequests[0].reject(new Error('network down'));
    assert.equal(await request, false);
    assert.equal(h.coordinator.snapshot().currentCard.review_card_id, 1);
    assert.equal(h.coordinator.snapshot().mutationLocked, false);
    assert.match(h.coordinator.snapshot().error, /network down/);
});

test('exit and dispose invalidate late answer and resume responses', async () => {
    const answerHarness = harness();
    const answer = answerHarness.coordinator.answer('easy');
    answerHarness.coordinator.exit();
    answerHarness.answerRequests[0].resolve({ refreshed_token: 'late', current_card: { review_card_id: 99 } });
    assert.equal(await answer, false);
    assert.equal(answerHarness.coordinator.snapshot().token, '');
    assert.equal(answerHarness.coordinator.snapshot().currentCard, null);

    const resumeHarness = harness();
    const resume = resumeHarness.coordinator.resume();
    resumeHarness.coordinator.dispose();
    resumeHarness.resumeRequests[0].resolve({ refreshed_token: 'late-resume', current_card: { review_card_id: 88 } });
    assert.equal(await resume, false);
    assert.notEqual(resumeHarness.coordinator.snapshot().token, 'late-resume');
});

test('stale answer and resume cannot overwrite a newer open payload', async () => {
    const answerHarness = harness();
    const answer = answerHarness.coordinator.answer('again');
    answerHarness.coordinator.open('new-token', {
        token: 'new-token',
        current_card: { review_card_id: 5 },
        summary: {},
    });
    answerHarness.answerRequests[0].resolve({ refreshed_token: 'stale-answer', current_card: { review_card_id: 91 } });
    assert.equal(await answer, false);
    assert.equal(answerHarness.coordinator.snapshot().token, 'new-token');
    assert.equal(answerHarness.coordinator.snapshot().currentCard.review_card_id, 5);

    const resumeHarness = harness();
    const resume = resumeHarness.coordinator.resume();
    resumeHarness.coordinator.open('newer-token', {
        token: 'newer-token',
        current_card: { review_card_id: 6 },
        summary: {},
    });
    resumeHarness.resumeRequests[0].resolve({ refreshed_token: 'stale-resume', current_card: { review_card_id: 92 } });
    assert.equal(await resume, false);
    assert.equal(resumeHarness.coordinator.snapshot().token, 'newer-token');
    assert.equal(resumeHarness.coordinator.snapshot().currentCard.review_card_id, 6);
});

test('stored token triggers resume and refreshed token replaces it', async () => {
    const h = harness({ token: null, storedToken: 'stored-token' });
    const restore = h.coordinator.restore();
    assert.equal(h.resumeRequests.length, 1);
    assert.equal(h.resumeRequests[0].token, 'stored-token');
    h.resumeRequests[0].resolve({ refreshed_token: 'fresh-token', current_card: { review_card_id: 3 }, summary: {} });
    assert.equal(await restore, true);
    assert.equal(h.coordinator.snapshot().token, 'fresh-token');
});

test('404 expires the session, clears storage, and does not reject outward', async () => {
    const h = harness();
    const resume = h.coordinator.resume();
    h.resumeRequests[0].reject({ response: { status: 404, data: { message: 'expired' } } });
    assert.equal(await resume, false);
    assert.equal(h.coordinator.snapshot().expired, true);
    assert.equal(h.coordinator.snapshot().token, '');
    assert.ok(h.storage.calls.some(call => call[0] === 'remove'));
});

test('completed clears token and exit never calls a cleanup transport', async () => {
    const h = harness();
    const answer = h.coordinator.answer('good');
    h.answerRequests[0].resolve({ refreshed_token: 'unused', completed: true, current_card: null, summary: {} });
    assert.equal(await answer, true);
    assert.equal(h.coordinator.snapshot().completed, true);
    assert.equal(h.coordinator.snapshot().token, '');
    h.coordinator.exit();
    assert.equal(h.answerRequests.length, 1);
    assert.equal(h.resumeRequests.length, 0);
});

test('automatic and manual waiting resume share one lock and auto-resume is deduplicated', async () => {
    const h = harness();
    h.coordinator.applyPayload({ refreshed_token: 'wait-token', wait_until: '2099-01-01T00:00:00Z', current_card: null, summary: {} });
    const automatic = h.coordinator.autoResume();
    const manual = await h.coordinator.resume();
    const repeatedAuto = await h.coordinator.autoResume();
    assert.equal(manual, false);
    assert.equal(repeatedAuto, false);
    assert.equal(h.resumeRequests.length, 1);
    h.resumeRequests[0].resolve({ refreshed_token: 'after-wait', current_card: { review_card_id: 4 }, summary: {} });
    assert.equal(await automatic, true);
});

test('keyboard mapping is truthful and guarded by reveal, focus, repeat, dialogs, and terminal state', () => {
    const base = { showAnswer: false, blocked: false, sourceDialogOpen: false };
    assert.equal(customStudyKeyboardAction({ code: 'Space', target: {} }, base), 'reveal');
    for (const code of ['Digit1', 'Digit2', 'Digit3', 'Digit4']) {
        assert.equal(customStudyKeyboardAction({ code, target: {} }, base), null);
    }
    assert.equal(customStudyKeyboardAction({ code: 'Space', target: {} }, { ...base, showAnswer: true }), null);
    assert.equal(customStudyKeyboardAction({ code: 'Digit1', target: {} }, { ...base, showAnswer: true }), 'again');
    assert.equal(customStudyKeyboardAction({ code: 'Digit2', target: {} }, { ...base, showAnswer: true }), 'hard');
    assert.equal(customStudyKeyboardAction({ code: 'Digit3', target: {} }, { ...base, showAnswer: true }), 'good');
    assert.equal(customStudyKeyboardAction({ code: 'Digit4', target: {} }, { ...base, showAnswer: true }), 'easy');
    assert.equal(customStudyKeyboardAction({ code: 'Digit1', repeat: true, target: {} }, { ...base, showAnswer: true }), null);
    assert.equal(customStudyKeyboardAction({ code: 'Digit1', target: { tagName: 'INPUT' } }, { ...base, showAnswer: true }), null);
    assert.equal(customStudyKeyboardAction({ code: 'Digit1', target: { isContentEditable: true } }, { ...base, showAnswer: true }), null);
    assert.equal(customStudyKeyboardAction({ code: 'Digit1', target: {} }, { ...base, showAnswer: true, blocked: true }), null);
    assert.equal(customStudyKeyboardAction({ code: 'Digit1', target: {} }, { ...base, showAnswer: true, sourceDialogOpen: true }), null);
});

for (const { name, run } of tests) {
    try {
        await run();
        console.log(`PASS: ${name}`);
    } catch (error) {
        console.error(`FAIL: ${name}`);
        throw error;
    }
}

console.log(`CustomStudy session coordinator: ${tests.length} executable behaviors passed.`);
