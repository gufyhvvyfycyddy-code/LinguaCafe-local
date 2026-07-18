import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = join(__dirname, '..', '..');
const parentPath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardManage.vue');
const surfacePath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardLifecycleMutationSurface.vue');
const drawerPath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardInfoDrawer.vue');
const searchPath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardSearchSurface.vue');
const leechPath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardLeechGovernanceMutationSurface.vue');

let passed = 0;
function test(name, fn) {
    try {
        fn();
        passed++;
    } catch (error) {
        console.error('FAIL: ' + name);
        console.error(error.message);
        process.exitCode = 1;
    }
}

for (const path of [parentPath, surfacePath, drawerPath, searchPath, leechPath]) {
    test(`File exists: ${path.split(/[\\/]/).pop()}`, () => assert.ok(existsSync(path)));
}

const parent = readFileSync(parentPath, 'utf8');
const surface = readFileSync(surfacePath, 'utf8');
const drawer = readFileSync(drawerPath, 'utf8');
const browser = `${parent}\n${readFileSync(searchPath, 'utf8')}`;
const leech = readFileSync(leechPath, 'utf8');

for (const state of ['active', 'buried', 'suspended', 'archived']) {
    test(`Has ${state} filter`, () => assert.ok(browser.includes(`value="${state}"`)));
}

test('Parent registers lifecycle mutation surface', () => {
    assert.ok(parent.includes('ReviewCardLifecycleMutationSurface'));
    assert.ok(parent.includes('<review-card-lifecycle-mutation-surface'));
});

test('Surface imports lifecycle presentation helpers', () => {
    assert.ok(surface.includes('ReviewCardLifecyclePresentation'));
    assert.ok(surface.includes('LIFECYCLE_PRESENTATION'));
    assert.ok(surface.includes('actionDangerLevel'));
});

test('Surface owns descriptor and request state', () => {
    for (const field of ['lifecycleMenuId', 'lifecycleDescriptor', 'lifecycleLoading', 'descriptorRequestSeq']) {
        assert.ok(surface.includes(field), `missing ${field}`);
    }
});

test('Surface owns confirmation and conflict state', () => {
    for (const field of ['lifecycleDialog', 'lifecycleDialogContext', 'lifecycleConflict']) {
        assert.ok(surface.includes(field), `missing ${field}`);
    }
});

test('Surface owns descriptor GET', () => {
    assert.match(surface, /axios\.get\('\/review-cards\/' \+ normalizedId \+ '\/lifecycle'\)/);
});

test('Surface owns lifecycle action POST', () => {
    assert.match(surface, /axios\.post\('\/review-cards\/' \+ reviewCardId \+ '\/lifecycle-actions'/);
});

test('Confirmation freezes expected version before menu cleanup', () => {
    assert.match(surface, /lifecycleDialogContext\s*=\s*\{[\s\S]*?expectedVersion/);
    assert.match(surface, /performLifecycleAction\(\)[\s\S]*?expectedVersion/);
});

test('Descriptor request has stale-response sequence protection', () => {
    assert.match(surface, /const seq = \+\+this\.descriptorRequestSeq/);
    assert.match(surface, /seq !== this\.descriptorRequestSeq/);
    assert.match(surface, /beforeDestroy\(\)[\s\S]*?descriptorRequestSeq\+\+/);
});

test('Surface handles 409 conflict', () => assert.ok(surface.includes('status === 409')));
test('Surface handles 422 contract error', () => assert.ok(surface.includes('status === 422')));
test('Surface handles network error', () => assert.ok(surface.includes('!err.response')));

test('State help belongs to lifecycle surface', () => {
    assert.ok(surface.includes('stateHelpDialog'));
    assert.ok(surface.includes('lifecycleStateHelpEntries'));
    assert.ok(surface.includes('复习卡生命周期状态说明'));
});

test('Parent exposes only lifecycle coordinator methods', () => {
    assert.ok(parent.includes('onLifecycleStateChange'));
    assert.ok(parent.includes('lifecycleMutationSurface?.handleMenuToggle'));
    assert.ok(parent.includes('lifecycleMutationSurface?.handleAction'));
    assert.ok(parent.includes('lifecycleMutationSurface?.openStateHelp'));
});

test('Parent has no lifecycle endpoint', () => {
    assert.ok(!parent.includes('/lifecycle-actions'));
    assert.ok(!parent.includes("'/lifecycle'"));
    assert.ok(!parent.includes('/bulk-lifecycle'));
});

test('Parent has no lifecycle dialog ownership', () => {
    assert.ok(!parent.includes('v-model="lifecycleDialog"'));
    assert.ok(!parent.includes('v-model="bulkLifecycleDialog"'));
    assert.ok(!parent.includes('v-model="stateHelpDialog"'));
});

test('Leech governance delegates lifecycle requests', () => {
    assert.ok(leech.includes('this.runLifecycleAction'));
    assert.ok(leech.includes('this.runBulkLifecycle'));
    assert.ok(parent.includes('runLeechLifecycleAction'));
    assert.ok(parent.includes('runLeechBulkLifecycle'));
    assert.ok(parent.includes('surface.runLifecycleAction'));
    assert.ok(parent.includes('surface.runBulkLifecycle'));
});

test('Detail drawer renders aggregate lifecycle events', () => {
    assert.ok(drawer.includes('lifecycle_events'));
    assert.ok(!drawer.includes('/lifecycle-events'));
});

test('No legacy bulk-enabled endpoint', () => assert.ok(!parent.includes('/bulk-enabled')));
test('No legacy bulk archive/restore dialogs', () => {
    assert.ok(!parent.includes('bulkArchiveDialog'));
    assert.ok(!parent.includes('bulkRestoreDialog'));
});

test('Surface does not replicate lifecycle state machine', () => {
    assert.ok(!surface.includes('ReviewCardLifecyclePolicy'));
    assert.ok(!surface.includes('buried_until ='));
    assert.ok(!surface.includes('fsrs_enabled ='));
});

console.log(`\n${passed} tests passed.`);
