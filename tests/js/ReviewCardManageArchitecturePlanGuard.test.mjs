import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = join(__dirname, '..', '..');
const paths = {
    plan: join(root, 'docs', 'plans', 'review-card-manage-architecture-convergence-plan.md'),
    roadmap: join(root, 'docs', 'plans', 'anki-aligned-product-and-architecture-roadmap.md'),
    master: join(root, 'docs', 'plans', 'linguacafe-master-plan.md'),
    index: join(root, 'docs', 'DOCUMENTATION_INDEX.md'),
    cardInfoAdr: join(root, 'docs', 'adr', 'ADR-0014-review-card-info-read-model.md'),
    containerAcceptance: join(root, 'docs', 'testing', 'review-card-container-closure-browser-acceptance-2026-07-18.md'),
    routes: join(root, 'routes', 'web.php'),
    controller: join(root, 'app', 'Http', 'Controllers', 'ReviewCardManageController.php'),
    parent: join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardManage.vue'),
    drawer: join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardInfoDrawer.vue'),
    search: join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardSearchSurface.vue'),
    table: join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardTableSurface.vue'),
    scheduling: join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardSchedulingMutationSurface.vue'),
    lifecycle: join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardLifecycleMutationSurface.vue'),
    deleteSurface: join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardDeleteMutationSurface.vue'),
    leechSurface: join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardLeechGovernanceMutationSurface.vue'),
    markerPicker: join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardMarkerPicker.vue'),
    tagManager: join(root, 'resources', 'js', 'components', 'ReviewCards', 'WordSenseTagManager.vue'),
    tagBulkPicker: join(root, 'resources', 'js', 'components', 'ReviewCards', 'WordSenseTagBulkPicker.vue'),
    portableData: join(root, 'resources', 'js', 'components', 'ReviewCards', 'PortableDataPanel.vue'),
    hygiene: join(root, 'resources', 'js', 'components', 'ReviewCards', 'KnowledgeHygienePanel.vue'),
};

for (const [name, filePath] of Object.entries(paths)) {
    assert.ok(existsSync(filePath), `required ${name} file missing: ${filePath}`);
}

const source = Object.fromEntries(
    Object.entries(paths).map(([name, filePath]) => [name, readFileSync(filePath, 'utf8')])
);
const count = (text, pattern) => (text.match(pattern) || []).length;
const masterOpenWork = source.master.slice(
    source.master.indexOf('## 4. Open Work Registry'),
    source.master.indexOf('## 5. 颜色语义规则')
);

