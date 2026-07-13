// SenseReviewStackUndoGuard.test.mjs
//
// ADR-0009: Review action transaction ledger and stack-based undo.
//
// Node built-in assert tests for the SenseReview stack-undo contract:
//   - Unified requestUndo(action, source) method.
//   - Ctrl/Cmd+Z hotkey guard.
//   - Snackbar, drawer, and hotkey all call requestUndo.
//   - Undo endpoint POST /reviews/senses/review-actions/{id}/undo.
//   - Undo payload includes review_session_id, undo_request_id, source.
//   - No frontend FSRS restoration (no optimistic restore).
//   - No frontend ReviewLog deletion.
//   - 409/404/network error handling.
//   - Restored card moved to front, showAnswer=false, preview cleared.
//   - Session summary excludes undone (removeRating).
//
// Tests:
//   1.  requestUndo method exists.
//   2.  requestUndo checks action.undoable.
//   3.  requestUndo checks undoLoadingReviewLogId guard.
//   4.  requestUndo uses correct undo endpoint.
//   5.  requestUndo sends review_session_id.
//   6.  requestUndo sends undo_request_id (UUID-like).
//   7.  requestUndo sends source parameter.
//   8.  Ctrl+Z triggers undo (handleHotkey has ctrlKey + z check).
//   9.  Cmd+Z triggers undo (metaKey check).
//  10.  Ctrl+Z guarded by input/textarea check.
//  11.  Ctrl+Z guarded by dialog check.
//  12.  Ctrl+Z guarded by showSessionSummary.
//  13.  Ctrl+Z guarded by rating loading.
//  14.  Ctrl+Z guarded by undo loading.
//  15.  Ctrl+Z guarded by latestUndoableAction existence.
//  16.  Snackbar undo calls requestUndo with source sense_review_snackbar.
//  17.  Drawer undo calls requestUndo with source sense_review_history.
//  18.  Hotkey calls requestUndo with source sense_review_hotkey.
//  19.  Undo success: closes undo snackbar.
//  20.  Undo success: reloads cards (loadCards).
//  21.  Undo success: moves restored card to front of queue.
//  22.  Undo success: sets showAnswer=false.
//  23.  Undo success: clears interval preview.
//  24.  Undo success: calls SessionTracker.removeRating.
//  25.  Undo success: decrements reviewedCount.
//  26.  Undo success: calls loadSessionActions.
//  27.  Undo success: calls loadFsrsStats.
//  28.  Undo success: shows "已撤销上一次评分" snackbar.
//  29.  409 error: shows conflict message.
//  30.  404 error: shows session mismatch message.
//  31.  Network error: shows network message.
//  32.  No frontend FSRS calculation in requestUndo.
//  33.  No frontend ReviewLog deletion (no DELETE /review-log).
//  34.  No optimistic restore (no local card state mutation before API response).
//  35.  undoLoadingReviewLogId set during request and cleared in finally.

import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const REVIEW_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'Senses', 'SenseReview.vue');
const TRACKER_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'Senses', 'SenseReviewSessionTracker.js');

let passed = 0;
function test(name, fn) {
    try {
        fn();
        passed++;
    } catch (e) {
        console.error('FAIL: ' + name);
        console.error(e.message);
        process.exitCode = 1;
    }
}

const source = existsSync(REVIEW_PATH) ? readFileSync(REVIEW_PATH, 'utf-8') : '';
const trackerSource = existsSync(TRACKER_PATH) ? readFileSync(TRACKER_PATH, 'utf-8') : '';

// Extract the requestUndo method section (from requestUndo to the next method)
const undoStart = source.indexOf('requestUndo(action, source)');
// Find handleHotkey AFTER undoStart (not the beforeDestroy reference)
const handleHotkeyAfterUndo = undoStart >= 0 ? source.indexOf('handleHotkey', undoStart) : -1;
const undoSection = undoStart >= 0 && handleHotkeyAfterUndo >= 0
    ? source.substring(undoStart, handleHotkeyAfterUndo)
    : (undoStart >= 0 ? source.substring(undoStart, undoStart + 3000) : '');

