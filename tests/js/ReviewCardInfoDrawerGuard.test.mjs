import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const childPath = join(root, 'resources/js/components/ReviewCards/ReviewCardInfoDrawer.vue');
const parentPath = join(root, 'resources/js/components/ReviewCards/ReviewCardManage.vue');
assert.ok(existsSync(childPath));
assert.ok(existsSync(parentPath));

const child = readFileSync(childPath, 'utf8');
const parent = readFileSync(parentPath, 'utf8');
const count = (source, pattern) => (source.match(pattern) || []).length;

assert.match(parent, /import ReviewCardInfoDrawer from ['"]\.\/ReviewCardInfoDrawer\.vue['"]/);
assert.match(parent, /<review-card-info-drawer/);
assert.equal(count(child, /axios\.get\(['"]\/review-cards\/manage\/['"] \+ reviewCardId \+ ['"]\/detail['"]\)/g), 1,
    'drawer owns exactly one canonical detail request');
assert.doesNotMatch(parent, /\/review-cards\/manage\/[^\n]+\/detail/,
    'parent must not duplicate the detail request');

for (const field of ['detailTarget', 'cardInfo', 'detailLoading', 'detailError', 'detailRequestSeq', 'detailTab']) {
    assert.ok(child.includes(field), `drawer owns ${field}`);
    assert.ok(!new RegExp(`\\b${field}\\s*:`).test(parent), `parent must not own ${field}`);
}
for (const tab of ['overview', 'history', 'diagnosis']) assert.ok(child.includes(`value="${tab}"`));
assert.match(child, /activeTargetKey\(\)/);
assert.match(child, /const seq = \+\+this\.detailRequestSeq/);
assert.match(child, /seq !== this\.detailRequestSeq/);
assert.match(child, /this\.detailRequestSeq\+\+/);
assert.match(child, /ReviewCardMarkerPicker/);

// Card Info is read-mostly. Its only direct write is an explicit user-triggered
// undo/redo transition for an already-created manual operation.
assert.equal(count(child, /axios\.post\s*\(/g), 1, 'drawer owns one explicit manual-operation transition POST');
assert.match(child, /transitionManualOperation\(operation, direction\)/);
assert.match(child, /\/review-card-operations\/['"]? \+ operation\.operation_id \+ ['"]\/['"]? \+ direction/);
assert.match(child, /client_action_id:/);
assert.match(child, /expected_version:/);
assert.match(child, /operation\.can_undo/);
assert.match(child, /operation\.can_redo/);
assert.match(child, /status === 409/);

// No rating, direct FSRS, delete, lifecycle, bulk, or scheduling write is owned here.
assert.doesNotMatch(child, /\/rate\b|reviews\/senses\/[^\n]+\/rate/);
assert.doesNotMatch(child, /lifecycle-actions|bulk-lifecycle|bulk-delete|due-now|manual-operations\/(preview|apply)|\/reset/);
assert.doesNotMatch(child, /axios\.(put|patch|delete)\s*\(/i);
assert.doesNotMatch(child, /fsrs_(state|due|stability|difficulty|reps|lapses)\s*=/i);

assert.match(child, /\$emit\(['"]open-source['"], this\.detailTarget\)/);
assert.match(child, /\$emit\(['"]return-to-report['"]/);
assert.match(parent, /@open-source="viewSource"/);
assert.match(parent, /@return-to-report="backToReport"/);
assert.match(parent, /parseReviewCardManageLocation/);
assert.doesNotMatch(child, /parseReviewCardManageLocation/);

console.log('ReviewCardInfoDrawer guard passed.');
