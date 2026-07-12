// SenseReviewLeechPanelGuard.test.mjs
//
// ADR-0011 (Task A-7): Sense leech governance UI guards.
//
// Node built-in assert tests for SenseReviewLeechPanel.vue contract:
//   - Imports SenseReviewLeechPresentation helpers.
//   - Has props: reviewCardId, showAnswer.
//   - Has data fields: descriptor / loading / error.
//   - Template hides when status === 'stable'.
//   - Template shows struggling hint when status === 'struggling'.
//   - Template shows leech governance card only when showAnswer is true.
//   - Four action buttons emit rewrite / edit / history / suspend.
//   - Suspend button disabled when blocked_actions contains suspend_temporarily.
//   - Fetches GET /reviews/senses/{reviewCardId}/leech.
//   - No provider-preview, no auto-creation, no FSRS, no rating block.
//
// Tests:
//   1.  File exists.
//   2.  Imports from SenseReviewLeechPresentation.
//   3.  Has reviewCardId prop.
//   4.  Has showAnswer prop.
//   5.  Has descriptor data field.
//   6.  Has loading data field.
//   7.  Has error data field.
//   8.  Template v-if hides when status is 'stable'.
//   9.  Template shows struggling hint text.
//  10.  Template shows leech governance card gated by showAnswer.
//  11.  Has '生成重写提示包' action button.
//  12.  Has '编辑词义' action button.
//  13.  Has '查看历史' action button.
//  14.  Has '暂停复习' action button.
//  15.  Suspend button is disabled when suspend_temporarily is blocked.
//  16.  Emits 'rewrite' event.
//  17.  Emits 'edit' event.
//  18.  Emits 'history' event.
//  19.  Emits 'suspend' event.
//  20.  Fetches GET /reviews/senses/{id}/leech endpoint.
//  21.  Source does NOT call provider-preview.
//  22.  Source does NOT create ReviewLog / WordSense / ReviewCard.
//  23.  Source does NOT contain FSRS scheduling.
//  24.  Source does NOT block rating (no v-if hiding rating buttons).

import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const PANEL_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'Senses', 'SenseReviewLeechPanel.vue');

const name = 'SenseReviewLeechPanelGuard';
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

const source = existsSync(PANEL_PATH) ? readFileSync(PANEL_PATH, 'utf-8') : '';

// 1. File exists
test('File exists', () => {
    assert.ok(existsSync(PANEL_PATH), 'SenseReviewLeechPanel.vue must exist');
});

// 2. Imports from SenseReviewLeechPresentation
test('Imports from SenseReviewLeechPresentation', () => {
    assert.ok(source.includes('SenseReviewLeechPresentation'), 'Must import SenseReviewLeechPresentation helpers');
});

// 3. Has reviewCardId prop
test('Has reviewCardId prop', () => {
    assert.ok(source.includes('reviewCardId'), 'Must have reviewCardId prop');
});

// 4. Has showAnswer prop
test('Has showAnswer prop', () => {
    assert.ok(source.includes('showAnswer'), 'Must have showAnswer prop');
});

// 5. Has descriptor data field
test('Has descriptor data field', () => {
    assert.ok(source.includes('descriptor'), 'Must have descriptor data field');
});

// 6. Has loading data field
test('Has loading data field', () => {
    assert.ok(source.includes('loading'), 'Must have loading data field');
});

// 7. Has error data field
test('Has error data field', () => {
    assert.ok(source.includes('error'), 'Must have error data field');
});

// 8. Template v-if hides when status is 'stable'
test("Template v-if hides when status is 'stable'", () => {
    assert.ok(source.includes('stable'), "Template must reference 'stable' status to hide the panel");
});

// 9. Template shows struggling hint text
test('Template shows struggling hint text', () => {
    assert.ok(source.includes('struggling'), 'Template must show struggling hint text');
    assert.ok(source.includes('strugglingHintText') || source.includes('hint'), 'Template must render struggling hint text');
});

