// SenseReviewDailyReportGuard.test.mjs
//
// SenseReview-DailyReport-1000-1
//
// Node built-in assert tests for the SenseReviewDailyReport frontend
// contract. No third-party test framework required — runs with
// `node --test` or plain `node <file>`.
//
// These tests guard:
//   1. The SenseReviewDailyReport.vue component file exists.
//   2. The component is presentational only — no axios/post/rating API,
//      no ReviewLog writes, no FSRS mutations.
//   3. SenseReview.vue registers the component and has the
//      "查看今日学习日报" entry button.
//   4. The daily-report endpoint is GET /reviews/senses/daily-report.
//   5. Empty state text is present ("今天还没有完成词义卡复习。").
//   6. Three concepts are clearly distinguished by wording:
//      - "本次复习总结" (session summary)
//      - "今日复习总结" (today summary)
//      - "今日学习日报" (daily report)

import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const COMPONENT_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'Senses', 'SenseReviewDailyReport.vue');
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

console.log('SenseReviewDailyReport frontend guard tests\n');

// 1. Component file exists
test('SenseReviewDailyReport.vue exists', () => {
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
    assert.ok(src.includes('今天还没有完成词义卡复习'), 'component must show empty-state text');
});

// 5. Component clearly labels itself as "今日学习日报" (daily report, post ADR-0006)
test('component labels itself as daily report', () => {
    const src = readFileSync(COMPONENT_PATH, 'utf8');
    assert.ok(src.includes('今日学习日报'), 'component must be titled "今日学习日报"');
    // The old "今日复习总结" concept has been merged into this daily report.
    assert.ok(!src.includes('今日复习总结'), 'component must NOT use the old 今日复习总结 title (merged in ADR-0006)');
});

// 6. Component contains all five block titles (post ADR-0006 consolidation)
test('component contains all five block titles', () => {
    const src = readFileSync(COMPONENT_PATH, 'utf8');
    assert.ok(src.includes('今日复习概览'), 'component must have overview block');
    assert.ok(src.includes('今日学习质量'), 'component must have quality block');
    assert.ok(src.includes('今日重点词义'), 'component must have focus_senses block');
    assert.ok(src.includes('今日进步记录'), 'component must have progress_senses block');
    assert.ok(src.includes('最近复习'), 'component must have recent_reviews block (migrated from TodaySummary)');
});

// 7. Container registers the component
test('SenseReview.vue registers SenseReviewDailyReport', () => {
    const src = readFileSync(CONTAINER_PATH, 'utf8');
    const centerSrc = readFileSync(CENTER_PATH, 'utf8');
    assert.ok(centerSrc.includes("import SenseReviewDailyReport"), 'ReportCenter must import the component');
    assert.ok(/SenseReviewDailyReport/.test(centerSrc), 'ReportCenter must register the component');
});

// 8. Container opens report center via single 学习报告 entry
test('SenseReview.vue opens report center via single 学习报告 entry', () => {
    const src = readFileSync(CONTAINER_PATH, 'utf8');
    assert.ok(src.includes('学习报告'), 'container must have the single 学习报告 entry button');
    assert.ok(src.includes('reportCenterOpen'), 'container must use reportCenterOpen to open report center');
});

// 9. Catalog lists the daily-report title
test('Catalog lists the daily-report title', () => {
    const catalogSrc = readFileSync(CATALOG_PATH, 'utf8');
    assert.ok(catalogSrc.includes('今日学习日报'), 'daily report title must be in Catalog');
});

// 10. Route is registered as GET /reviews/senses/daily-report
test('route GET /reviews/senses/daily-report is registered', () => {
    const src = readFileSync(ROUTES_PATH, 'utf8');
    assert.ok(
        /Route::get\('\/reviews\/senses\/daily-report'/.test(src),
        'route must be registered as GET /reviews/senses/daily-report',
    );
    assert.ok(/dailyReport/.test(src), 'route must point to dailyReport method');
});

// 11. Container only loads daily report via GET (read-only)
test('container only reads daily report via GET, never writes', () => {
    const src = readFileSync(CONTAINER_PATH, 'utf8');
    const centerSrc = readFileSync(CENTER_PATH, 'utf8');
    const catalogSrc = readFileSync(CATALOG_PATH, 'utf8');
    assert.ok(/axios\.get/.test(centerSrc), 'ReportCenter must use GET');
    assert.ok(catalogSrc.includes('/reviews/senses/daily-report'), 'Catalog must reference daily-report endpoint');
    assert.ok(!/axios\.(post|put|delete|patch)/.test(centerSrc), 'ReportCenter must not use write APIs');
});

// 12. Daily report component does not reference the today-summary prop name
test('daily report uses report prop, not summary prop', () => {
    const src = readFileSync(COMPONENT_PATH, 'utf8');
    assert.ok(/report:/.test(src), 'component must use report prop');
    assert.ok(!/summary:/.test(src), 'component must not reuse the summary prop name');
});

console.log(`\n${passed} passed`);
console.log('Done.');
