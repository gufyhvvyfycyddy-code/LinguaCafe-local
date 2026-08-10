import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

import {
    awaitReaderInlineOpenedBarrier,
    buildReaderInlineOfficialRatingCommand,
    chooseReaderInlineRating,
    chooseReaderInlineSense,
    clearReaderInlinePendingRating,
    createReaderInlineReviewIntent,
    createReaderInlineSenseReviewState,
    freezeReaderInlineRatingIntent,
    normalizeReaderManualSensePos,
    readerInlineOpenedFailureMessage,
    readerInlineRatingIntentMatches,
    replaceReaderInlineOccurrence,
    resolveReaderInteractionAttempt,
    revealReaderInlineSenseAnswer,
} from '../../resources/js/services/ReaderInlineSenseReviewPolicy.js';

const occurrence = { occurrence_id: 'occ2-bank', surface: 'bank' };
const candidates = [
    { word_sense_id: 81, review_card_id: 181, fsrs_enabled: true },
    { word_sense_id: 95, review_card_id: 195, fsrs_enabled: true },
];

function deferredPromise() {
    let resolve;
    let reject;
    const promise = new Promise((resolvePromise, rejectPromise) => {
        resolve = resolvePromise;
        reject = rejectPromise;
    });
    return { promise, resolve, reject };
}

test('explicit review entry stays closed until opened acknowledgement resolves', async () => {
    const intent = createReaderInlineReviewIntent('session-1', 'rev-1', occurrence);
    const deferred = deferredPromise();
    let settled = false;
    const barrier = awaitReaderInlineOpenedBarrier(intent, () => deferred.promise, () => intent);
    barrier.finally(() => { settled = true; });

    await Promise.resolve();
    assert.equal(settled, false);
    deferred.resolve(true);
    assert.equal(await barrier, 'acknowledged');
    assert.equal(settled, true);
});

test('already acknowledged opened state skips a write and a pending opened attempt reuses the exact same Promise', () => {
    const acknowledged = resolveReaderInteractionAttempt(
        { sessionId: 'session-1', sourceRevision: 'rev-1', status: 'acknowledged' },
        'session-1',
        'rev-1',
        null,
    );
    assert.equal(acknowledged.kind, 'acknowledged');
    assert.equal(acknowledged.promise, null);

    const pendingPromise = Promise.resolve(true);
    const pending = resolveReaderInteractionAttempt(
        { sessionId: 'session-1', sourceRevision: 'rev-1', status: 'pending' },
        'session-1',
        'rev-1',
        pendingPromise,
    );
    assert.equal(pending.kind, 'pending');
    assert.equal(pending.promise, pendingPromise);

    assert.equal(resolveReaderInteractionAttempt(
        { sessionId: 'session-1', sourceRevision: 'rev-old', status: 'acknowledged' },
        'session-1',
        'rev-1',
        null,
    ).kind, 'write');
});

test('opened rejection fails closed and reports inactive-session or uncertain transport accurately', async () => {
    const intent = createReaderInlineReviewIntent('session-1', 'rev-1', occurrence);
    assert.equal(
        await awaitReaderInlineOpenedBarrier(intent, () => Promise.resolve(false), () => intent),
        'unconfirmed',
    );
    assert.match(
        readerInlineOpenedFailureMessage({ errorCode: 'READING_SESSION_NOT_ACTIVE' }),
        /服务器结束.*停止打开词义复习/,
    );
    assert.match(
        readerInlineOpenedFailureMessage({ outcomeUnknown: true }),
        /还没有确认.*停止打开评分/,
    );
});

test('old session, source revision, or occurrence acknowledgement cannot open the current review', async () => {
    const expected = createReaderInlineReviewIntent('session-1', 'rev-1', occurrence);
    const staleCurrents = [
        createReaderInlineReviewIntent('session-2', 'rev-1', occurrence),
        createReaderInlineReviewIntent('session-1', 'rev-2', occurrence),
        createReaderInlineReviewIntent('session-1', 'rev-1', { occurrence_id: 'occ2-other' }),
    ];

    for (const current of staleCurrents) {
        const deferred = deferredPromise();
        let active = expected;
        const barrier = awaitReaderInlineOpenedBarrier(expected, () => deferred.promise, () => active);
        active = current;
        deferred.resolve(true);
        assert.equal(await barrier, 'stale');
    }
});

