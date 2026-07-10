// SenseReviewReportCenterGuard.test.mjs
//
// SenseReview-ReportCenter-1000-2
//
// Node built-in assert tests for the SenseReviewReportCenter frontend
// contract (redesigned in 1000-2). No third-party test framework required.
//
// These tests guard:
//   1. The SenseReviewReportCenter.vue component file exists.
//   2. The component is read-only — only axios.get, no POST/rating API.
//   3. The component uses v-model = boolean open prop (NOT activeReport string).
//   4. SenseReview.vue mounts exactly ONE ReportCenter.
//   5. SenseReview.vue uses reportCenterOpen (boolean), NOT activeReport.
//   6. ReportCenter owns selectedReportKey / loading / error / payload.
//   7. ReportCenter has requestSequence for async-race protection.
//   8. ReportCenter consumes SenseReviewReportCatalog.js.
//   9. ReportCenter registers all four report components.
//  10. ReportCenter endpoint map is GET-only (via Catalog).
//  11. Routes define all four GET endpoints.
//  12. ReportCenter does not import SessionSummary.
//  13. ReportCenter emits input false on close.
//  14. ReportCenter has a report home page (no GET until selection).

import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const CENTER_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'Senses', 'SenseReviewReportCenter.vue');
const CATALOG_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'Senses', 'SenseReviewReportCatalog.js');
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

console.log('SenseReviewReportCenter frontend guard tests\n');

const centerSrc = existsSync(CENTER_PATH) ? readFileSync(CENTER_PATH, 'utf-8') : '';
const catalogSrc = existsSync(CATALOG_PATH) ? readFileSync(CATALOG_PATH, 'utf-8') : '';
const containerSrc = existsSync(CONTAINER_PATH) ? readFileSync(CONTAINER_PATH, 'utf-8') : '';
const routesSrc = existsSync(ROUTES_PATH) ? readFileSync(ROUTES_PATH, 'utf-8') : '';

// 1. Component exists.
test('SenseReviewReportCenter.vue file exists', () => {
    assert.ok(existsSync(CENTER_PATH), 'SenseReviewReportCenter.vue must exist');
});

// 2. Component is read-only — only axios.get, no POST.
test('ReportCenter uses only axios.get (no POST/rating API)', () => {
    assert.ok(centerSrc.includes('axios.get'), 'ReportCenter must use axios.get');
    assert.ok(!centerSrc.includes('axios.post'), 'ReportCenter must NOT use axios.post');
    assert.ok(!centerSrc.includes('/rate'), 'ReportCenter must NOT call rating API');
});

// 3. Component uses v-model = boolean open prop (NOT activeReport string).
test('ReportCenter uses v-model = boolean open prop', () => {
    assert.ok(centerSrc.includes("prop: 'open'"), 'ReportCenter model prop must be open (boolean)');
    assert.ok(centerSrc.includes('open:'), 'ReportCenter must have open prop');
    assert.ok(!centerSrc.includes("prop: 'activeReport'"), 'ReportCenter must NOT use activeReport prop');
});

// 4. SenseReview.vue mounts exactly ONE ReportCenter.
test('SenseReview.vue mounts exactly one ReportCenter', () => {
    const mountCount = (containerSrc.match(/<SenseReviewReportCenter/g) || []).length;
    assert.strictEqual(mountCount, 1, 'SenseReview.vue must mount exactly one <SenseReviewReportCenter>');
});

// 5. SenseReview.vue uses reportCenterOpen (boolean), NOT activeReport.
test('SenseReview.vue uses reportCenterOpen (boolean)', () => {
    assert.ok(containerSrc.includes('reportCenterOpen'), 'SenseReview.vue must have reportCenterOpen');
    // activeReport should no longer appear as a data property or binding.
    // (It may still appear in comments, so we check the data/binding context.)
    assert.ok(!/activeReport\s*[:=]/.test(containerSrc), 'SenseReview.vue must not bind activeReport');
});

// 6. ReportCenter owns selectedReportKey / loading / error / payload.
test('ReportCenter owns internal state (selectedReportKey/loading/error/payload)', () => {
    assert.ok(centerSrc.includes('selectedReportKey'), 'ReportCenter must have selectedReportKey');
    assert.ok(centerSrc.includes('loading:'), 'ReportCenter must own loading');
    assert.ok(centerSrc.includes('error:'), 'ReportCenter must own error');
    assert.ok(centerSrc.includes('payload:'), 'ReportCenter must own payload');
});

// 7. ReportCenter has requestSequence for async-race protection.
test('ReportCenter has requestSequence for async-race protection', () => {
    assert.ok(centerSrc.includes('requestSequence'), 'ReportCenter must have requestSequence');
    assert.ok(centerSrc.includes('seq !== this.requestSequence'), 'ReportCenter must guard stale responses');
});

// 8. ReportCenter consumes SenseReviewReportCatalog.js.
test('ReportCenter consumes SenseReviewReportCatalog.js', () => {
    assert.ok(existsSync(CATALOG_PATH), 'SenseReviewReportCatalog.js must exist');
    assert.ok(centerSrc.includes('SenseReviewReportCatalog'), 'ReportCenter must import Catalog');
    assert.ok(catalogSrc.includes('REPORT_CATALOG'), 'Catalog must export REPORT_CATALOG');
    assert.ok(catalogSrc.includes('daily-report'), 'Catalog must have daily-report');
    assert.ok(catalogSrc.includes('seven-day-trend'), 'Catalog must have seven-day-trend');
    assert.ok(catalogSrc.includes('thirty-day-calendar'), 'Catalog must have thirty-day-calendar');
    // today-summary must NOT be in catalog (consolidated in ADR-0006).
    assert.ok(!catalogSrc.includes("'today-summary'"), 'Catalog must NOT have today-summary (ADR-0006)');
});

