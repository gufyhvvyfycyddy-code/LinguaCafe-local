// CurrentExecutionWorkflowDocsGuard.test.mjs
// Protect the current LinguaCafe fixed-DIRECT execution contract without
// freezing obsolete tool chains, dates, or model-independent prose.

import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);
const root = join(__dirname, '..', '..');
const rulesPath = join(root, 'docs', 'plans', 'vibe-coding-collaboration-rules.md');
const archivePath = join(root, 'docs', 'history', 'codebuddy-workbuddy-workflow-archive-2026-07-13.md');

const rules = existsSync(rulesPath) ? readFileSync(rulesPath, 'utf-8') : '';
const archive = existsSync(archivePath) ? readFileSync(archivePath, 'utf-8') : '';

const required = [
    'fixed DIRECT',
    'GPT-5.6 Sol',
    'opencode/deepseek-v4-flash-free',
    'opencode/mimo-v2.5-free',
    'opencode-go/mimo-v2.5',
    'FastCtx',
    '用户启动下一批次后才进入下一实现任务',
    'Codex 新任务只有用户当前明确点名授权时才能创建',
];

assert.ok(rules.length > 0, 'current collaboration rules are missing');
for (const phrase of required) {
    assert.ok(rules.includes(phrase), `current workflow contract missing: ${phrase}`);
}

assert.ok(
    rules.includes('只有真实 predecessor 才等待') || rules.includes('只有真实 predecessor 才等待。'),
    'fixed DIRECT windows must wait only on real dependencies',
);
assert.ok(
    rules.includes('两个 OpenCode free') && rules.includes('Reasonix'),
    'paid Reasonix must remain behind both free OpenCode routes',
);
assert.ok(
    rules.includes('CodeBuddy / WorkBuddy 旧接力式“必须出现”规则不再作为当前默认流程'),
    'old mandatory CodeBuddy/WorkBuddy relay must not remain current',
);

for (const forbidden of [
    '只要安排 OpenCode，就必须同时安排 CodeBuddy',
    '仍然必须后置 CodeBuddy',
    '仍然必须后置 WorkBuddy',
    '当前任务由一个主执行 Agent 对结果负责',
]) {
    assert.ok(!rules.includes(forbidden), `obsolete workflow requirement remains active: ${forbidden}`);
}

assert.ok(archive.length > 0, 'historical workflow archive is missing');
assert.ok(
    archive.includes('已停用') || archive.includes('Discontinued'),
    'historical workflow archive must remain clearly non-current',
);

console.log('Current execution workflow docs guard passed.');
