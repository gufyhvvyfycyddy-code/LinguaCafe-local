import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = join(__dirname, '..', '..');
const parentPath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardManage.vue');
const tablePath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardTableSurface.vue');
const surfacePath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardLifecycleMutationSurface.vue');

assert.ok(existsSync(parentPath), 'ReviewCardManage.vue must exist');
assert.ok(existsSync(tablePath), 'ReviewCardTableSurface.vue must exist');
assert.ok(existsSync(surfacePath), 'ReviewCardLifecycleMutationSurface.vue must exist');

const parent = readFileSync(parentPath, 'utf8');
const table = readFileSync(tablePath, 'utf8');
const surface = readFileSync(surfacePath, 'utf8');

const lifecycleDescriptorEndpoint = /\/review-cards\/['"]?\s*\+[^\n]+\/lifecycle/;
const lifecycleActionEndpoint = /\/lifecycle-actions/;
const bulkLifecycleEndpoint = /\/review-cards\/manage\/bulk-lifecycle/;

assert.match(surface, /name:\s*['"]ReviewCardLifecycleMutationSurface['"]/);
assert.match(parent, /<review-card-lifecycle-mutation-surface/);
assert.match(parent, /ref="lifecycleMutationSurface"/);
assert.match(parent, /@state-change="onLifecycleStateChange"/);
assert.match(parent, /@clear-selection="clearTableSelection"/);
assert.match(parent, /@refresh-list="loadData"/);
assert.match(parent, /@refresh-stats="loadFsrsStats"/);

assert.match(surface, lifecycleDescriptorEndpoint, 'lifecycle surface must own descriptor GET');
assert.match(surface, lifecycleActionEndpoint, 'lifecycle surface must own single lifecycle POST');
assert.match(surface, bulkLifecycleEndpoint, 'lifecycle surface must own bulk lifecycle POST');
assert.doesNotMatch(parent, lifecycleDescriptorEndpoint, 'parent must not own descriptor endpoint');
assert.doesNotMatch(parent, lifecycleActionEndpoint, 'parent must not own lifecycle-actions endpoint');
assert.doesNotMatch(parent, bulkLifecycleEndpoint, 'parent must not own bulk lifecycle endpoint');
assert.doesNotMatch(table, /axios\.(post|patch|delete)\s*\(/i, 'table must remain intent-only');

assert.equal((surface.match(/axios\.get\s*\(/g) || []).length, 1, 'surface must own exactly one descriptor GET');
assert.equal((surface.match(/axios\.post\s*\(/g) || []).length, 2, 'surface must own exactly two lifecycle POST requests');
assert.equal((surface.match(/<v-dialog/g) || []).length, 3, 'surface must own single, bulk and state-help dialogs');

for (const state of [
    'lifecycleMenuId',
    'lifecycleDescriptor',
    'lifecycleLoading',
    'lifecycleDialog',
    'lifecycleDialogContext',
    'lifecycleConflict',
    'bulkLifecycleLoading',
    'bulkLifecycleDialog',
    'bulkLifecycleAction',
    'stateHelpDialog',
]) {
    assert.match(surface, new RegExp(`\\b${state}\\b`), `surface must own ${state}`);
}

for (const legacyParentState of [
    'lifecycleMenuId:',
    'lifecycleDescriptor:',
    'lifecycleLoading:',
    'lifecycleDialog:',
    'lifecycleDialogAction:',
    'lifecycleDialogTarget:',
    'lifecycleConflict:',
    'bulkLifecycleLoading:',
    'bulkLifecycleDialog:',
    'bulkLifecycleAction:',
    'stateHelpDialog:',
]) {
    assert.doesNotMatch(parent, new RegExp(legacyParentState.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), `parent must not retain ${legacyParentState}`);
}

assert.match(surface, /descriptorRequestSeq/, 'descriptor requests need stale-response protection');
assert.match(surface, /expectedVersion/, 'confirmation context must freeze expected_version');
assert.match(surface, /request_id:/);
assert.match(surface, /expected_version:/);
assert.match(surface, /already_applied/);
assert.match(surface, /this\.lifecycleMenuId = null;[\s\S]*?this\.lifecycleDescriptor = null;[\s\S]*?this\.\$emit\(/, 'successful action must clear stale menu descriptor state');
assert.match(surface, /status === 409/);
assert.match(surface, /status === 422/);
assert.match(surface, /!err\.response/);
assert.match(surface, /LIFECYCLE_PRESENTATION/);
assert.match(surface, /LIFECYCLE_STATES/);
assert.match(surface, /actionDangerLevel/);
assert.match(surface, /actionLabel/);
assert.match(surface, /actionHint/);
assert.match(surface, /actionColor/);
assert.doesNotMatch(surface, /ReviewLog|fsrs_(state|due|stability|difficulty|reps|lapses)|WordSense/);
assert.doesNotMatch(surface, /bulk-delete|rewrite-package|due-now|\/reset/);
assert.doesNotMatch(surface, /Vuex|mapState|mapActions|eventBus|EventBus/);

assert.match(parent, /lifecycleSurfaceState/);
assert.match(parent, /onLifecycleMenuToggle\([^)]*\)[\s\S]*?lifecycleMutationSurface/);
assert.match(parent, /onLifecycleMenuClick\([^)]*\)[\s\S]*?lifecycleMutationSurface/);
assert.match(parent, /confirmBulkLifecycle\([^)]*\)[\s\S]*?lifecycleMutationSurface/);
assert.match(parent, /openLifecycleStateHelp\([^)]*\)[\s\S]*?lifecycleMutationSurface/);

console.log('ReviewCardLifecycleMutationSurface guard passed.');
