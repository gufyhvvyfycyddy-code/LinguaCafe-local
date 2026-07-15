import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname.replace(/^\/(.:)/, '$1')), '../..');
const read = relativePath => fs.readFileSync(path.join(root, relativePath), 'utf8');

const container = read('resources/js/components/Admin/AdminReviewSettings.vue');
const panel = read('resources/js/components/Admin/ReviewSettings/FsrsQueueOrderSettingsPanel.vue');
const api = read('resources/js/services/AdminReviewSettingsApi.js');

assert.match(container, /FsrsQueueOrderSettingsPanel/);
assert.match(container, /<fsrs-queue-order-settings-panel/);
assert.match(panel, /复习显示顺序/);

for (const [key, defaultValue] of [
    ['interday_learning_review_order', 'mix'],
    ['new_review_order', 'mix'],
    ['review_sort_order', 'due_random'],
    ['new_sort_order', 'created_asc'],
]) {
    assert.match(panel, new RegExp(`${key}: '${defaultValue}'`), `${key} default must be ${defaultValue}`);
}

for (const value of ['mix', 'before', 'after', 'due_random', 'due_stable', 'ascending_retrievability', 'random', 'created_asc', 'created_desc']) {
    assert.match(panel, new RegExp(`value: '${value}'`), `missing queue option ${value}`);
}

assert.match(panel, /mounted\(\)[\s\S]*this\.loadFsrsQueueOrder\(\)/);
assert.match(panel, /AdminReviewSettingsApi\.getQueueOrder\(\)/);
assert.match(panel, /AdminReviewSettingsApi\.updateQueueOrder\(\{ \.\.\.this\.queueOrder \}\)/);
assert.match(api, /export function getQueueOrder\(\)[\s\S]*axios\.get\('\/settings\/fsrs\/queue-order'\)/);
assert.match(api, /export function updateQueueOrder\(payload\)[\s\S]*axios\.post\('\/settings\/fsrs\/queue-order', payload\)/);
assert.match(panel, /@click="saveFsrsQueueOrder"/);
assert.match(panel, /saveError/);
assert.match(panel, /saveStatus/);
assert.doesNotMatch(panel, /Math\.random|shuffle/);
assert.doesNotMatch(container, /queueOrder|axios\./, 'container must not own queue state or HTTP calls');

console.log('Admin review settings queue order guard passed.');
