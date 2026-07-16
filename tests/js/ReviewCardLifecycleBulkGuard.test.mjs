import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = join(__dirname, '..', '..');
const parentPath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardManage.vue');
const tablePath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardTableSurface.vue');
const surfacePath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardLifecycleMutationSurface.vue');

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

for (const path of [parentPath, tablePath, surfacePath]) {
    test(`File exists: ${path.split(/[\\/]/).pop()}`, () => assert.ok(existsSync(path)));
}

const parent = readFileSync(parentPath, 'utf8');
const table = readFileSync(tablePath, 'utf8');
const surface = readFileSync(surfacePath, 'utf8');

for (const method of ['confirmBulk', 'requestBulkLifecycle', 'runBulkLifecycle', 'performBulkLifecycleAction']) {
    test(`Surface has ${method}`, () => assert.ok(surface.includes(`${method}(`)));
}

for (const field of ['bulkLifecycleDialog', 'bulkLifecycleLoading', 'bulkLifecycleAction', 'bulkSelectionIds']) {
    test(`Surface owns ${field}`, () => assert.ok(surface.includes(field)));
}

test('Surface owns bulk-lifecycle endpoint', () => {
    assert.match(surface, /axios\.post\('\/review-cards\/manage\/bulk-lifecycle'/);
    assert.ok(!parent.includes('/review-cards/manage/bulk-lifecycle'));
});

test('Bulk payload contains ids action and source', () => {
    assert.match(surface, /requestBulkLifecycle\(\{ ids, action, source/);
    assert.match(surface, /axios\.post\('\/review-cards\/manage\/bulk-lifecycle',[\s\S]*?ids: normalizedIds,[\s\S]*?action,[\s\S]*?source/);
});

const actionsSection = table.match(/bulkLifecycleActions\(\)[\s\S]*?return \[[\s\S]*?\];/)?.[0] || '';
for (const action of ['suspend', 'resume', 'archive', 'restore', 'unbury']) {
    test(`Bulk menu has ${action}`, () => assert.ok(actionsSection.includes(`key: '${action}'`)));
}
for (const action of ['bury', 'reset', 'delete']) {
    test(`Bulk menu excludes ${action}`, () => assert.ok(!actionsSection.includes(`key: '${action}'`)));
}

test('Table emits immutable bulk lifecycle selection snapshot', () => {
    assert.match(table, /emitBulkLifecycle\(action\)[\s\S]*?ids:\s*\[\.\.\.this\.selectedIds\]/);
    assert.match(table, /items:\s*\[\.\.\.this\.selectedItems\]/);
});

test('Parent forwards bulk lifecycle intent to child', () => {
    assert.match(parent, /confirmBulkLifecycle\(selection\)[\s\S]*?lifecycleMutationSurface\?\.confirmBulk\(selection\)/);
});

test('Bulk success clears selection through semantic event', () => {
    assert.ok(surface.includes("this.$emit('clear-selection')"));
    assert.ok(parent.includes('@clear-selection="clearTableSelection"'));
});

test('Bulk success reports applied and skipped counts', () => {
    assert.ok(surface.includes('data.applied'));
    assert.ok(surface.includes('data.skipped'));
});

test('Bulk request is locked', () => {
    assert.match(surface, /if \(this\.bulkLifecycleLoading\)/);
    assert.match(surface, /this\.bulkLifecycleLoading = true/);
    assert.match(surface, /finally\(\(\) => \{[\s\S]*?this\.bulkLifecycleLoading = false/);
});

test('Bulk handles 422 error', () => assert.ok(surface.includes('status === 422')));
test('Bulk handles network error', () => assert.ok(surface.includes('!err.response')));
test('Bulk has title hint and color computed values', () => {
    assert.ok(surface.includes('bulkLifecycleDialogTitle'));
    assert.ok(surface.includes('bulkLifecycleDialogHint'));
    assert.ok(surface.includes('bulkLifecycleDialogColor'));
});

test('Leech bulk suspend delegates to shared request owner', () => {
    assert.ok(parent.includes('surface.runBulkLifecycle'));
    assert.ok(parent.includes("source: 'manage_bulk_leech_suspend'"));
});

test('Surface excludes delete reset and rewrite endpoints', () => {
    assert.ok(!surface.includes('/bulk-delete'));
    assert.ok(!surface.includes('/reset'));
    assert.ok(!surface.includes('rewrite-package'));
});

console.log(`\n${passed} tests passed.`);