test('production Reader keeps Finish interaction drain ahead of preflight and commit', () => {
    const reader = fs.readFileSync('resources/js/components/TextReader/TextReader.vue', 'utf8');
    const safetyStart = reader.indexOf('preFinishSafetyCheck()');
    const projectionStart = reader.indexOf('handleFinishProjection(', safetyStart);
    assert.ok(safetyStart > 0 && projectionStart > safetyStart);
    const safetySource = reader.slice(safetyStart, projectionStart);
    assert.match(safetySource, /return this\.flushReadingInteractions\(\)/);
    assert.match(safetySource, /\.then\(\(\) => this\.refreshReadingSessionTargets\(\)\)/);

    const preflightStart = reader.indexOf('preflightFinishSettlement()');
    const commitStart = reader.indexOf('commitFinish()', preflightStart);
    assert.ok(preflightStart > 0 && commitStart > preflightStart);
    assert.match(reader.slice(preflightStart, commitStart), /return this\.preFinishSafetyCheck\(\)/);
    assert.match(reader.slice(commitStart), /return this\.preFinishSafetyCheck\(\)/);
});

test('inline review must reveal before a rating can become pending', () => {
    const initial = createReaderInlineSenseReviewState(occurrence);
    assert.equal(chooseReaderInlineRating(initial, 'good').pendingRating, null);
    const revealed = revealReaderInlineSenseAnswer(initial);
    assert.equal(chooseReaderInlineRating(revealed, 'good').pendingRating, 'good');
});

test('pending rating is frozen to one session, source revision, and occurrence identity', () => {
    const opened = createReaderInlineReviewIntent('session-a', 'revision-a', occurrence);
    const frozen = freezeReaderInlineRatingIntent(opened, { ...opened }, 'hard');
    assert.deepEqual(frozen, { ...opened, rating: 'hard' });
    assert.equal(readerInlineRatingIntentMatches(frozen, { ...opened }, 'hard'), true);
    assert.equal(readerInlineRatingIntentMatches(frozen, { ...opened, readingSessionId: 'session-b' }, 'hard'), false);
    assert.equal(readerInlineRatingIntentMatches(frozen, { ...opened, sourceRevision: 'revision-b' }, 'hard'), false);
    assert.equal(readerInlineRatingIntentMatches(frozen, { ...opened, occurrenceId: 'occ2-other' }, 'hard'), false);
    assert.equal(readerInlineRatingIntentMatches(frozen, { ...opened }, 'easy'), false);
    assert.equal(freezeReaderInlineRatingIntent(opened, { ...opened, readingSessionId: 'session-b' }, 'hard'), null);
});

test('formal command requires both explicit rating and concrete WordSense with active review card', () => {
    let state = revealReaderInlineSenseAnswer(createReaderInlineSenseReviewState(occurrence));
    state = chooseReaderInlineRating(state, 'hard');
    assert.equal(buildReaderInlineOfficialRatingCommand(state, candidates, 'session-1'), null);
    state = chooseReaderInlineSense(state, 95);
    const command = buildReaderInlineOfficialRatingCommand(state, candidates, 'session-1');
    assert.deepEqual(command, {
        reviewCardId: 195,
        wordSenseId: 95,
        payload: {
            rating: 'hard',
            reading_session_id: 'session-1',
            occurrence_id: 'occ2-bank',
        },
        occurrenceId: 'occ2-bank',
    });
    assert.equal(Object.hasOwn(command.payload, 'reading_action_id'), false);
});

test('Reader explicit rating refuses to build without reading-session and occurrence identity', () => {
    let state = revealReaderInlineSenseAnswer(createReaderInlineSenseReviewState(occurrence));
    state = chooseReaderInlineRating(state, 'good');
    state = chooseReaderInlineSense(state, 81);
    assert.equal(buildReaderInlineOfficialRatingCommand(state, candidates, ''), null);

    const noOccurrence = { ...state, occurrence: { surface: 'bank' } };
    assert.equal(buildReaderInlineOfficialRatingCommand(noOccurrence, candidates, 'session-1'), null);
});

test('candidate without official ReviewCard cannot be rated by the Reader', () => {
    let state = revealReaderInlineSenseAnswer(createReaderInlineSenseReviewState(occurrence));
    state = chooseReaderInlineRating(state, 'good');
    state = chooseReaderInlineSense(state, 81);
    assert.equal(buildReaderInlineOfficialRatingCommand(state, [{ word_sense_id: 81, review_card_id: null }]), null);
});

