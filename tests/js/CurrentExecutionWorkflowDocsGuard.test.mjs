// CurrentExecutionWorkflowDocsGuard.test.mjs
//
// Current documentation guard for the active LinguaCafe execution workflow.
// It replaces the discontinued GLM single-agent guard.

import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const RULES_PATH = join(
    __dirname, '..', '..',
    'docs', 'plans', 'vibe-coding-collaboration-rules.md'
);
const ARCHIVE_PATH = join(
    __dirname, '..', '..',
    'docs', 'history',
    'codebuddy-workbuddy-workflow-archive-2026-07-13.md'
);

const rulesSource = existsSync(RULES_PATH)
    ? readFileSync(RULES_PATH, 'utf-8')
    : '';
const archiveSource = existsSync(ARCHIVE_PATH)
    ? readFileSync(ARCHIVE_PATH, 'utf-8')
    : '';

let passed = 0;
function test(name, fn) {
    try {
        fn();
        passed++;
        console.log(`  √ ${name}`);
    } catch (error) {
        console.error('FAIL: ' + name);
        console.error(error.message);
        process.exitCode = 1;
    }
}

const FORBIDDEN_ACTIVE_PHRASES = [
    '当前任务由一个主执行 Agent 对结果负责',
    '只要安排 OpenCode，就必须同时安排 CodeBuddy',
    '仍然必须后置 CodeBuddy',
    '仍然必须后置 WorkBuddy',
    '给出 OpenCode / CodeBuddy / WorkBuddy 提示词',
];

test('current rules file exists', () => {
    assert.ok(rulesSource.length > 0, 'rules file is missing or empty');
});

test('current rules name both active execution paths', () => {
    assert.ok(rulesSource.includes('本地 Codex 直接处理'));
    assert.ok(rulesSource.includes('网页端 GPT 通过 DevSpace 直接处理'));
});

test('current rules require FastCtx-first local work', () => {
    assert.ok(
        rulesSource.includes('优先使用 FastCtx'),
        'Current rules must require FastCtx-first file and command work'
    );
});

test('discontinued workflows are not active requirements', () => {
    const hits = FORBIDDEN_ACTIVE_PHRASES.filter((phrase) => rulesSource.includes(phrase));
    assert.deepEqual(hits, [], `Discontinued active workflow phrases found: ${hits.join('; ')}`);
});

test('parallel Codex++ workflow is clearly future-only', () => {
    assert.ok(rulesSource.includes('Codex++'));
    assert.ok(rulesSource.includes('未来方向'));
    assert.ok(rulesSource.includes('尚未完整实现'));
});

test('historical workflow archive remains available', () => {
    assert.ok(archiveSource.length > 0, 'archive file is missing or empty');
    assert.ok(
        archiveSource.includes('已停用') || archiveSource.includes('Discontinued'),
        'Archive must remain marked as discontinued'
    );
});

console.log(`
${passed} tests passed.`);