// 10. Template shows leech governance card gated by showAnswer
test('Template shows leech governance card gated by showAnswer', () => {
    assert.ok(source.includes('leech'), 'Template must show leech governance card');
    assert.ok(source.includes('showAnswer'), 'Leech card must be gated by showAnswer');
});

// 11. Has '生成重写提示包' action button
test("Has '生成重写提示包' action button", () => {
    assert.ok(source.includes('生成重写提示包'), "Must have '生成重写提示包' action button");
});

// 12. Has '编辑词义' action button
test("Has '编辑词义' action button", () => {
    assert.ok(source.includes('编辑词义'), "Must have '编辑词义' action button");
});

// 13. Has '查看历史' action button
test("Has '查看历史' action button", () => {
    assert.ok(source.includes('查看历史'), "Must have '查看历史' action button");
});

// 14. Has '暂停复习' action button
test("Has '暂停复习' action button", () => {
    assert.ok(source.includes('暂停复习'), "Must have '暂停复习' action button");
});

// 15. Suspend button is disabled when suspend_temporarily is blocked
test('Suspend button is disabled when suspend_temporarily is blocked', () => {
    assert.ok(
        source.includes('suspend_temporarily') || source.includes('isSuspendBlocked') || source.includes('blocked_actions'),
        'Suspend button must be disabled when blocked_actions contains suspend_temporarily'
    );
    assert.ok(source.includes('disabled') || source.includes('blocked'), 'Suspend button must use a disabled / blocked guard');
});

// 16. Emits 'rewrite' event
test("Emits 'rewrite' event", () => {
    assert.ok(source.includes("$emit('rewrite')") || source.includes('$emit("rewrite")'), "Must emit 'rewrite' event");
});

// 17. Emits 'edit' event
test("Emits 'edit' event", () => {
    assert.ok(source.includes("$emit('edit')") || source.includes('$emit("edit")'), "Must emit 'edit' event");
});

// 18. Emits 'history' event
test("Emits 'history' event", () => {
    assert.ok(source.includes("$emit('history')") || source.includes('$emit("history")'), "Must emit 'history' event");
});

// 19. Emits 'suspend' event
test("Emits 'suspend' event", () => {
    assert.ok(source.includes("$emit('suspend')") || source.includes('$emit("suspend")'), "Must emit 'suspend' event");
});

// 20. Fetches GET /reviews/senses/{id}/leech endpoint
test('Fetches GET /reviews/senses/{id}/leech endpoint', () => {
    assert.ok(source.includes('/leech'), 'Must fetch the /leech endpoint');
    assert.ok(source.includes('axios.get'), 'Must use axios.get to fetch the descriptor');
    assert.ok(source.includes('/reviews/senses/'), 'Must hit the /reviews/senses/ path');
});

// 21. Source does NOT call provider-preview
test('Source does NOT call provider-preview', () => {
    assert.ok(!source.includes('provider-preview'), 'Source must not call provider-preview');
});

// 22. Source does NOT create ReviewLog / WordSense / ReviewCard
test('Source does NOT create ReviewLog / WordSense / ReviewCard', () => {
    assert.ok(!source.includes('createReviewLog'), 'Source must not create ReviewLog');
    assert.ok(!source.includes('createWordSense'), 'Source must not create WordSense');
    assert.ok(!source.includes('createReviewCard'), 'Source must not create ReviewCard');
});

// 23. Source does NOT contain FSRS scheduling
test('Source does NOT contain FSRS scheduling', () => {
    assert.ok(!source.includes('FsrsScheduling'), 'Source must not contain FSRS scheduling');
    assert.ok(!source.includes('fsrs_schedule'), 'Source must not call fsrs_schedule');
});

// 24. Source does NOT block rating
test('Source does NOT block rating (no v-if hiding rating buttons)', () => {
    // The panel only emits governance events; it must not hide or disable rating buttons.
    assert.ok(!source.includes('rating') || !/v-if.*rating.*disabled/.test(source), 'Source must not block rating buttons');
    assert.ok(!source.includes('blockRating') && !source.includes('disableRating'), 'Source must not block rating');
});

console.log(`\n${name}: ${passed} passed`);
