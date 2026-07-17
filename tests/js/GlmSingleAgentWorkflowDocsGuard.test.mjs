// Guards the transition from the former GLM/CodeBuddy/WorkBuddy workflow
// document to the current small-root + authoritative-rule-system layout.
// Historical role text may remain in the legacy appendix/archive, but it must
// not become active general instructions again.

import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);
const root = join(__dirname, '..', '..');

const CURRENT_RULES_PATH = join(root, 'docs', 'architecture', 'ai-development-rule-system.md');
const LEGACY_APPENDIX_PATH = join(root, 'docs', 'plans', 'vibe-coding-collaboration-rules.md');
const ARCHIVE_PATH = join(root, 'docs', 'history', 'codebuddy-workbuddy-workflow-archive-2026-07-13.md');

for (const path of [CURRENT_RULES_PATH, LEGACY_APPENDIX_PATH, ARCHIVE_PATH]) {
    assert.ok(existsSync(path), `required workflow document missing: ${path}`);
}

const currentRules = readFileSync(CURRENT_RULES_PATH, 'utf8');
const legacyAppendix = readFileSync(LEGACY_APPENDIX_PATH, 'utf8');
const archive = readFileSync(ARCHIVE_PATH, 'utf8');

const forbiddenActivePhrasings = [
    '仍然必须后置 CodeBuddy',
    '仍然必须后置 WorkBuddy',
    '只要安排 OpenCode，就必须同时安排 CodeBuddy',
    '给出 OpenCode / CodeBuddy / WorkBuddy 提示词',
    'CodeBuddy 仍然必须',
    'WorkBuddy 仍然必须',
    '是否需要 CodeBuddy / WorkBuddy 复核',
];

for (const phrase of forbiddenActivePhrasings) {
    assert.ok(!currentRules.includes(phrase), `forbidden active workflow phrase found in current rules: ${phrase}`);
}

assert.match(currentRules, /Current \/ Authoritative hard-rule system/);
assert.match(currentRules, /The local execution agent stops after the current task/);
assert.match(currentRules, /Ask the user only when the repository cannot answer/);

assert.match(legacyAppendix, /Detailed legacy operational appendix/);
assert.match(legacyAppendix, /no longer a default task entry/);
assert.match(legacyAppendix, /Current authority.*AGENTS\.md.*ai-development-rule-system\.md/s);
assert.match(legacyAppendix, /codebuddy-workbuddy-workflow-archive-2026-07-13\.md/);

const section14Line = legacyAppendix.split(/\r?\n/).find(line => line.startsWith('## 14.'));
const section18Line = legacyAppendix.split(/\r?\n/).find(line => line.startsWith('## 18.'));
assert.ok(section14Line && /(已停用|已归档|~~)/.test(section14Line), 'legacy §14 must remain explicitly discontinued');
assert.ok(section18Line && /(已停用|已归档|~~)/.test(section18Line), 'legacy §18 must remain explicitly discontinued');

assert.match(archive, /(已停用|Discontinued)/);
assert.match(archive, /CodeBuddy/);
assert.match(archive, /WorkBuddy/);

console.log('Current-vs-legacy workflow documentation guard passed.');
