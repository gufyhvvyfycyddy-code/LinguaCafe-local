// ReviewCardManageUndoneLogGuard.test.mjs
//
// ADR-0009: Review action transaction ledger and stack-based undo.
//
// Node built-in assert tests for the ReviewCardManage audit display.
// Guards that undone review logs are retained (not hidden) on the
// management page, with audit fields shown:
//   - Original rating is preserved (not changed to "undo")
//   - undone/undone_at/undo_source fields are displayed
//   - No undo button on the management page
//   - Log is not hidden when undone
//
// Tests:
//   1.  ReviewCardManage.vue shows "已撤销" chip for undone logs.
//   2.  ReviewCardManage.vue shows "撤销时间" for undone logs.
//   3.  ReviewCardManage.vue shows undo source for undone logs.
//   4.  ReviewCardManage.vue does NOT change rating to "undo".
//   5.  ReviewCardManage.vue does NOT hide undone logs (no v-if filtering).
//   6.  ReviewCardManage.vue does NOT provide undo button.
//   7.  ReviewCardManage.vue has log.undone conditional block.
//   8.  Backend controller includes undone/undone_at/undo_source in logs payload.

import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const MANAGE_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardManage.vue');
const CONTROLLER_PATH = join(__dirname, '..', '..', 'app', 'Http', 'Controllers', 'ReviewCardManageController.php');

let passed = 0;
function test(name, fn) {
    try {
        fn();
        passed++;
    } catch (e) {
        console.error('FAIL: ' + name);
        console.error(e.message);
        process.exitCode = 1;
    }
}

const source = existsSync(MANAGE_PATH) ? readFileSync(MANAGE_PATH, 'utf-8') : '';
const controllerSource = existsSync(CONTROLLER_PATH) ? readFileSync(CONTROLLER_PATH, 'utf-8') : '';

// 1. Shows "已撤销" chip for undone logs
test('shows 已撤销 chip for undone logs', () => {
    assert.ok(source.includes('已撤销'), 'must show 已撤销 chip for undone logs');
    assert.ok(source.includes('log.undone'), 'must reference log.undone');
});

// 2. Shows "撤销时间" for undone logs
test('shows 撤销时间 for undone logs', () => {
    assert.ok(source.includes('撤销时间'), 'must show 撤销时间 label');
    assert.ok(source.includes('log.undone_at'), 'must reference log.undone_at');
});

// 3. Shows undo source for undone logs
test('shows undo source for undone logs', () => {
    assert.ok(source.includes('log.undo_source'), 'must reference log.undo_source');
});

// 4. Does NOT change rating to "undo"
test('does NOT change rating to undo', () => {
    // The original rating chip should still use log.rating, not "undo"
    assert.ok(source.includes('logRatingColor(log.rating)'), 'must still use log.rating for color');
    assert.ok(source.includes('{{ log.rating }}'), 'must still display original log.rating');
});

// 5. Does NOT hide undone logs (no v-if filtering on undone)
test('does NOT hide undone logs', () => {
    // The log list should iterate over all logs without filtering by undone
    // Check that the v-for does not have a filter that excludes undone
    const logListSection = source.substring(source.indexOf('detailLogs'), source.indexOf('detailLogs') + 800);
    // The v-for should not have a .filter that excludes undone
    assert.ok(!logListSection.includes('.filter('), 'must not filter logs to exclude undone');
});

// 6. Does NOT provide undo button on management page
test('does NOT provide undo button on management page', () => {
    // The management page should not have an undo action button
    // It should not call requestUndo or any undo endpoint
    assert.ok(!source.includes('requestUndo'), 'must not have requestUndo method');
    assert.ok(!source.includes('/reviews/senses/review-actions/'), 'must not reference undo endpoint');
    assert.ok(!source.includes('/undo'), 'must not reference undo path');
});

// 7. Has log.undone conditional block
test('has log.undone conditional block', () => {
    // The undone display should be inside a v-if="log.undone" block
    assert.ok(source.includes('v-if="log.undone"'), 'must have v-if="log.undone" conditional');
});

// 8. Backend controller includes undone/undone_at/undo_source in logs payload
test('backend controller includes undone audit fields in logs payload', () => {
    assert.ok(controllerSource.includes("'undone'"), 'controller must include undone field');
    assert.ok(controllerSource.includes("'undone_at'"), 'controller must include undone_at field');
    assert.ok(controllerSource.includes("'undo_source'"), 'controller must include undo_source field');
});

console.log(`\n${passed} passed`);
