import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import test from 'node:test';

const surfaceUrl = new URL('../../resources/js/components/Senses/SenseReviewSessionActionsSurface.vue', import.meta.url);
const parent = readFileSync(new URL('../../resources/js/components/Senses/SenseReview.vue', import.meta.url), 'utf8');
const tracker = readFileSync(new URL('../../resources/js/components/Senses/SenseReviewSessionTracker.js', import.meta.url), 'utf8');
const surface = existsSync(surfaceUrl) ? readFileSync(surfaceUrl, 'utf8') : '';

test('surface exclusively owns undo request state and API call', () => {
    assert.match(surface, /requestUndo\(action, source\)/);
    assert.match(surface, /!action\.undoable/);
    assert.match(surface, /undoLoadingReviewLogId !== null/);
    assert.match(surface, /undo_request_id: undoRequestId/);
    assert.match(surface, /review_session_id: this\.reviewSessionId/);
    assert.match(surface, /source: source/);
    assert.match(surface, /reviewApi\.undoSenseReviewAction\(action\.review_log_id, payload\)/);
    assert.match(surface, /status === 409/);
    assert.match(surface, /status === 404/);
    assert.match(surface, /无法撤销：卡片状态已在其他页面发生变化/);
    assert.match(surface, /无法撤销：该操作不属于当前复习会话/);
    assert.match(surface, /撤销失败，请检查网络后重试/);
    assert.match(surface, /this\.\$emit\('undone', response\.data\)/);
    assert.doesNotMatch(parent, /undoSenseReviewAction/);
    assert.doesNotMatch(parent, /\/reviews\/senses\/review-actions/);
});

test('parent delegates snackbar and hotkey undo to the surface', () => {
    assert.match(parent, /requestUndo\(action, source\)[\s\S]*this\.\$refs\.sessionActionsSurface\.requestUndo\(action, source\)/);
    assert.match(parent, /event\.ctrlKey/);
    assert.match(parent, /event\.metaKey/);
    assert.match(parent, /latestUndoableAction/);
    assert.match(parent, /undoLoadingReviewLogId/);
    assert.match(parent, /'sense_review_snackbar'/);
    assert.match(surface, /'sense_review_history'/);
    assert.match(parent, /'sense_review_hotkey'/);
});

test('parent owns canonical post-undo queue and session reconciliation', () => {
    const start = parent.indexOf('onSessionActionUndone(data)');
    assert.ok(start >= 0, 'parent must handle the surface undone event');
    const section = parent.slice(start, parent.indexOf('handleHotkey(event)', start));
    assert.match(section, /this\.undoSnackbar\.show = false/);
    assert.match(section, /this\.loadCards\(\)/);
    assert.match(section, /this\.cards\.unshift\(card\)/);
    assert.match(section, /this\.showAnswer = false/);
    assert.match(section, /this\.intervalPreviews = null/);
    assert.match(section, /SessionTracker\.removeRating/);
    assert.match(section, /this\.reviewedCount--/);
    assert.match(section, /this\.loadFsrsStats\(\)/);
    assert.match(section, /已撤销上一次评分，可以重新作答/);
    assert.match(tracker, /removeRating/);
    assert.doesNotMatch(section, /stability|difficulty|fsrs_state/);
    assert.doesNotMatch(parent, /delete[^\n]*ReviewLog/i);
});
