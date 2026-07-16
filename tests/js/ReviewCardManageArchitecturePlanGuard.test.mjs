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

assert.ok(existsSync(planPath), 'ReviewCardManage architecture convergence plan must exist');

const plan = readFileSync(planPath, 'utf8');
const roadmap = readFileSync(roadmapPath, 'utf8');
const master = readFileSync(masterPath, 'utf8');
const handoff = readFileSync(handoffPath, 'utf8');
const index = readFileSync(indexPath, 'utf8');

assert.match(plan, /Implemented \/ Automated Verified \/ Browser Functional Checks Complete \/ Network Trace Pending \/ Awaiting web-side final acceptance/);
assert.match(plan, /3,411 lines/);
assert.match(plan, /24 direct `axios\.` references/);
assert.match(plan, /12 `v-dialog` blocks/);
assert.match(plan, /Phase 3A — Card Info Drawer Extraction/);
assert.match(plan, /ReviewCardInfoDrawer\.vue/);
assert.match(plan, /exactly one canonical detail request/);
assert.match(plan, /Phase 3B — Search and Table Surface/);
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
    assert.match(doc, /Network Trace Pending|Network trace and web-side final acceptance still pending/);
    assert.match(doc, /review-card-manage-architecture-convergence-plan\.md/);
}

assert.doesNotMatch(master, /Current Phase \| Preset V1D is Production Closed; no follow-up task has been entered automatically/);
assert.match(master, /Current Phase \| Browser\/ReviewCardManage Phase 3A/);
assert.match(handoff, /Phase 3A — Card Info Drawer Extraction/);
assert.match(index, /Card Info Drawer Extraction/);

console.log('ReviewCardManage architecture plan guard passed.');
