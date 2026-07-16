import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = join(__dirname, '..', '..');
const planPath = join(root, 'docs', 'plans', 'review-card-manage-architecture-convergence-plan.md');
const roadmapPath = join(root, 'docs', 'plans', 'anki-aligned-product-and-architecture-roadmap.md');
const masterPath = join(root, 'docs', 'plans', 'linguacafe-master-plan.md');
const handoffPath = join(root, 'docs', 'plans', 'current-working-handoff.md');
const indexPath = join(root, 'docs', 'DOCUMENTATION_INDEX.md');
const parentPath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardManage.vue');
const drawerPath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardInfoDrawer.vue');
const searchSurfacePath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardSearchSurface.vue');
const tableSurfacePath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardTableSurface.vue');

for (const path of [planPath, drawerPath, searchSurfacePath, tableSurfacePath]) {
    assert.ok(existsSync(path), `required architecture file missing: ${path}`);
}

const plan = readFileSync(planPath, 'utf8');
const roadmap = readFileSync(roadmapPath, 'utf8');
const master = readFileSync(masterPath, 'utf8');
const handoff = readFileSync(handoffPath, 'utf8');
const index = readFileSync(indexPath, 'utf8');
const parent = readFileSync(parentPath, 'utf8');
const drawer = readFileSync(drawerPath, 'utf8');
const searchSurface = readFileSync(searchSurfacePath, 'utf8');
const tableSurface = readFileSync(tableSurfacePath, 'utf8');
const parentLineCount = (parent.match(/\n/g) || []).length;
const parentAxiosCount = (parent.match(/axios\./g) || []).length;
const parentDialogCount = (parent.match(/<v-dialog/g) || []).length;
const tableLineCount = (tableSurface.match(/\n/g) || []).length;
const tableAxiosCount = (tableSurface.match(/axios\./g) || []).length;
const detailRequestPattern = /axios\.get\(['"]\/review-cards\/manage\/[\s\S]{0,120}?\/detail['"]/;
const canonicalOwnerCount = [parent, drawer].filter(source => detailRequestPattern.test(source)).length;

assert.equal(parentLineCount, 1532, 'ReviewCardManage.vue current line count must remain explicit and reviewable');
assert.equal(parentAxiosCount, 19, 'ReviewCardManage.vue direct axios count must remain explicit and reviewable');
assert.equal(tableLineCount, 866, 'ReviewCardTableSurface.vue current line count must remain explicit and reviewable');
assert.equal(tableAxiosCount, 3, 'ReviewCardTableSurface.vue must own exactly the three read-only export requests');
assert.equal(parentDialogCount, 11, 'ReviewCardManage.vue dialog count must remain explicit and reviewable');
assert.equal(canonicalOwnerCount, 1, 'Card Info canonical detail request must have exactly one frontend owner');
assert.match(parent, /<review-card-search-surface/);
assert.match(parent, /<review-card-table-surface/);
assert.match(parent, /@apply="applySearchFilterState"/);
assert.doesNotMatch(parent, /<review-card-saved-search-panel/);
assert.doesNotMatch(parent, /v-model="searchQuery"/);
assert.match(searchSurface, /<review-card-saved-search-panel/);
assert.match(searchSurface, /ReviewCardManageFilterState\.js/);
assert.doesNotMatch(searchSurface, /axios\./);
assert.match(tableSurface, /axios\.get\(['"]\/review-cards\/manage\/export['"]/);
assert.match(tableSurface, /axios\.get\(['"]\/review-cards\/manage\/export-anki-tsv['"]/);
assert.match(tableSurface, /axios\.get\(['"]\/review-cards\/manage\/export-csv['"]/);
assert.doesNotMatch(tableSurface, /axios\.(post|put|patch|delete)\s*\(/i);
assert.doesNotMatch(tableSurface, /Vuex|mapState|mapActions|eventBus|EventBus/);
assert.match(drawer, /Number\.isInteger\(reviewCardId\) && reviewCardId > 0/);
assert.match(drawer, /const seq = \+\+this\.detailRequestSeq/);
assert.match(drawer, /clearDetailState\(\)[\s\S]*?this\.detailRequestSeq\+\+/);
assert.doesNotMatch(drawer, /axios\.(post|put|patch|delete)\s*\(/i);
assert.doesNotMatch(drawer, />\s*(删除|归档|恢复|重置|立即到期|忘了|困难|良好|简单)\s*</);

assert.match(plan, /Phase 3B-2 Accepted \/ Production Closed/);
assert.doesNotMatch(plan, /^> \*\*Status\*\*:.*Incomplete/m);
assert.match(plan, /2,792 lines/);
assert.match(plan, /2,462 lines/);
assert.match(plan, /1,532 lines/);
assert.match(plan, /866 lines/);
assert.match(plan, /3,411 lines/);
assert.match(plan, /22 direct `axios\.` references/);
assert.match(plan, /from 22 to 19/);
assert.match(plan, /11 `v-dialog` blocks/);
assert.match(plan, /ReviewCardSearchSurface\.vue/);
assert.match(plan, /ReviewCardTableSurface\.vue/);
assert.match(plan, /ReviewCardSavedSearchPanel\.vue/);
assert.match(plan, /server-authoritative search/i);
assert.match(plan, /invalid `is:unknown`/);
assert.match(plan, /reps_min=1/);
assert.match(plan, /900×900/);
assert.match(plan, /Console/);
assert.match(plan, /Phase 3B-1 — Search \/ Filter \/ Saved Search Surface/);
assert.match(plan, /Phase 3B-1[^\n]*Accepted \/ Production Closed/);
assert.match(plan, /Phase 3B-2 — Table \/ Columns \/ Pagination \/ Selection \/ Export/);
assert.match(plan, /Phase 3B-2[^\n]*Accepted \/ Production Closed/);
assert.match(plan, /Phase 3C — Mutation and Dialog Families[^\n]*Authorized Next \/ Not Started/);
assert.match(plan, /Phase 3D — Container Closure/);
assert.match(plan, /ARCH-ReviewCardManage-3B-1/);
assert.match(plan, /DEV-ReviewCardManage-3B-1/);
assert.match(plan, /ARCH-ReviewCardManage-3B-2/);
assert.match(plan, /DEV-ReviewCardManage-3B-2/);
assert.match(plan, /Anki Manual — Browsing/);
assert.match(plan, /qt\/aqt\/browser\/sidebar/);
assert.match(plan, /qt\/aqt\/browser\/table/);
assert.match(plan, /qt\/aqt\/browser\/card_info\.py/);
assert.match(plan, /rslib\/src\/search\/parser\.rs/);
assert.match(plan, /rslib\/src\/search\/sqlwriter\.rs/);
assert.match(plan, /build_search_string/);
assert.match(plan, /table\.search/);
assert.match(plan, /你写了一堆文档AI还是不听话？问题不在文档本身\.srt/);
assert.match(plan, /AI编程项目为什么总是烂尾？长期项目迭代先给 AI 画边界\.srt/);
assert.match(plan, /稳定决定/);
assert.match(plan, /可执行 guard/);
assert.match(plan, /一个真实职责/);
assert.match(plan, /不复制 Anki 的 Cards\/Notes 双模式/);
assert.match(plan, /不实现 deck\/subdeck 树/);
assert.match(plan, /不进入 Phase 3C/);
assert.match(plan, /Feature tests must be grouped/);

for (const doc of [roadmap, master, handoff, index]) {
    assert.match(doc, /Browser\s*\/\s*ReviewCardManage/);
    assert.match(doc, /Phase 3B-2[^\n]*Accepted \/ Production Closed/);
    assert.match(doc, /1,532/);
    assert.match(doc, /Phase 3C[^\n]*Authorized Next \/ Not Started/);
    assert.match(doc, /review-card-manage-architecture-convergence-plan\.md/);
}

assert.match(master, /Current Phase \| Browser\/ReviewCardManage Phase 3B-2[^\n]*Accepted \/ Production Closed/);
assert.match(handoff, /Phase 3B-2 — Table \/ Columns \/ Pagination \/ Selection \/ Export/);
assert.match(index, /Table \/ Columns \/ Pagination \/ Selection \/ Export/);

console.log('ReviewCardManage architecture plan guard passed.');
