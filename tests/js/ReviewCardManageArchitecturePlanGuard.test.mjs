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
    handoff: join(root, 'docs', 'plans', 'current-working-handoff.md'),
    index: join(root, 'docs', 'DOCUMENTATION_INDEX.md'),
    parent: join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardManage.vue'),
    drawer: join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardInfoDrawer.vue'),
    search: join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardSearchSurface.vue'),
    table: join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardTableSurface.vue'),
    scheduling: join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardSchedulingMutationSurface.vue'),
    lifecycle: join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardLifecycleMutationSurface.vue'),
    leech: join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardLeechMutationSurface.vue'),
};

for (const [name, path] of Object.entries(paths)) {
    assert.ok(existsSync(path), `required ${name} file missing: ${path}`);
}

const source = Object.fromEntries(Object.entries(paths).map(([name, path]) => [name, readFileSync(path, 'utf8')]));
const lines = text => (text.match(/\n/g) || []).length;
const count = (text, pattern) => (text.match(pattern) || []).length;

assert.equal(lines(source.parent), 905, 'ReviewCardManage.vue line count must remain explicit');
assert.equal(count(source.parent, /axios\./g), 9, 'parent direct axios count must remain explicit');
assert.equal(count(source.parent, /<v-dialog/g), 4, 'parent dialog count must remain explicit');
assert.equal(lines(source.table), 883, 'table surface line count must remain explicit');
assert.equal(count(source.table, /axios\./g), 3, 'table owns exactly three read-only export requests');
assert.equal(lines(source.scheduling), 117, 'scheduling surface line count must remain explicit');
assert.equal(count(source.scheduling, /axios\./g), 2, 'scheduling owns exactly two requests');
assert.equal(count(source.scheduling, /<v-dialog/g), 2, 'scheduling owns two dialogs');
assert.equal(lines(source.lifecycle), 414, 'lifecycle surface line count must remain explicit');
assert.equal(count(source.lifecycle, /axios\./g), 3, 'lifecycle owns one GET and two POST requests');
assert.equal(count(source.lifecycle, /<v-dialog/g), 3, 'lifecycle owns single, bulk and help dialogs');
assert.equal(lines(source.leech), 312, 'Leech surface line count must remain explicit');
assert.equal(count(source.leech, /axios\./g), 2, 'Leech surface owns summary and bulk rewrite requests');
assert.equal(count(source.leech, /<v-dialog/g), 2, 'Leech surface owns bulk rewrite and suspend dialogs');

assert.match(source.parent, /<review-card-search-surface/);
assert.match(source.parent, /<review-card-table-surface/);
assert.match(source.parent, /<review-card-info-drawer/);
assert.match(source.parent, /<review-card-scheduling-mutation-surface/);
assert.match(source.parent, /<review-card-lifecycle-mutation-surface/);
assert.match(source.parent, /<review-card-leech-mutation-surface/);
assert.match(source.parent, /lifecycleSurfaceState/);
assert.doesNotMatch(source.parent, /\/lifecycle-actions|\/review-cards\/manage\/bulk-lifecycle/);
assert.doesNotMatch(source.parent, /v-model="lifecycleDialog"|v-model="bulkLifecycleDialog"|v-model="stateHelpDialog"/);
assert.match(source.parent, /surface\.runLifecycleAction/);
assert.match(source.parent, /surface\.runBulkLifecycle/);
assert.doesNotMatch(source.parent, /leech-summary|bulk-leech-rewrite-packages|bulkRewriteDialog|bulkLeechSuspendDialog/);

