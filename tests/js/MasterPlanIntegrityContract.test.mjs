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
const ruleLoadingAdr = read('docs/adr/ADR-0028-ai-development-rule-loading-and-document-status.md');
const roadmap = read('docs/plans/anki-aligned-product-and-architecture-roadmap.md');
const index = read('docs/DOCUMENTATION_INDEX.md');
const collaborationRules = read('docs/plans/vibe-coding-collaboration-rules.md');
const phpVerificationPlaybook = read('docs/plans/devspace-php-verification-playbook.md');
const rootAgents = read('AGENTS.md');
const domainContext = read('CONTEXT.md');
const aiRuleSystem = read('docs/architecture/ai-development-rule-system.md');
const subtitleSummary = read('docs/architecture/subtitle-guided-development-summary.md');
const historyIndex = read('docs/HISTORY_INDEX.md');
const presetPlan = read('docs/plans/review-settings-preset-v1-plan.md');

const masterAuthority = section(master, '> **Current authority — 2026-07-16**', '> **Custom Study 1A shared card update');
const openWork = section(master, '## 4. Open Work Registry（当前唯一开放工作账本）', '## 5. 颜色语义规则');
const executionOrder = section(master, '## 8. Anki 对齐产品与架构执行顺序', '## Recent Update: Codex-FinalArchitectureClosureTargetMode-1');
const handoffAuthority = section(handoff, '> **Current authority — 2026-07-16**', '> **Authoritative Custom Study status');
const manualClosure = section(handoff, '## Manual Sense shared form corrective follow-up (2026-07-15)', null);

assert.match(rootAgents, /docs\/architecture\/ai-development-rule-system\.md/);
assert.match(rootAgents, /docs\/architecture\/subtitle-guided-development-summary\.md/);
assert.match(rootAgents, /Do not load all plans, ADRs, reports, handoffs, or history by default/);
assert.match(domainContext, /EncounteredWord/);
assert.match(domainContext, /WordSenseOccurrence/);
assert.match(domainContext, /ReviewCard/);
assert.match(domainContext, /ReviewLog/);
assert.match(aiRuleSystem, /Current \/ Authoritative hard-rule system/);
assert.match(aiRuleSystem, /Document Status Model/);
assert.match(aiRuleSystem, /Progressive Task Loading/);
assert.match(aiRuleSystem, /Local Architecture Preflight/);
assert.match(aiRuleSystem, /Interfaces, Compatibility, And Migrations/);
assert.match(aiRuleSystem, /Hard-Rule Admission And Guard Economics/);
assert.match(subtitleSummary, /raw subtitle count: 9 files/);
assert.match(subtitleSummary, /LinguaCafe is now a long-lived project/);
assert.match(historyIndex, /Downgraded Operational Appendices/);
assert.match(collaborationRules, /Detailed legacy operational appendix/);
assert.match(collaborationRules, /cites that exact section/);
assert.match(master, /Loading gate — 2026-07-17/);
assert.match(handoff, /Loading gate — 2026-07-17/);

assert.match(masterAuthority, /Settings architecture convergence/);
assert.match(masterAuthority, /Production Closed[^\n]*Preset V1A[^\n]*Preset V1B[^\n]*Preset V1C[^\n]*Preset V1D/);
assert.match(masterAuthority, /Current Phase \| Phase 3–7 authorized scope closed; AI Study Card lifecycle\/package\/generation\/source-binding and disabled-provider preflight Accepted under ADR-0032; real provider deliberately disabled; Browser\/ReviewCardManage Phase 3C-3 Delete Mutation Family remains Planned \/ Not Authorized/);
assert.match(masterAuthority, /28 production files over 500 lines, 10 over 1,000, and 1 over 1,500/);
assert.match(masterAuthority, /6\.0\/10, localized medium-high burden/);
assert.doesNotMatch(masterAuthority, /Preset V1A[^\n]*Web Acceptance Pending/);
assert.match(masterAuthority, /old “overall architecture closure 100%” statement is historical/);

