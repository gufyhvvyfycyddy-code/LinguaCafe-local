// ReviewCardManageLifecycleGuard.test.mjs
//
// ADR-0010: Review card lifecycle state machine.
//
// Node built-in assert tests for ReviewCardManage.vue lifecycle contract:
//   - Lifecycle state filters (active/buried/suspended/archived).
//   - Per-row lifecycle menu with descriptor fetch.
//   - Detail drawer shows lifecycle events.
//   - State help dialog.
//   - 409/422/network error handling.
//   - No legacy bulk-enabled endpoint.
//
// Tests:
//   1.  File exists.
//   2.  Imports from ReviewCardLifecyclePresentation.
//   3.  Has 'active' filter button.
//   4.  Has 'buried' filter button.
//   5.  Has 'suspended' filter button.
//   6.  Has 'archived' filter button.
//   7.  Has lifecycleDescriptor data field.
//   8.  Has lifecycleLoading data field.
//   9.  Has lifecycleDialog data field.
//  10.  Has fetchLifecycleDescriptor method.
//  11.  Has executeLifecycleAction method.
//  12.  Uses POST /review-cards/{id}/lifecycle-actions.
//  13.  Uses GET /review-cards/{id}/lifecycle for descriptor.
//  14.  Uses GET /review-cards/{id}/lifecycle-events for events.
//  15.  Detail drawer shows lifecycle state.
//  16.  409 error handling.
//  17.  422 error handling.
//  18.  Network error handling.
//  19.  Has stateHelpDialog data field.
//  20.  Has lifecycleStateHelpEntries computed.
//  21.  No legacy bulkArchive method (replaced by doBulkLifecycle).
//  22.  No legacy bulkRestore method.
//  23.  No legacy bulkArchiveDialog.
//  24.  No legacy bulkRestoreDialog.
//  25.  No legacy /bulk-enabled endpoint usage.

import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const MANAGE_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardManage.vue');
const DRAWER_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardInfoDrawer.vue');

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

const source = existsSync(MANAGE_PATH) ? readFileSync(MANAGE_PATH, 'utf-8') : '';
const drawerSource = existsSync(DRAWER_PATH) ? readFileSync(DRAWER_PATH, 'utf-8') : '';

// 1. File exists
test('File exists', () => {
    assert.ok(existsSync(MANAGE_PATH), 'ReviewCardManage.vue must exist');
});

// 2. Imports from ReviewCardLifecyclePresentation
test('Imports from ReviewCardLifecyclePresentation', () => {
    assert.ok(source.includes('ReviewCardLifecyclePresentation'), 'Must import lifecycle presentation helpers');
});

// 3. Has 'active' filter button
test("Has 'active' filter button", () => {
    assert.ok(source.includes("value=\"active\""), "Must have 'active' filter button");
});

// 4. Has 'buried' filter button
test("Has 'buried' filter button", () => {
    assert.ok(source.includes("value=\"buried\""), "Must have 'buried' filter button");
});

// 5. Has 'suspended' filter button
test("Has 'suspended' filter button", () => {
    assert.ok(source.includes("value=\"suspended\""), "Must have 'suspended' filter button");
});

// 6. Has 'archived' filter button
test("Has 'archived' filter button", () => {
    assert.ok(source.includes("value=\"archived\""), "Must have 'archived' filter button");
});

// 7. Has lifecycleDescriptor data field
test('Has lifecycleDescriptor data field', () => {
    assert.ok(source.includes('lifecycleDescriptor'), 'Must have lifecycleDescriptor data field');
});

// 8. Has lifecycleLoading data field
test('Has lifecycleLoading data field', () => {
    assert.ok(source.includes('lifecycleLoading'), 'Must have lifecycleLoading data field');
});

// 9. Has lifecycleDialog data field
test('Has lifecycleDialog data field', () => {
    assert.ok(source.includes('lifecycleDialog'), 'Must have lifecycleDialog data field');
});

// 10. Has fetchLifecycleDescriptor method
test('Has fetchLifecycleDescriptor method', () => {
    assert.ok(source.includes('fetchLifecycleDescriptor'), 'Must have fetchLifecycleDescriptor method');
});

// 11. Has executeLifecycleAction method
test('Has executeLifecycleAction method', () => {
    assert.ok(source.includes('executeLifecycleAction'), 'Must have executeLifecycleAction method');
});

// 12. Uses POST /review-cards/{id}/lifecycle-actions
test('Uses POST /review-cards/{id}/lifecycle-actions', () => {
    assert.ok(source.includes('/lifecycle-actions'), 'Must use lifecycle-actions endpoint');
});

// 13. Uses GET /review-cards/{id}/lifecycle for descriptor
test('Uses GET /review-cards/{id}/lifecycle for descriptor', () => {
    assert.ok(source.includes('/lifecycle') && source.includes('axios.get'), 'Must fetch lifecycle descriptor');
});

// 14. Lifecycle events come from the canonical aggregate detail request.
test('Uses aggregate card_info lifecycle events without a granular request', () => {
    assert.ok(drawerSource.includes('lifecycle_events'), 'Drawer must render card_info lifecycle events');
    assert.ok(!drawerSource.includes('/lifecycle-events'), 'Drawer must not fire a granular lifecycle-events request');
});

// 15. Detail drawer shows lifecycle state
test('Detail drawer shows lifecycle state', () => {
    assert.ok(drawerSource.includes('lifecycle_state') || drawerSource.includes('lifecycleState'), 'Detail drawer must show lifecycle state');
});

// 16. 409 error handling
test('409 error handling', () => {
    assert.ok(source.includes('409'), 'Must handle 409 status');
});

// 17. 422 error handling
test('422 error handling', () => {
    assert.ok(source.includes('422'), 'Must handle 422 status');
});

// 18. Network error handling
test('Network error handling', () => {
    assert.ok(source.includes('!err.response') || source.includes('网络错误'), 'Must handle network error');
});

// 19. Has stateHelpDialog data field
test('Has stateHelpDialog data field', () => {
    assert.ok(source.includes('stateHelpDialog'), 'Must have stateHelpDialog for state explanation UI');
});

// 20. Has lifecycleStateHelpEntries computed
test('Has lifecycleStateHelpEntries computed', () => {
    assert.ok(source.includes('lifecycleStateHelpEntries'), 'Must have lifecycleStateHelpEntries computed');
});

// 21. No legacy bulkArchive method
test('No legacy bulkArchive method (replaced by doBulkLifecycle)', () => {
    // The old bulkArchive() method that opened bulkArchiveDialog should be gone
    assert.ok(!/\bbulkArchive\(\)/.test(source), 'Must not have legacy bulkArchive() method');
});

// 22. No legacy bulkRestore method
test('No legacy bulkRestore method', () => {
    assert.ok(!/\bbulkRestore\(\)/.test(source), 'Must not have legacy bulkRestore() method');
});

// 23. No legacy bulkArchiveDialog
test('No legacy bulkArchiveDialog', () => {
    assert.ok(!source.includes('bulkArchiveDialog'), 'Must not have legacy bulkArchiveDialog');
});

// 24. No legacy bulkRestoreDialog
test('No legacy bulkRestoreDialog', () => {
    assert.ok(!source.includes('bulkRestoreDialog'), 'Must not have legacy bulkRestoreDialog');
});

// 25. No legacy /bulk-enabled endpoint usage
test('No legacy /bulk-enabled endpoint usage', () => {
    assert.ok(!source.includes('/bulk-enabled'), 'Must not use legacy /bulk-enabled endpoint');
});

console.log(`\n${passed} tests passed.`);