test('cancel/close and occurrence change clear pending rating and selected sense', () => {
    let state = revealReaderInlineSenseAnswer(createReaderInlineSenseReviewState(occurrence));
    state = chooseReaderInlineRating(state, 'easy');
    state = chooseReaderInlineSense(state, 95);
    const cleared = clearReaderInlinePendingRating(state);
    assert.equal(cleared.pendingRating, null);
    assert.equal(cleared.selectedWordSenseId, null);
    assert.equal(cleared.showAnswer, false);

    const changed = replaceReaderInlineOccurrence(state, { occurrence_id: 'occ2-other' });
    assert.equal(changed.pendingRating, null);
    assert.equal(changed.selectedWordSenseId, null);
});

test('production inline dialog preserves pending rating through manual new-sense continuation', () => {
    const source = fs.readFileSync('resources/js/components/TextReader/ReaderInlineSenseReviewDialog.vue', 'utf8');
    assert.match(source, /create-sense-and-submit/);
    assert.match(source, /rating:\s*this\.state\.pendingRating/);
    assert.match(source, /不会再让你选第二次评分/);
});

test('manual sense form normalizes common token POS tags to backend canonical values', () => {
    assert.equal(normalizeReaderManualSensePos('NOUN'), 'noun');
    assert.equal(normalizeReaderManualSensePos('VERB'), 'verb');
    assert.equal(normalizeReaderManualSensePos('ADJ'), 'adjective');
    assert.equal(normalizeReaderManualSensePos('ADV'), 'adverb');
    assert.equal(normalizeReaderManualSensePos('ADP'), 'preposition');
    assert.equal(normalizeReaderManualSensePos('CCONJ'), 'conjunction');
    assert.equal(normalizeReaderManualSensePos('unknown-tag'), 'other');
});

test('production inline review creates an action id only at formal submit and locks ambiguous retries to the saved command', () => {
    const reader = fs.readFileSync('resources/js/components/TextReader/TextReader.vue', 'utf8');
    const dialog = fs.readFileSync('resources/js/components/TextReader/ReaderInlineSenseReviewDialog.vue', 'utf8');
    assert.match(reader, /const actionCommand = this\.prepareInlineOfficialRatingCommand\(command\)/);
    assert.match(reader, /buildReaderExplicitRatingActionCommand\(identifiedCommand, readingActionId\)/);
    assert.match(reader, /readerExplicitRatingCommandMatchesSession/);
    assert.match(reader, /setInlineOutcomeUnknownCommand\(command\)/);
    assert.match(reader, /performInlineOfficialRating\(this\.inlineOutcomeUnknownCommand, true\)/);
    assert.match(reader, /loadReaderExplicitRatingRetry\(this\.chapterId\)/);
    assert.match(reader, /上一笔正式评分结果仍未知/);
    assert.match(dialog, /outcomeUnknown/);
    assert.match(dialog, /只能安全重试刚才那一笔正式评分/);
    assert.match(dialog, /retry-outcome-unknown/);
    assert.match(dialog, /:disabled="busy \|\| outcomeUnknown"/);
    assert.doesNotMatch(dialog, /buildReaderExplicitRatingActionCommand|reading_action_id/);
});

test('Reader explicit action conflicts stop retry before any 5xx outcome-unknown fallback', () => {
    const reader = fs.readFileSync('resources/js/components/TextReader/TextReader.vue', 'utf8');
    const undoneIndex = reader.indexOf("if (code === 'READING_EXPLICIT_ACTION_UNDONE')");
    const activeIndex = reader.indexOf("else if (code === 'READING_EXPLICIT_ACTION_ACTIVE')", undoneIndex);
    const outcomeUnknownIndex = reader.indexOf('else if (!error || !error.response || status >= 500)', activeIndex);
    assert.ok(undoneIndex > 0 && activeIndex > undoneIndex && outcomeUnknownIndex > activeIndex);
    assert.match(reader, /旧动作编号不会再次评分/);
    assert.match(reader, /停止生成新的动作编号/);
    assert.match(reader, /releaseManualContinuationActionForRatingCommand\(command\)/);
    assert.match(reader, /clearManualContinuationForRatingCommand\(command\)/);
});

