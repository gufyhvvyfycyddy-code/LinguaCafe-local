// SenseReviewLeechRewritePackageGuard.test.mjs
//
// ADR-0011 (Task A-7): Sense leech rewrite package dialog guards.
//
// Node built-in assert tests for SenseReviewLeechRewritePackageDialog.vue:
//   - Props: value (Boolean), reviewCardId (Number), lemma (String).
//   - Data: loading / package / error.
//   - v-dialog container.
//   - Prominent 'no AI' notice (不调用 AI / noAiNoticeText).
//   - JSON tab and Markdown tab.
//   - Copy buttons using navigator.clipboard.writeText.
//   - Fetches POST /reviews/senses/{id}/leech/rewrite-package.
//   - Shows provider_called / card_created / review_log_created as chips.
//   - Error state keeps dialog open.
//   - No provider-preview, no auto-creation, no FSRS.
//
// Tests:
//   1.  File exists.
//   2.  Has value (Boolean) prop.
//   3.  Has reviewCardId (Number) prop.
//   4.  Has lemma (String) prop.
//   5.  Has loading data field.
//   6.  Has package data field.
//   7.  Has error data field.
//   8.  Template has v-dialog.
//   9.  Template shows '不调用 AI' notice or noAiNoticeText.
//  10.  Template has JSON tab/section.
//  11.  Template has Markdown tab/section.
//  12.  Has '复制 JSON' copy button.
//  13.  Has '复制 Markdown' copy button.
//  14.  Uses navigator.clipboard.writeText for copy.
//  15.  Fetches POST /reviews/senses/{id}/leech/rewrite-package endpoint.
//  16.  Shows provider_called as a chip or text.
//  17.  Shows card_created as a chip or text.
//  18.  Shows review_log_created as a chip or text.
//  19.  Source does NOT call provider-preview.
//  20.  Source does NOT create WordSense / ReviewCard / ReviewLog.
//  21.  Source does NOT contain FSRS scheduling.
//  22.  Error state keeps dialog open (no close on error).

import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const DIALOG_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'Senses', 'SenseReviewLeechRewritePackageDialog.vue');

const name = 'SenseReviewLeechRewritePackageGuard';
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

const source = existsSync(DIALOG_PATH) ? readFileSync(DIALOG_PATH, 'utf-8') : '';

// Strip HTML and JS comments so that "does NOT call X" doc comments do not
// produce false positives in the negative assertions below.
const sourceNoComments = source
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/(^|[^:])\/\/[^\n]*/g, '$1');

// 1. File exists
test('File exists', () => {
    assert.ok(existsSync(DIALOG_PATH), 'SenseReviewLeechRewritePackageDialog.vue must exist');
});

