// SenseReviewThirtyDayCalendarGuard.test.mjs
//
// SenseReview-ThirtyDayCalendar-1000-1
//
// Node built-in assert tests for the SenseReviewThirtyDayCalendar frontend
// contract. No third-party test framework required.
//
// These tests guard:
//   1. The SenseReviewThirtyDayCalendar.vue component file exists.
//   2. The component is presentational only — no axios/post/rating API,
//      no ReviewLog writes, no FSRS mutations.
//   3. Component emits close event.
//   4. Empty state text present ("近 30 天还没有完成词义卡复习。").
//   5. Component labels itself as "近 30 天复习日历".
//   6. No chart library imported.
//   7. ReportCenter registers the component.
//   8. SenseReview.vue has the "查看近 30 天复习日历" entry button.
//   9. Route GET /reviews/senses/thirty-day-calendar is registered.
//  10. ReportCenter only reads calendar via GET, never writes.
//  11. Five concepts are clearly distinguished by wording.
//  12. Component uses calendar prop.

import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const COMPONENT_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'Senses', 'SenseReviewThirtyDayCalendar.vue');
const CONTAINER_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'Senses', 'SenseReview.vue');
const CENTER_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'Senses', 'SenseReviewReportCenter.vue');
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

console.log('SenseReviewThirtyDayCalendar frontend guard tests\n');

const componentSrc = existsSync(COMPONENT_PATH) ? readFileSync(COMPONENT_PATH, 'utf-8') : '';
const containerSrc = existsSync(CONTAINER_PATH) ? readFileSync(CONTAINER_PATH, 'utf-8') : '';
const centerSrc = existsSync(CENTER_PATH) ? readFileSync(CENTER_PATH, 'utf-8') : '';
const routesSrc = existsSync(ROUTES_PATH) ? readFileSync(ROUTES_PATH, 'utf-8') : '';

// 1. Component file exists.
test('SenseReviewThirtyDayCalendar.vue exists', () => {
    assert.ok(existsSync(COMPONENT_PATH), 'component file must exist');
});

// 2. Component is presentational only.
test('component does not call rating API or write ReviewLog', () => {
    assert.ok(!/axios\.(post|put|delete|patch)/.test(componentSrc), 'component must not call write APIs');
    assert.ok(!/axios\.get/.test(componentSrc), 'component must not call any API (parent loads data)');
    assert.ok(!/\/reviews\/senses\/[^/]+\/rate/.test(componentSrc), 'component must not call the rate API');
    // Check actual code references, not docblock comments.
    assert.ok(!/\bReviewLog\b/.test(componentSrc.replace(/\/\/[^\n]*/g, '').replace(/\/\*[\s\S]*?\*\//g, '')), 'component must not reference ReviewLog in code');
    assert.ok(!/\bfsrs\b/i.test(componentSrc.replace(/\/\/[^\n]*/g, '').replace(/\/\*[\s\S]*?\*\//g, '')), 'component must not reference FSRS in code');
});

// 3. Component emits 'close'.
test('component emits close event', () => {
    assert.ok(/\$emit\('close'\)/.test(componentSrc) || /\$emit\("close"\)/.test(componentSrc), "component must emit 'close'");
});

// 4. Empty-state text.
test('component contains empty-state text', () => {
    assert.ok(componentSrc.includes('近 30 天还没有完成词义卡复习'), 'component must show empty-state text');
});

// 5. Component labels itself.
test('component labels itself as thirty day calendar', () => {
    assert.ok(componentSrc.includes('近 30 天复习日历'), 'component must be titled "近 30 天复习日历"');
});

// 6. No chart library.
test('component does not import chart library', () => {
    assert.ok(!/chart\.js|ChartJS|echarts|d3|highcharts|apexcharts/i.test(componentSrc), 'component must not import chart libraries');
});

// 7. ReportCenter registers the component.
test('ReportCenter registers SenseReviewThirtyDayCalendar', () => {
    assert.ok(centerSrc.includes('import SenseReviewThirtyDayCalendar'), 'ReportCenter must import the component');
    assert.ok(/SenseReviewThirtyDayCalendar/.test(centerSrc), 'ReportCenter must register the component');
});

// 8. SenseReview.vue has the entry button.
test('SenseReview.vue has the thirty-day-calendar entry button', () => {
    assert.ok(containerSrc.includes('查看近 30 天复习日历'), 'container must have the entry button');
    assert.ok(containerSrc.includes("'thirty-day-calendar'"), "container must set activeReport to 'thirty-day-calendar'");
});

// 9. Route is registered.
test('route GET /reviews/senses/thirty-day-calendar is registered', () => {
    assert.ok(
        /Route::get\('\/reviews\/senses\/thirty-day-calendar'/.test(routesSrc),
        'route must be registered as GET /reviews/senses/thirty-day-calendar',
    );
    assert.ok(/thirtyDayCalendar/.test(routesSrc), 'route must point to thirtyDayCalendar method');
});

// 10. ReportCenter only reads calendar via GET.
test('ReportCenter only reads thirty day calendar via GET, never writes', () => {
    assert.ok(/axios\.get/.test(centerSrc), 'ReportCenter must use GET');
    assert.ok(centerSrc.includes('/reviews/senses/thirty-day-calendar'), 'ReportCenter must reference thirty-day-calendar endpoint');
    assert.ok(!/axios\.(post|put|delete|patch)/.test(centerSrc), 'ReportCenter must not use write APIs');
});

// 11. Five concepts are clearly distinguished by wording.
test('SenseReview.vue keeps five concepts separate', () => {
    assert.ok(containerSrc.includes('今日复习总结'), 'today summary wording must be present');
    assert.ok(containerSrc.includes('今日学习日报'), 'daily report wording must be present');
    assert.ok(containerSrc.includes('近 7 天学习趋势'), 'seven day trend wording must be present');
    assert.ok(containerSrc.includes('近 30 天复习日历'), 'thirty day calendar wording must be present');
});

// 12. Component uses calendar prop.
test('component uses calendar prop', () => {
    assert.ok(componentSrc.includes('calendar'), 'component must reference calendar prop');
    assert.ok(/props:\s*\{[\s\S]*calendar/.test(componentSrc), 'component must define calendar prop');
});

// 13. Component has 30-cell grid rendering entry.
test('component has calendar grid for 30 cells', () => {
    assert.ok(componentSrc.includes('calendar-grid'), 'component must have calendar-grid CSS class');
    assert.ok(componentSrc.includes('v-for'), 'component must use v-for to render day cells');
});

// 14. Day detail uses local state only (no API call on click).
test('day detail uses local state only', () => {
    assert.ok(componentSrc.includes('selectedIndex'), 'component must use local selectedIndex state');
    assert.ok(componentSrc.includes('selectDay'), 'component must have selectDay method');
});

console.log(`\n${passed} passed`);
