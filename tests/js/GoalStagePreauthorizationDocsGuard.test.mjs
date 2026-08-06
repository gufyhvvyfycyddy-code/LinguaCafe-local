import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (...parts) => readFileSync(join(root, ...parts), 'utf8');
const agents = read('AGENTS.md');
const gate = read('docs', 'adr', 'ADR-0001-architecture-gate-workflow.md');
const historicalGoalAdr = read('docs', 'adr', 'ADR-0028-goal-mode-stage-preauthorization.md');
const currentGoalAdr = read('docs', 'adr', 'ADR-0031-goal-mode-roadmap-execution-authorization.md');
const frontierAdr = read('docs', 'adr', 'ADR-0037-goal-mode-nonblocking-execution-frontier.md');
const collaboration = read('docs', 'plans', 'vibe-coding-collaboration-rules.md');

assert.ok(agents.includes('目标模式 roadmap 执行授权'));
assert.ok(agents.includes('架构审查和计划/ADR/契约必须先冻结当前切片'));
assert.ok(agents.includes('Accepted under current goal authorization'));
assert.ok(agents.includes('完成相称验证和逐项完成审计后可自动进入下一命名里程碑'));
assert.match(agents, /additive migration 文件、testing 专用数据库中的 schema 应用/);
assert.match(agents, /开发\/预发布\/生产或真实用户数据上的 migration 执行/);
assert.match(agents, /真实 AI provider、密钥、外发、付费、模型或成本上限/);
assert.match(agents, /部署、签名、商店提交/);

assert.ok(gate.includes('ADR-0031 supersedes ADR-0028'));
assert.ok(gate.includes('明确持续目标按 ADR-0031/ADR-0034/ADR-0037 使用已冻结切片的 roadmap 执行授权和决策梯'));
assert.ok(gate.includes('明确持续目标按 ADR-0031/ADR-0034/ADR-0037 在完成切片审计后继续'));

assert.match(historicalGoalAdr, /supersedes.*approval-repeat/is);
assert.ok(currentGoalAdr.includes('supersedes ADR-0028'));
assert.ok(currentGoalAdr.includes('additive migration files and new tables'));
assert.ok(currentGoalAdr.includes('automatic entry into the next named milestone'));
assert.ok(currentGoalAdr.includes('does not authorize `php artisan migrate`'));
assert.ok(currentGoalAdr.includes('deployment, application-store submission'));
assert.ok(frontierAdr.includes('只有此时才向用户请求具体动作'));
assert.ok(frontierAdr.includes('不是 Agent 自行批准新的产品'));

assert.ok(collaboration.includes('目标模式 roadmap 执行授权'));
assert.ok(collaboration.includes('执行 Agent 完成相称验证和逐项完成审计后可自动进入下一命名里程碑'));
assert.ok(collaboration.includes('testing 专用数据库 schema'));
assert.ok(collaboration.includes('开发/预发布/生产或真实用户数据上的 migration'));
assert.ok(collaboration.includes('真实 AI provider、密钥、外发、付费、模型或成本上限'));
assert.ok(collaboration.includes('部署、签名、商店提交'));

assert.doesNotMatch(agents, /以下情况还必须立即停止并请求新的确认：/);
assert.doesNotMatch(gate, /实施 — 用户确认后才能开始编码/);

console.log('Goal stage preauthorization documentation guard passed.');
