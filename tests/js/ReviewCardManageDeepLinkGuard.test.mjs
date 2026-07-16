// ReviewCardManageDeepLinkGuard.test.mjs
//
// ADR-0007 — SenseReview Report Card Deep Link
//
// Node built-in assert tests for the ReviewCardManageDeepLink pure functions.
// No third-party test framework required — runs with `node <file>`.
//
// These tests guard:
//   1. buildReviewCardManageLocation produces correct path + query.
//   2. parseReviewCardManageLocation parses valid query correctly.
//   3. Invalid / zero / negative / string-garbage review_card_id → null.
//   4. Source whitelist enforced (invalid source → null).
//   5. word_sense_id is NOT required (diagnostic only).
//   6. Functions do not call axios / Vue / DOM (pure).
//   7. File does not import axios or Vue.

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const HELPER_PATH = join(__dirname, '..', '..', 'resources', 'js', 'services', 'ReviewCardManageDeepLink.js');

// Dynamic import of the ES module helper
const {
    buildReviewCardManageLocation,
    parseReviewCardManageLocation,
    DEEP_LINK_SOURCES,
} = await import('file://' + HELPER_PATH.replace(/\\/g, '/'));

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

console.log('ReviewCardManageDeepLink guard tests\n');

// --- 1. buildReviewCardManageLocation: correct output ---

test('build: valid target + source → correct path + query', () => {
    const loc = buildReviewCardManageLocation(
        { review_card_id: 123, word_sense_id: 45 },
        'daily-report'
    );
    assert.deepEqual(loc, {
        path: '/review-cards/manage',
        query: { review_card_id: 123, from: 'daily-report' },
    });
});

test('build: path is always /review-cards/manage', () => {
    const loc = buildReviewCardManageLocation(
        { review_card_id: 1 },
        'seven-day-trend'
    );
    assert.strictEqual(loc.path, '/review-cards/manage');
});

// --- 2. parseReviewCardManageLocation: valid query ---

test('parse: valid query → { review_card_id, from }', () => {
    const result = parseReviewCardManageLocation({
        review_card_id: '123',
        from: 'daily-report',
    });
    assert.deepEqual(result, { review_card_id: 123, from: 'daily-report' });
});

test('parse: round-trip build → parse', () => {
    const loc = buildReviewCardManageLocation(
        { review_card_id: 99 },
        'thirty-day-calendar'
    );
    const parsed = parseReviewCardManageLocation(loc.query);
    assert.deepEqual(parsed, { review_card_id: 99, from: 'thirty-day-calendar' });
});

// --- 3. Invalid / zero / negative / string-garbage review_card_id ---

test('build: review_card_id = 0 → null', () => {
    assert.strictEqual(buildReviewCardManageLocation({ review_card_id: 0 }, 'daily-report'), null);
});

test('build: review_card_id = -1 → null', () => {
    assert.strictEqual(buildReviewCardManageLocation({ review_card_id: -1 }, 'daily-report'), null);
});

test('build: review_card_id = "abc" (string) → null', () => {
    assert.strictEqual(buildReviewCardManageLocation({ review_card_id: 'abc' }, 'daily-report'), null);
});

test('build: review_card_id = undefined → null', () => {
    assert.strictEqual(buildReviewCardManageLocation({}, 'daily-report'), null);
});

test('build: review_card_id = 1.5 (float) → null', () => {
    assert.strictEqual(buildReviewCardManageLocation({ review_card_id: 1.5 }, 'daily-report'), null);
});

test('parse: review_card_id = "0" → null', () => {
    assert.strictEqual(parseReviewCardManageLocation({ review_card_id: '0', from: 'daily-report' }), null);
});

test('parse: review_card_id = "-5" → null', () => {
    assert.strictEqual(parseReviewCardManageLocation({ review_card_id: '-5', from: 'daily-report' }), null);
});

test('parse: review_card_id = "abc" → null', () => {
    assert.strictEqual(parseReviewCardManageLocation({ review_card_id: 'abc', from: 'daily-report' }), null);
});

test('parse: review_card_id missing → null', () => {
    assert.strictEqual(parseReviewCardManageLocation({ from: 'daily-report' }), null);
});

test('parse: query = null → null', () => {
    assert.strictEqual(parseReviewCardManageLocation(null), null);
});

test('parse: query = undefined → null', () => {
    assert.strictEqual(parseReviewCardManageLocation(undefined), null);
});

// --- 4. Source whitelist ---

test('build: invalid source → null', () => {
    assert.strictEqual(buildReviewCardManageLocation({ review_card_id: 1 }, 'invalid-source'), null);
});

