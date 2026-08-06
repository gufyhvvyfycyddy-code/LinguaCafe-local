import assert from 'node:assert/strict';
import fs from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (...parts) => fs.readFileSync(join(root, ...parts), 'utf8');

const agents = read('AGENTS.md');
const adr31 = read('docs', 'adr', 'ADR-0031-goal-mode-roadmap-execution-authorization.md');
const adr33 = read('docs', 'adr', 'ADR-0033-real-browser-acceptance-channel-fallback.md');
const adr34 = read('docs', 'adr', 'ADR-0034-goal-mode-autonomous-decisions-and-deferred-acceptance.md');
const adr37 = read('docs', 'adr', 'ADR-0037-goal-mode-nonblocking-execution-frontier.md');
const adr52 = read('docs', 'adr', 'ADR-0052-goal-mode-deferred-evidence-clusters.md');
const adr53 = read('docs', 'adr', 'ADR-0053-testing-emulator-credential-transport.md');
const roadmap = read(
  'docs',
  'plans',
  'cloud-first-mobile-product-and-technical-milestones-2026-07-28.md',
);
const collaboration = read('docs', 'plans', 'vibe-coding-collaboration-rules.md');
const browserPlaybook = read('docs', 'plans', 'mcp-chrome-local-smoke-playbook.md');
const current = read('docs', 'CURRENT_AI_CONTEXT.md');
const acceptance = read(
  'docs',
  'testing',
  'mobile-api-foundation-acceptance-2026-07-28.md',
);

// Authority conflicts must be scoped before they can stop a persistent goal.
assert.match(agents, /按范围、阶段、supersession 和本节权威层级消解/);
assert.match(agents, /同一最高有效权威内仍存在/);
assert.match(adr34, /不同范围或不同阶段/);
assert.match(adr34, /判定某文件过时不等于获得修改该文件的授权/);

// Delegated choices are bounded and cannot self-authorize safety/product expansion.
for (const source of [agents, adr34, collaboration]) {
  assert.match(source, /Anki 官方/);
  assert.match(source, /最小.*可逆/);
}
assert.match(adr34, /现有代码和 Anki 只提供证据/);
assert.match(adr34, /不得用 Agent 自写的\s+Architecture Gate/);
assert.match(agents, /不得借此把整个里程碑变成开放范围/);
assert.match(agents, /脏工作区本身不构成阻断/);
assert.match(adr37, /读取目标文件和其当前 diff/);

// Slice documents faithfully expanding the accepted goal do not create a new human gate.
for (const source of [agents, adr31, adr37]) {
  assert.match(source, /Accepted under current goal authorization/);
}
assert.match(adr37, /不是 Agent 自行批准新的产品\s*边界/);

// Local acceptance identity is testing-only, least-privilege, and credential-safe.
for (const source of [agents, adr33, adr34, browserPlaybook]) {
  assert.match(source, /专用 testing 数据库/);
  assert.match(source, /最小权限/);
  assert.match(source, /正常.*登录|正常 UI|正常认证入口/);
  assert.match(source, /不得在\s*普通开发数据库创建[\s\S]{0,40}(?:直接删号|直接数据库删除)/);
}
assert.match(adr34, /codex-acceptance-<task-marker>@example\.test/);
assert.match(adr34, /密码不得进入仓库、shell 参数、命令输出/);
assert.match(agents, /ADR-0053[\s\S]{0,240}scoped ADB/);
assert.match(adr53, /no full password literal appears in the command source or a process argument/);
assert.match(adr53, /ordinary UI key events one character at a time/);
assert.match(adr53, /does not authorize existing credentials/);
for (const source of [agents, adr33, adr34, browserPlaybook]) {
  assert.match(source, /提供的.*测试账号[\s\S]{0,120}(?:专用 testing 数据库|testing 数据库)[\s\S]{0,80}权限/);
}

// Repository rules must never launder an explicit platform safety refusal.
for (const source of [agents, adr33, adr34, collaboration, browserPlaybook]) {
  assert.match(source, /禁止.*alternate surface|明确禁止.*替代通道|平台安全拒绝/);
}
assert.match(adr34, /仓库 ADR 只能选择\s+平台允许的工具/);
assert.match(adr33, /没有明确禁止同一结果的替代通道/);
assert.match(acceptance, /recorded explicit platform\s+prohibition/);

// Deferred acceptance enables only proven-independent work and never becomes completion.
for (const source of [agents, adr34, roadmap, collaboration, acceptance]) {
  assert.match(source, /Acceptance Deferred — Not Complete/);
}
assert.match(current, /当前唯一未完成的产品能力簇仍是 M9 iOS sync\/Xcode\/签名\/模拟器\/真机\/TestFlight\/App Store/);
assert.match(current, /sync 前必须清除旧 generated bundle 与 sourcemap/);
assert.match(adr34, /不是 Accept、Closed、Completed/);
assert.match(adr34, /不使用“每条路径最多一个 deferred”的机械预算/);
assert.match(adr34, /不能消费或假设缺失行为正确/);
assert.match(adr34, /不得通过改名为“外部开放项”/);
assert.match(agents, /最终目标完成审计必须清零全部 deferred 能力簇/);
assert.match(agents, /不设机械的“每条路径最多一个 deferred”预算/);
assert.match(adr37, /只有此时才向用户请求具体动作/);
assert.match(adr37, /同一路径或同一能力簇可以登记多个缺失检查/);
assert.match(adr37, /不得用改名、handoff、签名待办、发布待办或 provider 待办逃避登记/);
assert.match(adr52, /supersedes ADR-0034 and ADR-0037/);
assert.match(adr52, /capability cluster/);
assert.match(adr52, /does not depend on the missing behavior being correct/);
assert.match(adr52, /Deferred is never Accepted, Closed or Complete/);
assert.match(collaboration, /能力簇累计/);
assert.match(adr37, /普通开发服务器.*只允许只读诊断/s);
assert.match(agents, /未提交用户资产放进权威层级自动消解/);
assert.match(agents, /单独运行的 PHPUnit\/testing DB 健康检查.*不能证明当前服务器/);
assert.match(adr37, /绑定同一监听 host\/port 和进程的 server-bound/);
assert.match(browserPlaybook, /普通 localhost 连接\/会话失败，且未禁止替代通道/);
assert.match(browserPlaybook, /明确禁止 workaround\/alternate surface.*本路径禁用/);
assert.match(roadmap, /M2 Architecture Gate/);
assert.doesNotMatch(roadmap, /每个里程碑完成后仍由网页端总设计师/);
assert.doesNotMatch(roadmap, /当前先执行 M1，不并行进入 M2—M18/);

// Existing destructive/external boundaries remain load-bearing.
for (const source of [agents, adr31, adr34]) {
  assert.match(source, /migrate:fresh/);
  assert.match(source, /真实.*用户数据|real-data|real AI providers/);
  assert.match(source, /部署|deployment/);
}
assert.match(agents, /不读取、修改或提交 `\.env`/);
assert.match(agents, /不绕过权限、认证、user\/language 隔离/);

console.log('Goal-mode autonomy documentation guard passed.');
