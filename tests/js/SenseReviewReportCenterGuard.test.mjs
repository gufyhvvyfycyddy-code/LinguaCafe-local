// SenseReviewReportCenterGuard.test.mjs
//
// SenseReview-ReportCenter-1000-1
//
// Node built-in assert tests for the SenseReviewReportCenter frontend
// contract. No third-party test framework required — runs with
// `node --test` or plain `node <file>`.
//
// These tests guard:
//   1. The SenseReviewReportCenter.vue component file exists.
//   2. The component is read-only — only axios.get, no POST/rating API,
//      no ReviewLog writes, no FSRS mutations.
//   3. The component uses v-model / activeReport prop.
//   4. SenseReview.vue mounts exactly ONE ReportCenter (not three dialogs).
//   5. SenseReview.vue no longer has three sets of report payload/loading/API.
//   6. Four report concepts remain clearly distinguished by wording.
//   7. ReportCenter registers TodaySummary/DailyReport/SevenDayTrend.
//   8. ReportCenter endpoint map is GET-only.

import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const CENTER_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'Senses', 'SenseReviewReportCenter.vue');
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

// 3. Component uses v-model / activeReport prop.
test('ReportCenter uses v-model / activeReport prop', () => {
    assert.ok(centerSrc.includes("activeReport"), 'ReportCenter must have activeReport prop');
    assert.ok(centerSrc.includes("model:"), 'ReportCenter must define model option for v-model');
    assert.ok(centerSrc.includes("props:"), 'ReportCenter must define props');
});

// 4. SenseReview.vue mounts exactly ONE ReportCenter.
test('SenseReview.vue mounts exactly one ReportCenter (not three dialogs)', () => {
    const reportCenterCount = (containerSrc.match(/SenseReviewReportCenter/g) || []).length;
    assert.ok(reportCenterCount >= 2, 'SenseReview.vue must import and register SenseReviewReportCenter');
    const mountCount = (containerSrc.match(/<SenseReviewReportCenter/g) || []).length;
    assert.strictEqual(mountCount, 1, 'SenseReview.vue must mount exactly one <SenseReviewReportCenter>');
});

// 5. SenseReview.vue no longer has three sets of report payload/loading/API.
test('SenseReview.vue no longer has three sets of report dialog state', () => {
    assert.ok(!containerSrc.includes('showTodaySummary'), 'SenseReview.vue must not have showTodaySummary');
    assert.ok(!containerSrc.includes('showDailyReport'), 'SenseReview.vue must not have showDailyReport');
    assert.ok(!containerSrc.includes('showSevenDayTrend'), 'SenseReview.vue must not have showSevenDayTrend');
    assert.ok(!containerSrc.includes('openTodaySummary'), 'SenseReview.vue must not have openTodaySummary');
    assert.ok(!containerSrc.includes('openDailyReport'), 'SenseReview.vue must not have openDailyReport');
    assert.ok(!containerSrc.includes('openSevenDayTrend'), 'SenseReview.vue must not have openSevenDayTrend');
    assert.ok(!containerSrc.includes('todaySummaryLoading'), 'SenseReview.vue must not have todaySummaryLoading');
    assert.ok(!containerSrc.includes('dailyReportLoading'), 'SenseReview.vue must not have dailyReportLoading');
    assert.ok(!containerSrc.includes('sevenDayTrendLoading'), 'SenseReview.vue must not have sevenDayTrendLoading');
});

// 6. Four report concepts remain clearly distinguished by wording.
test('Four report concepts remain distinguished in SenseReview.vue', () => {
    assert.ok(containerSrc.includes('查看今日复习总结'), 'today summary entry text preserved');
    assert.ok(containerSrc.includes('查看今日学习日报'), 'daily report entry text preserved');
    assert.ok(containerSrc.includes('查看近 7 天学习趋势'), 'seven day trend entry text preserved');
});

// 7. ReportCenter registers TodaySummary/DailyReport/SevenDayTrend.
test('ReportCenter registers TodaySummary/DailyReport/SevenDayTrend components', () => {
    assert.ok(centerSrc.includes('SenseReviewTodaySummary'), 'ReportCenter must register SenseReviewTodaySummary');
    assert.ok(centerSrc.includes('SenseReviewDailyReport'), 'ReportCenter must register SenseReviewDailyReport');
    assert.ok(centerSrc.includes('SenseReviewSevenDayTrend'), 'ReportCenter must register SenseReviewSevenDayTrend');
});

// 8. ReportCenter endpoint map is GET-only.
test('ReportCenter endpoint map uses GET endpoints', () => {
    assert.ok(centerSrc.includes('/reviews/senses/today-summary'), 'today-summary endpoint present');
    assert.ok(centerSrc.includes('/reviews/senses/daily-report'), 'daily-report endpoint present');
    assert.ok(centerSrc.includes('/reviews/senses/seven-day-trend'), 'seven-day-trend endpoint present');
});

// 9. Routes still define all three GET endpoints.
test('Routes define all three GET report endpoints', () => {
    assert.ok(routesSrc.includes('/reviews/senses/today-summary'), 'today-summary route present');
    assert.ok(routesSrc.includes('/reviews/senses/daily-report'), 'daily-report route present');
    assert.ok(routesSrc.includes('/reviews/senses/seven-day-trend'), 'seven-day-trend route present');
});

// 10. ReportCenter does not import SessionSummary (not its responsibility).
test('ReportCenter does not manage SessionSummary', () => {
    assert.ok(!centerSrc.includes('import SenseReviewSessionSummary'), 'ReportCenter must NOT import SessionSummary');
    assert.ok(!centerSrc.includes("'SenseReviewSessionSummary'"), 'ReportCenter must NOT register SessionSummary component');
});

// 11. SenseReview.vue has activeReport data property.
test('SenseReview.vue has activeReport data property', () => {
    assert.ok(containerSrc.includes('activeReport'), 'SenseReview.vue must have activeReport');
});

// 12. ReportCenter emits input event for v-model.
test('ReportCenter emits input event for v-model close', () => {
    assert.ok(centerSrc.includes("this.\$emit('input', null)"), 'ReportCenter must emit input null on close');
});

console.log(`\n${passed} passed`);