test('build: empty source → null', () => {
    assert.strictEqual(buildReviewCardManageLocation({ review_card_id: 1 }, ''), null);
});

test('parse: invalid from → null', () => {
    assert.strictEqual(parseReviewCardManageLocation({ review_card_id: '1', from: 'invalid' }), null);
});

test('parse: missing from → null', () => {
    assert.strictEqual(parseReviewCardManageLocation({ review_card_id: '1' }), null);
});

test('DEEP_LINK_SOURCES contains exactly 3 sources', () => {
    assert.strictEqual(DEEP_LINK_SOURCES.length, 3);
    assert.ok(DEEP_LINK_SOURCES.includes('daily-report'));
    assert.ok(DEEP_LINK_SOURCES.includes('seven-day-trend'));
    assert.ok(DEEP_LINK_SOURCES.includes('thirty-day-calendar'));
});

// --- 5. word_sense_id is NOT required ---

test('build: works without word_sense_id (diagnostic only)', () => {
    const loc = buildReviewCardManageLocation({ review_card_id: 5 }, 'daily-report');
    assert.ok(loc !== null);
    assert.strictEqual(loc.query.review_card_id, 5);
    assert.strictEqual(loc.query.word_sense_id, undefined);
});

// --- 6. Functions are pure (no axios / Vue / DOM) ---

test('helper file does not import axios', () => {
    const content = readFileSync(HELPER_PATH, 'utf-8');
    const importLines = content.split('\n').filter(l => l.trim().startsWith('import'));
    assert.ok(!importLines.some(l => l.includes('axios')), 'DeepLink helper must not import axios');
});

test('helper file does not import Vue', () => {
    const content = readFileSync(HELPER_PATH, 'utf-8');
    const importLines = content.split('\n').filter(l => l.trim().startsWith('import'));
    assert.ok(!importLines.some(l => l.toLowerCase().includes('vue')), 'DeepLink helper must not import Vue');
});

test('helper file does not reference document/window', () => {
    const content = readFileSync(HELPER_PATH, 'utf-8');
    assert.ok(!content.includes('document'), 'DeepLink helper must not access DOM');
    assert.ok(!content.includes('window'), 'DeepLink helper must not access window');
});

// --- 7. ADR-0007 / Task A-3: ReviewCardManage.vue recognizes route query ---

const MANAGE_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardManage.vue');
const DRAWER_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardInfoDrawer.vue');

test('ReviewCardManage.vue imports parseReviewCardManageLocation', () => {
    const src = readFileSync(MANAGE_PATH, 'utf-8');
    assert.ok(/import.*parseReviewCardManageLocation.*ReviewCardManageDeepLink/.test(src), 'ReviewCardManage must import parseReviewCardManageLocation from DeepLink helper');
});

test('ReviewCardManage.vue calls handleDeepLink on mount', () => {
    const src = readFileSync(MANAGE_PATH, 'utf-8');
    assert.ok(/handleDeepLink/.test(src), 'ReviewCardManage must have handleDeepLink method');
    assert.ok(/mounted\(\)/.test(src), 'ReviewCardManage must have mounted hook');
    assert.ok(/this\.handleDeepLink\(\)/.test(src), 'ReviewCardManage must call handleDeepLink in mounted');
});

test('deep link hands the exact ID to the canonical drawer request', () => {
    const src = readFileSync(MANAGE_PATH, 'utf-8');
    const drawer = readFileSync(DRAWER_PATH, 'utf-8');
    assert.ok(/detailReviewCardId\s*=\s*reviewCardId/.test(src), 'parent must hand exact reviewCardId to drawer');
    assert.ok(/\/review-cards\/manage\/.*\/detail/.test(drawer), 'drawer must call /review-cards/manage/{id}/detail endpoint');
    const methodMatch = src.match(/loadDeepLinkDetail\(reviewCardId\)\s*\{([\s\S]*?)\n\s*\},/);
    if (methodMatch) {
        assert.ok(!/searchQuery\s*=/.test(methodMatch[1]), 'loadDeepLinkDetail must not set searchQuery (no lemma fallback)');
        assert.ok(!/axios\./.test(methodMatch[1]), 'parent must not duplicate the drawer request');
    }
});

