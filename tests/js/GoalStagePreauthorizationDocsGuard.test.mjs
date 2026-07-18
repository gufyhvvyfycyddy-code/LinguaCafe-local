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

assert.match(agents, /目标模式阶段预授权/);
assert.match(agents, /migration 执行/);
assert.match(agents, /FSRS.*ReviewLog.*WordSense/s);
assert.match(agents, /真实 AI/);
assert.match(gate, /ADR-0028/);
assert.match(goalAdr, /supersedes.*approval-repeat|取代.*重复确认/is);
assert.match(goalAdr, /Non-goal tasks|非目标任务/i);
assert.match(collaboration, /目标本身即构成.*实施授权/s);
assert.match(collaboration, /强制停止条件/);
assert.doesNotMatch(agents, /目标模式.*可绕过.*(?:migration|FSRS|ReviewLog|真实 AI)/s);

console.log('Goal stage preauthorization documentation guard passed.');
