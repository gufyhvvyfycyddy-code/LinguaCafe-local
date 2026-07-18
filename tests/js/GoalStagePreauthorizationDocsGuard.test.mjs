import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (...parts) => readFileSync(join(root, ...parts), 'utf8');
const agents = read('AGENTS.md');
const gate = read('docs', 'adr', 'ADR-0001-architecture-gate-workflow.md');
const goalAdr = read('docs', 'adr', 'ADR-0028-goal-mode-stage-preauthorization.md');
const collaboration = read('docs', 'plans', 'vibe-coding-collaboration-rules.md');

assert.ok(agents.includes('目标模式阶段预授权'));
assert.ok(agents.includes('当用户明确创建或恢复一个指向权威 roadmap 或有序里程碑的持续目标时'));
assert.ok(agents.includes('架构审查在目标点名范围内冻结当前切片的具体范围'));
assert.ok(agents.includes('仅用接受的计划、ADR、契约和测试作为约束与证据'));
assert.ok(agents.includes('不存在未解决的强制停止项时，实施授权才生效'));
assert.ok(agents.includes('完成相称验证和逐项完成审计后，方可进入下一切片'));
assert.ok(agents.includes('批准 migration 计划不等于批准 migration 执行'));
assert.ok(agents.includes('权威、范围、风险、数据处理、外发、成本假设或实现发生实质变化时必须重新确认'));
assert.match(agents, /破坏性或生产数据操作；migration 执行、新表或数据回填/);
assert.match(agents, /未冻结的数据模型或公开 API\/payload 语义/);
assert.match(agents, /FSRS、正式评分、ReviewLog、WordSense 绑定、ReviewCard 身份或 lifecycle 语义/);
assert.match(agents, /真实 AI provider、密钥、外发、付费、模型或成本上限/);
assert.match(agents, /多个未审查 seam；未批准的新 ADR；未决产品选择、权威冲突或目标外扩张/);

assert.ok(gate.includes('ADR-0028'));
assert.ok(gate.includes('明确持续目标按 ADR-0028 使用已冻结切片的阶段预授权'));
assert.ok(gate.includes('明确持续目标仅按 ADR-0028 在完成切片审计后继续'));

assert.match(goalAdr, /supersedes.*approval-repeat/is);
assert.ok(goalAdr.includes('Implementation authorization for a slice becomes active only after its architecture review freezes the exact scope'));
assert.ok(goalAdr.includes('inside the named roadmap or milestones'));
assert.ok(goalAdr.includes('only as constraints and evidence'));
assert.ok(goalAdr.includes('no unresolved mandatory stop applies'));
assert.ok(goalAdr.includes('Only the specific decision explicitly approved at a stop joins the active goal authorization'));
assert.ok(goalAdr.includes('approval of a migration plan does not by itself authorize migration execution'));
assert.ok(goalAdr.includes('authority, scope, risk, data handling, external transmission, cost assumptions, or implementation'));
assert.ok(goalAdr.includes('requirement-by-requirement completion audit'));
assert.ok(goalAdr.includes('Tasks without an explicit persistent goal retain ADR-0001\'s per-task confirmation rule'));
assert.ok(goalAdr.includes('destructive or production data action; migration execution, new table, or backfill'));
assert.ok(goalAdr.includes('unfrozen data-model or public interface semantics'));
assert.ok(goalAdr.includes('FSRS, formal rating, ReviewLog, WordSense binding, review-card identity, or lifecycle semantics'));
assert.ok(goalAdr.includes('real AI provider, secret, external transmission, paid usage, model, or cost limit'));
assert.ok(goalAdr.includes('multiple unreviewed seams; an unapproved ADR; an unresolved product choice or authority conflict; or work outside the named goal'));

assert.ok(collaboration.includes('架构审查在目标点名范围内冻结当前切片的具体范围'));
assert.ok(collaboration.includes('仅用接受的计划、ADR、契约和测试作为约束与证据'));
assert.ok(collaboration.includes('执行 Agent 完成相称验证和逐项完成审计后方可进入下一切片'));
assert.ok(collaboration.includes('强制停止条件'));
assert.ok(collaboration.includes('批准 migration 计划不等于批准 migration 执行'));
assert.ok(collaboration.includes('破坏性或生产数据操作；migration 执行、新表或数据回填'));
assert.ok(collaboration.includes('未冻结的数据模型或公开接口语义'));
assert.ok(collaboration.includes('FSRS、正式评分、ReviewLog、WordSense 绑定、ReviewCard 身份或 lifecycle 语义'));
assert.ok(collaboration.includes('真实 AI provider、密钥、外发、付费、模型或成本上限'));
assert.ok(collaboration.includes('多个未审查 seam；未批准的新 ADR；未决产品选择、权威冲突或目标外扩张'));

assert.doesNotMatch(agents, /以下情况还必须立即停止并请求新的确认：/);
assert.doesNotMatch(gate, /实施 — 用户确认后才能开始编码/);

console.log('Goal stage preauthorization documentation guard passed.');
