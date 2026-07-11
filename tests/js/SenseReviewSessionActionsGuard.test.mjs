// SenseReviewSessionActionsGuard.test.mjs
//
// ADR-0009: Review action transaction ledger and stack-based undo.
//
// Node built-in assert tests for the SenseReview session-actions timeline
// contract between:
//   - SenseReview.vue (loads and displays session actions)
//   - Backend endpoint GET /reviews/senses/session-actions
//
// Guards:
//   1.  SenseReview.vue imports getOrCreateReviewSessionId.
//   2.  SenseReview.vue has reviewSessionId data field.
//   3.  SenseReview.vue has sessionActions data field.
//   4.  SenseReview.vue has sessionActionsLoading data field.
//   5.  SenseReview.vue has sessionActionDrawerOpen data field.
//   6.  SenseReview.vue has sessionActionRequestSequence data field.
//   7.  SenseReview.vue calls getOrCreateReviewSessionId in mounted.
//   8.  SenseReview.vue calls loadSessionActions in mounted.
//   9.  loadSessionActions uses correct endpoint /reviews/senses/session-actions.
//  10.  loadSessionActions passes review_session_id as query param.
//  11.  loadSessionActions has race protection (sequence check).
//  12.  rate() sends review_session_id in POST payload.
//  13.  rate() uses response.data.action (not faking review_log_id).
//  14.  rate() calls loadSessionActions after success.
//  15.  Session actions drawer shows "本次操作" text.
//  16.  Drawer shows undo button only for undoable actions.
//  17.  latestUndoableAction computed exists.
//  18.  activeSessionActionCount computed exists.
//  19.  No localStorage usage for session ID.
//  20.  No fake review_log_id generation on frontend.

import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const REVIEW_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'Senses', 'SenseReview.vue');
const IDENTITY_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'Senses', 'SenseReviewSessionIdentity.js');

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
const identitySource = existsSync(IDENTITY_PATH) ? readFileSync(IDENTITY_PATH, 'utf-8') : '';

// 1. Imports getOrCreateReviewSessionId
test('imports getOrCreateReviewSessionId', () => {
    assert.ok(source.includes('getOrCreateReviewSessionId'), 'must import getOrCreateReviewSessionId');
    assert.ok(source.includes('SenseReviewSessionIdentity'), 'must import from SenseReviewSessionIdentity');
});

// 2. Has reviewSessionId data field
test('has reviewSessionId data field', () => {
    assert.ok(/reviewSessionId\s*:/.test(source), 'must have reviewSessionId data field');
});

// 3. Has sessionActions data field
test('has sessionActions data field', () => {
    assert.ok(/sessionActions\s*:/.test(source), 'must have sessionActions data field');
});

// 4. Has sessionActionsLoading data field
test('has sessionActionsLoading data field', () => {
    assert.ok(/sessionActionsLoading\s*:/.test(source), 'must have sessionActionsLoading data field');
});

// 5. Has sessionActionDrawerOpen data field
test('has sessionActionDrawerOpen data field', () => {
    assert.ok(/sessionActionDrawerOpen\s*:/.test(source), 'must have sessionActionDrawerOpen data field');
});

// 6. Has sessionActionRequestSequence data field
test('has sessionActionRequestSequence data field', () => {
    assert.ok(/sessionActionRequestSequence\s*:/.test(source), 'must have sessionActionRequestSequence data field');
});

// 7. Calls getOrCreateReviewSessionId in mounted
test('calls getOrCreateReviewSessionId in mounted', () => {
    assert.ok(source.includes('this.reviewSessionId = getOrCreateReviewSessionId'), 'must call getOrCreateReviewSessionId in mounted');
});

// 8. Calls loadSessionActions in mounted
test('calls loadSessionActions in mounted', () => {
    assert.ok(source.includes('this.loadSessionActions()'), 'must call loadSessionActions in mounted');
});

// 9. loadSessionActions uses correct endpoint
test('loadSessionActions uses correct endpoint', () => {
    assert.ok(source.includes('/reviews/senses/session-actions'), 'must use /reviews/senses/session-actions endpoint');
});

// 10. Passes review_session_id as query param
test('loadSessionActions passes review_session_id as query param', () => {
    assert.ok(source.includes('review_session_id: this.reviewSessionId'), 'must pass review_session_id as query param');
});

// 11. Has race protection (sequence check)
test('loadSessionActions has race protection', () => {
    assert.ok(source.includes('sessionActionRequestSequence'), 'must have sessionActionRequestSequence');
    assert.ok(source.includes('seq !== this.sessionActionRequestSequence'), 'must check sequence for stale responses');
});

// 12. rate() sends review_session_id in POST payload
test('rate() sends review_session_id in POST payload', () => {
    assert.ok(source.includes('payload.review_session_id = this.reviewSessionId'), 'must add review_session_id to rating payload');
});

// 13. rate() uses response.data.action (not faking review_log_id)
test('rate() uses response.data.action from backend', () => {
    assert.ok(source.includes('response.data.action'), 'must use response.data.action');
    // Must NOT generate a fake review_log_id on the frontend
    assert.ok(!source.includes('fake_review_log_id'), 'must not fake review_log_id');
});

// 14. rate() calls loadSessionActions after success
test('rate() calls loadSessionActions after success', () => {
    // Check that loadSessionActions is called within the rate success handler
    const rateSection = source.substring(source.indexOf('rate(rating)'));
    const successSection = rateSection.substring(0, rateSection.indexOf('.catch'));
    assert.ok(successSection.includes('this.loadSessionActions()'), 'must call loadSessionActions after rating success');
});

// 15. Session actions drawer shows "本次操作" text
test('drawer shows 本次操作 text', () => {
    assert.ok(source.includes('本次操作'), 'must show 本次操作 text in drawer');
});

// 16. Drawer shows undo button only for undoable actions
test('drawer shows undo button only for undoable actions', () => {
    assert.ok(source.includes('v-if="action.undoable"'), 'undo button must be gated on action.undoable');
});

// 17. latestUndoableAction computed exists
test('latestUndoableAction computed exists', () => {
    assert.ok(/latestUndoableAction\s*\(\)/.test(source), 'must have latestUndoableAction computed');
});

// 18. activeSessionActionCount computed exists
test('activeSessionActionCount computed exists', () => {
    assert.ok(/activeSessionActionCount\s*\(\)/.test(source), 'must have activeSessionActionCount computed');
});

// 19. No localStorage usage for session ID
test('no localStorage usage for session ID', () => {
    assert.ok(!identitySource.includes('localStorage.getItem'), 'identity helper must not use localStorage.getItem');
    assert.ok(!identitySource.includes('localStorage.setItem'), 'identity helper must not use localStorage.setItem');
    assert.ok(!identitySource.includes('localStorage.removeItem'), 'identity helper must not use localStorage.removeItem');
});

// 20. No fake review_log_id generation on frontend
test('no fake review_log_id generation in rate()', () => {
    // The frontend must use the backend-provided review_log_id, not generate one
    const rateSection = source.substring(source.indexOf('rate(rating)'));
    const successSection = rateSection.substring(0, rateSection.indexOf('.catch'));
    assert.ok(!successSection.includes('review_log_id:'), 'must not set review_log_id manually in rate success');
    assert.ok(!successSection.includes("review_log_id = '"), 'must not generate fake review_log_id');
});

console.log(`\n${passed} passed`);
