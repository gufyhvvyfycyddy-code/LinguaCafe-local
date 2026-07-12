// ReviewCardLifecycleBulkGuard.test.mjs
//
// ADR-0010: Review card lifecycle state machine.
//
// Node built-in assert tests for the bulk lifecycle operations contract:
//   - Bulk endpoint is /review-cards/manage/bulk-lifecycle.
//   - Supports suspend/resume/archive/restore/unbury.
//   - Does NOT support bury/reset/delete (by design).
//   - Sends source parameter.
//   - Clears selection on success.
//   - Handles skipped count.
//   - 422 error handling.
//   - Network error handling.
//
// Tests:
//   1.  File exists.
//   2.  Has confirmBulkLifecycle method.
//   3.  Has doBulkLifecycle method.
//   4.  Has bulkLifecycleDialog data field.
//   5.  Has bulkLifecycleLoading data field.
//   6.  Has bulkLifecycleAction data field.
//   7.  Uses POST /review-cards/manage/bulk-lifecycle endpoint.
//   8.  Sends ids array in payload.
//   9.  Sends action in payload.
//  10.  Sends source in payload.
//  11.  Bulk menu has 'suspend' option.
//  12.  Bulk menu has 'resume' option.
//  13.  Bulk menu has 'archive' option.
//  14.  Bulk menu has 'restore' option.
//  15.  Bulk menu has 'unbury' option.
//  16.  Bulk menu does NOT have 'bury' option.
//  17.  Bulk menu does NOT have 'reset' option.
//  18.  Bulk menu does NOT have 'delete' option.
//  19.  confirmBulkLifecycle checks selectedIds.length.
//  20.  doBulkLifecycle clears selection on success.
//  21.  doBulkLifecycle handles skipped count.
//  22.  doBulkLifecycle handles 422 error.
//  23.  doBulkLifecycle handles network error.
//  24.  Has bulkLifecycleDialogTitle computed.
//  25.  Has bulkLifecycleDialogHint computed.
//  26.  Has bulkLifecycleDialogColor computed.

import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const MANAGE_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardManage.vue');

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

// 1. File exists
test('File exists', () => {
    assert.ok(existsSync(MANAGE_PATH), 'ReviewCardManage.vue must exist');
});

// 2. Has confirmBulkLifecycle method
test('Has confirmBulkLifecycle method', () => {
    assert.ok(source.includes('confirmBulkLifecycle'), 'Must have confirmBulkLifecycle method');
});

// 3. Has doBulkLifecycle method
test('Has doBulkLifecycle method', () => {
    assert.ok(source.includes('doBulkLifecycle'), 'Must have doBulkLifecycle method');
});

// 4. Has bulkLifecycleDialog data field
test('Has bulkLifecycleDialog data field', () => {
    assert.ok(source.includes('bulkLifecycleDialog'), 'Must have bulkLifecycleDialog data field');
});

// 5. Has bulkLifecycleLoading data field
test('Has bulkLifecycleLoading data field', () => {
    assert.ok(source.includes('bulkLifecycleLoading'), 'Must have bulkLifecycleLoading data field');
});

// 6. Has bulkLifecycleAction data field
test('Has bulkLifecycleAction data field', () => {
    assert.ok(source.includes('bulkLifecycleAction'), 'Must have bulkLifecycleAction data field');
});

// 7. Uses POST /review-cards/manage/bulk-lifecycle endpoint
test('Uses POST /review-cards/manage/bulk-lifecycle endpoint', () => {
    assert.ok(source.includes('/review-cards/manage/bulk-lifecycle'), 'Must use bulk-lifecycle endpoint');
});

// 8. Sends ids array in payload
test('Sends ids array in payload', () => {
    assert.ok(source.includes('ids:') && source.includes('selectedIds'), 'Must send ids array from selectedIds');
});

// 9. Sends action in payload
test('Sends action in payload', () => {
    assert.ok(source.includes('action:'), 'Must send action in payload');
});

// 10. Sends source in payload
test('Sends source in payload', () => {
    assert.ok(source.includes('source:'), 'Must send source in payload');
});

// Extract the bulk menu section (between confirmBulkLifecycle calls)
const bulkMenuSection = source.match(/批量生命周期[\s\S]*?<\/v-menu>/)?.[0] || '';

// 11. Bulk menu has 'suspend' option
test("Bulk menu has 'suspend' option", () => {
    assert.ok(bulkMenuSection.includes("confirmBulkLifecycle('suspend')"), "Bulk menu must have suspend option");
});

