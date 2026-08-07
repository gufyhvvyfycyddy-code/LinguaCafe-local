import assert from 'node:assert/strict';
import test from 'node:test';

import {
    buildReaderInlineOfficialRatingCommand,
    chooseReaderInlineRating,
    chooseReaderInlineSense,
    clearReaderInlinePendingRating,
    createReaderInlineSenseReviewState,
    replaceReaderInlineOccurrence,
    revealReaderInlineSenseAnswer,
} from '../../resources/js/services/ReaderInlineSenseReviewPolicy.js';

const occurrence = { occurrence_id: 'occ2-bank', surface: 'bank' };
const candidates = [
    { word_sense_id: 81, review_card_id: 181, fsrs_enabled: true },
    { word_sense_id: 95, review_card_id: 195, fsrs_enabled: true },
];

test('inline review must reveal before a rating can become pending', () => {
    const initial = createReaderInlineSenseReviewState(occurrence);
    assert.equal(chooseReaderInlineRating(initial, 'good').pendingRating, null);
    const revealed = revealReaderInlineSenseAnswer(initial);
    assert.equal(chooseReaderInlineRating(revealed, 'good').pendingRating, 'good');
});

test('formal command requires both explicit rating and concrete WordSense with active review card', () => {
    let state = revealReaderInlineSenseAnswer(createReaderInlineSenseReviewState(occurrence));
    state = chooseReaderInlineRating(state, 'hard');
    assert.equal(buildReaderInlineOfficialRatingCommand(state, candidates, 'session-1'), null);
    state = chooseReaderInlineSense(state, 95);
    assert.deepEqual(buildReaderInlineOfficialRatingCommand(state, candidates, 'session-1'), {
        reviewCardId: 195,
        wordSenseId: 95,
        payload: {
            rating: 'hard',
            reading_session_id: 'session-1',
            occurrence_id: 'occ2-bank',
        },
        occurrenceId: 'occ2-bank',
    });
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
