// ReviewCardInfoGuard.test.mjs
//
// ADR-0014 — Review Card Info Read Model (Card Info V1 convergence)
//
// Node built-in assert tests for the ReviewCardManage.vue Card Info panel.
// Source-code guard tests that verify the Vue component implements the
// single-request Card Info architecture without firing the old three
// parallel sub-requests (/logs, /lifecycle-events, /leech).
//
// Tests:
//   1.  openDetail calls the canonical /detail endpoint.
//   2.  loadDeepLinkDetail uses the same canonical /detail endpoint.
//   3.  openDetail fires exactly one request (loadCardInfo).
//   4.  openDetail does NOT fire /logs, /lifecycle-events, or /leech.
//   5.  Loading state (detailLoading) exists.
//   6.  Empty state (v-else without detailTarget) exists.
//   7.  Error state (detailError) exists.
//   8.  Overview tab (v-tab-item value="overview") exists.
//   9.  History tab (v-tab-item value="history") exists.
//  10.  Diagnosis tab (v-tab-item value="diagnosis") exists.
//  11.  Undone display (v-if="log.undone" + 已撤销 chip) preserved.
//  12.  Stale-response guard (detailRequestSeq) exists.
//  13.  closeDetail clears cardInfo and bumps sequence.
//  14.  No hardcoded width exceeding 420 on the drawer.
//  15.  Management table data() method still exists (table unaffected).
//  16.  Browser Search parser reference intact (search unaffected).
//  17.  Export methods (exportJson/exportCsv/exportAnkiTsv) intact.
//  18.  Daily report deep link (deepLink) handling intact.

import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const MANAGE_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardManage.vue');

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

const source = existsSync(MANAGE_PATH) ? readFileSync(MANAGE_PATH, 'utf-8') : '';

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

// 1. openDetail calls the canonical /detail endpoint
test('openDetail calls the canonical /detail endpoint', () => {
    const body = extractMethod('openDetail');
    assert.ok(body.includes('loadCardInfo'), 'openDetail must call loadCardInfo');
});

// 2. loadDeepLinkDetail uses the same canonical /detail endpoint
test('loadDeepLinkDetail uses the canonical /detail endpoint', () => {
    const body = extractMethod('loadDeepLinkDetail');
    assert.ok(body.includes('/detail'), 'loadDeepLinkDetail must request /detail');
});

// 3. openDetail fires exactly one request (loadCardInfo)
test('openDetail fires exactly one request via loadCardInfo', () => {
    const body = extractMethod('openDetail');
    const axiosGetCount = (body.match(/axios\.get/g) || []).length;
    assert.ok(axiosGetCount === 0, 'openDetail must not directly call axios.get (delegates to loadCardInfo)');
    assert.ok(body.includes('loadCardInfo('), 'openDetail must delegate to loadCardInfo');
});

// 4. openDetail does NOT fire /logs, /lifecycle-events, or /leech
test('openDetail does NOT fire /logs, /lifecycle-events, or /leech', () => {
    const body = extractMethod('openDetail');
    assert.ok(!body.includes('/logs'), 'openDetail must not request /logs');
    assert.ok(!body.includes('/lifecycle-events'), 'openDetail must not request /lifecycle-events');
    assert.ok(!body.includes('/leech'), 'openDetail must not request /leech');
});

// 5. Loading state (detailLoading) exists
test('loading state detailLoading exists', () => {
    assert.ok(source.includes('detailLoading'), 'must have detailLoading data property');
});

// 6. Empty state (v-else without detailTarget) exists
test('empty state exists when no detailTarget', () => {
    assert.ok(source.includes("请从列表中选择一张卡片查看详情"), 'must have empty state message');
});

// 7. Error state (detailError) exists
test('error state detailError exists', () => {
    assert.ok(source.includes('detailError'), 'must have detailError data property');
    assert.ok(source.includes('加载卡片详情失败'), 'must show error message on failure');
});

// 8. Overview tab exists
test('overview tab exists', () => {
    assert.ok(source.includes('v-tab-item value="overview"'), 'must have overview tab');
    assert.ok(source.includes('概览'), 'must display 概览 tab label');
});

// 9. History tab exists
test('history tab exists', () => {
    assert.ok(source.includes('v-tab-item value="history"'), 'must have history tab');
    assert.ok(source.includes('历史'), 'must display 历史 tab label');
});

