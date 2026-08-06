import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const parentPath = join(root, 'resources/js/components/ReviewCards/ReviewCardManage.vue');
const surfacePath = join(root, 'resources/js/components/ReviewCards/ReviewCardSchedulingMutationSurface.vue');
assert.ok(existsSync(surfacePath));
const parent = readFileSync(parentPath, 'utf8');
const surface = readFileSync(surfacePath, 'utf8');

assert.match(parent, /<review-card-scheduling-mutation-surface/);
assert.match(parent, /ref="schedulingMutationSurface"/);
for (const delegation of [
    /@due-now="confirmDueNow"/,
    /@set-due="confirmSetDue"/,
    /@reset="confirmReset"/,
    /schedulingMutationSurface\.confirmDueNow\(item\)/,
    /schedulingMutationSurface\.confirmSetDue\(item\)/,
    /schedulingMutationSurface\.confirmReset\(item\)/,
]) assert.match(parent, delegation);

assert.doesNotMatch(parent, /manual-operations\/(preview|apply)|\/due-now|\/reset/,
    'parent must not own scheduling writes');
assert.doesNotMatch(parent, /dueNowDialog:|resetDialog:|previewRequestSeq:/);

assert.match(surface, /name:\s*['"]ReviewCardSchedulingMutationSurface['"]/);
for (const action of ['due_now', 'set_due', 'reset_new']) assert.ok(surface.includes(action));
for (const state of ['dialog', 'target', 'action', 'dueDate', 'resetCounts', 'preview', 'previewLoading', 'previewError', 'submitting', 'previewRequestSeq']) {
    assert.match(surface, new RegExp(`\\b${state}\\b`), `surface owns ${state}`);
}
assert.match(surface, /manual-operations\/preview/);
assert.match(surface, /manual-operations\/apply/);
assert.equal((surface.match(/axios\.post\s*\(/g) || []).length, 2,
    'surface owns exactly preview and apply POSTs');
assert.doesNotMatch(surface, /axios\.(get|put|patch|delete)\s*\(/i);
assert.doesNotMatch(surface, /\/review-cards\/manage\/[^\n]+\/(due-now|reset)/,
    'legacy direct mutation endpoints must stay removed');

assert.match(surface, /const seq = \+\+this\.previewRequestSeq/);
assert.match(surface, /seq !== this\.previewRequestSeq/);
assert.match(surface, /expected_state_fingerprint:\s*this\.preview\.expected_state_fingerprint/);
assert.match(surface, /operation_id:\s*operationId/);
assert.match(surface, /status === 409/);
assert.match(surface, /this\.loadPreview\(\)/);
assert.match(surface, /这不是复习评分/);
assert.match(surface, /不会写入复习历史/);
assert.match(surface, /旧复习历史会保留/);
assert.match(surface, /可在卡片详情中撤销/);
assert.doesNotMatch(surface, /lifecycle-actions|bulk-lifecycle|bulk-delete|bulk-leech|rewrite-package/);

console.log('ReviewCardSchedulingMutationSurface guard passed.');