// 12. Bulk menu has 'resume' option
test("Bulk menu has 'resume' option", () => {
    assert.ok(bulkMenuSection.includes("confirmBulkLifecycle('resume')"), "Bulk menu must have resume option");
});

// 13. Bulk menu has 'archive' option
test("Bulk menu has 'archive' option", () => {
    assert.ok(bulkMenuSection.includes("confirmBulkLifecycle('archive')"), "Bulk menu must have archive option");
});

// 14. Bulk menu has 'restore' option
test("Bulk menu has 'restore' option", () => {
    assert.ok(bulkMenuSection.includes("confirmBulkLifecycle('restore')"), "Bulk menu must have restore option");
});

// 15. Bulk menu has 'unbury' option
test("Bulk menu has 'unbury' option", () => {
    assert.ok(bulkMenuSection.includes("confirmBulkLifecycle('unbury')"), "Bulk menu must have unbury option");
});

// 16. Bulk menu does NOT have 'bury' option
test("Bulk menu does NOT have 'bury' option", () => {
    assert.ok(!bulkMenuSection.includes("confirmBulkLifecycle('bury')"), "Bulk menu must NOT have bury option (bulk bury not supported)");
});

// 17. Bulk menu does NOT have 'reset' option
test("Bulk menu does NOT have 'reset' option", () => {
    assert.ok(!bulkMenuSection.includes("confirmBulkLifecycle('reset')"), "Bulk menu must NOT have reset option (reset is not a lifecycle action)");
});

// 18. Bulk menu does NOT have 'delete' option
test("Bulk menu does NOT have 'delete' option", () => {
    assert.ok(!bulkMenuSection.includes("confirmBulkLifecycle('delete')"), "Bulk menu must NOT have delete option (delete is not a lifecycle action)");
});

// 19. confirmBulkLifecycle checks selectedIds.length
test('confirmBulkLifecycle checks selectedIds.length', () => {
    const methodSection = source.match(/confirmBulkLifecycle\([^)]*\)\s*\{[\s\S]*?\}/)?.[0] || '';
    assert.ok(methodSection.includes('selectedIds.length'), 'confirmBulkLifecycle must check selectedIds.length');
});

// 20. doBulkLifecycle clears selection on success
test('doBulkLifecycle clears selection on success', () => {
    const methodSection = source.match(/doBulkLifecycle\(\)\s*\{[\s\S]*?\}\s*,/)?.[0] || '';
    assert.ok(methodSection.includes('clearSelection'), 'doBulkLifecycle must call clearSelection on success');
});

// 21. doBulkLifecycle handles skipped count
test('doBulkLifecycle handles skipped count', () => {
    const methodSection = source.match(/doBulkLifecycle\(\)\s*\{[\s\S]*?\}\s*,/)?.[0] || '';
    assert.ok(methodSection.includes('skipped'), 'doBulkLifecycle must handle skipped count in response');
});

// 22. doBulkLifecycle handles 422 error
test('doBulkLifecycle handles 422 error', () => {
    const methodSection = source.match(/doBulkLifecycle\(\)\s*\{[\s\S]*?\}\s*,/)?.[0] || '';
    assert.ok(methodSection.includes('422'), 'doBulkLifecycle must handle 422 error');
});

// 23. doBulkLifecycle handles network error
test('doBulkLifecycle handles network error', () => {
    const methodSection = source.match(/doBulkLifecycle\(\)\s*\{[\s\S]*?\}\s*,/)?.[0] || '';
    assert.ok(methodSection.includes('!err.response') || methodSection.includes('网络错误'), 'doBulkLifecycle must handle network error');
});

// 24. Has bulkLifecycleDialogTitle computed
test('Has bulkLifecycleDialogTitle computed', () => {
    assert.ok(source.includes('bulkLifecycleDialogTitle'), 'Must have bulkLifecycleDialogTitle computed');
});

// 25. Has bulkLifecycleDialogHint computed
test('Has bulkLifecycleDialogHint computed', () => {
    assert.ok(source.includes('bulkLifecycleDialogHint'), 'Must have bulkLifecycleDialogHint computed');
});

// 26. Has bulkLifecycleDialogColor computed
test('Has bulkLifecycleDialogColor computed', () => {
    assert.ok(source.includes('bulkLifecycleDialogColor'), 'Must have bulkLifecycleDialogColor computed');
});

console.log(`\n${passed} tests passed.`);
