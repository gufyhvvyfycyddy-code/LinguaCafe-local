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

assert.match(agents, /本文件只放每次任务都值得加载、长期稳定、违反后代价高的规则/);
assert.match(agents, /所有高风险任务都必须先完成架构审查，再由用户明确确认后实施/);
assert.match(agents, /Vuex\/store 逻辑/);
assert.match(agents, /ReviewController::rateReviewCard[\s\S]{0,220}ReviewCardService::recordReview[\s\S]{0,120}FsrsSchedulingService::schedule/);
assert.match(agents, /修改 `AGENTS\.md` 必须获得用户明确授权/);
assert.match(agents, /不得用 SQLite 替代 testing MySQL/);

assert.match(rules, /纯文档、纯验证、单文件修复可以独立成任务/);
assert.match(rules, /不强制每个任务同时产生架构改造和功能代码/);
assert.match(rules, /不设最低复杂度、运行时长或百分比/);
assert.match(rules, /字幕是经验来源，不是项目权威/);
assert.match(rules, /完成当前任务后停止/);
assert.doesNotMatch(rules, /所有 GLM 任务的最低复杂度为 100/);
assert.doesNotMatch(rules, /每一个正式 GLM 主线任务必须[\s\S]{0,160}ARCH-/);

assert.match(index, /本文只负责路由/);
assert.match(index, /不默认读取全部计划、全部 ADR、全部历史或全部字幕/);
assert.match(index, /current-working-handoff\.md/);
assert.match(index, /ADR-0001-architecture-gate-workflow\.md/);
assert.doesNotMatch(index, /### 28\.1/);

console.log('Long-term rule convergence contract passed.');
