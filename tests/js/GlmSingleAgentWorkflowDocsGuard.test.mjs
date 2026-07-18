// GlmSingleAgentWorkflowDocsGuard.test.mjs
//
// Task 2000-12 Track A (GOV-4) — Documentation guard for the GLM single-agent
// workflow migration.
//
// After the CodeBuddy / WorkBuddy workflow was formally discontinued
// (2026-07-13), the active workflow rules in
// docs/plans/vibe-coding-collaboration-rules.md were rewritten. The old
// rule bodies were migrated to
// docs/history/codebuddy-workbuddy-workflow-archive-2026-07-13.md.
//
// This guard prevents regressions that would re-introduce active
// CodeBuddy / WorkBuddy commands into the current rules file. Historical
// references and archive pointers are allowed; commands that would be
// executed by an agent are not.
//
// Forbidden active phrasings in the CURRENT rules file:
//   * "仍然必须后置 CodeBuddy"
//   * "仍然必须后置 WorkBuddy"
//   * "只要安排 OpenCode，就必须同时安排 CodeBuddy"
//   * "给出 OpenCode / CodeBuddy / WorkBuddy 提示词"
//   * "CodeBuddy 仍然必须"
//   * "WorkBuddy 仍然必须"
//   * "是否需要 CodeBuddy / WorkBuddy 复核"
//
// The archive file under docs/history/ is exempt, but must:
//   1. Live under docs/history/.
//   2. Contain an explicit "已停用" / "discontinued" marker.

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

let passed = 0;
function test(name, fn) {
    try {
        fn();
        passed++;
        console.log(`  \u221a ${name}`);
    } catch (e) {
        console.error('FAIL: ' + name);
        console.error(e.message);
        process.exitCode = 1;
    }
}

const rulesSource = existsSync(RULES_PATH)
    ? readFileSync(RULES_PATH, 'utf-8')
    : '';
const archiveSource = existsSync(ARCHIVE_PATH)
    ? readFileSync(ARCHIVE_PATH, 'utf-8')
    : '';

// --- Forbidden active commands in the current rules file ---

const FORBIDDEN_PHRASINGS = [
    '仍然必须后置 CodeBuddy',
    '仍然必须后置 WorkBuddy',
    '只要安排 OpenCode，就必须同时安排 CodeBuddy',
    '给出 OpenCode / CodeBuddy / WorkBuddy 提示词',
    'CodeBuddy 仍然必须',
    'WorkBuddy 仍然必须',
    '是否需要 CodeBuddy / WorkBuddy 复核',
];

test('current rules file exists', () => {
    assert.ok(rulesSource.length > 0, 'rules file is missing or empty');
});

test('no forbidden active CodeBuddy/WorkBuddy phrasings in current rules', () => {
    const hits = [];
    for (const phrase of FORBIDDEN_PHRASINGS) {
        if (rulesSource.includes(phrase)) {
            hits.push(phrase);
        }
    }
    assert.deepEqual(
        hits, [],
        `Forbidden active phrasings found in current rules: ${hits.join('; ')}`
    );
});

test('current rules assign one main agent responsibility without reviving old roles', () => {
    assert.ok(
        rulesSource.includes('一个主执行 Agent 对结果负责'),
        'Current rules must assign one main executing agent responsibility'
    );
});

test('current rules mark old role workflows as historical', () => {
    assert.ok(
        rulesSource.includes('旧 CodeBuddy / WorkBuddy / GLM 接力流程仅是历史，不再生效'),
        'Current rules must explicitly de-activate old role workflows'
    );
});

test('archive file exists under docs/history/', () => {
    assert.ok(archiveSource.length > 0, 'archive file is missing or empty');
});

test('archive file contains explicit discontinued marker', () => {
    assert.ok(
        archiveSource.includes('已停用') || archiveSource.includes('Discontinued'),
        'Archive must contain explicit "已停用" or "Discontinued" marker'
    );
});

test('archive file preserves old workflow rule text (CodeBuddy role)', () => {
    // The archive must preserve enough historical context that the old
    // rule bodies are discoverable, but only inside the archive.
    assert.ok(
        archiveSource.includes('CodeBuddy'),
        'Archive must preserve CodeBuddy role text'
    );
    assert.ok(
        archiveSource.includes('WorkBuddy'),
        'Archive must preserve WorkBuddy role text'
    );
});

console.log(`\n${passed} tests passed.`);
