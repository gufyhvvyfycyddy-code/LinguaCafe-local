import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname.replace(/^\/(.:)/, '$1')), '../..');
const absolute = relativePath => path.join(root, relativePath);
const read = relativePath => fs.readFileSync(absolute(relativePath), 'utf8');

const master = read('docs/plans/linguacafe-master-plan.md');
const goal = read('docs/plans/LinguaCafe_Goal_Mode_All_Milestones_Sol_Medium_2026-08-09.md');
const rebaseline = read('docs/product/LinguaCafe_Product_Rebaseline_English_Reading_First_2026-08-18.md');
const productHistory = read('docs/product/confirmed-product-decisions-and-discussion-roadmap-2026-07-23.md');
const oldAnkiRoadmap = read('docs/plans/anki-aligned-product-and-architecture-roadmap.md');
const currentContext = read('docs/CURRENT_AI_CONTEXT.md');
const index = read('docs/DOCUMENTATION_INDEX.md');
const collaboration = read('docs/plans/vibe-coding-collaboration-rules.md');
const supersededDueAdr = read('docs/adr/ADR-0059-reading-reinforcement-spacing-and-reader-review-boundary.md');
const supersededEarlyAdr = read('docs/adr/ADR-0060-reading-opportunistic-early-review-and-single-credit-boundary.md');
const readingReviewAdr = read('docs/adr/ADR-0061-reading-early-review-minimum-spacing-boundary.md');
const sourceExampleAdr = read('docs/adr/ADR-0062-reading-ai-matched-existing-source-example-binding.md');
const recoveryMilestone = JSON.parse(read('docs/execution/CURRENT_MILESTONE.json'));

// Current authority must be explicit and must not make the old Anki roadmap current again.
assert.match(master, /Current authority — 2026-08-18/);
assert.match(master, /LinguaCafe_Product_Rebaseline_English_Reading_First_2026-08-18\.md/);
assert.match(master, /Goal Phase G/);
assert.match(master, /ADR-0061-reading-early-review-minimum-spacing-boundary\.md/);
assert.match(master, /ADR-0062-reading-ai-matched-existing-source-example-binding\.md/);
assert.match(oldAnkiRoadmap, /Historical reference \/ forward authority superseded on 2026-08-18/);

// The forward roadmap is the seven-slice Phase G rebase, in dependency order.
let previous = -1;
for (const id of ['G-06A', 'G-06B', 'G-06C', 'G-06D', 'G-06E', 'G-06F', 'G-06G', 'G-GATE']) {
    const current = goal.indexOf(`| ${id} |`);
    assert.ok(current > previous, `Goal Phase G milestone missing or out of order: ${id}`);
    previous = current;
}
assert.match(goal, /G-06B[\s\S]*24h[\s\S]*提前[\s\S]*Good[\s\S]*Again/);
assert.match(goal, /G-06C[\s\S]*matched_existing[\s\S]*WordSenseOccurrence[\s\S]*例句轮换[\s\S]*译文显隐不移动英文/);
assert.match(goal, /G-06D[\s\S]*阅读进度[\s\S]*手动书签/);
assert.match(goal, /G-06E[\s\S]*PDF\/TXT\/CSV/);
assert.match(goal, /G-06F[\s\S]*自动优化默认 30/);
assert.match(goal, /G-06G[\s\S]*Tag\/Marker[\s\S]*Article Health/);

// Core product semantics: English-only, Sense-first, anti-duplicate AI, stable layout and spaced reading.
for (const phrase of [
    'English-only, reading-first, Sense-first',
    'Semantic anti-duplicate rule',
    'Reading reinforcement, early review, and single-credit boundary',
    'Stable translation layout',
    'Reading continuity: progress, resume, bookmarks',
    'Daily reading-new-Sense goal',
    'Learning record and history',
    'Memory durability and future review pressure',
    'Configurable interval optimization',
]) {
    assert.ok(rebaseline.includes(phrase), `product rebaseline missing: ${phrase}`);
}
assert.match(rebaseline, /substantially the same meaning[\s\S]*matched_existing/);
assert.match(rebaseline, /fsrs_due_at[\s\S]*future[\s\S]*Good/);
assert.match(rebaseline, /24 full elapsed hours|24 full elapsed|24 full|24-hour|24 full/i);
assert.match(rebaseline, /real Reader occurrence[\s\S]*WordSenseOccurrence[\s\S]*example pool/i);
assert.match(rebaseline, /ten occurrences[\s\S]*at most one review/);
assert.match(rebaseline, /标记.*不认识|`不认识`/);
assert.match(rebaseline, /认识\s*\/\s*记得.*Good/);
assert.match(rebaseline, /default.*30 days|defaults to \*\*30 days\*\*/i);

