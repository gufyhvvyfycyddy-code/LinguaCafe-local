import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

import {
    buildReaderInlineOfficialRatingCommand,
    chooseReaderInlineRating,
    chooseReaderInlineSense,
    clearReaderInlinePendingRating,
    createReaderInlineSenseReviewState,
    normalizeReaderManualSensePos,
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

test('production inline review locks choices after an ambiguous rating response and exposes only safe retry', () => {
    const reader = fs.readFileSync('resources/js/components/TextReader/TextReader.vue', 'utf8');
    const dialog = fs.readFileSync('resources/js/components/TextReader/ReaderInlineSenseReviewDialog.vue', 'utf8');
    assert.match(reader, /inlineOutcomeUnknownCommand = command/);
    assert.match(reader, /performInlineOfficialRating\(this\.inlineOutcomeUnknownCommand, true\)/);
    assert.match(reader, /上一笔正式评分结果仍未知/);
    assert.match(dialog, /outcomeUnknown/);
    assert.match(dialog, /只能安全重试刚才那一笔正式评分/);
    assert.match(dialog, /retry-outcome-unknown/);
    assert.match(dialog, /:disabled="busy \|\| outcomeUnknown"/);
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

test('manual-sense continuation distinguishes malformed server identity from network outcome-unknown', () => {
    const reader = fs.readFileSync('resources/js/components/TextReader/TextReader.vue', 'utf8');
    assert.match(reader, /readerMalformedManualSenseResponse/);
    assert.match(reader, /缺少词义卡身份/);
    assert.match(reader, /停止续接和自动重发创建请求/);
    assert.match(reader, /setPendingManualSenseContinuation\(null\)/);
});
