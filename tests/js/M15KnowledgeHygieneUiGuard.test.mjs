import assert from 'node:assert/strict';
import fs from 'node:fs';

const panel = fs.readFileSync(
    new URL('../../resources/js/components/ReviewCards/KnowledgeHygienePanel.vue', import.meta.url),
    'utf8',
);
const manage = fs.readFileSync(
    new URL('../../resources/js/components/ReviewCards/ReviewCardManage.vue', import.meta.url),
    'utf8',
);
const table = fs.readFileSync(
    new URL('../../resources/js/components/ReviewCards/ReviewCardTableSurface.vue', import.meta.url),
    'utf8',
);
const deletion = fs.readFileSync(
    new URL('../../resources/js/components/ReviewCards/ReviewCardDeleteMutationSurface.vue', import.meta.url),
    'utf8',
);
const recentDeletes = fs.readFileSync(
    new URL('../../resources/js/components/ReviewCards/ReviewCardRecentDeletesPanel.vue', import.meta.url),
    'utf8',
);
const settings = fs.readFileSync(
    new URL('../../resources/js/components/UserSettings/UserSettingsLayout.vue', import.meta.url),
    'utf8',
);
const review = fs.readFileSync(
    new URL('../../resources/js/components/Senses/SenseReview.vue', import.meta.url),
    'utf8',
);

for (const endpoint of [
    '/review-cards/knowledge-hygiene/preferences',
    '/review-cards/knowledge-hygiene/find-replace/preview',
    '/review-cards/knowledge-hygiene/find-replace/apply',
    '/review-cards/knowledge-hygiene/duplicates',
    '/review-cards/knowledge-hygiene/merge/preview',
    '/review-cards/knowledge-hygiene/merge/apply',
]) {
    assert.ok(panel.includes(endpoint), `missing M15 UI endpoint ${endpoint}`);
}
assert.ok(panel.includes('preview_fingerprint'), 'replace and merge must use fresh preview fingerprints');
assert.ok(panel.includes('automatic') || panel.includes('自动创建 M6 备份'), 'merge must explain automatic backup');
assert.ok(panel.includes('mergeConfirmed'), 'merge must require explicit human confirmation');
assert.match(manage, /<knowledge-hygiene-panel[\s\S]*?class="d-none"/, 'Knowledge Hygiene must stay mounted only as an internal preference owner');
assert.ok(panel.includes("axios.get('/review-cards/knowledge-hygiene/preferences')"), 'M15 panel owns preference loading');
assert.ok(panel.includes("axios.put('/review-cards/knowledge-hygiene/preferences'"), 'M15 panel owns preference persistence');
assert.ok(panel.includes('persistColumns(columns)'), 'M15 panel exposes focused column persistence');
assert.ok(!manage.includes('/review-cards/knowledge-hygiene/preferences'), 'parent must not duplicate preference HTTP ownership');
assert.ok(table.includes('visibleColumns') && table.includes("columns-change"), 'table must consume and persist server column preferences');
assert.ok(!table.includes('批量彻底删除'), 'bulk delete copy must not claim irreversible deletion');
assert.ok(!deletion.includes('确认彻底删除'), 'delete dialog must describe recent-delete semantics');
assert.ok(!deletion.includes('知识库整理 → 最近删除'), 'delete recovery copy must not point to the internalized panel');
assert.ok(deletion.includes('我的 → 高级 → 最近删除'), 'delete recovery copy must point to the focused recovery surface');
assert.match(settings, /ReviewCardRecentDeletesPanel/);
assert.match(settings, /<review-card-recent-deletes-panel/);
assert.match(recentDeletes, /axios\.get\(['"]\/review-cards\/knowledge-hygiene\/recent-deletes['"]\)/);
assert.match(recentDeletes, /axios\.post\(`\/review-cards\/knowledge-hygiene\/operations\/\$\{operationId\}\/undo`\)/);
assert.match(recentDeletes, /最近 30 天没有可恢复的删除/);
assert.doesNotMatch(review, /此操作不可恢复|已彻底删除词义复习卡/);
assert.match(review, /我的 → 高级 → 最近删除/);

console.log('M15 knowledge hygiene UI guard passed.');