// 2. Has value (Boolean) prop
test('Has value (Boolean) prop', () => {
    assert.ok(source.includes('value'), 'Must have value prop');
    assert.ok(/value\s*:\s*\{[\s\S]*?type\s*:\s*Boolean/.test(source), 'value prop must be Boolean');
});

// 3. Has reviewCardId (Number) prop
test('Has reviewCardId (Number) prop', () => {
    assert.ok(source.includes('reviewCardId'), 'Must have reviewCardId prop');
    assert.ok(/reviewCardId\s*:\s*\{[\s\S]*?type\s*:\s*Number/.test(source), 'reviewCardId prop must be Number');
});

// 4. Has lemma (String) prop
test('Has lemma (String) prop', () => {
    assert.ok(source.includes('lemma'), 'Must have lemma prop');
    assert.ok(/lemma\s*:\s*\{[\s\S]*?type\s*:\s*String/.test(source), 'lemma prop must be String');
});

// 5. Has loading data field
test('Has loading data field', () => {
    assert.ok(source.includes('loading'), 'Must have loading data field');
});

// 6. Has package data field
test('Has package data field', () => {
    assert.ok(source.includes('package'), 'Must have package data field');
});

// 7. Has error data field
test('Has error data field', () => {
    assert.ok(source.includes('error'), 'Must have error data field');
});

// 8. Template has v-dialog
test('Template has v-dialog', () => {
    assert.ok(source.includes('v-dialog'), 'Template must have a v-dialog container');
});

// 9. Template shows '不调用 AI' notice or noAiNoticeText
test("Template shows '不调用 AI' notice or noAiNoticeText", () => {
    assert.ok(
        source.includes('不调用 AI') || source.includes('noAiNoticeText'),
        "Template must show '不调用 AI' notice or call noAiNoticeText"
    );
});

// 10. Template has JSON tab/section
test('Template has JSON tab/section', () => {
    assert.ok(source.includes('JSON'), 'Template must have a JSON tab or section');
});

// 11. Template has Markdown tab/section
test('Template has Markdown tab/section', () => {
    assert.ok(source.includes('Markdown'), 'Template must have a Markdown tab or section');
});

// 12. Has '复制 JSON' copy button
test("Has '复制 JSON' copy button", () => {
    assert.ok(source.includes('复制 JSON'), "Must have '复制 JSON' copy button");
});

// 13. Has '复制 Markdown' copy button
test("Has '复制 Markdown' copy button", () => {
    assert.ok(source.includes('复制 Markdown'), "Must have '复制 Markdown' copy button");
});

// 14. Uses navigator.clipboard.writeText for copy
test('Uses navigator.clipboard.writeText for copy', () => {
    assert.ok(source.includes('navigator.clipboard'), 'Must use navigator.clipboard');
    assert.ok(source.includes('writeText'), 'Must call navigator.clipboard.writeText');
});

// 15. Fetches POST /reviews/senses/{id}/leech/rewrite-package endpoint
test('Fetches POST /reviews/senses/{id}/leech/rewrite-package endpoint', () => {
    assert.ok(source.includes('/leech/rewrite-package'), 'Must hit the /leech/rewrite-package endpoint');
    assert.ok(source.includes('axios.post'), 'Must use axios.post to fetch the package');
    assert.ok(source.includes('/reviews/senses/'), 'Must hit the /reviews/senses/ path');
});

// 16. Shows provider_called as a chip or text
test('Shows provider_called as a chip or text', () => {
    assert.ok(source.includes('provider_called'), 'Must show provider_called as a chip or text');
});

// 17. Shows card_created as a chip or text
test('Shows card_created as a chip or text', () => {
    assert.ok(source.includes('card_created'), 'Must show card_created as a chip or text');
});

// 18. Shows review_log_created as a chip or text
test('Shows review_log_created as a chip or text', () => {
    assert.ok(source.includes('review_log_created'), 'Must show review_log_created as a chip or text');
});

// 19. Source does NOT call provider-preview
test('Source does NOT call provider-preview', () => {
    assert.ok(!sourceNoComments.includes('provider-preview'), 'Source must not call provider-preview');
});

// 20. Source does NOT create WordSense / ReviewCard / ReviewLog
test('Source does NOT create WordSense / ReviewCard / ReviewLog', () => {
    assert.ok(!sourceNoComments.includes('createWordSense'), 'Source must not create WordSense');
    assert.ok(!sourceNoComments.includes('createReviewCard'), 'Source must not create ReviewCard');
    assert.ok(!sourceNoComments.includes('createReviewLog'), 'Source must not create ReviewLog');
});

// 21. Source does NOT contain FSRS scheduling
test('Source does NOT contain FSRS scheduling', () => {
    assert.ok(!sourceNoComments.includes('FsrsScheduling'), 'Source must not contain FSRS scheduling');
    assert.ok(!sourceNoComments.includes('fsrs_schedule'), 'Source must not call fsrs_schedule');
});

// 22. Error state keeps dialog open (no close on error)
test('Error state keeps dialog open (no close on error)', () => {
    // On error, the dialog sets `error` but must NOT emit input=false / close().
    // Inspect the catch block: it should set `this.error`, not call close()/resetState().
    assert.ok(source.includes('error'), 'Must have an error data field for the error state');
    // Heuristic: catch block must reference this.error assignment.
    assert.ok(/catch[\s\S]*?this\.error\s*=/.test(source), 'catch block must set this.error to keep the dialog open');
    // The catch block must NOT call close() inside the error path.
    const catchBlockMatch = source.match(/catch\(\s*\(?err\)?\s*\)\s*\{([\s\S]*?)\}\s*(?:\.finally|\n\s*\}|\n\s*\/)/);
    if (catchBlockMatch) {
        const catchBlock = catchBlockMatch[1];
        assert.ok(!/\bclose\(\)/.test(catchBlock), 'catch block must not call close() — keep dialog open on error');
    }
});

console.log(`\n${name}: ${passed} passed`);