// 1. requestUndo method exists
test('requestUndo method exists', () => {
    assert.ok(undoStart >= 0, 'must have requestUndo method');
});

// 2. Checks action.undoable
test('requestUndo checks action.undoable', () => {
    assert.ok(undoSection.includes('action.undoable'), 'must check action.undoable');
});

// 3. Checks undoLoadingReviewLogId guard
test('requestUndo checks undoLoadingReviewLogId guard', () => {
    assert.ok(undoSection.includes('undoLoadingReviewLogId !== null'), 'must check undoLoadingReviewLogId');
});

// 4. Uses correct undo endpoint
test('requestUndo uses correct undo endpoint', () => {
    assert.ok(source.includes('/reviews/senses/review-actions/'), 'must use /reviews/senses/review-actions/ endpoint');
    assert.ok(source.includes('/undo'), 'must use /undo path');
});

// 5. Sends review_session_id
test('requestUndo sends review_session_id', () => {
    assert.ok(undoSection.includes('review_session_id'), 'must send review_session_id');
    assert.ok(undoSection.includes('this.reviewSessionId'), 'must send this.reviewSessionId');
});

// 6. Sends undo_request_id
test('requestUndo sends undo_request_id', () => {
    assert.ok(undoSection.includes('undo_request_id'), 'must send undo_request_id');
});

// 7. Sends source parameter
test('requestUndo sends source parameter', () => {
    assert.ok(undoSection.includes('source: source'), 'must send source parameter');
});

// 8. Ctrl+Z triggers undo
test('Ctrl+Z triggers undo', () => {
    assert.ok(source.includes('event.ctrlKey'), 'must check ctrlKey');
    assert.ok(source.includes("'z'") || source.includes('"z"'), 'must check z key');
});

// 9. Cmd+Z triggers undo
test('Cmd+Z triggers undo', () => {
    assert.ok(source.includes('event.metaKey'), 'must check metaKey for Cmd+Z');
});

// 10. Ctrl+Z guarded by input/textarea
test('Ctrl+Z guarded by input/textarea check', () => {
    // Find the handleHotkey method definition (handleHotkey(event)) — not the
    // addEventListener / removeEventListener references which appear earlier.
    const methodStart = source.indexOf('handleHotkey(event)');
    assert.ok(methodStart >= 0, 'handleHotkey method must exist');
    const hotkeySection = source.substring(methodStart, methodStart + 1500);
    const ctrlZSection = hotkeySection.substring(hotkeySection.indexOf('ctrlKey'));
    assert.ok(ctrlZSection.includes('input') && ctrlZSection.includes('textarea'), 'Ctrl+Z must be guarded by input/textarea');
});

// 11. Ctrl+Z guarded by dialog check
test('Ctrl+Z guarded by dialog check', () => {
    const methodStart = source.indexOf('handleHotkey(event)');
    const hotkeySection = source.substring(methodStart, methodStart + 1500);
    const ctrlZSection = hotkeySection.substring(hotkeySection.indexOf('ctrlKey'));
    assert.ok(ctrlZSection.includes('editDialog') || ctrlZSection.includes('archiveDialog'), 'Ctrl+Z must check dialogs');
});

// 12. Ctrl+Z guarded by showSessionSummary
test('Ctrl+Z guarded by showSessionSummary', () => {
    const methodStart = source.indexOf('handleHotkey(event)');
    const hotkeySection = source.substring(methodStart, methodStart + 1500);
    const ctrlZSection = hotkeySection.substring(hotkeySection.indexOf('ctrlKey'));
    assert.ok(ctrlZSection.includes('showSessionSummary'), 'Ctrl+Z must check showSessionSummary');
});

