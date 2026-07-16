import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = join(__dirname, '..', '..');
const parentPath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardManage.vue');
const surfacePath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardSchedulingMutationSurface.vue');

assert.ok(existsSync(surfacePath), 'ReviewCardSchedulingMutationSurface.vue must exist');

const parent = readFileSync(parentPath, 'utf8');
const surface = readFileSync(surfacePath, 'utf8');

assert.match(parent, /<review-card-scheduling-mutation-surface/);
assert.match(parent, /ref="schedulingMutationSurface"/);
assert.match(parent, /@due-now="confirmDueNow"/);
assert.match(parent, /@reset="confirmReset"/);
assert.match(parent, /this\.\$refs\.schedulingMutationSurface\.confirmDueNow\(item\)/);
assert.match(parent, /this\.\$refs\.schedulingMutationSurface\.confirmReset\(item\)/);
assert.match(parent, /@card-updated="onSchedulingCardUpdated"/);
assert.match(parent, /@refresh-list="loadData"/);
assert.match(parent, /@refresh-stats="loadFsrsStats"/);
assert.match(parent, /@notify="onSchedulingNotify"/);
assert.match(parent, /@error="onSchedulingError"/);

assert.doesNotMatch(parent, /\/review-cards\/manage\/['"]?\s*\+[^\n]*\/due-now/);
assert.doesNotMatch(parent, /\/review-cards\/manage\/['"]?\s*\+[^\n]*\/reset/);
assert.doesNotMatch(parent, /dueNowDialog:\s*/);
assert.doesNotMatch(parent, /dueNowTarget:\s*/);
assert.doesNotMatch(parent, /resetDialog:\s*/);
assert.doesNotMatch(parent, /resetTarget:\s*/);
assert.doesNotMatch(parent, /resetLoading:\s*/);
assert.doesNotMatch(parent, /setDueNow\s*\(/);
assert.doesNotMatch(parent, /doDueNow\s*\(/);
assert.doesNotMatch(parent, /doReset\s*\(/);
assert.doesNotMatch(parent, /review-card-manage-due-now-title/);
assert.doesNotMatch(parent, /review-card-manage-reset-title/);

assert.match(surface, /name:\s*['"]ReviewCardSchedulingMutationSurface['"]/);
assert.match(surface, /confirmDueNow\(item\)/);
assert.match(surface, /dueNowLoading:\s*false/);
assert.match(surface, /if \(!this\.dueNowTarget \|\| this\.dueNowLoading\) return/);
assert.match(surface, /confirmReset\(item\)/);
assert.match(surface, /doDueNow\(\)/);
assert.match(surface, /doReset\(\)/);
assert.match(surface, /if \(!this\.resetTarget \|\| this\.resetLoading\) return/);
assert.match(surface, /review-card-manage-due-now-title/);
assert.match(surface, /review-card-manage-reset-title/);
assert.match(surface, /不是一次复习评分/);
assert.match(surface, /不会写入复习历史/);
assert.match(surface, /旧复习历史会保留/);
assert.match(surface, /['"]\/review-cards\/manage\/['"]\s*\+\s*item\.review_card_id\s*\+\s*['"]\/due-now['"]/);
assert.match(surface, /['"]\/review-cards\/manage\/['"]\s*\+\s*item\.review_card_id\s*\+\s*['"]\/reset['"]/);

const axiosCount = (surface.match(/axios\.post\s*\(/g) || []).length;
assert.equal(axiosCount, 2, 'scheduling surface must own exactly due-now and reset POST requests');
assert.doesNotMatch(surface, /lifecycle-actions|bulk-lifecycle|bulk-delete|bulk-leech|rewrite-package/);
assert.doesNotMatch(surface, /axios\.(get|patch|put|delete)\s*\(/i);

console.log('ReviewCardSchedulingMutationSurface guard passed.');
