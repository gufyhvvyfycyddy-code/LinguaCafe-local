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
const CENTER_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'Senses', 'SenseReviewReportCenter.vue');
const CATALOG_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'Senses', 'SenseReviewReportCatalog.js');
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

// 7. ReportCenter registers the component (SenseReview.vue delegates to ReportCenter)
test('SenseReview.vue registers SenseReviewSevenDayTrend via ReportCenter', () => {
    const centerSrc = readFileSync(CENTER_PATH, 'utf8');
    assert.ok(centerSrc.includes("import SenseReviewSevenDayTrend"), 'ReportCenter must import the component');
    assert.ok(/SenseReviewSevenDayTrend/.test(centerSrc), 'ReportCenter must register the component');
});

// 8. Container opens report center via single 学习报告 entry
test('SenseReview.vue opens report center via single 学习报告 entry', () => {
    const src = readFileSync(CONTAINER_PATH, 'utf8');
    assert.ok(src.includes('学习报告'), 'container must have the single 学习报告 entry button');
    assert.ok(src.includes('reportCenterOpen'), 'container must use reportCenterOpen to open report center');
});

// 9. Catalog lists all three report titles (post ADR-0006)
test('Catalog lists all three report titles', () => {
    const catalogSrc = readFileSync(CATALOG_PATH, 'utf8');
    assert.ok(catalogSrc.includes('今日学习日报'), 'daily report title must be in Catalog');
    assert.ok(catalogSrc.includes('近 7 天学习趋势'), 'seven day trend title must be in Catalog');
    assert.ok(catalogSrc.includes('近 30 天复习日历'), 'thirty day calendar title must be in Catalog');
    assert.ok(!catalogSrc.includes('今日复习总结'), 'today summary title must NOT be in Catalog (ADR-0006)');
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

// 11. ReportCenter only loads seven day trend via GET (read-only)
test('ReportCenter only reads seven day trend via GET, never writes', () => {
    const centerSrc = readFileSync(CENTER_PATH, 'utf8');
    const catalogSrc = readFileSync(CATALOG_PATH, 'utf8');
    assert.ok(/axios\.get/.test(centerSrc), 'ReportCenter must use GET');
    assert.ok(catalogSrc.includes('/reviews/senses/seven-day-trend'), 'Catalog must reference seven-day-trend endpoint');
    assert.ok(!/axios\.(post|put|delete|patch)/.test(centerSrc), 'ReportCenter must not use write APIs');
});

// 12. Component uses trend prop (not report or summary)
test('component uses trend prop, not report or summary', () => {
    const src = readFileSync(COMPONENT_PATH, 'utf8');
    assert.ok(/trend:/.test(src), 'component must use trend prop');
});

// 13. ADR-0007 / Task A-5: 7-day rolling window is frozen — no date
//     picker, no week toggle, no natural week, no weekly snapshot.
//     The component must NOT introduce UI controls that would let the
//     user change the fixed rolling window (today + previous 6 natural
//     days). This guard locks the product decision from ADR-0006/A-5.
test('component has no date picker, week toggle, or weekly snapshot controls', () => {
    const src = readFileSync(COMPONENT_PATH, 'utf8');
    assert.ok(!/v-date-picker|DatePicker|date-picker/i.test(src), 'component must not have a date picker');
    assert.ok(!/周一|周日|weekStart|week-start|isoWeek/i.test(src), 'component must not have a Mon-Sun week toggle');
    assert.ok(!/自然周|weekly snapshot|weekReport|week-report/i.test(src), 'component must not reference natural week or weekly snapshot');
    assert.ok(!/v-switch|v-select/i.test(src), 'component must not have a switch or select to change the window');
});

// 14. Backend service uses rollingDays(7, tz) — fixed 7-day window
test('backend service uses rollingDays(7, tz) for fixed 7-day window', () => {
    const servicePath = join(__dirname, '..', '..', 'app', 'Services', 'SenseReviewSevenDayTrendService.php');
    const src = readFileSync(servicePath, 'utf8');
    assert.ok(/rollingDays\(7/.test(src), 'service must call rollingDays(7, ...) for the 7-day window');
    assert.ok(!/rollingDays\(8|rollingDays\(6|rollingDays\(14|rollingDays\(30/.test(src), 'service must not use other window sizes for seven-day trend');
});

// 15. Period service implements today + subDays(days-1) semantics
test('period service implements today + previous (days-1) natural days', () => {
    const periodPath = join(__dirname, '..', '..', 'app', 'Services', 'SenseReviewReportPeriodService.php');
    const src = readFileSync(periodPath, 'utf8');
    assert.ok(/Carbon::today/.test(src), 'period must use Carbon::today as end day');
    assert.ok(/subDays\(\$days\s*-\s*1\)/.test(src), 'period must subDays(days-1) for start day');
});

console.log(`\n${passed} passed`);
console.log('Done.');
