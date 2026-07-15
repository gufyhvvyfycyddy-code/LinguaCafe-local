import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname.replace(/^\/(.:)/, '$1')), '../..');
const read = relativePath => fs.readFileSync(path.join(root, relativePath), 'utf8');
const section = (source, startHeading, nextHeading) => {
    const start = source.indexOf(startHeading);
    assert.notEqual(start, -1, `missing section: ${startHeading}`);
    const end = nextHeading ? source.indexOf(nextHeading, start + startHeading.length) : source.length;
    assert.notEqual(end, -1, `missing next section: ${nextHeading}`);
    return source.slice(start, end);
};

const master = read('docs/plans/linguacafe-master-plan.md');
const handoff = read('docs/plans/current-working-handoff.md');
const manualSenseAdr = read('docs/adr/ADR-0022-word-sense-pos-canonicalization-boundary.md');
const settingsAdr = read('docs/adr/ADR-0023-settings-architecture-convergence.md');
const presetAdr = read('docs/adr/ADR-0024-review-settings-preset-v1a-foundation.md');
const presetManagementAdr = read('docs/adr/ADR-0025-review-settings-preset-v1b-management.md');
const presetConvergenceAdr = read('docs/adr/ADR-0026-review-settings-preset-v1c-consumer-convergence.md');
const settingsUxAdr = read('docs/adr/ADR-0027-settings-advanced-tools-ux-state-model.md');
const roadmap = read('docs/plans/anki-aligned-product-and-architecture-roadmap.md');
const index = read('docs/DOCUMENTATION_INDEX.md');
const collaborationRules = read('docs/plans/vibe-coding-collaboration-rules.md');
const presetPlan = read('docs/plans/review-settings-preset-v1-plan.md');

const masterAuthority = section(master, '> **Current authority — 2026-07-15**', '> **Custom Study 1A shared card update');
const openWork = section(master, '## 4. Open Work Registry（当前唯一开放工作账本）', '## 5. 颜色语义规则');
const executionOrder = section(master, '## 8. Anki 对齐产品与架构执行顺序', '## Recent Update: Codex-FinalArchitectureClosureTargetMode-1');
const handoffAuthority = section(handoff, '> **Current authority — 2026-07-15**', '> **Authoritative Custom Study status');
const manualClosure = section(handoff, '## Manual Sense shared form corrective follow-up (2026-07-15)', null);

assert.match(masterAuthority, /Settings architecture convergence/);
assert.match(masterAuthority, /Production Closed[^\n]*Preset V1A[^\n]*Preset V1B[^\n]*Preset V1C/);
assert.match(masterAuthority, /Current Phase \| Preset V1D — Settings UX and Production Closure; Settings UX-1 Accepted/);
assert.match(masterAuthority, /27 production files over 500 lines, 10 over 1,000, and 2 over 1,500/);
assert.match(masterAuthority, /6\.5\/10, localized high burden/);
assert.doesNotMatch(masterAuthority, /Preset V1A[^\n]*Web Acceptance Pending/);
assert.match(masterAuthority, /old “overall architecture closure 100%” statement is historical/);

assert.match(openWork, /Settings architecture convergence \| Completed \/ Production Closed/);
assert.match(openWork, /FSRS-Anki-Mgmt-9 Preset V1A \| Completed \/ Production Closed/);
assert.match(openWork, /Preset V1B — Management Operations and UI \| Completed \/ Production Closed/);
assert.match(openWork, /Preset V1C — Consumer Convergence \| Completed \/ Production Closed/);
assert.match(openWork, /Preset V1D — Settings UX and Production Closure \| Partial \/ Settings UX-1 Accepted/);
assert.match(openWork, /fsrs_parameters_previous/);
assert.match(openWork, /Settings UX-1/);
assert.match(openWork, /Browser \/ ReviewCardManage architecture convergence \| Planned/);
assert.match(openWork, /Card Marker \+ Custom Study 1B \| Planned/);
assert.match(openWork, /Real AI provider \/ automatic chapter analysis \| Environment Gate/);
assert.match(openWork, /Reader-UI-4[^\n]*高级字段默认折叠/);
assert.match(openWork, /SenseReview-Smoke-1[^\n]*SenseReview-Smoke-5[^\n]*已完成真实页面验收/);
assert.match(openWork, /ReviewCardManage-MutationService-Extract-1B[^\n]*已抽入 Mutation Service/);
assert.doesNotMatch(openWork, /Reader-UI-4[^\n]*计划中|SenseReview-Smoke-1[^\n]*待验收|ReviewCardManage-MutationService-Extract-1B[^\n]*待决定/);

