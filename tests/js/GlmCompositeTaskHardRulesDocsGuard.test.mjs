// GlmCompositeTaskHardRulesDocsGuard.test.mjs
//
// Task 2000-17 — Documentation guard for the composite-task hard rules
// canonicalization (§28 of vibe-coding-collaboration-rules.md).
//
// This guard verifies that the long-term hard rules written in
// docs/plans/vibe-coding-collaboration-rules.md §28 are present, carry the
// required semantic content, and that conflicting old complexity rules have
// been removed. It also enforces that the authoritative full text lives ONLY
// in the rules file (master plan / handoff / DOCUMENTATION_INDEX must NOT
// duplicate the full rule body — they may only carry short references).
//
// The guard checks semantics, not just section titles.

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
const MASTER_PLAN_PATH = join(
    __dirname, '..', '..',
    'docs', 'plans', 'linguacafe-master-plan.md'
);
const HANDOFF_PATH = join(
    __dirname, '..', '..',
    'docs', 'plans', 'current-working-handoff.md'
);
const DOC_INDEX_PATH = join(
    __dirname, '..', '..',
    'docs', 'DOCUMENTATION_INDEX.md'
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

function readSafe(p) {
    return existsSync(p) ? readFileSync(p, 'utf-8') : '';
}

const rulesSource = readSafe(RULES_PATH);
const masterPlanSource = readSafe(MASTER_PLAN_PATH);
const handoffSource = readSafe(HANDOFF_PATH);
const docIndexSource = readSafe(DOC_INDEX_PATH);

// --- Helpers ---------------------------------------------------------------

function hasSection(source, headerPattern) {
    return source.split('\n').some(line =>
        line.startsWith('## 28.') || line.startsWith('### 28.')
    );
}

function sectionBody(source, sectionNum) {
    const lines = source.split('\n');
    const startIdx = lines.findIndex(line =>
        line.startsWith(`## ${sectionNum}.`)
    );
    if (startIdx === -1) return '';
    // Find the next top-level "## " header after startIdx.
    let endIdx = lines.length;
    for (let i = startIdx + 1; i < lines.length; i++) {
        if (lines[i].startsWith('## ')) {
            endIdx = i;
            break;
        }
    }
    return lines.slice(startIdx, endIdx).join('\n');
}

// --- Tests -----------------------------------------------------------------

test('rules file exists', () => {
    assert.ok(rulesSource.length > 0, 'rules file is missing or empty');
});

// 1. 需求先落位，不默认立即实现
test('rule: requirements are placed first, not implemented by default', () => {
    const sec = sectionBody(rulesSource, 28);
    assert.ok(sec.length > 0, '§28 not found');
    assert.ok(
        sec.includes('不得默认把它变成下一轮立即实现的功能'),
        '§28 must state that new requirements are NOT immediately implemented'
    );
    assert.ok(
        sec.includes('登记未来位置，不等于下一步实现'),
        '§28 must state "write to plan = register future, not next step"'
    );
});

// 2. 安全、最低耦合、最低屎山程度判断
test('rule: placement must satisfy safety / low coupling / low mud', () => {
    const sec = sectionBody(rulesSource, 28);
    assert.ok(
        sec.includes('屎山和耦合增长最低'),
        '§28 must include "lowest mud and coupling growth" criterion'
    );
    assert.ok(
        sec.includes('不破坏当前模块边界'),
        '§28 must include "not break current module boundary" criterion'
    );
});

// 3. 每个正式主线任务同时包含 ARCH 和 DEV
test('rule: every formal main task includes both ARCH and DEV', () => {
    const sec = sectionBody(rulesSource, 28);
    assert.ok(
        sec.includes('ARCH-*') && sec.includes('DEV-*'),
        '§28 must reference ARCH-* and DEV-* task prefixes'
    );
    assert.ok(
        sec.includes('不能只开发功能而不检查架构'),
        '§28 must forbid dev-only tasks'
    );
    assert.ok(
        sec.includes('不能只写架构文档而完全不推进当前主线功能'),
        '§28 must forbid arch-only tasks'
    );
});

// 4. 不得为了架构任务制造无意义接口
test('rule: forbid fabricating meaningless interfaces for ARCH track', () => {
    const sec = sectionBody(rulesSource, 28);
    assert.ok(
        sec.includes('禁止为了满足"有架构任务"而制造无意义的新接口、新 DTO 或新 Service'),
        '§28 must forbid meaningless new interfaces/DTOs/Services just to satisfy ARCH track'
    );
});

// 5. 字幕架构核查规则
test('rule: subtitle architecture review exists', () => {
    const sec = sectionBody(rulesSource, 28);
    assert.ok(
        sec.includes('字幕架构核查规则'),
        '§28 must contain subtitle architecture review section'
    );
    assert.ok(
        sec.includes('必须检查与本轮架构问题相关的项目字幕'),
        '§28 must require checking relevant subtitle files for each composite task'
    );
});

// 6. 报告处理固定顺序
test('rule: report processing fixed order exists', () => {
    const sec = sectionBody(rulesSource, 28);
    assert.ok(
        sec.includes('报告处理与下一步规划流程'),
        '§28 must contain report processing section'
    );
    assert.ok(
        sec.includes('核查 GitHub 最新 master'),
        '§28 must require checking GitHub latest master, not just the report'
    );
});

// 7. 即使没有用户新需求也必须查看大计划
test('rule: must review master plan even without new user requirements', () => {
    const sec = sectionBody(rulesSource, 28);
    assert.ok(
        sec.includes('查看总控大计划，即使用户没有提出新要求也必须查看'),
        '§28 must require reviewing master plan even when user has no new request'
    );
});

// 8. 下一提示词必须是复合任务
test('rule: next prompt must be composite task', () => {
    const sec = sectionBody(rulesSource, 28);
    assert.ok(
        sec.includes('下一步提示词构成规则'),
        '§28 must contain next-prompt composition section'
    );
    assert.ok(
        sec.includes('每个下一步提示词都是复合型任务'),
        '§28 must state next prompt is composite task'
    );
});

// 9. 测试失败后修复并重跑的闭环
test('rule: test failure must be fixed and rerun, not hidden', () => {
    const sec = sectionBody(rulesSource, 28);
    assert.ok(
        sec.includes('循环直到通过'),
        '§28 must require fix-rerun loop until tests pass'
    );
    assert.ok(
        sec.includes('禁止把失败测试隐藏、删除或改弱以换取通过'),
        '§28 must forbid hiding/deleting/weakening failing tests'
    );
});

// 10. GLM 最低复杂度 100
test('rule: GLM minimum complexity 100', () => {
    const sec19 = sectionBody(rulesSource, 19);
    assert.ok(
        sec19.includes('所有 GLM 任务的最低复杂度为 100'),
        '§19 must state minimum complexity 100'
    );
    assert.ok(
        sec19.includes('100 是最低基线，不是最高上限'),
        '§19 must state 100 is baseline floor, not ceiling'
    );
});

// 11. 不存在当前有效的"普通任务上限 20"
test('rule: no active "complexity 20 upper limit" phrasing', () => {
    // The old §19.1 text "复杂度 20 是普通主线任务的常用上限" must be gone
    // from the current rules. It may appear in historical/archived material,
    // but not as an active rule in §19.
    const sec19 = sectionBody(rulesSource, 19);
    assert.ok(
        !sec19.includes('复杂度 20 是普通主线任务的常用上限'),
        '§19 must NOT contain active "complexity 20 is common upper limit" phrasing'
    );
    // The §19.3 must explicitly note this phrasing is abolished.
    assert.ok(
        sec19.includes('已废止'),
        '§19 must mark the old "20 upper limit" / "100 upper limit" as abolished'
    );
});

// 12. 不存在当前有效的"复杂度 100 是上限"
test('rule: no active "complexity 100 is upper limit" phrasing', () => {
    const sec19 = sectionBody(rulesSource, 19);
    assert.ok(
        !sec19.includes('复杂度 100 是大型复合型主线任务的上限'),
        '§19 must NOT contain active "complexity 100 is upper limit" phrasing'
    );
    // §8.8 must be reduced to a short reference, not the full rule body.
    const sec88Line = rulesSource.split('\n').find(line =>
        line.startsWith('### 8.8')
    );
    assert.ok(sec88Line, '§8.8 header must still exist');
    // §8.8 should now point to §19 as the authoritative source.
    const sec88Idx = rulesSource.indexOf('### 8.8');
    const sec88End = rulesSource.indexOf('## 9.', sec88Idx);
    const sec88Body = rulesSource.slice(sec88Idx, sec88End);
    assert.ok(
        sec88Body.includes('§19') && sec88Body.includes('短引用'),
        '§8.8 must be a short reference pointing to §19, not a duplicate full body'
    );
});

// 13. 权威规则全文只在协作规则中存在，master plan / handoff 只引用
test('rule: authoritative full text only in rules file, others only reference', () => {
    // The §28 full body must NOT be duplicated verbatim in master plan / handoff / doc index.
    // Those files should carry a short reference / pointer only.
    const sec28 = sectionBody(rulesSource, 28);
    const sampleUniquePhrase = '屎山和耦合增长最低';
    // The unique phrase must appear in the rules file (already asserted above).
    // It must NOT appear in master plan / handoff / doc index as part of a duplicated full body.
    // (A short reference that happens to quote a few words is acceptable, but the full
    // multi-section §28 body should not be copied.)
    const masterPlanHasFullBody =
        masterPlanSource.includes('### 28.1') &&
        masterPlanSource.includes('### 28.6');
    const handoffHasFullBody =
        handoffSource.includes('### 28.1') &&
        handoffSource.includes('### 28.6');
    const docIndexHasFullBody =
        docIndexSource.includes('### 28.1') &&
        docIndexSource.includes('### 28.6');
    assert.ok(
        !masterPlanHasFullBody,
        'master plan must NOT duplicate §28 full subsection body'
    );
    assert.ok(
        !handoffHasFullBody,
        'handoff must NOT duplicate §28 full subsection body'
    );
    assert.ok(
        !docIndexHasFullBody,
        'DOCUMENTATION_INDEX must NOT duplicate §28 full subsection body'
    );
    // They should carry at least a short pointer.
    assert.ok(
        masterPlanSource.includes('§28') || masterPlanSource.includes('vibe-coding-collaboration-rules.md'),
        'master plan must reference §28 or the rules file'
    );
    assert.ok(
        handoffSource.includes('§28') || handoffSource.includes('vibe-coding-collaboration-rules.md'),
        'handoff must reference §28 or the rules file'
    );
});

// 14. 不出现 CodeBuddy / WorkBuddy 重新启用
test('rule: no CodeBuddy / WorkBuddy reactivation in §28', () => {
    const sec28 = sectionBody(rulesSource, 28);
    assert.ok(
        !sec28.includes('重新启用 CodeBuddy') && !sec28.includes('重新启用 WorkBuddy'),
        '§28 must not reactivate CodeBuddy/WorkBuddy'
    );
    // The whole rules file must not re-enable them as the current workflow.
    // (Historical/archive references are still allowed and covered by the
    // other guard GlmSingleAgentWorkflowDocsGuard.test.mjs.)
    assert.ok(
        rulesSource.includes('GLM 单 Agent 闭环'),
        'Current workflow must remain GLM single-agent'
    );
});

// 15. 不出现"用户新需求默认下一轮实现"
test('rule: no "user new requirement defaults to next-round implementation"', () => {
    const sec28 = sectionBody(rulesSource, 28);
    // The rule must explicitly forbid this default.
    assert.ok(
        sec28.includes('不得默认把它变成下一轮立即实现的功能'),
        '§28 must explicitly forbid defaulting new requirement to next round'
    );
    // It must NOT contain a contradictory active statement that user requests
    // are implemented next round by default.
    const contradictory = '用户新需求默认下一轮实现';
    assert.ok(
        !sec28.includes(contradictory),
        '§28 must NOT contain contradictory "user new requirement defaults to next round" phrasing'
    );
});

console.log(`\n${passed} tests passed.`);