// The container remains an orchestrator. Do not freeze changing line counts.
for (const component of [
    'review-card-search-surface',
    'review-card-table-surface',
    'review-card-info-drawer',
    'review-card-scheduling-mutation-surface',
    'review-card-lifecycle-mutation-surface',
    'review-card-delete-mutation-surface',
    'review-card-leech-governance-mutation-surface',
]) {
    assert.match(source.parent, new RegExp(`<${component}`), `parent must mount ${component}`);
}
assert.equal(count(source.parent, /<v-dialog/g), 0, 'parent must not reclaim child dialogs');
assert.ok(count(source.parent, /axios\./g) <= 4, 'parent must remain limited to coordinating requests');
assert.doesNotMatch(source.parent, /\/lifecycle-actions|\/review-cards\/manage\/bulk-lifecycle/);
assert.doesNotMatch(source.parent, /axios\.delete\('\/review-cards\/manage\/'|axios\.post\('\/review-cards\/manage\/bulk-delete'/);
assert.doesNotMatch(source.parent, /\/review-cards\/manage\/leech-summary|\/review-cards\/manage\/bulk-leech-rewrite-packages/);
assert.doesNotMatch(source.parent, /\/enabled|toggleEnabled|confirmArchive|doArchive|confirmRestore|doRestore/);

// Read-only surfaces remain read-only; Card Info remains read-mostly because it owns one explicit undo/redo action.
assert.match(source.search, /ReviewCardSavedSearchPanel/);
assert.doesNotMatch(source.search, /axios\./);
assert.match(source.table, /\/review-cards\/manage\/export/);
assert.match(source.table, /\/review-cards\/manage\/export-anki-tsv/);
assert.match(source.table, /\/review-cards\/manage\/export-csv/);
assert.doesNotMatch(source.table, /axios\.(post|put|patch|delete)\s*\(/i);
assert.match(source.drawer, /detailRequestSeq/);
assert.equal(count(source.drawer, /axios\.post\s*\(/g), 1, 'Card Info may only own explicit manual-operation undo/redo');
assert.match(source.drawer, /\/review-card-operations\//);
assert.doesNotMatch(source.drawer, /\/rate\b|lifecycle-actions|bulk-delete|manual-operations\/(preview|apply)/);
assert.doesNotMatch(source.drawer, /axios\.(put|patch|delete)\s*\(/i);
assert.match(source.cardInfoAdr, /\*\*Status\*\*: Accepted/);
assert.match(source.cardInfoAdr, /Frontend opens the drawer with \*\*one\*\* canonical detail request/i);
assert.match(source.containerAcceptance, /Phase 3D — Container Closure is \*\*Accepted \/ Production Closed\*\*/);
assert.match(source.containerAcceptance, /There was no `\/enabled` request/);

// M10–M16 additions keep focused owners instead of returning mutation logic to the coordinator.
assert.match(source.parent, /<knowledge-hygiene-panel/);
assert.match(source.parent, /<portable-data-panel/);
assert.match(source.table, /ReviewCardMarkerPicker/);
assert.match(source.table, /WordSenseTagBulkPicker/);
assert.match(source.drawer, /ReviewCardMarkerPicker/);
assert.match(source.markerPicker, /\/review-cards\/manage\/bulk-marker/);
assert.match(source.markerPicker, /\/marker/);
assert.match(source.tagManager, /\/review-cards\/manage\/tags/);
assert.match(source.tagBulkPicker, /\/review-cards\/manage\/tags\/bulk-assignments/);
assert.match(source.portableData, /\/review-cards\/manage\/portable\/import-preview/);
assert.match(source.portableData, /\/review-cards\/manage\/portable\/import-apply/);
assert.match(source.hygiene, /\/review-cards\/knowledge-hygiene\/find-replace\/preview/);
assert.match(source.hygiene, /\/review-cards\/knowledge-hygiene\/find-replace\/apply/);

// Backend compatibility remains available while the active parent no longer calls the legacy endpoint.
assert.match(source.routes, /Route::patch\('\/review-cards\/manage\/\{reviewCard\}\/enabled'/);
assert.match(source.controller, /function enabled\s*\(/);
assert.match(source.routes, /Route::post\('\/review-cards\/manage\/bulk-enabled'/);
assert.match(source.controller, /function bulkEnabled\s*\(/);
assert.doesNotMatch(source.parent, /\/enabled|\/bulk-enabled/);

// Each mutation family keeps a single owner and does not absorb another family.
assert.match(source.scheduling, /manual-operations\/preview/);
assert.match(source.scheduling, /manual-operations\/apply/);
assert.match(source.scheduling, /expected_state_fingerprint/);
assert.doesNotMatch(source.scheduling, /\/review-cards\/manage\/[^\n]+\/(due-now|reset)/);
assert.doesNotMatch(source.scheduling, /lifecycle-actions|bulk-delete|rewrite-package/);

assert.match(source.lifecycle, /\/lifecycle-actions/);
assert.match(source.lifecycle, /\/review-cards\/manage\/bulk-lifecycle/);
assert.match(source.lifecycle, /expected_version/);
assert.doesNotMatch(source.lifecycle, /bulk-delete|rewrite-package|due-now|\/reset/);
assert.doesNotMatch(source.lifecycle, /ReviewLog|fsrs_(state|due|stability|difficulty|reps|lapses)|WordSense/);

assert.match(source.deleteSurface, /axios\.delete\('\/review-cards\/manage\/'/);
assert.match(source.deleteSurface, /\/review-cards\/manage\/bulk-delete/);
assert.match(source.deleteSurface, /复习历史和阅读来源记录会保留/);
assert.match(source.deleteSurface, /不会按筛选条件全量删除/);
assert.doesNotMatch(source.deleteSurface, /lifecycle-actions|bulk-lifecycle|due-now|\/reset|rewrite-package/);

assert.match(source.leechSurface, /\/review-cards\/manage\/leech-summary/);
assert.match(source.leechSurface, /\/review-cards\/manage\/bulk-leech-rewrite-packages/);
assert.match(source.leechSurface, /provider_called/);
assert.match(source.leechSurface, /review_log_created/);
assert.doesNotMatch(source.leechSurface, /provider-preview|createReviewLog|createWordSense|createReviewCard|FsrsScheduling/);

// Current documentation protects status and ownership, not historical metrics.
assert.match(source.plan, /Phase 3C-2 — Lifecycle Mutation Family[^\n]*Accepted \/ Production Closed/);
assert.match(source.plan, /Phase 3C-3 — Delete Mutation Family[^\n]*Accepted \/ Production Closed/);
assert.match(source.plan, /Phase 3C-4 — Leech Governance Mutation Family[^\n]*Accepted \/ Production Closed/);
assert.match(source.plan, /Phase 3D — Container Closure[^\n]*Accepted \/ Production Closed/);
assert.match(source.plan, /Card Marker \+ Custom Study 1B[^\n]*Production Closed/);
assert.doesNotMatch(source.plan, /Card Marker \+ Custom Study 1B remains \*\*Planned \/ Not Authorized\*\*/);
assert.match(source.roadmap, /Phase 3：Browser \/ ReviewCardManage[\s\S]*?Phase 3A–3D Accepted \/ Production Closed/);
assert.match(source.master, /M1–M8/);
assert.match(source.master, /M10–M16/);
assert.match(source.master, /M17 Web slice/);
assert.match(source.master, /M18[^\n]*(共享实现|Web\/Android|Web.*Android)/);
assert.doesNotMatch(masterOpenWork, /Browser \/ ReviewCardManage architecture convergence/, 'closed Browser work must not remain in the open registry');
assert.match(source.index, /review-card-manage-architecture-convergence-plan\.md/);

for (const [name, doc] of [
    ['roadmap', source.roadmap],
    ['master open-work registry', masterOpenWork],
    ['index', source.index],
]) {
    assert.doesNotMatch(
        doc,
        /Card Marker[^\n]*Custom Study 1B[^\n]*Planned \/ Not Authorized/,
        `${name} must not revive the completed Card Marker + Custom Study 1B phase`
    );
}

console.log('ReviewCardManage semantic architecture guard passed.');