assert.match(source.leech, /axios\.get\('\/review-cards\/manage\/leech-summary'\)/);
assert.match(source.leech, /axios\.post\('\/review-cards\/manage\/bulk-leech-rewrite-packages'/);
assert.match(source.leech, /this\.runLifecycleAction/);
assert.match(source.leech, /this\.runBulkLifecycle/);
assert.doesNotMatch(source.leech, /\/lifecycle-actions|\/review-cards\/manage\/bulk-lifecycle/);
assert.doesNotMatch(source.leech, /provider-preview|createReviewLog|createWordSense|createReviewCard|fsrs_schedule/);

assert.match(source.lifecycle, /axios\.get\('\/review-cards\/' \+ normalizedId \+ '\/lifecycle'\)/);
assert.match(source.lifecycle, /axios\.post\('\/review-cards\/' \+ reviewCardId \+ '\/lifecycle-actions'/);
assert.match(source.lifecycle, /axios\.post\('\/review-cards\/manage\/bulk-lifecycle'/);
assert.match(source.lifecycle, /descriptorRequestSeq/);
assert.match(source.lifecycle, /expectedVersion/);
assert.match(source.lifecycle, /request_id:/);
assert.match(source.lifecycle, /expected_version:/);
assert.match(source.lifecycle, /already_applied/);
assert.doesNotMatch(source.lifecycle, /bulk-delete|rewrite-package|due-now|\/reset/);
assert.doesNotMatch(source.lifecycle, /ReviewLog|fsrs_(state|due|stability|difficulty|reps|lapses)|WordSense/);
assert.doesNotMatch(source.lifecycle, /Vuex|mapState|mapActions|eventBus|EventBus/);

assert.match(source.search, /ReviewCardSavedSearchPanel/);
assert.doesNotMatch(source.search, /axios\./);
assert.match(source.table, /axios\.get\(['"]\/review-cards\/manage\/export['"]/);
assert.match(source.table, /axios\.get\(['"]\/review-cards\/manage\/export-anki-tsv['"]/);
assert.match(source.table, /axios\.get\(['"]\/review-cards\/manage\/export-csv['"]/);
assert.doesNotMatch(source.table, /axios\.(post|put|patch|delete)\s*\(/i);
assert.match(source.drawer, /const seq = \+\+this\.detailRequestSeq/);
assert.doesNotMatch(source.drawer, /axios\.(post|put|patch|delete)\s*\(/i);

assert.match(source.plan, /Phase 3C-2 — Lifecycle Mutation Family[^\n]*Accepted \/ Production Closed/);
assert.match(source.plan, /Phase 3C-3 — Delete Mutation Family[^\n]*Planned \/ Not Authorized/);
assert.match(source.plan, /Phase 3C-4 — Leech Governance Mutation Family[^\n]*Accepted \/ Production Closed/);
assert.match(source.plan, /ReviewCardLeechMutationSurface\.vue/);
assert.match(source.plan, /905 lines/);
assert.match(source.plan, /312 lines/);
assert.match(source.plan, /ReviewCardLifecycleMutationSurface\.vue/);
assert.match(source.plan, /1,210 lines/);
assert.match(source.plan, /414 lines/);
assert.match(source.plan, /from 16 to 11/);
assert.match(source.plan, /from 9 to 6/);
assert.match(source.plan, /expected_version/);
assert.match(source.plan, /stale-response/);
assert.match(source.plan, /ARCH-ReviewCardManage-3C-2/);
assert.match(source.plan, /DEV-ReviewCardManage-3C-2/);
assert.match(source.plan, /Anki Manual — Browsing/);
assert.match(source.plan, /qt\/aqt\/operations\/scheduling\.py/);
assert.match(source.plan, /9 个原始字幕文件/);
assert.match(source.plan, /你写了一堆文档AI还是不听话？问题不在文档本身\.srt/);
assert.match(source.plan, /一个真实职责/);
assert.match(source.plan, /ReviewCardManage 域内唯一生命周期请求所有者/);
assert.match(source.plan, /SenseReview\.vue[^\n]*独立产品入口/);
assert.match(source.plan, /遗留 `\/enabled`[^\n]*无可达表格入口/);
assert.match(source.plan, /review-card-leech-mutation-browser-acceptance-2026-07-17\.md/);
assert.match(source.plan, /Phase 3D — Container Closure — Accepted \/ Production Closed/);

for (const doc of [source.roadmap, source.master, source.handoff, source.index]) {
    assert.match(doc, /Browser\s*\/\s*ReviewCardManage/);
    assert.match(doc, /905/);
    assert.match(doc, /Phase 3C-3[^\n]*Planned \/ Not Authorized/);
    assert.match(doc, /review-card-manage-architecture-convergence-plan\.md/);
}

assert.match(source.roadmap, /Phase 3C-2[^\n]*Accepted \/ Production Closed/);
assert.match(source.master, /Production Closed[^\n]*Browser\/ReviewCardManage Phase 3C-2/);
assert.match(source.handoff, /Custom Study 1B: Accepted \/ Production Closed/);
assert.match(source.index, /Browser authorized scope[^\n]*Accepted \/ Production Closed/);
assert.match(source.roadmap, /Browser 授权范围、Card Marker \/ Custom Study 1B[^\n]*已生产关闭/);
assert.match(source.master, /Production Closed[^\n]*Browser\/ReviewCardManage Phase 3C-4[^\n]*Browser\/ReviewCardManage Phase 3D/);
assert.match(source.handoff, /review-card-marker-custom-study-browser-acceptance-2026-07-17\.md/);

assert.match(source.master, /Current Phase \| Phase 3–7 authorized scope closed/);
assert.match(source.handoff, /Current phase: \*\*Phase 3–7 authorized scope closed\*\*/);
assert.match(source.index, /disabled-provider preflight convergence is Accepted under ADR-0032/);

console.log('ReviewCardManage architecture plan guard passed.');