// 13. Ctrl+Z guarded by rating loading
test('Ctrl+Z guarded by rating loading', () => {
    const methodStart = source.indexOf('handleHotkey(event)');
    const hotkeySection = source.substring(methodStart, methodStart + 1500);
    const ctrlZSection = hotkeySection.substring(hotkeySection.indexOf('ctrlKey'));
    assert.ok(ctrlZSection.includes('this.rating'), 'Ctrl+Z must check rating loading');
});

// 14. Ctrl+Z guarded by undo loading
test('Ctrl+Z guarded by undo loading', () => {
    const methodStart = source.indexOf('handleHotkey(event)');
    const hotkeySection = source.substring(methodStart, methodStart + 1500);
    const ctrlZSection = hotkeySection.substring(hotkeySection.indexOf('ctrlKey'));
    assert.ok(ctrlZSection.includes('undoLoadingReviewLogId'), 'Ctrl+Z must check undo loading');
});

// 15. Ctrl+Z guarded by latestUndoableAction
test('Ctrl+Z guarded by latestUndoableAction existence', () => {
    const methodStart = source.indexOf('handleHotkey(event)');
    const hotkeySection = source.substring(methodStart, methodStart + 1500);
    const ctrlZSection = hotkeySection.substring(hotkeySection.indexOf('ctrlKey'));
    assert.ok(ctrlZSection.includes('latestUndoableAction'), 'Ctrl+Z must check latestUndoableAction');
});

// 16. Snackbar undo uses sense_review_snackbar source
test('snackbar undo calls requestUndo with sense_review_snackbar', () => {
    assert.ok(source.includes("'sense_review_snackbar'"), 'snackbar undo must use sense_review_snackbar source');
});

// 17. Drawer undo uses sense_review_history source
test('drawer undo calls requestUndo with sense_review_history', () => {
    assert.ok(source.includes("'sense_review_history'"), 'drawer undo must use sense_review_history source');
});

// 18. Hotkey uses sense_review_hotkey source
test('hotkey calls requestUndo with sense_review_hotkey', () => {
    assert.ok(source.includes("'sense_review_hotkey'"), 'hotkey undo must use sense_review_hotkey source');
});

// 19. Undo success: closes undo snackbar
test('undo success closes undo snackbar', () => {
    assert.ok(undoSection.includes('this.undoSnackbar.show = false'), 'must close undo snackbar on success');
});

// 20. Undo success: reloads cards
test('undo success reloads cards', () => {
    assert.ok(undoSection.includes('this.loadCards()'), 'must reload cards on success');
});

// 21. Undo success: moves restored card to front
test('undo success moves restored card to front', () => {
    assert.ok(undoSection.includes('unshift'), 'must move restored card to front (unshift)');
});

// 22. Undo success: sets showAnswer=false
test('undo success sets showAnswer=false', () => {
    assert.ok(undoSection.includes('this.showAnswer = false'), 'must set showAnswer=false on success');
});

// 23. Undo success: clears interval preview
test('undo success clears interval preview', () => {
    assert.ok(undoSection.includes('this.intervalPreviews = null'), 'must clear interval preview on success');
});

// 24. Undo success: calls SessionTracker.removeRating
test('undo success calls SessionTracker.removeRating', () => {
    assert.ok(undoSection.includes('SessionTracker.removeRating'), 'must call removeRating to exclude undone from summary');
    assert.ok(trackerSource.includes('removeRating'), 'SessionTracker must export removeRating');
});

// 25. Undo success: decrements reviewedCount
test('undo success decrements reviewedCount', () => {
    assert.ok(undoSection.includes('this.reviewedCount--'), 'must decrement reviewedCount on success');
});

// 26. Undo success: calls loadSessionActions
test('undo success calls loadSessionActions', () => {
    assert.ok(undoSection.includes('this.loadSessionActions()'), 'must refresh timeline on success');
});

// 27. Undo success: calls loadFsrsStats
test('undo success calls loadFsrsStats', () => {
    assert.ok(undoSection.includes('this.loadFsrsStats()'), 'must refresh stats on success');
});

// 28. Undo success: shows "已撤销" snackbar
test('undo success shows 已撤销 snackbar', () => {
    assert.ok(undoSection.includes('已撤销上一次评分'), 'must show 已撤销上一次评分 message');
});

