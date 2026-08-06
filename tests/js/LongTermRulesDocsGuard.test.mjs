// Long-term rule convergence guard.

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = path => readFileSync(join(root, path), 'utf8');
const agents = read('AGENTS.md');
const rules = read('docs/plans/vibe-coding-collaboration-rules.md');
const index = read('docs/DOCUMENTATION_INDEX.md');
const historyIndex = read('docs/HISTORY_INDEX.md');
const productQaHandoff = read('docs/product-handoff-prompt.md');
const qaHandoff0719 = read('docs/testing-handoff-2026-07-19.md');
const qaHandoff0720 = read('docs/testing-handoff-2026-07-20.md');
const currentContext = read('docs/CURRENT_AI_CONTEXT.md');
const gitignore = read('.gitignore');
const browserPlaybook = read('docs/plans/mcp-chrome-local-smoke-playbook.md');
const browserFallbackAdr = read('docs/adr/ADR-0033-real-browser-acceptance-channel-fallback.md');

assert.match(agents, /本文件只放每次任务都值得加载、长期稳定、违反后代价高的规则/);
assert.match(agents, /所有高风险任务都必须先完成架构审查/);
assert.match(agents, /普通任务在用户明确确认后实施/);
assert.match(agents, /Vuex\/store 逻辑/);
assert.match(agents, /ReviewController::rateReviewCard[\s\S]{0,220}ReviewCardService::recordReview[\s\S]{0,120}FsrsSchedulingService::schedule/);
assert.match(agents, /修改 `AGENTS\.md` 必须获得用户明确授权/);
assert.match(agents, /不得用 SQLite 替代 testing MySQL/);
assert.match(agents, /真实浏览器验收[\s\S]{0,260}不绑定某一个工具/);
assert.match(agents, /Chrome DevTools\/MCP[\s\S]{0,160}Playwright[\s\S]{0,160}Computer Use/);
assert.match(agents, /单一工具失败不构成 `Incomplete`/);
assert.match(agents, /禁止用 fetch\/axios 直接调用写接口后声称完成了按钮验收/);

assert.match(rules, /纯文档、纯验证、单文件修复可以独立成任务/);
assert.match(rules, /不强制每个任务同时产生架构改造和功能代码/);
assert.match(rules, /不设最低复杂度、运行时长或百分比/);
assert.match(rules, /字幕是经验来源，不是项目权威/);
assert.match(rules, /普通任务完成后停止/);
assert.doesNotMatch(rules, /所有任务的最低复杂度为 100/);
assert.doesNotMatch(rules, /每一个正式主线任务必须[\s\S]{0,160}ARCH-/);
assert.match(rules, /单一工具拒绝 localhost 就提前停工/);

assert.match(browserPlaybook, /真实浏览器通道降级顺序/);
assert.match(browserPlaybook, /Playwright[\s\S]{0,180}Computer Use/);
assert.match(browserPlaybook, /全部平台允许的通道都实际尝试失败/);
assert.match(browserPlaybook, /明确禁止同一结果的 workaround/);
assert.match(browserPlaybook, /不得切换成 fetch\/axios 写接口来冒充页面操作/);
assert.match(browserFallbackAdr, /状态：Accepted/);
assert.match(browserFallbackAdr, /本地验收预授权/);
assert.match(browserFallbackAdr, /不授权[\s\S]{0,260}清库/);

assert.match(index, /本文只负责路由/);
assert.match(index, /不默认读取全部计划、全部 ADR、全部历史或全部字幕/);
assert.match(index, /current-working-handoff\.md/);
assert.match(index, /ADR-0001-architecture-gate-workflow\.md/);
assert.match(index, /ADR-0033-real-browser-acceptance-channel-fallback\.md/);
assert.match(index, /workspace-stabilization-plan-2026-08-03\.md/);
assert.match(index, /node scripts\/workspace-inventory\.mjs/);
assert.doesNotMatch(index, /### 28\.1/);

assert.match(historyIndex, /Historical QA Handoffs/);
assert.match(historyIndex, /docs\/product-handoff-prompt\.md/);
assert.match(historyIndex, /docs\/testing-handoff-2026-07-19\.md/);
assert.match(historyIndex, /docs\/testing-handoff-2026-07-20\.md/);
assert.match(historyIndex, /local Codex directly or webpage GPT through DevSpace/);
assert.doesNotMatch(historyIndex, /GLM 单 Agent 闭环规则 \(current\)/);
assert.match(productQaHandoff, /Historical QA handoff \/ Superseded/);
assert.match(productQaHandoff, /Do not execute this file as a current task prompt/);
assert.match(productQaHandoff, /#12`–`#18[\s\S]{0,100}#5`–`#11/);
assert.match(productQaHandoff, /不得再次提交“剩余 7 个问题”/);
for (const handoff of [qaHandoff0719, qaHandoff0720]) {
    assert.match(handoff, /Historical QA Snapshot/);
    assert.match(handoff, /2026-08-06/);
    assert.match(handoff, /重新验证|revalidation/);
}
assert.match(qaHandoff0720, /已提交，禁止重复创建/);

assert.match(currentContext, /本文件更新前，本地 `master`、`origin\/master` 与远端 `master` 对齐到/);
assert.match(currentContext, /执行新任务仍必须重新运行 Git preflight/);
assert.match(currentContext, /不得把本段 SHA 当作永久实时值/);
assert.match(currentContext, /工作区收口优先于继续扩大产品范围/);
assert.ok(
    currentContext.split(/\r?\n/).length <= 320,
    'CURRENT_AI_CONTEXT.md must remain a compact default context'
);

assert.match(gitignore, /\/\.playwright-cli\//);
assert.match(gitignore, /\/cookies\.txt/);
assert.match(gitignore, /\/_tmp_\*\.php/);
assert.match(gitignore, /\/audit_screenshots\//);

console.log('Long-term rule convergence contract passed.');