const orderedRoadmapTerms = [
    'Phase 1：Settings 架构收敛',
    'Phase 2：Preset V1',
    'Phase 3：Browser / ReviewCardManage 架构收敛',
    'Phase 4：Card Marker + Custom Study 1B',
    'Phase 5：Reviewer 架构收敛',
    'Phase 6：Reader UI 与阅读架构治理',
    'Phase 7：AI Study Card service 收敛与真实 provider',
];
let previous = -1;
for (const term of orderedRoadmapTerms) {
    const current = roadmap.indexOf(term);
    assert.ok(current > previous, `roadmap term must exist in order: ${term}`);
    previous = current;
}
assert.match(roadmap, /Preset 归属于用户；每个用户 \+ 学习语言只绑定一个 Preset/);
assert.match(roadmap, /Preset V1\.1 Leech Configuration Product Gate/);
assert.match(roadmap, /review-settings-preset-v1-plan\.md/);
assert.match(roadmap, /Card Marker 参考 Anki Card Flag，落在 ReviewCard/);
assert.match(roadmap, /Phase 1：Settings 架构收敛[\s\S]*Completed \/ Production Closed/);
assert.match(roadmap, /Phase 2：Preset V1[\s\S]*V1A–V1C Completed \/ Production Closed/);
assert.match(roadmap, /V1A–V1C Completed \/ Production Closed/);
assert.match(roadmap, /Settings UX-1 已 Accepted/);
assert.match(roadmap, /Settings UX-1/);
assert.match(roadmap, /6\.5 \/ 10，局部高负担/);
assert.match(roadmap, /27 个生产文件超过 500 行/);
assert.match(roadmap, /10 个生产文件超过 1,000 行/);
assert.match(roadmap, /2 个生产文件超过 1,500 行/);
assert.doesNotMatch(roadmap, /\| 1 \| Settings 架构收敛 \| Preset 的前置地基/);
assert.match(roadmap, /超过 1,000 行的生产文件不得继续无计划增加职责/);

assert.match(executionOrder, /Settings architecture convergence/);
assert.match(executionOrder, /Preset V1/);
assert.match(executionOrder, /Current Phase \/ Settings UX-1 Accepted/);
assert.match(executionOrder, /Browser \/ ReviewCardManage convergence/);
assert.match(executionOrder, /Card Marker \+ Custom Study 1B/);
assert.doesNotMatch(executionOrder, /当前没有自动授权的下一产品任务|仍须由用户.*指定/);

assert.match(manualSenseAdr, /Accepted \/ Production Closed/);
assert.match(manualSenseAdr, /DevSpace5\/Chrome web acceptance are complete/);
assert.doesNotMatch(manualSenseAdr, /web acceptance remains pending|Production closure is not claimed/);
assert.match(settingsAdr, /Accepted \/ Production Closed/);
assert.match(settingsAdr, /AdminReviewSettings\.vue.*2,164 lines/);
assert.match(settingsAdr, /SettingsService.*1,006 lines/);
assert.match(settingsAdr, /No Preset is implemented/);
assert.match(presetAdr, /Accepted \/ Production Closed/);
assert.match(presetAdr, /ReviewSettingsResolver/);
assert.match(presetAdr, /no create, clone, rename, delete, or switch action/i);
assert.match(presetManagementAdr, /Accepted \/ Production Closed/);
assert.match(presetManagementAdr, /transactionally rebinds all of its languages/);
assert.match(presetConvergenceAdr, /Accepted \/ Production Closed/);
assert.match(presetConvergenceAdr, /Stop writing `fsrs_parameters_previous`/);
assert.match(settingsUxAdr, /Accepted — Settings UX-1/);
assert.match(settingsUxAdr, /Pure presentation state module/);
assert.match(settingsUxAdr, /does not mutate its input/);
assert.match(settingsUxAdr, /Preset V1D final production closure remains open/);

