// SenseReviewTodaySummaryGuard.test.mjs
//
// SenseReview-TodaySummary-1000-1
//
// Node built-in assert tests for the SenseReviewTodaySummary frontend
// contract. No third-party test framework required — runs with
// `node --test` or plain `node <file>`.
//
// These tests guard:
//   1. The SenseReviewTodaySummary.vue component file exists.
//   2. The component is presentational only — no axios/post/rating API,
//      no ReviewLog writes, no FSRS mutations.
//   3. SenseReview.vue registers the component and has the
//      "查看今日复习总结" entry button.
//   4. The today-summary endpoint is GET /reviews/senses/today-summary.
//   5. Empty state text is present ("今天还没有完成词义卡复习。").
//   6. Session summary ("本次复习总结") and today summary ("今日复习总结")
//      are clearly distinguished by wording.

import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const COMPONENT_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'Senses', 'SenseReviewTodaySummary.vue');
const CONTAINER_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'Senses', 'SenseReview.vue');
const ROUTES_PATH = join(__dirname, '..', '..', 'routes', 'web.php');

let passed = 0;
function test(name, fn) {
    try {
        fn();
        passed++;
        console.log(`  ✓ ${name}`);
    } catch (e) {
        console.error(`  ✗ ${name}`);
        console.error(`    ${e.message}`);
        process.exitCode = 1;
    }
}

console.log('SenseReviewTodaySummary frontend guard tests\n');

// 1. Component file exists
test('SenseReviewTodaySummary.vue exists', () => {
    assert.ok(existsSync(COMPONENT_PATH), `Expected ${COMPONENT_PATH} to exist`);
});

// 2. Component is presentational only — no API calls / ReviewLog writes
test('component does not call rating API or write ReviewLog', () => {
    const src = readFileSync(COMPONENT_PATH, 'utf8');
    assert.ok(!/axios\.(post|put|delete|patch)/.test(src), 'component must not call write APIs');
    assert.ok(!/\/reviews\/senses\/[^/]+\/rate/.test(src), 'component must not call the rate API');
    assert.ok(!/ReviewLog/i.test(src), 'component must not reference ReviewLog');
    assert.ok(!/fsrs/i.test(src), 'component must not reference FSRS');
});

// 3. Component emits 'close' (does not own dismissal logic)
test('component emits close event', () => {
    const src = readFileSync(COMPONENT_PATH, 'utf8');
    assert.ok(/\$emit\('close'\)/.test(src) || /\$emit\("close"\)/.test(src), "component must emit 'close'");
});

// 4. Component contains empty-state text
test('component contains empty-state text', () => {
    const src = readFileSync(COMPONENT_PATH, 'utf8');
    assert.ok(src.includes('今天还没有完成词义卡复习'), 'component must show empty-state text');
});

// 5. Component clearly labels itself as "今日复习总结" (today), NOT "本次复习" (session)
test('component labels itself as today summary, distinct from session summary', () => {
    const src = readFileSync(COMPONENT_PATH, 'utf8');
    assert.ok(src.includes('今日复习总结'), 'component must be titled "今日复习总结"');
    // The component should not reuse the session-summary title
    assert.ok(!src.includes('本次复习总结'), 'component must not reuse the session-summary title');
});

// 6. Container registers the component
test('SenseReview.vue registers SenseReviewTodaySummary', () => {
    const src = readFileSync(CONTAINER_PATH, 'utf8');
    assert.ok(src.includes("import SenseReviewTodaySummary"), 'container must import the component');
    assert.ok(/SenseReviewTodaySummary/.test(src), 'container must register the component');
});

// 7. Container has the "查看今日复习总结" entry button
test('SenseReview.vue has the today-summary entry button', () => {
    const src = readFileSync(CONTAINER_PATH, 'utf8');
    assert.ok(src.includes('查看今日复习总结'), 'container must have the entry button');
    assert.ok(/openTodaySummary/.test(src), 'container must wire openTodaySummary');
    assert.ok(/closeTodaySummary/.test(src), 'container must wire closeTodaySummary');
});

// 8. Container distinguishes session summary from today summary
test('SenseReview.vue keeps session summary and today summary separate', () => {
    const src = readFileSync(CONTAINER_PATH, 'utf8');
    // Both titles must be present and distinct
    assert.ok(src.includes('本次复习'), 'session summary wording must be present');
    assert.ok(src.includes('今日复习总结'), 'today summary wording must be present');
});

// 9. Route is registered as GET /reviews/senses/today-summary
test('route GET /reviews/senses/today-summary is registered', () => {
    const src = readFileSync(ROUTES_PATH, 'utf8');
    assert.ok(
        /Route::get\('\/reviews\/senses\/today-summary'/.test(src),
        'route must be registered as GET /reviews/senses/today-summary',
    );
    assert.ok(/todaySummary/.test(src), 'route must point to todaySummary method');
});

// 10. Container only loads today summary via GET (read-only)
test('container only reads today summary via GET, never writes', () => {
    const src = readFileSync(CONTAINER_PATH, 'utf8');
    assert.ok(/axios\.get\('\/reviews\/senses\/today-summary'\)/.test(src), 'container must GET the today summary');
    // No POST/PUT/DELETE to the today-summary endpoint
    assert.ok(!/axios\.(post|put|delete|patch)\('\/reviews\/senses\/today-summary'/.test(src), 'container must not write to today-summary');
});

console.log(`\n${passed} passed`);
console.log('Done.');