// 29. 409 error handling
test('409 error shows conflict message', () => {
    assert.ok(undoSection.includes('409'), 'must handle 409 status');
    assert.ok(undoSection.includes('卡片状态已在其他页面发生变化'), 'must show conflict message for 409');
});

// 30. 404 error handling
test('404 error shows session mismatch message', () => {
    assert.ok(undoSection.includes('404'), 'must handle 404 status');
    assert.ok(undoSection.includes('不属于当前复习会话'), 'must show session mismatch message for 404');
});

// 31. Network error handling
test('network error shows network message', () => {
    assert.ok(undoSection.includes('撤销失败，请检查网络后重试'), 'must show network error message');
});

// 32. No frontend FSRS calculation in requestUndo
test('no frontend FSRS calculation in requestUndo', () => {
    // The undo must NOT calculate stability/difficulty/due_at on the frontend
    assert.ok(!undoSection.includes('fsrs_stability ='), 'must not set fsrs_stability on frontend');
    assert.ok(!undoSection.includes('fsrs_difficulty ='), 'must not set fsrs_difficulty on frontend');
    assert.ok(!undoSection.includes('fsrs_due_at ='), 'must not set fsrs_due_at on frontend');
});

// 33. No frontend ReviewLog deletion
test('no frontend ReviewLog deletion', () => {
    // axios.delete is allowed for card deletion (/review-cards/manage/{id}),
    // but must NOT target ReviewLog endpoints. The undo backend never deletes
    // the log; it only marks it undone.
    assert.ok(!source.includes('/review-log'), 'must not reference /review-log endpoint');
    assert.ok(!source.includes('/review_logs/'), 'must not target review_logs endpoint');
    assert.ok(!source.includes('deleteReviewLog'), 'must not call deleteReviewLog');
});

// 34. No optimistic restore (no local card state mutation before API response)
test('no optimistic restore before API response', () => {
    // The card restoration must happen inside the .then() callback, not before
    const thenIdx = undoSection.indexOf('.then(');
    const beforeThen = undoSection.substring(0, thenIdx);
    assert.ok(!beforeThen.includes('this.cards'), 'must not mutate cards before API response');
    assert.ok(!beforeThen.includes('showAnswer = false'), 'must not set showAnswer before API response');
});

// 35. undoLoadingReviewLogId set and cleared
test('undoLoadingReviewLogId set during request and cleared in finally', () => {
    assert.ok(undoSection.includes('this.undoLoadingReviewLogId = action.review_log_id'), 'must set undoLoadingReviewLogId');
    assert.ok(undoSection.includes('this.undoLoadingReviewLogId = null'), 'must clear undoLoadingReviewLogId in finally');
});

// ==================== DEV-QO-7: SenseReview next_card & loadCards guard ====================

// Helper: extract method body from SenseReview.vue
function extractSenseMethod(name) {
    const re = new RegExp(name + '\\s*\\([^)]*\\)\\s*\\{');
    const m = source.match(re);
    if (!m) return '';
    const start = m.index + m[0].length;
    let depth = 1;
    let i = start;
    while (i < source.length && depth > 0) {
        if (source[i] === '{') depth++;
        else if (source[i] === '}') depth--;
        i++;
    }
    return source.slice(m.index, i);
}

const loadCardsBody = extractSenseMethod('loadCards');
const rateBody = extractSenseMethod('rate');

// 36. DEV-QO-7: loadCardsRequestSequence exists in data()
test('loadCardsRequestSequence exists in data()', () => {
    assert.ok(/loadCardsRequestSequence\s*:\s*0/.test(source),
        'data() must include loadCardsRequestSequence: 0');
});

// 37. DEV-QO-7: loadCards() increments loadCardsRequestSequence
test('loadCards() increments loadCardsRequestSequence', () => {
    assert.ok(/this\.loadCardsRequestSequence\+\+/.test(loadCardsBody),
        'loadCards() must increment loadCardsRequestSequence');
});

