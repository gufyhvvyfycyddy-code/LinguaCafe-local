import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = join(__dirname, '..', '..');
const parentPath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardManage.vue');
const tablePath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardTableSurface.vue');
const surfacePath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardDeleteMutationSurface.vue');

assert.ok(existsSync(parentPath), 'ReviewCardManage.vue must exist');
assert.ok(existsSync(tablePath), 'ReviewCardTableSurface.vue must exist');
assert.ok(existsSync(surfacePath), 'ReviewCardDeleteMutationSurface.vue must exist');

const parent = readFileSync(parentPath, 'utf8');
const table = readFileSync(tablePath, 'utf8');
const surface = readFileSync(surfacePath, 'utf8');

assert.match(surface, /name:\s*['"]ReviewCardDeleteMutationSurface['"]/);
assert.match(parent, /import ReviewCardDeleteMutationSurface from ['"]\.\/ReviewCardDeleteMutationSurface\.vue['"]/);
assert.match(parent, /<review-card-delete-mutation-surface/);
assert.match(parent, /ref="deleteMutationSurface"/);
assert.match(parent, /@clear-selection="clearTableSelection"/);
assert.match(parent, /@refresh-list="loadData"/);
assert.match(parent, /@refresh-stats="loadFsrsStats"/);
assert.match(parent, /@notify="showSnackbar"/);
assert.match(parent, /@error="onDeleteError"/);

assert.match(parent, /confirmDelete\(item\)[\s\S]*?deleteMutationSurface/);
assert.match(parent, /confirmBulkDelete\(selection\)[\s\S]*?deleteMutationSurface/);
assert.match(table, /emitRow\(['"]delete['"], item\)/);
assert.match(table, /emitBulk\(['"]bulk-delete['"]\)/);
assert.doesNotMatch(table, /axios\.(post|patch|delete)\s*\(/i, 'table remains intent-only');

assert.match(surface, /axios\.delete\('\/review-cards\/manage\/' \+ reviewCardId\)/);
assert.match(surface, /axios\.post\('\/review-cards\/manage\/bulk-delete'/);
assert.equal((surface.match(/<v-dialog/g) || []).length, 2, 'delete surface owns single and bulk confirmation dialogs');
assert.equal((surface.match(/axios\.delete\s*\(/g) || []).length, 1, 'delete surface owns exactly one DELETE request');
assert.equal((surface.match(/axios\.post\s*\(/g) || []).length, 1, 'delete surface owns exactly one bulk POST request');

for (const state of [
    'deleteDialog',
    'deleteTarget',
    'deleteLoading',
    'bulkDeleteDialog',
    'bulkDeleteLoading',
    'bulkSelectionIds',
    'bulkSelectionItems',
]) {
    assert.match(surface, new RegExp(`\\b${state}\\b`), `delete surface must own ${state}`);
}

assert.match(surface, /visibleBulkDeleteItems/);
assert.match(surface, /hiddenBulkDeleteCount/);
assert.match(surface, /复习历史会保留/);
assert.match(surface, /阅读来源记录会保留/);
assert.match(surface, /最后一个已确认词义/);
assert.match(surface, /不会按筛选条件全量删除/);
assert.match(surface, /if \(!this\.deleteTarget \|\| this\.deleteLoading\) return/);
assert.match(surface, /if \(this\.bulkDeleteLoading \|\| this\.bulkSelectionIds\.length === 0\) return/);
assert.match(surface, /this\.\$emit\(['"]clear-selection['"]\)/);
assert.match(surface, /this\.\$emit\(['"]refresh-list['"]\)/);
assert.match(surface, /this\.\$emit\(['"]refresh-stats['"]\)/);
assert.doesNotMatch(surface, /lifecycle-actions|bulk-lifecycle|due-now|\/reset|rewrite-package/);
assert.doesNotMatch(surface, /ReviewLog|fsrs_(state|due|stability|difficulty|reps|lapses)|WordSense/);
assert.doesNotMatch(surface, /Vuex|mapState|mapActions|eventBus|EventBus/);

assert.doesNotMatch(parent, /axios\.delete\('\/review-cards\/manage\/'/);
assert.doesNotMatch(parent, /axios\.post\('\/review-cards\/manage\/bulk-delete'/);
assert.doesNotMatch(parent, /deleteDialog:\s*/);
assert.doesNotMatch(parent, /deleteTarget:\s*/);
assert.doesNotMatch(parent, /bulkDeleteDialog:\s*/);
assert.doesNotMatch(parent, /visibleBulkDeleteItems\s*\(/);
assert.doesNotMatch(parent, /hiddenBulkDeleteCount\s*\(/);
assert.doesNotMatch(parent, /review-card-manage-delete-title/);
assert.doesNotMatch(parent, /review-card-manage-bulk-delete-title/);
assert.doesNotMatch(parent, /bulk-delete-list/);

console.log('ReviewCardDeleteMutationSurface guard passed.');
