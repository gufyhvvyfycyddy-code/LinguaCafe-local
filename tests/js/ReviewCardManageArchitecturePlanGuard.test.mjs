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

assert.ok(existsSync(planPath), 'ReviewCardManage architecture convergence plan must exist');
assert.ok(existsSync(drawerPath), 'ReviewCardInfoDrawer.vue must exist');

const plan = readFileSync(planPath, 'utf8');
const roadmap = readFileSync(roadmapPath, 'utf8');
const master = readFileSync(masterPath, 'utf8');
const handoff = readFileSync(handoffPath, 'utf8');
const index = readFileSync(indexPath, 'utf8');
const parent = readFileSync(parentPath, 'utf8');
const drawer = readFileSync(drawerPath, 'utf8');
const parentLineCount = (parent.match(/\n/g) || []).length;
const parentAxiosCount = (parent.match(/axios\./g) || []).length;
const parentDialogCount = (parent.match(/<v-dialog/g) || []).length;
const detailRequestPattern = /axios\.get\(['"]\/review-cards\/manage\/[\s\S]{0,120}?\/detail['"]/;
const canonicalOwnerCount = [parent, drawer].filter(source => detailRequestPattern.test(source)).length;

assert.equal(parentLineCount, 2792, 'ReviewCardManage.vue current line count must remain explicit and reviewable');
assert.equal(parentAxiosCount, 22, 'ReviewCardManage.vue direct axios count must remain explicit and reviewable');
assert.equal(parentDialogCount, 12, 'ReviewCardManage.vue dialog count must remain explicit and reviewable');
assert.equal(canonicalOwnerCount, 1, 'Card Info canonical detail request must have exactly one frontend owner');
assert.match(drawer, /Number\.isInteger\(reviewCardId\) && reviewCardId > 0/);
assert.match(drawer, /const seq = \+\+this\.detailRequestSeq/);
assert.match(drawer, /clearDetailState\(\)[\s\S]*?this\.detailRequestSeq\+\+/);
assert.doesNotMatch(drawer, /axios\.(post|put|patch|delete)\s*\(/i);
assert.doesNotMatch(drawer, />\s*(删除|归档|恢复|重置|立即到期|忘了|困难|良好|简单)\s*</);

assert.match(plan, /Accepted \/ Production Closed/);
assert.doesNotMatch(plan, /^> \*\*Status\*\*:.*Incomplete/m);
assert.doesNotMatch(plan, /authenticated Network acceptance (?:is |remains )?blocked/i);
assert.match(plan, /2,792 lines/);
assert.match(plan, /3,411 lines/);
assert.match(plan, /22 direct `axios\.` references/);
assert.match(plan, /12 `v-dialog` blocks/);
assert.match(plan, /exactly one canonical detail request/);
assert.match(plan, /authenticated Chrome acceptance/i);
assert.match(plan, /\/review-cards\/manage\/leech-summary/);
assert.match(plan, /Slow 3G/);
assert.match(plan, /1920×1080/);
assert.match(plan, /900×900/);
assert.match(plan, /Console/);
assert.match(plan, /ReviewLog/);
assert.match(plan, /FSRS/);
assert.match(plan, /Phase 3B-1 — Search \/ Filter \/ Saved Search Surface/);
assert.match(plan, /Phase 3B-1[^\n]*Authorized Next \/ Not Started/);
assert.match(plan, /Phase 3B-2 — Table \/ Columns \/ Pagination \/ Selection \/ Export/);
assert.match(plan, /Phase 3B-2[^\n]*Planned \/ Not Started/);
assert.match(plan, /Phase 3C — Mutation and Dialog Families/);
assert.match(plan, /Phase 3D — Container Closure/);
assert.match(plan, /ARCH-ReviewCardManage-3A/);
assert.match(plan, /DEV-ReviewCardManage-3A/);
assert.match(plan, /Anki Manual — Browsing/);
assert.match(plan, /qt\/aqt\/browser\/table\/model\.py/);
assert.match(plan, /rslib\/src\/search\/parser\.rs/);
assert.match(plan, /AI可以帮你写代码，但帮不了你成为架构师\.srt/);
assert.match(plan, /你写了一堆文档AI还是不听话？问题不在文档本身\.srt/);
assert.match(plan, /10万代码量真实项目，我是如何防止AI把旧功能改坏的？\.srt/);
assert.match(plan, /不复制 Anki 的 Cards\/Notes 双模式/);
assert.match(plan, /不实现 deck\/subdeck 树/);
assert.match(plan, /不进入 Card Marker 或 Custom Study 1B/);
assert.match(plan, /Feature tests must be grouped/);

for (const doc of [roadmap, master, handoff, index]) {
    assert.match(doc, /Browser\s*\/\s*ReviewCardManage/);
    assert.match(doc, /Phase 3A[^\n]*Accepted \/ Production Closed/);
    assert.match(doc, /2,792/);
    assert.match(doc, /review-card-manage-architecture-convergence-plan\.md/);
}

assert.match(master, /Current Phase \| Browser\/ReviewCardManage Phase 3A[^\n]*Accepted \/ Production Closed/);
assert.match(handoff, /Phase 3A — Card Info Drawer Extraction/);
assert.match(index, /Card Info Drawer Extraction/);

console.log('ReviewCardManage architecture plan guard passed.');