assert.match(openWork, /Settings architecture convergence \| Completed \/ Production Closed/);
assert.match(openWork, /FSRS-Anki-Mgmt-9 Preset V1A \| Completed \/ Production Closed/);
assert.match(openWork, /Preset V1B — Management Operations and UI \| Completed \/ Production Closed/);
assert.match(openWork, /Preset V1C — Consumer Convergence \| Completed \/ Production Closed/);
assert.match(openWork, /Preset V1D — Settings UX and Production Closure \| Completed \/ Production Closed/);
assert.match(openWork, /fsrs_parameters_previous/);
assert.match(openWork, /Settings UX-1/);
assert.match(openWork, /Browser \/ ReviewCardManage architecture convergence \| Completed \/ Production Closed for authorized scope/);
assert.match(openWork, /Phase 3A Card Info[^\n]*Phase 3D Container Closure 均已接受/);
assert.match(openWork, /Card Marker \+ Custom Study 1B \| Accepted \/ Production Closed/);
assert.match(openWork, /Real AI provider \/ automatic chapter analysis \| Deferred by user decision/);
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
    'Phase 7：AI Study Card service 收敛与禁用 provider 预检',
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
assert.match(roadmap, /Phase 2：Preset V1[\s\S]*V1A–V1D Completed \/ Accepted \/ Production Closed/);
assert.match(roadmap, /Preset V1A–V1D[^\n]*已生产关闭/);
assert.match(roadmap, /V1D — Completed \/ Production Closed/);
assert.match(roadmap, /Settings UX-1/);
assert.match(roadmap, /6\.0 \/ 10，局部中高负担/);
assert.match(roadmap, /28 个生产文件超过 500 行/);
assert.match(roadmap, /10 个生产文件超过 1,000 行/);
assert.match(roadmap, /1 个生产文件超过 1,500 行/);
assert.doesNotMatch(roadmap, /\| 1 \| Settings 架构收敛 \| Preset 的前置地基/);
assert.match(roadmap, /超过 1,000 行的生产文件不得继续无计划增加职责/);

assert.match(executionOrder, /Settings architecture convergence/);
assert.match(executionOrder, /Preset V1/);
assert.match(executionOrder, /Completed \/ Production Closed[\s\S]*双用户、English\/French/);
assert.match(executionOrder, /Completed \/ Production Closed for authorized scope/);
assert.match(executionOrder, /Phase 3C-3[^\n]*Planned \/ Not Authorized/);
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
assert.match(settingsUxAdr, /Accepted — Settings UX-1 \/ Preset V1D Production Closed/);
assert.match(settingsUxAdr, /Pure presentation state module/);
assert.match(settingsUxAdr, /does not mutate its input/);
assert.match(settingsUxAdr, /broader cross-user and cross-language Preset acceptance matrix was completed/);
assert.doesNotMatch(settingsUxAdr, /production closure remains open|remains the current phase/);
assert.match(ruleLoadingAdr, /Accepted — 2026-07-17/);
assert.match(ruleLoadingAdr, /Short root entry/);
assert.match(ruleLoadingAdr, /progressive task loading/i);
assert.match(ruleLoadingAdr, /Detailed legacy operational appendix/);

assert.match(handoffAuthority, /Settings architecture convergence: \*\*Accepted \/ Production Closed\*\*/);
assert.match(handoffAuthority, /Preset V1A — Default Preset Foundation and Transparent Binding: \*\*Accepted \/ Production Closed\*\*/);
assert.match(handoffAuthority, /Preset V1B — Management Operations and UI: \*\*Accepted \/ Production Closed\*\*/);
assert.match(handoffAuthority, /Preset V1C — Consumer Convergence: \*\*Accepted \/ Production Closed\*\*/);
assert.match(handoffAuthority, /Preset V1D — Settings UX and Production Closure: \*\*Accepted \/ Production Closed\*\*/);
assert.match(handoffAuthority, /ReaderSidebar-Boundary-Fix-1: \*\*Accepted\*\*/);
assert.match(handoffAuthority, /6\.0\/10, localized medium-high burden/);
assert.match(manualClosure, /Status: \*\*Accepted \/ Production Closed\*\*/);
assert.match(manualClosure, /All scenarios below were executed on 2026-07-15 and passed/);
assert.doesNotMatch(manualClosure, /web acceptance pending|待网页端执行/);

