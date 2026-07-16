import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..');
const childPath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardInfoDrawer.vue');
const parentPath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardManage.vue');
const parent = readFileSync(parentPath, 'utf8');
const child = existsSync(childPath) ? readFileSync(childPath, 'utf8') : '';

let passed = 0;
function test(name, fn) {
    try {
        fn();
        passed++;
        console.log(`  PASS ${name}`);
    } catch (error) {
        console.error(`  FAIL ${name}: ${error.message}`);
        process.exitCode = 1;
    }
}

function count(source, pattern) {
    return (source.match(pattern) || []).length;
}

test('dedicated drawer component exists', () => assert.ok(existsSync(childPath)));
test('parent registers and renders drawer child', () => {
    assert.match(parent, /import ReviewCardInfoDrawer from ['"]\.\/ReviewCardInfoDrawer\.vue['"]/);
    assert.match(parent, /ReviewCardInfoDrawer/);
    assert.match(parent, /<review-card-info-drawer/);
});
test('child owns exactly one canonical detail request expression', () => {
    assert.equal(count(child, /axios\.get\(['"]\/review-cards\/manage\/['"] \+ reviewCardId \+ ['"]\/detail['"]\)/g), 1);
});
test('parent owns no detail request', () => {
    assert.doesNotMatch(parent, /axios\.get\(['"]\/review-cards\/manage\/['"] \+ [^)]+ \+ ['"]\/detail['"]\)/);
});
test('only child owns Card Info request state', () => {
    for (const field of ['detailTarget', 'cardInfo', 'detailLoading', 'detailError', 'detailRequestSeq', 'detailTab']) {
        assert.ok(child.includes(field), `child must own ${field}`);
        assert.ok(!new RegExp(`\\b${field}\\s*:`).test(parent), `parent must not declare ${field}`);
    }
});
test('child renders loading error empty and three tabs', () => {
    assert.match(child, /detailLoading/);
    assert.match(child, /detailError/);
    assert.match(child, /v-else/);
    for (const tab of ['overview', 'history', 'diagnosis']) assert.ok(child.includes(`value="${tab}"`));
});
test('child guards stale responses monotonically', () => {
    assert.match(child, /activeTargetKey\(\)/, 'one computed open/id key must own request triggering');
    assert.doesNotMatch(child, /\n\s*reviewCardId\(\)\s*\{/, 'reviewCardId must not have a second request watcher');
    assert.match(child, /const seq = \+\+this\.detailRequestSeq/);
    assert.match(child, /seq !== this\.detailRequestSeq/);
});
test('close invalidates requests and clears detail state', () => {
    assert.match(child, /this\.detailRequestSeq\+\+/);
    for (const assignment of ['this.detailTarget = null', 'this.cardInfo = null', "this.detailError = ''", 'this.detailLoading = false']) {
        assert.ok(child.includes(assignment), `close must contain ${assignment}`);
    }
});
test('deep-link parsing remains in parent', () => {
    assert.match(parent, /parseReviewCardManageLocation/);
    assert.match(parent, /handleDeepLink\(\)/);
    assert.doesNotMatch(child, /parseReviewCardManageLocation/);
});
test('source and report navigation use semantic events', () => {
    assert.match(child, /\$emit\(['"]open-source['"], this\.detailTarget\)/);
    assert.match(child, /\$emit\(['"]return-to-report['"]/);
    assert.match(parent, /@open-source="viewSource"/);
    assert.match(parent, /@return-to-report="backToReport"/);
});
test('child has no write endpoint or dangerous action button', () => {
    assert.doesNotMatch(child, /axios\.(post|put|patch|delete)/);
    assert.doesNotMatch(child, /\/(due-now|reset|bulk-delete|bulk-lifecycle|lifecycle-actions)/);
    assert.doesNotMatch(child, />\s*(删除|归档|恢复|暂停|埋藏|重置|立即到期)\s*</);
});

console.log(`\n${passed} passed`);