// The current Reader ADR allows meaningful early review, adds a 24h floor, and keeps one canonical scheduler.
assert.match(readingReviewAdr, /full 24-hour minimum spacing floor/);
assert.match(readingReviewAdr, /latest effective formal rating/);
assert.match(readingReviewAdr, /After 24 hours, genuine early Good remains allowed before due/);
assert.match(readingReviewAdr, /Again remains genuine failure evidence/);
assert.match(readingReviewAdr, /Same-session one-credit remains a stronger local guard/);
assert.match(readingReviewAdr, /No second scheduler or persisted Reader due/);
assert.match(supersededEarlyAdr, /Superseded for forward Reader spacing by ADR-0061/);
assert.match(supersededDueAdr, /current authority ADR-0061/);

// Authoritative AI/user matched-existing binds the real Reader sentence into the existing source/example owner.
assert.match(sourceExampleAdr, /Authoritative matched-existing binding must also make the real Reader sentence a WordSense source occurrence/);
assert.match(sourceExampleAdr, /English example sentence must come from the real Reader source/);
assert.match(sourceExampleAdr, /Retry\/reimport must be idempotent/);
assert.match(sourceExampleAdr, /Example binding has zero rating side effect/);

// Historical product documents stay readable but no longer control conflicting forward behavior.
assert.match(productHistory, /PD-012 阅读中直接刷词义卡 V1（历史决定，forward 已 supersede）/);
assert.match(productHistory, /ADR-0061/);
assert.match(productHistory, /Historical \/ Superseded for forward product behavior on 2026-08-18/);

// The minimal context and documentation router must lead new tasks to current authority first.
assert.match(currentContext, /2026-08-18 current overlay/);
assert.match(currentContext, /G-06A…G-06G \+ G-GATE/);
assert.match(currentContext, /ADR-0061/);
assert.match(currentContext, /ADR-0062/);
assert.match(index, /当前产品权威/);
assert.match(index, /LinguaCafe_Product_Rebaseline_English_Reading_First_2026-08-18\.md/);
assert.match(index, /Reader 提前复习 \/ 24h 最小正向间隔 \/ 单次计分 \/ 不认识→Again/);
assert.match(index, /AI 阅读 matched-existing 真实来源例句绑定 \/ 例句轮换/);

// The old recovery milestone remains valid history, but it must not be advertised as current Phase G authority.
assert.equal(recoveryMilestone.active_task, 'NONE');
assert.equal(recoveryMilestone.product_code_authorized, false);
assert.match(currentContext, /只描述已经关闭的 recovery-publication program/);

// Current execution is fixed-DIRECT and user-started between batches.
for (const phrase of [
    'fixed DIRECT',
    'GPT-5.6 Sol',
    'opencode/deepseek-v4-flash-free',
    'opencode/mimo-v2.5-free',
    'opencode-go/mimo-v2.5',
    '用户启动下一批次后才进入下一实现任务',
]) {
    assert.ok(collaboration.includes(phrase), `collaboration rule missing: ${phrase}`);
}

// Documentation routes must resolve. Do not freeze exact counts, SHAs or old phase prose here.
const indexedPaths = [...index.matchAll(/`((?:docs|tests|app|resources)\/[^`]+)`/g)]
    .map(match => match[1])
    .filter(value => !value.includes('*'));
for (const relativePath of new Set(indexedPaths)) {
    assert.ok(fs.existsSync(absolute(relativePath)), `index route missing in checkout: ${relativePath}`);
}

console.log('Current master plan integrity contract passed.');
