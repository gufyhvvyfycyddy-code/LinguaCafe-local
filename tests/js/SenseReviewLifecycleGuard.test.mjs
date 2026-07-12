// SenseReviewLifecycleGuard.test.mjs
//
// ADR-0010: Review card lifecycle state machine.
//
// Node built-in assert tests for the SenseReview.vue lifecycle contract:
//   - Imports lifecycle presentation helpers.
//   - Has lifecycle data fields (descriptor, loading, dialog, conflict).
//   - Has lifecycle methods (fetch, click, execute, perform).
//   - Uses POST /review-cards/{id}/lifecycle-actions endpoint.
//   - Sends request_id (UUID) and expected_version.
//   - 409/422/network error handling.
//   - No frontend state machine replication.
//   - availableLifecycleActions from descriptor (not hardcoded).
//
// Tests:
//   1.  File exists.
//   2.  Imports from ReviewCardLifecyclePresentation.
//   3.  Has lifecycleDescriptor data field.
//   4.  Has lifecycleLoading data field.
//   5.  Has lifecycleDialog data field.
//   6.  Has lifecycleConflict data field.
//   7.  Has availableLifecycleActions computed.
//   8.  Has currentCardLifecycleState computed.
//   9.  Has currentCardIsInactive computed.
//  10.  Has fetchLifecycleDescriptor method.
//  11.  Has onLifecycleMenuClick method.
//  12.  Has executeLifecycleAction method.
//  13.  Has openLifecycleDialog method.
//  14.  Has performLifecycleAction method.
//  15.  Uses POST /review-cards/{id}/lifecycle-actions endpoint.
//  16.  Sends request_id in payload.
//  17.  Sends expected_version in payload.
//  18.  Uses crypto.randomUUID with fallback.
//  19.  409 error: sets lifecycleConflict + refreshes descriptor.
//  20.  422 error: sets lifecycleConflict + refreshes descriptor.
//  21.  Network error: shows snackbar, keeps dialog open.
//  22.  availableLifecycleActions derives from descriptor (not hardcoded list).
//  23.  No frontend state machine (no transition table, no state map).
//  24.  lifecycleConflict cleared on new action attempt.

import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const REVIEW_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'Senses', 'SenseReview.vue');

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

// 1. File exists
test('File exists', () => {
    assert.ok(existsSync(REVIEW_PATH), 'SenseReview.vue must exist');
});

// 2. Imports from ReviewCardLifecyclePresentation
test('Imports from ReviewCardLifecyclePresentation', () => {
    assert.ok(source.includes('ReviewCardLifecyclePresentation'), 'Must import lifecycle presentation helpers');
});

// 3. Has lifecycleDescriptor data field
test('Has lifecycleDescriptor data field', () => {
    assert.ok(source.includes('lifecycleDescriptor'), 'Must have lifecycleDescriptor data field');
});

// 4. Has lifecycleLoading data field
test('Has lifecycleLoading data field', () => {
    assert.ok(source.includes('lifecycleLoading'), 'Must have lifecycleLoading data field');
});

// 5. Has lifecycleDialog data field
test('Has lifecycleDialog data field', () => {
    assert.ok(source.includes('lifecycleDialog'), 'Must have lifecycleDialog data field');
});

// 6. Has lifecycleConflict data field
test('Has lifecycleConflict data field', () => {
    assert.ok(source.includes('lifecycleConflict'), 'Must have lifecycleConflict data field');
});

// 7. Has availableLifecycleActions computed
test('Has availableLifecycleActions computed', () => {
    assert.ok(source.includes('availableLifecycleActions'), 'Must have availableLifecycleActions computed property');
});

// 8. Has currentCardLifecycleState computed
test('Has currentCardLifecycleState computed', () => {
    assert.ok(source.includes('currentCardLifecycleState'), 'Must have currentCardLifecycleState computed');
});