// 9. ReportCenter registers all three report components (post ADR-0006).
test('ReportCenter registers all three report components', () => {
    assert.ok(centerSrc.includes('SenseReviewDailyReport'), 'ReportCenter must register SenseReviewDailyReport');
    assert.ok(centerSrc.includes('SenseReviewSevenDayTrend'), 'ReportCenter must register SenseReviewSevenDayTrend');
    assert.ok(centerSrc.includes('SenseReviewThirtyDayCalendar'), 'ReportCenter must register SenseReviewThirtyDayCalendar');
    assert.ok(!centerSrc.includes('SenseReviewTodaySummary'), 'ReportCenter must NOT register SenseReviewTodaySummary (deleted in ADR-0006)');
});

// 10. ReportCenter endpoint map is GET-only (via Catalog).
test('Catalog endpoint map uses GET endpoints', () => {
    assert.ok(catalogSrc.includes('/reviews/senses/daily-report'), 'daily-report endpoint present');
    assert.ok(catalogSrc.includes('/reviews/senses/seven-day-trend'), 'seven-day-trend endpoint present');
    assert.ok(catalogSrc.includes('/reviews/senses/thirty-day-calendar'), 'thirty-day-calendar endpoint present');
    assert.ok(!catalogSrc.includes('/reviews/senses/today-summary'), 'today-summary endpoint must NOT be present (ADR-0006)');
});

// 11. Routes define all three GET endpoints.
test('Routes define all three GET report endpoints', () => {
    assert.ok(routesSrc.includes('/reviews/senses/daily-report'), 'daily-report route present');
    assert.ok(routesSrc.includes('/reviews/senses/seven-day-trend'), 'seven-day-trend route present');
    assert.ok(routesSrc.includes('/reviews/senses/thirty-day-calendar'), 'thirty-day-calendar route present');
    assert.ok(!routesSrc.includes('/reviews/senses/today-summary'), 'today-summary route must NOT be present (ADR-0006)');
});

// 12. ReportCenter does not import SessionSummary.
test('ReportCenter does not manage SessionSummary', () => {
    assert.ok(!centerSrc.includes('import SenseReviewSessionSummary'), 'ReportCenter must NOT import SessionSummary');
    assert.ok(!centerSrc.includes("'SenseReviewSessionSummary'"), 'ReportCenter must NOT register SessionSummary');
});

// 13. ReportCenter emits input false on close.
test('ReportCenter emits input false on close', () => {
    assert.ok(centerSrc.includes("this.$emit('input', false)"), 'ReportCenter must emit input false on close');
});

// 14. ReportCenter has a report home page (no GET until selection).
test('ReportCenter has report home page (catalog selection, no GET until selection)', () => {
    assert.ok(centerSrc.includes('selectReport'), 'ReportCenter must have selectReport method');
    assert.ok(centerSrc.includes('backToList'), 'ReportCenter must have backToList method');
    assert.ok(centerSrc.includes('!selectedReportKey'), 'ReportCenter must show home page when no report selected');
});

// 15. SenseReview.vue has exactly ONE "学习报告" entry button and no old report buttons.
test('SenseReview.vue has single 学习报告 entry, no old report buttons', () => {
    const reportButtonCount = (containerSrc.match(/学习报告/g) || []).length;
    assert.ok(reportButtonCount >= 1, 'container must have the 学习报告 entry');
    // Old individual report entry buttons must be gone.
    assert.ok(!containerSrc.includes('查看今日学习日报'), 'old daily-report button must be removed');
    assert.ok(!containerSrc.includes('查看近 7 天学习趋势'), 'old seven-day-trend button must be removed');
    assert.ok(!containerSrc.includes('查看近 30 天复习日历'), 'old thirty-day-calendar button must be removed');
});

// 16. Reopen always returns to report home page (A-4 contract).
test('ReportCenter reopens to home page (selectedReportKey resets on close)', () => {
    // resetState must set selectedReportKey to null.
    assert.ok(/selectedReportKey\s*=\s*null/.test(centerSrc), 'resetState must null selectedReportKey');
    // The watch on `open` must call resetState when dialog closes.
    assert.ok(/open\(newVal\)/.test(centerSrc), 'ReportCenter must watch open prop');
    assert.ok(/resetState\(\)/.test(centerSrc), 'ReportCenter must call resetState on close');
    // close() must also call resetState.
    assert.ok(/close\(\)/.test(centerSrc), 'ReportCenter must have close method');
    // The home page condition must check !selectedReportKey.
    assert.ok(centerSrc.includes('!selectedReportKey'), 'ReportCenter must show home page when selectedReportKey is null');
    // No GET request must fire on home page — fetchReport is only called from selectReport.
    assert.ok(/selectReport\(key\)/.test(centerSrc), 'ReportCenter must have selectReport method');
    assert.ok(/this\.fetchReport\(\)/.test(centerSrc), 'selectReport must call fetchReport');
    // The home page template must NOT call fetchReport.
    const homePageBlock = centerSrc.match(/v-else-if="!selectedReportKey"[\s\S]*?<\/div>/);
    if (homePageBlock) {
        assert.ok(!/fetchReport/.test(homePageBlock[0]), 'home page must NOT call fetchReport');
    }
});

// 17. Catalog has exactly 3 reports (not 4 — today-summary removed in ADR-0006).
test('Catalog has exactly 3 report entries', () => {
    const keyCount = (catalogSrc.match(/key:\s*'/g) || []).length;
    assert.strictEqual(keyCount, 3, 'Catalog must have exactly 3 report entries (post ADR-0006)');
});

console.log(`\n${passed} passed`);