assert.match(handoffAuthority, /Settings architecture convergence: \*\*Accepted \/ Production Closed\*\*/);
assert.match(handoffAuthority, /Preset V1A — Default Preset Foundation and Transparent Binding: \*\*Accepted \/ Production Closed\*\*/);
assert.match(handoffAuthority, /Preset V1B — Management Operations and UI: \*\*Accepted \/ Production Closed\*\*/);
assert.match(handoffAuthority, /Preset V1C — Consumer Convergence: \*\*Accepted \/ Production Closed\*\*/);
assert.match(handoffAuthority, /Current phase: \*\*Preset V1D — Settings UX and Production Closure\*\*/);
assert.match(handoffAuthority, /Settings UX-1 is \*\*Accepted\*\*/);
assert.match(handoffAuthority, /6\.5\/10, localized high burden/);
assert.match(manualClosure, /Status: \*\*Accepted \/ Production Closed\*\*/);
assert.match(manualClosure, /All scenarios below were executed on 2026-07-15 and passed/);
assert.doesNotMatch(manualClosure, /web acceptance pending|待网页端执行/);

assert.match(index, /anki-aligned-product-and-architecture-roadmap\.md/);
assert.match(index, /Preset V1A–V1C are Accepted \/ Production Closed/);
assert.match(index, /Settings UX-1 is Accepted under ADR-0027/);
assert.match(index, /broader cross-user\/cross-language V1D production-closure matrix remains open/);
assert.match(index, /ADR-0025-review-settings-preset-v1b-management\.md/);
assert.match(index, /ADR-0026-review-settings-preset-v1c-consumer-convergence\.md/);
assert.match(index, /ADR-0027-settings-advanced-tools-ux-state-model\.md/);
assert.match(index, /ADR-0023-settings-architecture-convergence\.md/);
assert.match(index, /ADR-0024-review-settings-preset-v1a-foundation\.md/);
assert.match(index, /review-settings-preset-v1-plan\.md/);
assert.match(index, /old “overall architecture closure 100%” statement is historical/);
assert.match(index, /§27\.8 — DevSpace PHP \/ PHPUnit 502 截断规则/);

assert.match(collaborationRules, /### 27\.8 DevSpace 执行 PHP 测试时的 502 截断规则/);
assert.match(collaborationRules, /工具传输失败/);
assert.match(collaborationRules, /不能直接认定为代码测试失败，也不能认定为测试通过/);
assert.match(collaborationRules, /默认只使用替代检测，禁止先运行原始高输出流式方案/);
assert.match(collaborationRules, /Feature 永远分组复核，不再运行 Feature 全量命令/);
assert.match(collaborationRules, /Feature 永远按文件批次或业务模块分组运行/);
assert.match(collaborationRules, /禁止执行 `php artisan test --testsuite=Feature`/);
assert.match(collaborationRules, /完整输出重定向到仓库忽略目录中的临时日志/);
assert.match(collaborationRules, /Feature 永远按文件批次或业务模块分组运行/);
assert.match(collaborationRules, /禁止执行 `php artisan test --testsuite=Feature`/);
assert.match(collaborationRules, /记录每组文件数、退出码、passed\/skipped\/assertions 摘要/);
assert.match(collaborationRules, /Incomplete \/ DevSpace PHP verification unavailable/);
assert.match(collaborationRules, /交给下一轮相关 Codex 复杂主任务执行，不再回退尝试原始 DevSpace 流式方案/);
assert.match(collaborationRules, /禁止为了绕开 502 使用 SQLite/);

assert.match(presetPlan, /Preset V1A — Default Preset Foundation and Transparent Binding/);
assert.match(presetPlan, /每个 `user_id \+ language_id` 只能绑定一个 Preset/);
assert.match(presetPlan, /Default Preset 可以修改和复制，不能重命名为其他名称，不能删除/);
assert.match(presetPlan, /Preset V1\.1 Leech Configuration Product Gate/);
assert.match(presetPlan, /ReviewSettingsResolver/);
assert.match(presetPlan, /不自动修改任何 `fsrs_due_at`/);
assert.match(presetPlan, /Preset V1B — Management Operations and UI/);
assert.match(presetPlan, /V1A–V1C Completed \/ Production Closed/);
assert.match(presetPlan, /Preset V1C — Multi-language Sharing and Consumer Convergence/);
assert.match(presetPlan, /Preset V1D — Settings UX and Production Closure/);
assert.match(presetPlan, /In Progress。Settings UX-1 已 Accepted/);
assert.match(presetPlan, /Settings UX-1 — Advanced Tools Diagnostic Empty-State and Action Safety：Completed \/ Accepted/);
assert.match(presetPlan, /fsrs_parameters_previous/);
assert.match(presetPlan, /状态更新为 \*\*Accepted \/ Production Closed\*\*/);

console.log('Master plan integrity contract passed.');