assert.match(index, /AGENTS\.md/);
assert.match(index, /CONTEXT\.md/);
assert.match(index, /ai-development-rule-system\.md/);
assert.match(index, /subtitle-guided-development-summary\.md/);
assert.match(index, /detailed legacy operational appendix/i);
assert.match(index, /anki-aligned-product-and-architecture-roadmap\.md/);
assert.match(index, /Preset V1A[–-]V1D/);
assert.match(index, /ReaderDataService.*single reader-projection owner/);
assert.match(index, /Phase 3C-3 Delete Mutation Family is Planned \/ Not Authorized/);
assert.match(index, /AI Study Card lifecycle, package\/candidate, generation, source-binding, and disabled-provider preflight convergence is Accepted under ADR-0032; the user explicitly chose to keep the real provider disabled/);
assert.match(index, /review-card-manage-architecture-convergence-plan\.md/);
assert.doesNotMatch(index, /V1D production-closure matrix remains open|V1D broader production closure remains open/);
assert.match(index, /ADR-0025-review-settings-preset-v1b-management\.md/);
assert.match(index, /ADR-0026-review-settings-preset-v1c-consumer-convergence\.md/);
assert.match(index, /ADR-0027-settings-advanced-tools-ux-state-model\.md/);
assert.match(index, /ADR-0028-ai-development-rule-loading-and-document-status\.md/);
assert.match(index, /ADR-0023-settings-architecture-convergence\.md/);
assert.match(index, /ADR-0024-review-settings-preset-v1a-foundation\.md/);
assert.match(index, /review-settings-preset-v1-plan\.md/);
assert.match(index, /old “overall architecture closure 100%” statement is historical/);
assert.match(index, /devspace-php-verification-playbook\.md/);

assert.match(phpVerificationPlaybook, /Current verification playbook/);
assert.match(phpVerificationPlaybook, /Do not use a transport failure as evidence that tests passed or failed/);
assert.match(phpVerificationPlaybook, /preserving the real process exit code/);
assert.match(phpVerificationPlaybook, /Do not convert missing evidence into pass/);
assert.match(phpVerificationPlaybook, /split by relevant suite or test group without changing test behavior/);
assert.match(phpVerificationPlaybook, /Do not claim a full-suite pass from focused tests/);
assert.match(phpVerificationPlaybook, /Missing or untrustworthy completion evidence is `Incomplete`/);
assert.match(phpVerificationPlaybook, /Only the current user\/task contract may reduce the required verification scope/);

assert.match(presetPlan, /Preset V1A — Default Preset Foundation and Transparent Binding/);
assert.match(presetPlan, /每个 `user_id \+ language_id` 只能绑定一个 Preset/);
assert.match(presetPlan, /Default Preset 可以修改和复制，不能重命名为其他名称，不能删除/);
assert.match(presetPlan, /Preset V1\.1 Leech Configuration Product Gate/);
assert.match(presetPlan, /ReviewSettingsResolver/);
assert.match(presetPlan, /不自动修改任何 `fsrs_due_at`/);
assert.match(presetPlan, /Preset V1B — Management Operations and UI/);
assert.match(presetPlan, /Preset V1A–V1D Completed \/ Production Closed/);
assert.match(presetPlan, /Preset V1C — Multi-language Sharing and Consumer Convergence/);
assert.match(presetPlan, /Preset V1D — Settings UX and Production Closure/);
assert.match(presetPlan, /Completed \/ Accepted \/ Production Closed。Settings UX-1/);
assert.match(presetPlan, /ReviewLog=166/);
assert.match(presetPlan, /ReviewCard=95/);
assert.match(presetPlan, /Settings UX-1 — Advanced Tools Diagnostic Empty-State and Action Safety：Completed \/ Accepted/);
assert.match(presetPlan, /fsrs_parameters_previous/);
assert.match(presetPlan, /状态更新为 \*\*Accepted \/ Production Closed\*\*/);

console.log('Master plan integrity contract passed.');
