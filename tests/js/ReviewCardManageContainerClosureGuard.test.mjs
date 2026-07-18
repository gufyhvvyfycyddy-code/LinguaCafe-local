import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = join(__dirname, '..', '..');
const parentPath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardManage.vue');

assert.ok(existsSync(parentPath), 'ReviewCardManage.vue must exist');

const parent = readFileSync(parentPath, 'utf8');
const lineCount = (parent.match(/\n/g) || []).length;
const axiosCount = (parent.match(/axios\./g) || []).length;
const dialogCount = (parent.match(/<v-dialog/g) || []).length;

assert.ok(lineCount <= 700, `container closure must keep the coordinator at or below 700 lines; got ${lineCount}`);
assert.equal(axiosCount, 4, 'parent keeps only stats, list, inline edit and source-context requests');
assert.equal(dialogCount, 0, 'parent owns no mutation confirmation dialogs after container closure');

for (const component of [
    'review-card-search-surface',
    'review-card-table-surface',
    'review-card-info-drawer',
    'review-card-scheduling-mutation-surface',
    'review-card-lifecycle-mutation-surface',
    'review-card-delete-mutation-surface',
    'review-card-leech-governance-mutation-surface',
]) {
    assert.ok(parent.includes(`<${component}`), `parent must coordinate ${component}`);
}

for (const request of [
    "axios.get('/review-cards/stats')",
    "axios.get('/review-cards/manage/data'",
    "axios.patch('/review-cards/manage/' + item.review_card_id, this.editForm)",
    "axios.get('/senses/' + item.word_sense_id + '/source-context')",
]) {
    assert.ok(parent.includes(request), `parent must retain coordinator request: ${request}`);
}

for (const legacy of [
    '/enabled',
    'toggleEnabled',
    'confirmArchive',
    'doArchive',
    'confirmRestore',
    'doRestore',
    'archiveDialog',
    'archiveTarget',
    'restoreDialog',
    'restoreTarget',
    'review-card-manage-archive-title',
    'review-card-manage-restore-title',
]) {
    assert.ok(!parent.includes(legacy), `dormant legacy container code must be removed: ${legacy}`);
}

assert.ok(parent.includes('ReviewCardLifecycleMutationSurface'), 'lifecycle owner must remain registered');
assert.ok(parent.includes('surface.runLifecycleAction'), 'Leech single suspend must still delegate to lifecycle owner');
assert.ok(parent.includes('surface.runBulkLifecycle'), 'Leech bulk suspend must still delegate to lifecycle owner');
assert.ok(!parent.includes('/lifecycle-actions'), 'parent must not own lifecycle HTTP writes');
assert.ok(!parent.includes('/review-cards/manage/bulk-lifecycle'), 'parent must not own bulk lifecycle HTTP writes');

console.log('ReviewCardManage container closure guard passed.');
