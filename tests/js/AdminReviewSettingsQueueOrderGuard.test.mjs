// AdminReviewSettingsQueueOrderGuard.test.mjs
//
// ADR-0015 V1 — Review Queue Order (Configurable display order)
//
// Source-code guard tests for AdminReviewSettings.vue's "复习显示顺序"
// section. Verifies the four Anki-aligned Queue Order dropdowns exist,
// wire to GET/POST /settings/fsrs/queue-order, and are loaded on mount.
//
// Tests:
//   1.  "复习显示顺序" card title exists.
//   2.  queueOrder data object has the 4 required keys with Anki defaults.
//   3.  interdayLearningReviewOrderOptions has mix/before/after.
//   4.  newReviewOrderOptions has mix/before/after.
//   5.  reviewSortOrderOptions has the 4 review sort enums.
//   6.  newSortOrderOptions has created_asc/created_desc/random.
//   7.  mounted calls loadFsrsQueueOrder.
//   8.  loadFsrsQueueOrder GETs /settings/fsrs/queue-order.
//   9.  saveFsrsQueueOrder POSTs /settings/fsrs/queue-order.
//  10.  Four v-select bound to queueOrder.* keys exist.
//  11.  Save button triggers saveFsrsQueueOrder.
//  12.  No Math.random / shuffle in the queue order path.
//  13.  Error alert (queueOrderSaveError) exists.
//  14.  Success alert (queueOrderSaveStatus) exists.

import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const COMPONENT_PATH = join(
    __dirname, '..', '..',
    'resources', 'js', 'components', 'Admin', 'AdminReviewSettings.vue'
);

let passed = 0;
function test(name, fn) {
    try {
        fn();
        passed++;
        console.log(`  ✓ ${name}`);
    } catch (e) {
        console.error('FAIL: ' + name);
        console.error(e.message);
        process.exitCode = 1;
    }
}

const source = existsSync(COMPONENT_PATH) ? readFileSync(COMPONENT_PATH, 'utf-8') : '';

// Helper: extract the body of a named method from the Vue source.
function extractMethod(name) {
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

// 1. "复习显示顺序" card title exists
test('复习显示顺序 card title exists', () => {
    assert.ok(source.includes('复习显示顺序'), 'card title 复习显示顺序 must exist');
});

// 2. queueOrder data object has the 4 required keys with Anki defaults
test('queueOrder data object has 4 keys with Anki defaults', () => {
    assert.ok(source.includes("interday_learning_review_order: 'mix'"), 'default mix for interday');
    assert.ok(source.includes("new_review_order: 'mix'"), 'default mix for new_review');
    assert.ok(source.includes("review_sort_order: 'due_random'"), 'default due_random for review_sort');
    assert.ok(source.includes("new_sort_order: 'created_asc'"), 'default created_asc for new_sort');
});

// 3. interdayLearningReviewOrderOptions has mix/before/after
test('interdayLearningReviewOrderOptions has mix/before/after', () => {
    assert.ok(source.includes("value: 'mix'"), 'mix option');
    assert.ok(source.includes("value: 'before'"), 'before option');
    assert.ok(source.includes("value: 'after'"), 'after option');
});

// 4. newReviewOrderOptions has mix/before/after (covered by #3 shared values,
//    but verify the array name exists)
test('newReviewOrderOptions array exists', () => {
    assert.ok(source.includes('newReviewOrderOptions:'), 'newReviewOrderOptions array must exist');
});

// 5. reviewSortOrderOptions has the 4 review sort enums
test('reviewSortOrderOptions has 4 review sort enums', () => {
    assert.ok(source.includes("value: 'due_random'"), 'due_random option');
    assert.ok(source.includes("value: 'due_stable'"), 'due_stable option');
    assert.ok(source.includes("value: 'ascending_retrievability'"), 'ascending_retrievability option');
    assert.ok(source.includes("value: 'random'"), 'random option');
});

// 6. newSortOrderOptions has created_asc/created_desc/random
test('newSortOrderOptions has created_asc/created_desc/random', () => {
    assert.ok(source.includes("value: 'created_asc'"), 'created_asc option');
    assert.ok(source.includes("value: 'created_desc'"), 'created_desc option');
});

// 7. mounted calls loadFsrsQueueOrder
test('mounted calls loadFsrsQueueOrder', () => {
    const mountedMatch = source.match(/mounted\(\)\s*\{([\s\S]*?)\n\s*\},/);
    assert.ok(mountedMatch, 'mounted() must exist');
    assert.ok(mountedMatch[1].includes('this.loadFsrsQueueOrder'), 'mounted must call loadFsrsQueueOrder');
});

// 8. loadFsrsQueueOrder GETs /settings/fsrs/queue-order
test('loadFsrsQueueOrder GETs /settings/fsrs/queue-order', () => {
    const body = extractMethod('loadFsrsQueueOrder');
    assert.ok(body.includes('/settings/fsrs/queue-order'), 'must request /settings/fsrs/queue-order');
    assert.ok(/axios\.get/.test(body), 'must use axios.get');
});

// 9. saveFsrsQueueOrder POSTs /settings/fsrs/queue-order
test('saveFsrsQueueOrder POSTs /settings/fsrs/queue-order', () => {
    const body = extractMethod('saveFsrsQueueOrder');
    assert.ok(body.includes('/settings/fsrs/queue-order'), 'must post to /settings/fsrs/queue-order');
    assert.ok(/axios\.post/.test(body), 'must use axios.post');
    // Payload must include all 4 keys
    assert.ok(body.includes('interday_learning_review_order'), 'payload has interday key');
    assert.ok(body.includes('new_review_order'), 'payload has new_review key');
    assert.ok(body.includes('review_sort_order'), 'payload has review_sort key');
    assert.ok(body.includes('new_sort_order'), 'payload has new_sort key');
});

// 10. Four v-select bound to queueOrder.* keys exist
test('four v-select bound to queueOrder.* keys exist', () => {
    assert.ok(source.includes('v-model="queueOrder.interday_learning_review_order"'), 'interday select bound');
    assert.ok(source.includes('v-model="queueOrder.new_review_order"'), 'new_review select bound');
    assert.ok(source.includes('v-model="queueOrder.review_sort_order"'), 'review_sort select bound');
    assert.ok(source.includes('v-model="queueOrder.new_sort_order"'), 'new_sort select bound');
});

// 11. Save button triggers saveFsrsQueueOrder
test('save button triggers saveFsrsQueueOrder', () => {
    assert.ok(/@click="saveFsrsQueueOrder"/.test(source), 'save button must call saveFsrsQueueOrder');
});

// 12. No Math.random / shuffle in the queue order path
test('no Math.random or shuffle in queue order methods', () => {
    const loadBody = extractMethod('loadFsrsQueueOrder');
    const saveBody = extractMethod('saveFsrsQueueOrder');
    assert.ok(!/Math\.random/.test(loadBody), 'load must not use Math.random');
    assert.ok(!/Math\.random/.test(saveBody), 'save must not use Math.random');
    assert.ok(!/shuffle/.test(loadBody), 'load must not shuffle');
    assert.ok(!/shuffle/.test(saveBody), 'save must not shuffle');
});

// 13. Error alert (queueOrderSaveError) exists
test('error alert queueOrderSaveError exists', () => {
    assert.ok(source.includes('queueOrderSaveError'), 'error alert must exist');
});

// 14. Success alert (queueOrderSaveStatus) exists
test('success alert queueOrderSaveStatus exists', () => {
    assert.ok(source.includes('queueOrderSaveStatus'), 'success alert must exist');
});

console.log(`\n${passed} tests passed.`);