test('deterministic 409/422 releases only the rejected manual action id and keeps created sense identity', () => {
    const reader = fs.readFileSync('resources/js/components/TextReader/TextReader.vue', 'utf8');
    assert.match(reader, /releaseManualContinuationActionForRatingCommand\(command\)[\s\S]{0,500}readingActionId: ''/);
    assert.doesNotMatch(reader, /readingActionId: '',[\s\S]{0,120}readingSessionId: ''/);
    const conflictStart = reader.indexOf('else if (status === 409 || status === 422)');
    const conflictEnd = reader.indexOf('} else {', conflictStart);
    assert.ok(conflictStart > 0 && conflictEnd > conflictStart);
    const conflictSource = reader.slice(conflictStart, conflictEnd);
    assert.match(conflictSource, /releaseManualContinuationActionForRatingCommand\(command\)/);
    assert.doesNotMatch(conflictSource, /clearManualContinuationForRatingCommand\(command\)/);
});

test('manual new-sense continuation binds and persists one action id only when formal rating is about to send', () => {
    const reader = fs.readFileSync('resources/js/components/TextReader/TextReader.vue', 'utf8');
    assert.match(reader, /prepareInlineOfficialRatingCommand\([\s\S]{0,500}continuation\.readingActionId \|\| ''\)/);
    assert.match(reader, /readingActionId: actionCommand\.payload\.reading_action_id/);
    assert.match(reader, /readingSessionId: actionCommand\.payload\.reading_session_id/);
    assert.doesNotMatch(reader, /axios\.post\('\/senses\/manual'[\s\S]{0,600}buildReaderExplicitRatingActionCommand\(/);
});

test('successful rating and undo leave no reusable reading action id for a later rerating intent', () => {
    const reader = fs.readFileSync('resources/js/components/TextReader/TextReader.vue', 'utf8');
    assert.match(reader, /\.then\(\(response\) => \{[\s\S]{0,300}setInlineOutcomeUnknownCommand\(null\)/);
    assert.match(reader, /submitInlineOfficialRating\(command\)[\s\S]{0,400}prepareInlineOfficialRatingCommand\(command\)/);
    assert.match(reader, /performInlineOfficialRating\(command[\s\S]{0,500}readerExplicitRatingCommandMatchesSession\(command/);
    const undoStart = reader.indexOf('undoLastInlineRating()');
    const undoEnd = reader.indexOf('resolveReadingSenseEvidence(', undoStart);
    assert.ok(undoStart > 0 && undoEnd > undoStart);
    const undoSource = reader.slice(undoStart, undoEnd);
    assert.doesNotMatch(undoSource, /reading_action_id|createReaderActionId/);
});

test('ordinary known-sense lookup never sends a formal rating or allocates a reading action id', () => {
    const reader = fs.readFileSync('resources/js/components/TextReader/TextReader.vue', 'utf8');
    const lookupStart = reader.indexOf("axios.get('/senses/known-sense-lookup'");
    const lookupEnd = reader.indexOf('startInlineReview()', lookupStart);
    assert.ok(lookupStart > 0 && lookupEnd > lookupStart);
    const lookupSource = reader.slice(lookupStart, lookupEnd);
    assert.doesNotMatch(lookupSource, /rateSenseCard|reading_action_id|createReaderActionId/);
});

test('production Reader fails closed when known-sense card details cannot be loaded', () => {
    const reader = fs.readFileSync('resources/js/components/TextReader/TextReader.vue', 'utf8');
    assert.match(reader, /inlineReviewCandidatesError/);
    assert.match(reader, /this\.inlineReviewCandidates = \[\]/);
    assert.match(reader, /服务器词义卡详情没有加载成功/);
    assert.match(reader, /避免把查询失败误当成没有已有词义/);
    assert.match(reader, /正在重试详情查询/);
    assert.match(reader, /this\.loadInlineReviewCandidates\(target\)/);
});

test('manual-sense recovery persists before transport and never guesses the created sense', () => {
    const reader = fs.readFileSync('resources/js/components/TextReader/TextReader.vue', 'utf8');
    const persistIndex = reader.indexOf('setPendingManualSenseContinuation(pending)');
    const transportIndex = reader.indexOf("axios.post('/senses/manual'", persistIndex);
    assert.ok(persistIndex > 0 && transportIndex > persistIndex);
    assert.match(reader, /readerMalformedManualSenseResponse/);
    assert.match(reader, /manual-create-blocked="manualSenseCreateBlocked"/);
    assert.match(reader, /:frozen-rating="inlineReviewIntent/);
    assert.doesNotMatch(reader, /resolveReaderManualSenseCandidate|excludedSenseIds/);
});