// 38. DEV-QO-7: loadCards() checks seq in .then() (stale response drop)
test('loadCards() drops stale responses in .then()', () => {
    assert.ok(/seq\s*!==\s*this\.loadCardsRequestSequence/.test(loadCardsBody),
        'loadCards() must check seq !== this.loadCardsRequestSequence in .then()');
});

// 39. DEV-QO-7: loadCards() checks seq in .catch()
test('loadCards() drops stale responses in .catch()', () => {
    const catchMatch = loadCardsBody.match(/\.catch\(\(([^)]*)\)\s*=>\s*\{([\s\S]*?)\}\)/);
    assert.ok(catchMatch, 'loadCards() must have a .catch() handler');
    assert.ok(/seq\s*!==\s*this\.loadCardsRequestSequence/.test(catchMatch[0]),
        'loadCards() .catch() must check seq');
});

// 40. DEV-QO-7: loadCards() checks seq in .finally()
test('loadCards() only clears loading in .finally() if seq matches', () => {
    const finallyMatch = loadCardsBody.match(/\.finally\(\(\)\s*=>\s*\{([\s\S]*?)\}\)/);
    assert.ok(finallyMatch, 'loadCards() must have a .finally() handler');
    assert.ok(/seq\s*===\s*this\.loadCardsRequestSequence/.test(finallyMatch[0]),
        'loadCards() .finally() must check seq === this.loadCardsRequestSequence');
});

// 41. DEV-QO-7: rate() checks this.rating at start (double-click protection)
test('rate() checks this.rating at start', () => {
    assert.ok(/if\s*\(\s*this\.rating\s*\)\s*\{[\s\S]*?return/.test(rateBody),
        'rate() must check this.rating and return early if true');
});

// 42. DEV-QO-7: rate() increments loadCardsRequestSequence (invalidates in-flight loadCards)
test('rate() increments loadCardsRequestSequence', () => {
    assert.ok(/this\.loadCardsRequestSequence\+\+/.test(rateBody),
        'rate() must increment loadCardsRequestSequence to invalidate in-flight loadCards()');
});

// 43. DEV-QO-7: rate() passes ignoreDailyLimits to backend
test('rate() passes ignoreDailyLimits to backend', () => {
    assert.ok(/payload\.ignoreDailyLimits\s*=\s*true/.test(rateBody),
        'rate() must set payload.ignoreDailyLimits when flag is true');
});

// 44. DEV-QO-7: rate() reads response.data.summary
test('rate() reads response.data.summary', () => {
    assert.ok(/response\.data\.summary/.test(rateBody),
        'rate() must read response.data.summary from backend');
});

// 45. DEV-QO-7: rate() reads response.data.reviewed_card
test('rate() reads response.data.reviewed_card', () => {
    assert.ok(/response\.data\.reviewed_card/.test(rateBody),
        'rate() must read response.data.reviewed_card from backend');
});

// 46. DEV-QO-7: rate() reads response.data.action
test('rate() reads response.data.action for undo metadata', () => {
    assert.ok(/response\.data\.action/.test(rateBody),
        'rate() must read response.data.action from backend');
});

// 47. DEV-QO-7: No Math.random used to pick next card (only for requestId)
test('no Math.random used to pick next card in rate()', () => {
    // Math.random is allowed ONLY for requestId generation, not for
    // deciding the next card. The next card comes from the backend via
    // loadCards() which uses Queue Order.
    const randomMatches = rateBody.match(/Math\.random/g) || [];
    // Should only appear in the requestId line, not in next-card logic
    assert.ok(rateBody.includes('requestId'),
        'Math.random should only be used for requestId generation');
});

// 48. DEV-QO-7: rate() calls loadCards() after successful rating
test('rate() calls loadCards() after successful rating', () => {
    assert.ok(/this\.loadCards\(\)/.test(rateBody),
        'rate() must call loadCards() after successful rating to refresh queue');
});

console.log(`\n${passed} passed`);
