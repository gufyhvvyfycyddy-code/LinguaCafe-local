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
const adr = read('docs/adr/ADR-0022-word-sense-pos-canonicalization-boundary.md');
const roadmap = read('docs/plans/anki-aligned-product-and-architecture-roadmap.md');
const index = read('docs/DOCUMENTATION_INDEX.md');
const collaborationRules = read('docs/plans/vibe-coding-collaboration-rules.md');

const masterAuthority = section(master, '> **Current authority — 2026-07-15**', '> **Custom Study 1A shared card update');
const openWork = section(master, '## 4. Open Work Registry（当前唯一开放工作账本）', '## 5. 颜色语义规则');
const executionOrder = section(master, '## 8. Anki 对齐产品与架构执行顺序', '## Recent Update: Codex-FinalArchitectureClosureTargetMode-1');
const handoffAuthority = section(handoff, '> **Current authority — 2026-07-15**', '> **Authoritative Custom Study status');
const manualClosure = section(handoff, '## Manual Sense shared form corrective follow-up (2026-07-15)', null);

assert.match(masterAuthority, /Manual Sense POS \+ shared create\/edit form \+ inline validation/);
assert.match(masterAuthority, /Current Next Task \| Settings architecture convergence/);
assert.match(masterAuthority, /6\.5\/10, localized high burden/);
assert.doesNotMatch(masterAuthority, /web acceptance pending/i);
assert.match(masterAuthority, /old “overall architecture closure 100%” statement is historical/);

assert.match(openWork, /Settings architecture convergence \| Planned \/ Current Next Task/);
assert.match(openWork, /FSRS-Anki-Mgmt-9 Preset V1 \| Planned/);
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
assert.match(roadmap, /Preset V1 绑定用户 \+ 语言/);
assert.match(roadmap, /Card Marker 参考 Anki Card Flag，落在 ReviewCard/);
assert.match(roadmap, /6\.5 \/ 10，局部高负担/);
assert.match(roadmap, /超过 1,000 行的生产文件不得继续无计划增加职责/);

assert.match(executionOrder, /Settings architecture convergence/);
assert.match(executionOrder, /Preset V1/);
assert.match(executionOrder, /Browser \/ ReviewCardManage convergence/);
assert.match(executionOrder, /Card Marker \+ Custom Study 1B/);
assert.doesNotMatch(executionOrder, /当前没有自动授权的下一产品任务|仍须由用户.*指定/);

assert.match(adr, /Accepted \/ Production Closed/);
assert.match(adr, /DevSpace5\/Chrome web acceptance are complete/);
assert.doesNotMatch(adr, /web acceptance remains pending|Production closure is not claimed/);

assert.match(handoffAuthority, /Current next task: \*\*Settings architecture convergence\*\*/);
assert.match(handoffAuthority, /6\.5\/10, localized high burden/);
assert.match(manualClosure, /Status: \*\*Accepted \/ Production Closed\*\*/);
assert.match(manualClosure, /All scenarios below were executed on 2026-07-15 and passed/);
assert.doesNotMatch(manualClosure, /web acceptance pending|待网页端执行/);

assert.match(index, /anki-aligned-product-and-architecture-roadmap\.md/);
assert.match(index, /old “overall architecture closure 100%” statement is historical/);
assert.match(index, /§27\.8 — DevSpace PHP \/ PHPUnit 502 截断规则/);

assert.match(collaborationRules, /### 27\.8 DevSpace 执行 PHP 测试时的 502 截断规则/);
assert.match(collaborationRules, /工具传输失败/);
assert.match(collaborationRules, /不能直接认定为代码测试失败，也不能认定为测试通过/);
assert.match(collaborationRules, /默认只使用替代检测，禁止先运行原始高输出流式方案/);
assert.match(collaborationRules, /完整输出重定向到仓库忽略目录中的临时日志/);
assert.match(collaborationRules, /将完整套件拆成 Unit、Feature 或按模块分组/);
assert.match(collaborationRules, /Incomplete \/ DevSpace PHP verification unavailable/);
assert.match(collaborationRules, /交给下一轮相关 Codex 复杂主任务执行，不再回退尝试原始 DevSpace 流式方案/);
assert.match(collaborationRules, /禁止为了绕开 502 使用 SQLite/);

console.log('Master plan integrity contract passed.');