test('ReviewCardManage.vue does not auto-open first card on invalid ID', () => {
    const src = readFileSync(MANAGE_PATH, 'utf-8');
    // Extract the loadDeepLinkDetail method block (from method definition to its closing })
    const methodMatch = src.match(/loadDeepLinkDetail\(reviewCardId\)\s*\{([\s\S]*?)\n\s*\},/);
    assert.ok(methodMatch, 'loadDeepLinkDetail method must exist');
    const methodBody = methodMatch[1];
    // Must NOT fall back to opening the first card in the list
    assert.ok(!/this\.items\[0\]/.test(methodBody), 'catch block must NOT open first card in list');
    assert.ok(!/openDetail\(this\.items/.test(methodBody), 'catch block must NOT call openDetail with list item');
    const drawer = readFileSync(DRAWER_PATH, 'utf-8');
    assert.ok(drawer.includes('加载卡片详情失败'), 'drawer must show a safe detail error');
    assert.ok(src.includes('@detail-load-error="onDetailLoadError"'), 'parent must receive canonical request failures');
    assert.ok(src.includes('未找到可管理的词义复习卡'), 'parent must preserve the safe deep-link error message');
    assert.match(src, /onDetailLoadError\(reviewCardId\)[\s\S]*?deepLink\.active\s*=\s*false[\s\S]*?detailDrawer\s*=\s*false/, 'failure must keep deep link inactive and close the failed drawer');
});

test('deep link loading and active state follow the canonical child request', () => {
    const src = readFileSync(MANAGE_PATH, 'utf-8');
    const drawer = readFileSync(DRAWER_PATH, 'utf-8');
    assert.match(src, /loadDeepLinkDetail\(reviewCardId\)[\s\S]*?deepLink\.loading\s*=\s*true[\s\S]*?deepLink\.active\s*=\s*false/, 'deep link must remain loading and inactive before success');
    assert.ok(src.includes('@detail-loaded="onDetailLoaded"'), 'parent must receive canonical request success');
    assert.match(src, /onDetailLoaded\(reviewCardId\)[\s\S]*?deepLink\.loading\s*=\s*false[\s\S]*?deepLink\.active\s*=\s*true/, 'success must activate the deep link after loading');
    assert.ok(drawer.includes("this.$emit('detail-loaded', reviewCardId)"), 'child must emit success for the requested ID');
    assert.ok(drawer.includes("this.$emit('detail-load-error', reviewCardId)"), 'child must emit failure for the requested ID');
    assert.match(src, /onDetailClosed\(\)[\s\S]*?deepLink\.loading\s*=\s*false[\s\S]*?detailDrawer\s*=\s*false/, 'closing a pending deep link must clear the page-level loading state');
});

test('ReviewCardManage.vue has back-to-report button', () => {
    const src = readFileSync(MANAGE_PATH, 'utf-8');
    assert.ok(/backToReport/.test(src), 'ReviewCardManage must have backToReport method');
    assert.ok(/window\.history\.back/.test(src), 'backToReport must use window.history.back()');
    assert.ok(/\/reviews\/senses/.test(src), 'backToReport must fall back to /reviews/senses');
});

test('ReviewCardManage.vue shows deep link hint when opened from report', () => {
    const src = readFileSync(MANAGE_PATH, 'utf-8');
    const drawer = readFileSync(DRAWER_PATH, 'utf-8');
    assert.ok(/从学习报告打开/.test(drawer), 'drawer must show hint when opened from report');
    assert.ok(/deepLink\.active/.test(src), 'ReviewCardManage must track deepLink.active state');
});

test('ReviewCardManage.vue does not write ReviewLog or modify FSRS during deep link', () => {
    const src = readFileSync(MANAGE_PATH, 'utf-8');
    const drawer = readFileSync(DRAWER_PATH, 'utf-8');
    // Extract the loadDeepLinkDetail method block
    const methodMatch = src.match(/loadDeepLinkDetail\(reviewCardId\)\s*\{([\s\S]*?)\n\s*\},/);
    assert.ok(methodMatch, 'loadDeepLinkDetail method must exist');
    const methodBody = methodMatch[1];
    assert.ok(!/axios\./.test(methodBody), 'parent deep-link handoff must not make an HTTP request');
    assert.ok(/axios\.get/.test(drawer), 'drawer must use the canonical read request');
    assert.ok(!/axios\.(post|put|delete|patch)/.test(drawer), 'drawer must NOT use write APIs');
});

test('ReviewCardManage.vue preserves review_card_id in URL for refresh', () => {
    const src = readFileSync(MANAGE_PATH, 'utf-8');
    // handleDeepLink reads from this.$route.query, which survives refresh
    assert.ok(/\$route\.query/.test(src) || /\$route.*query/.test(src), 'ReviewCardManage must read route query for deep link');
});

console.log(`\n${passed} passed`);