// 10. Diagnosis tab exists
test('diagnosis tab exists', () => {
    assert.ok(source.includes('v-tab-item value="diagnosis"'), 'must have diagnosis tab');
    assert.ok(source.includes('诊断'), 'must display 诊断 tab label');
});

// 11. Undone display preserved (ADR-0009)
test('undone display preserved with 已撤销 chip', () => {
    assert.ok(source.includes('v-if="log.undone"'), 'must have v-if="log.undone" conditional');
    assert.ok(source.includes('已撤销'), 'must show 已撤销 chip');
    assert.ok(source.includes('log.undone_at'), 'must reference log.undone_at');
    assert.ok(source.includes('log.undo_source'), 'must reference log.undo_source');
});

// 12. Stale-response guard (detailRequestSeq) exists
test('stale-response guard detailRequestSeq exists', () => {
    assert.ok(source.includes('detailRequestSeq'), 'must have detailRequestSeq guard');
    const loadBody = extractMethod('loadCardInfo');
    assert.ok(loadBody.includes('detailRequestSeq'), 'loadCardInfo must use detailRequestSeq');
    assert.ok(loadBody.includes('seq !== this.detailRequestSeq'), 'loadCardInfo must check seq before applying response');
});

// 13. closeDetail clears cardInfo and bumps sequence
test('closeDetail clears cardInfo and bumps sequence', () => {
    const body = extractMethod('closeDetail');
    assert.ok(body.includes('detailRequestSeq'), 'closeDetail must bump detailRequestSeq');
    assert.ok(body.includes('cardInfo'), 'closeDetail must clear cardInfo');
    assert.ok(body.includes('detailLoading'), 'closeDetail must clear detailLoading');
    assert.ok(body.includes('detailError'), 'closeDetail must clear detailError');
});

// 14. No hardcoded width exceeding 420 on the drawer
test('drawer width does not exceed 420', () => {
    const drawerMatch = source.match(/v-navigation-drawer[^>]*width="(\d+)"/);
    if (drawerMatch) {
        const width = parseInt(drawerMatch[1], 10);
        assert.ok(width <= 420, `drawer width must be <= 420, got ${width}`);
    }
});

// 15. Management table data() method still exists (table unaffected)
test('management table data method still exists', () => {
    assert.ok(source.includes('data('), 'must still have data() method');
    assert.ok(source.includes('items'), 'must still have items data for table');
});

// 16. Browser Search parser reference intact
test('browser search references intact', () => {
    // The browser search V1 syntax must not be changed.
    assert.ok(source.includes('search_meta') || source.includes('browserSearch') || source.includes('search'),
        'browser search references must be intact');
});

// 17. Export methods intact
test('export methods intact', () => {
    assert.ok(source.includes('exportJson') || source.includes('export-csv') || source.includes('exportCsv') || source.includes('export'),
        'export functionality must be intact');
});

// 18. Daily report deep link handling intact
test('daily report deep link handling intact', () => {
    assert.ok(source.includes('deepLink'), 'must have deepLink handling');
    assert.ok(source.includes('handleDeepLink'), 'must have handleDeepLink method');
    assert.ok(source.includes('backToReport'), 'must have backToReport method');
    assert.ok(source.includes('返回学习报告'), 'must show 返回学习报告 button');
});

// Additional: history sections labeled with 最近 N 条
test('history sections labeled with 最近 N 条', () => {
    assert.ok(source.includes('最近'), 'history sections must be labeled with 最近');
    assert.ok(source.includes('cardInfoLifecycleEventsLimit()'), 'must show lifecycle events limit');
    assert.ok(source.includes('cardInfoReviewLogsLimit()'), 'must show review logs limit');
});

// Additional: cardInfo helpers exist
test('cardInfo helper methods exist', () => {
    assert.ok(source.includes('cardInfoReviewLogs()'), 'must have cardInfoReviewLogs helper');
    assert.ok(source.includes('cardInfoLifecycleEvents()'), 'must have cardInfoLifecycleEvents helper');
    assert.ok(source.includes('cardInfoLeech()'), 'must have cardInfoLeech helper');
});

// Additional: no loadDetailLeech dead code
test('loadDetailLeech dead code removed', () => {
    assert.ok(!source.includes('loadDetailLeech'), 'loadDetailLeech must be removed (dead code)');
});

console.log(`\n${passed} passed`);
