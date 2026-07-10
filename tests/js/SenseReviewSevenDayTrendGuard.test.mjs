// SenseReviewSevenDayTrendGuard.test.mjs
//
// SenseReview-SevenDayTrend-1000-1
//
// Node built-in assert tests for the SenseReviewSevenDayTrend frontend
// contract. No third-party test framework required — runs with
// `node --test` or plain `node <file>`.
//
// These tests guard:
//   1. The SenseReviewSevenDayTrend.vue component file exists.
//   2. The component is presentational only — no axios/post/rating API,
//      no ReviewLog writes, no FSRS mutations.
//   3. SenseReview.vue registers the component and has the
//      "查看近 7 天学习趋势" entry button.
//   4. The seven-day-trend endpoint is GET /reviews/senses/seven-day-trend.
//   5. Empty state text is present ("近 7 天还没有完成词义卡复习。").
//   6. Four concepts are clearly distinguished by wording:
//      - "本次复习总结" (session summary)
//      - "今日复习总结" (today summary)
//      - "今日学习日报" (daily report)
//      - "近 7 天学习趋势" (seven day trend)
//   7. No chart library is imported (uses Vuetify + CSS only).

import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const COMPONENT_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'Senses', 'SenseReviewSevenDayTrend.vue');
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

console.log('SenseReviewSevenDayTrend frontend guard tests\n');

// 1. Component file exists
test('SenseReviewSevenDayTrend.vue exists', () => {
    assert.ok(existsSync(COMPONENT_PATH), `Expected ${COMPONENT_PATH} to exist`);
});

// 2. Component is presentational only — no API calls / ReviewLog writes
test('component does not call rating API or write ReviewLog', () => {
    const src = readFileSync(COMPONENT_PATH, 'utf8');
    assert.ok(!/axios\.(post|put|delete|patch)/.test(src), 'component must not call write APIs');
    assert.ok(!/axios\.get/.test(src), 'component must not call any API (parent loads data)');
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
    assert.ok(src.includes('近 7 天还没有完成词义卡复习'), 'component must show empty-state text');
});

// 5. Component labels itself as "近 7 天学习趋势"
test('component labels itself as seven day trend', () => {
    const src = readFileSync(COMPONENT_PATH, 'utf8');
    assert.ok(src.includes('近 7 天学习趋势'), 'component must be titled "近 7 天学习趋势"');
});

// 6. No chart library imported
test('component does not import chart library', () => {
    const src = readFileSync(COMPONENT_PATH, 'utf8');
    assert.ok(!/chart\.js|ChartJS|echarts|d3|highcharts|apexcharts/i.test(src), 'component must not import chart libraries');
});

// 7. Container registers the component
test('SenseReview.vue registers SenseReviewSevenDayTrend', () => {
    const src = readFileSync(CONTAINER_PATH, 'utf8');
    assert.ok(src.includes("import SenseReviewSevenDayTrend"), 'container must import the component');
    assert.ok(/SenseReviewSevenDayTrend/.test(src), 'container must register the component');
});

// 8. Container has the "查看近 7 天学习趋势" entry button
test('SenseReview.vue has the seven-day-trend entry button', () => {
    const src = readFileSync(CONTAINER_PATH, 'utf8');
    assert.ok(src.includes('查看近 7 天学习趋势'), 'container must have the entry button');
    assert.ok(/openSevenDayTrend/.test(src), 'container must wire openSevenDayTrend');
    assert.ok(/closeSevenDayTrend/.test(src), 'container must wire closeSevenDayTrend');
});

// 9. Four concepts are clearly distinguished by wording
test('SenseReview.vue keeps four concepts separate', () => {
    const src = readFileSync(CONTAINER_PATH, 'utf8');
    assert.ok(src.includes('本次复习'), 'session summary wording must be present');
    assert.ok(src.includes('今日复习总结'), 'today summary wording must be present');
    assert.ok(src.includes('今日学习日报'), 'daily report wording must be present');
    assert.ok(src.includes('近 7 天学习趋势'), 'seven day trend wording must be present');
});

// 10. Route is registered as GET /reviews/senses/seven-day-trend
test('route GET /reviews/senses/seven-day-trend is registered', () => {
    const src = readFileSync(ROUTES_PATH, 'utf8');
    assert.ok(
        /Route::get\('\/reviews\/senses\/seven-day-trend'/.test(src),
        'route must be registered as GET /reviews/senses/seven-day-trend',
    );
    assert.ok(/sevenDayTrend/.test(src), 'route must point to sevenDayTrend method');
});

// 11. Container only loads seven day trend via GET (read-only)
test('container only reads seven day trend via GET, never writes', () => {
    const src = readFileSync(CONTAINER_PATH, 'utf8');
    assert.ok(/axios\.get\('\/reviews\/senses\/seven-day-trend'\)/.test(src), 'container must GET the seven day trend');
    assert.ok(!/axios\.(post|put|delete|patch)\('\/reviews\/senses\/seven-day-trend'/.test(src), 'container must not write to seven-day-trend');
});

// 12. Component uses trend prop (not report or summary)
test('component uses trend prop, not report or summary', () => {
    const src = readFileSync(COMPONENT_PATH, 'utf8');
    assert.ok(/trend:/.test(src), 'component must use trend prop');
});

console.log(`\n${passed} passed`);
console.log('Done.');