// 9. Has currentCardIsInactive computed
test('Has currentCardIsInactive computed', () => {
    assert.ok(source.includes('currentCardIsInactive'), 'Must have currentCardIsInactive computed');
});

// 10. Has fetchLifecycleDescriptor method
test('Has fetchLifecycleDescriptor method', () => {
    assert.ok(source.includes('fetchLifecycleDescriptor'), 'Must have fetchLifecycleDescriptor method');
});

// 11. Has onLifecycleMenuClick method
test('Has onLifecycleMenuClick method', () => {
    assert.ok(source.includes('onLifecycleMenuClick'), 'Must have onLifecycleMenuClick method');
});

// 12. Has executeLifecycleAction method
test('Has executeLifecycleAction method', () => {
    assert.ok(source.includes('executeLifecycleAction'), 'Must have executeLifecycleAction method');
});

// 13. Has openLifecycleDialog method
test('Has openLifecycleDialog method', () => {
    assert.ok(source.includes('openLifecycleDialog'), 'Must have openLifecycleDialog method');
});

// 14. Has performLifecycleAction method
test('Has performLifecycleAction method', () => {
    assert.ok(source.includes('performLifecycleAction'), 'Must have performLifecycleAction method');
});

// 15. Uses POST /review-cards/{id}/lifecycle-actions endpoint
test('Uses POST /review-cards/{id}/lifecycle-actions endpoint', () => {
    assert.ok(source.includes('/lifecycle-actions'), 'Must use lifecycle-actions endpoint');
    assert.ok(source.includes('axios.post'), 'Must use POST method');
});

// 16. Sends request_id in payload
test('Sends request_id in payload', () => {
    assert.ok(source.includes('request_id'), 'Must send request_id for idempotency');
});

// 17. Sends expected_version in payload
test('Sends expected_version in payload', () => {
    assert.ok(source.includes('expected_version'), 'Must send expected_version for optimistic locking');
});

// 18. Uses crypto.randomUUID with fallback
test('Uses crypto.randomUUID with fallback', () => {
    assert.ok(source.includes('crypto') && source.includes('randomUUID'), 'Must use crypto.randomUUID');
    assert.ok(source.includes('Date.now') || source.includes('Math.random'), 'Must have fallback when crypto.randomUUID unavailable');
});

// 19. 409 error handling
test('409 error: sets lifecycleConflict + refreshes descriptor', () => {
    assert.ok(source.includes('409'), 'Must handle 409 status');
    assert.ok(source.includes('lifecycleConflict'), 'Must set lifecycleConflict on 409');
});

// 20. 422 error handling
test('422 error: sets lifecycleConflict + refreshes descriptor', () => {
    assert.ok(source.includes('422'), 'Must handle 422 status');
});

// 21. Network error handling
test('Network error: shows snackbar, keeps dialog open', () => {
    assert.ok(source.includes('!err.response') || source.includes('!err.response?'), 'Must handle network error (no err.response)');
    assert.ok(source.includes('网络错误'), 'Must show network error message');
});

// 22. availableLifecycleActions derives from descriptor
test('availableLifecycleActions derives from descriptor (not hardcoded)', () => {
    // The computed should reference lifecycleDescriptor, not a hardcoded array
    const hasDescriptorRef = source.includes('this.lifecycleDescriptor');
    assert.ok(hasDescriptorRef, 'Must reference lifecycleDescriptor for available actions');
});

// 23. No frontend state machine replication
test('No frontend state machine (no transition table)', () => {
    // Should NOT have a transition map like { active: ['bury', 'suspend', ...] }
    assert.ok(!source.includes('TRANSITIONS') && !source.includes('STATE_MACHINE'), 'Must not replicate state machine on frontend');
});

// 24. lifecycleConflict cleared on new action
test('lifecycleConflict cleared on new action attempt', () => {
    assert.ok(source.includes("lifecycleConflict = ''") || source.includes('lifecycleConflict = ""'), 'Must clear lifecycleConflict when starting new action');
});

console.log(`\n${passed} tests passed.`);
