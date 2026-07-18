import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = join(__dirname, '..', '..');
const parentPath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardManage.vue');
const tablePath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardTableSurface.vue');
const lifecyclePath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardLifecycleMutationSurface.vue');
const surfacePath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardLeechGovernanceMutationSurface.vue');

assert.ok(existsSync(parentPath), 'ReviewCardManage.vue must exist');
assert.ok(existsSync(tablePath), 'ReviewCardTableSurface.vue must exist');
assert.ok(existsSync(lifecyclePath), 'ReviewCardLifecycleMutationSurface.vue must exist');
assert.ok(existsSync(surfacePath), 'ReviewCardLeechGovernanceMutationSurface.vue must exist');

const parent = readFileSync(parentPath, 'utf8');
const table = readFileSync(tablePath, 'utf8');
const lifecycle = readFileSync(lifecyclePath, 'utf8');
const surface = readFileSync(surfacePath, 'utf8');

assert.match(surface, /name:\s*['"]ReviewCardLeechGovernanceMutationSurface['"]/);
assert.match(parent, /import ReviewCardLeechGovernanceMutationSurface from ['"]\.\/ReviewCardLeechGovernanceMutationSurface\.vue['"]/);
assert.match(parent, /<review-card-leech-governance-mutation-surface/);
assert.match(parent, /ref="leechGovernanceMutationSurface"/);
assert.match(parent, /:run-lifecycle-action="runLeechLifecycleAction"/);
assert.match(parent, /:run-bulk-lifecycle="runLeechBulkLifecycle"/);
assert.match(parent, /@state-change="onLeechGovernanceStateChange"/);
assert.match(parent, /@clear-selection="clearTableSelection"/);
assert.match(parent, /@refresh-list="loadData"/);
assert.match(parent, /@refresh-stats="loadFsrsStats"/);
assert.match(parent, /@notify="showSnackbar"/);
assert.match(parent, /@error="onLeechGovernanceError"/);

for (const coordinator of [
    'openRewritePackageDialog',
    'suspendLeechCard',
    'openBulkRewritePackages',
    'confirmBulkLeechSuspend',
]) {
    assert.match(parent, new RegExp(`${coordinator}\\([^)]*\\)[\\s\\S]*?leechGovernanceMutationSurface`));
}
assert.match(parent, /runLeechLifecycleAction\(options\)[\s\S]*?lifecycleMutationSurface/);
assert.match(parent, /runLeechBulkLifecycle\(options\)[\s\S]*?lifecycleMutationSurface/);
assert.match(parent, /leechGovernanceSurfaceState/);
assert.match(parent, /bulk-rewrite-loading="leechGovernanceSurfaceState\.bulkRewriteLoading"/);
assert.match(parent, /bulk-leech-suspend-loading="leechGovernanceSurfaceState\.bulkLeechSuspendLoading"/);

assert.match(surface, /axios\.get\('\/review-cards\/manage\/leech-summary'\)/);
assert.match(surface, /axios\.post\('\/review-cards\/manage\/bulk-leech-rewrite-packages'/);
assert.equal((surface.match(/axios\./g) || []).length, 2, 'leech surface owns exactly summary GET and bulk rewrite POST');
assert.equal((surface.match(/<v-dialog/g) || []).length, 2, 'leech surface owns bulk rewrite and bulk suspend dialogs');
assert.match(surface, /SenseReviewLeechRewritePackageDialog/);
assert.match(surface, /runLifecycleAction:\s*\{\s*type:\s*Function,\s*required:\s*true\s*\}/);
assert.match(surface, /runBulkLifecycle:\s*\{\s*type:\s*Function,\s*required:\s*true\s*\}/);
assert.match(surface, /this\.runLifecycleAction\(/);
assert.match(surface, /this\.runBulkLifecycle\(/);
assert.doesNotMatch(surface, /\/lifecycle-actions|\/review-cards\/manage\/bulk-lifecycle/);
assert.match(lifecycle, /runLifecycleAction\(options\)/);
assert.match(lifecycle, /runBulkLifecycle\(options\)/);

for (const state of [
    'leechSummary',
    'leechSummaryLoaded',
    'rewritePackageDialog',
    'rewritePackageCardId',
    'bulkRewriteDialog',
    'bulkRewriteLoading',
    'bulkRewritePackages',
    'bulkRewriteFailed',
    'bulkLeechSuspendDialog',
    'bulkLeechSuspendLoading',
    'bulkSelectionIds',
]) {
    assert.match(surface, new RegExp(`\\b${state}\\b`), `leech surface must own ${state}`);
}

assert.match(surface, /provider_called/);
assert.match(surface, /card_created/);
assert.match(surface, /review_log_created/);
assert.match(surface, /不调用 AI/);
assert.match(surface, /不创建学习卡/);
assert.match(surface, /不写复习记录/);
assert.match(surface, /bulkRewriteFailed/);
assert.match(surface, /navigator\.clipboard/);
assert.match(surface, /source:\s*['"]sense_review_leech['"]/);
assert.match(surface, /source:\s*['"]manage_bulk_leech_suspend['"]/);
assert.match(surface, /this\.\$emit\(['"]state-change['"]/);
assert.match(surface, /this\.\$emit\(['"]clear-selection['"]\)/);
assert.match(surface, /this\.\$emit\(['"]refresh-list['"]\)/);
assert.match(surface, /this\.\$emit\(['"]refresh-stats['"]\)/);

assert.doesNotMatch(surface, /provider-preview|createReviewLog|createWordSense|createReviewCard|FsrsScheduling|fsrs_schedule/);
assert.doesNotMatch(surface, /Vuex|mapState|mapActions|eventBus|EventBus/);
assert.doesNotMatch(table, /axios\.(post|patch|delete)\s*\(/i, 'table remains intent-only');

assert.doesNotMatch(parent, /\/review-cards\/manage\/leech-summary|\/review-cards\/manage\/bulk-leech-rewrite-packages/);
assert.doesNotMatch(parent, /SenseReviewLeechRewritePackageDialog|SenseReviewLeechPresentation/);
assert.doesNotMatch(parent, /v-model="bulkRewriteDialog"|v-model="bulkLeechSuspendDialog"/);
assert.doesNotMatch(parent, /bulkRewritePackages:\s*|bulkRewriteFailed:\s*|bulkLeechSuspendDialog:\s*|bulkSelectionIds:\s*/);
assert.doesNotMatch(parent, /bulk-rewrite-pre/);

console.log('ReviewCardLeechGovernanceMutationSurface guard passed.');
