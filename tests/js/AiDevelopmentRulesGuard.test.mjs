import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname.replace(/^\/(.:)/, '$1')), '../..');
const read = relativePath => fs.readFileSync(path.join(root, relativePath), 'utf8');
const exists = relativePath => fs.existsSync(path.join(root, relativePath));
const lineCount = source => source.split(/\r?\n/).length;

const requiredFiles = [
    'AGENTS.md',
    'CLAUDE.md',
    'CONTEXT.md',
    'docs/DOCUMENTATION_INDEX.md',
    'docs/HISTORY_INDEX.md',
    'docs/architecture/ai-development-rule-system.md',
    'docs/architecture/subtitle-guided-development-summary.md',
    'docs/architecture/improve-codebase-architecture-install-report.md',
    'docs/adr/ADR-0028-ai-development-rule-loading-and-document-status.md',
    'docs/plans/current-working-handoff.md',
    'docs/plans/linguacafe-master-plan.md',
    'docs/plans/devspace-php-verification-playbook.md',
    'docs/plans/vibe-coding-collaboration-rules.md',
];

for (const file of requiredFiles) {
    assert.ok(exists(file), `required AI rule-system file missing: ${file}`);
}

const agents = read('AGENTS.md');
const claude = read('CLAUDE.md');
const context = read('CONTEXT.md');
const rules = read('docs/architecture/ai-development-rule-system.md');
const subtitles = read('docs/architecture/subtitle-guided-development-summary.md');
const skillInstallReport = read('docs/architecture/improve-codebase-architecture-install-report.md');
const index = read('docs/DOCUMENTATION_INDEX.md');
const history = read('docs/HISTORY_INDEX.md');
const ruleAdr = read('docs/adr/ADR-0028-ai-development-rule-loading-and-document-status.md');
const handoff = read('docs/plans/current-working-handoff.md');
const masterPlan = read('docs/plans/linguacafe-master-plan.md');
const phpVerification = read('docs/plans/devspace-php-verification-playbook.md');
const legacy = read('docs/plans/vibe-coding-collaboration-rules.md');

assert.ok(lineCount(agents) <= 130, `AGENTS.md must remain a short root entry; found ${lineCount(agents)} lines`);
assert.match(agents, /Project Goal/);
assert.match(agents, /Rule Priority/);
assert.match(agents, /Required Task Entry/);
assert.match(agents, /Safety And Protected State/);
assert.match(agents, /docs\/architecture\/ai-development-rule-system\.md/);
assert.match(agents, /docs\/architecture\/subtitle-guided-development-summary\.md/);
assert.match(agents, /CONTEXT\.md/);
assert.match(agents, /Do not load all plans, ADRs, reports, handoffs, or history by default/);
assert.match(agents, /Do not create new legacy word cards/);
assert.match(agents, /Do not commit or push unless the current task explicitly asks for it/);
assert.match(agents, /git add \.|git add -A/);
assert.match(agents, /migrate:fresh/);
assert.match(agents, /convenience hooks as task authorization when they conflict with current task or project safety rules/i);
assert.match(agents, /Local Browser Acceptance Account/);
assert.match(agents, /Only on `http:\/\/127\.0\.0\.1` or `http:\/\/localhost`/);
assert.match(agents, /If the email is absent, create it through the normal local registration flow/);
assert.match(agents, /preserving its ID and learning data/);
assert.match(agents, /never valid for a remote host or production/i);
const taskEntry = agents.slice(agents.indexOf('## 3. Required Task Entry'), agents.indexOf('## 4. Development Flow'));
assert.ok(taskEntry.indexOf('relevant local Skill') < taskEntry.indexOf('ai-development-rule-system.md'), 'AGENTS task entry must load the relevant Skill before the detailed rule system');
assert.ok(taskEntry.indexOf('ai-development-rule-system.md') < taskEntry.indexOf('Check branch'), 'AGENTS task entry must load the detailed rule system before Git/worktree inspection');
assert.match(taskEntry, /smallest task route/i);
assert.match(taskEntry, /master plan\/roadmap only when sequencing is part of the task/i);
assert.match(taskEntry, /Stop loading context once ownership, scope, contracts, and verification are resolved/i);
assert.doesNotMatch(agents, /GLM 1000%|## 27\.|ReviewFsrsTest|FsrsSchedulingServiceTest/);

assert.match(claude, /^# Claude Project Entry/m);
assert.match(claude, /@AGENTS\.md/);
assert.ok(lineCount(claude) <= 8, 'CLAUDE.md must only point to the root rules');

for (const term of ['EncounteredWord', 'WordSense', 'WordSenseOccurrence', 'ReviewCard', 'ReviewLog']) {
    assert.match(context, new RegExp(term), `CONTEXT.md missing canonical term: ${term}`);
}
assert.match(context, /Reading familiarity versus formal review/);
assert.match(context, /Sense status versus card lifecycle/);
assert.match(context, /not universal task context/i);
assert.match(context, /target_type=sense/);
assert.match(context, /target_type=word.*legacy compatibility/s);

for (const status of [
    'Current fact',
    'Stable spec/module contract',
    'ADR',
    'Temporary implementation plan',
    'Acceptance report/playbook',
    'History/superseded',
]) {
    assert.match(rules, new RegExp(status.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), `rule system missing document status: ${status}`);
}

for (const skill of [
    'subtitle-guided-project-development',
    'domain-modeling',
    'improve-codebase-architecture',
    'codebase-design',
    'api-and-interface-design',
    'documentation-and-adrs',
    'grilling',
    'code-review-and-quality',
]) {
    assert.match(rules, new RegExp(`\\b${skill}\\b`), `rule system missing Skill routing: ${skill}`);
}

assert.match(rules, /Local Architecture Preflight/);
assert.match(rules, /Which business\/module owner receives the behavior/);
assert.match(rules, /Does the task add or change an observable interface/);
assert.match(rules, /Feature delivery and broad refactoring are separate/);
assert.match(rules, /Prefer additive, backward-compatible changes/);
assert.match(rules, /Breaking changes require explicit authorization/);
assert.match(rules, /Documentation explains a rule; a harness makes it harder to violate/);
assert.match(rules, /“done”, “looks correct”, “should work”/);
assert.match(rules, /Context budget and stop rule/);
assert.match(rules, /not a universal bundle/);
assert.match(rules, /Hard-Rule Admission And Guard Economics/);
for (const admissionField of ['Trigger and scope', 'Required or forbidden action', 'Evidence or failure signal', 'Exception owner', 'Storage level']) {
    assert.match(rules, new RegExp(admissionField), `rule system missing hard-rule admission field: ${admissionField}`);
}
assert.match(rules, /behavioral\/structural assertions over brittle checks for exact prose/i);
assert.match(rules, /code\/tests\/Git prove what currently exists; accepted specs\/ADRs define what must remain true/i);
assert.match(rules, /Do not commit or push unless the current task explicitly requests it/);
assert.match(rules, /\[User decision\]/);
assert.match(rules, /\[Repository fact\]/);
assert.match(rules, /\[Subtitle principle\]/);
assert.match(rules, /\[Skill method/);

const subtitleNames = [
    '10万代码量真实项目，我是如何防止AI把旧功能改坏的？.srt',
    'AI 编程的 spec 到底该什么时候写？和先写文档完全相反.srt',
    'AI可以帮你写代码，但帮不了你成为架构师.srt',
    'AI编程别一开始就写太多spec，MVP阶段放开抡.srt',
    'AI编程越写越乱？我用水桶装水，把边界讲透，快速认识spec与harness.srt',
    'AI编程项目为什么总是烂尾？长期项目迭代先给 AI 画边界.srt',
    'Vibe Coding 第二讲：像架构师一样用 AI 做复杂产品.srt',
    '你写了一堆文档AI还是不听话？问题不在文档本身.srt',
    '答应我，别再和AI一起拉屎了；Vibe Coding如何避免屎山.srt',
];

for (const name of subtitleNames) {
    assert.ok(subtitles.includes(name), `subtitle summary missing source: ${name}`);
}
assert.match(subtitles, /repository checkout contained \*\*0\*\* tracked/);
assert.match(subtitles, /raw subtitle count: 9 files/);
assert.match(subtitles, /raw subtitle line count: 11,156 lines/);
assert.match(subtitles, /LinguaCafe is now a long-lived project/);
assert.match(subtitles, /Temporary plan/);
assert.match(subtitles, /Stable spec\/module contract/);
assert.match(subtitles, /Harness/);
assert.match(subtitles, /self-declaration is not evidence/);
assert.match(subtitles, /load this summary only when the task concerns rules, specs, architecture, harnesses, or methodology/);
assert.doesNotMatch(subtitles, /read this summary before task-specific Skills/);
assert.doesNotMatch(subtitles, /Normal tasks should read this summary/);
assert.match(skillInstallReport, /Historical installation report/);
assert.match(skillInstallReport, /not proof that the 2026-07-17/);
assert.match(skillInstallReport, /external temporary extraction/);

for (const pathText of [
    'AGENTS.md',
    'CONTEXT.md',
    'docs/architecture/ai-development-rule-system.md',
    'docs/architecture/subtitle-guided-development-summary.md',
]) {
    assert.ok(index.includes(pathText), `documentation index missing current entry: ${pathText}`);
}
assert.match(index, /routing index plus a current-status ledger/i);
assert.match(index, /not universal default context/i);
assert.match(index, /master plan\/roadmap only if ledger or sequencing is needed/i);
assert.ok(index.indexOf('Accepted module contracts and ADRs') < index.indexOf('A specifically cited legacy appendix section'), 'current index must rank accepted contracts/ADRs above the legacy appendix');
assert.match(index, /devspace-php-verification-playbook\.md/);
assert.match(index, /detailed legacy operational appendix/i);
assert.match(history, /Downgraded Operational Appendices/);
assert.match(history, /vibe-coding-collaboration-rules\.md/);
assert.match(ruleAdr, /Accepted — 2026-07-17/);
assert.match(ruleAdr, /progressive task loading/i);
assert.match(ruleAdr, /Detailed legacy operational appendix/);
assert.match(ruleAdr, /Large handoffs, ledgers, and indexes are searched and read by relevant section first/);
assert.match(handoff, /Loading gate — 2026-07-17/);
assert.match(handoff, /bounded module fix/);
const codexCandidateStart = handoff.indexOf('### F. Codex 大任务候选');
const codexCandidateEnd = handoff.indexOf('## 6.', codexCandidateStart);
assert.ok(codexCandidateStart >= 0 && codexCandidateEnd > codexCandidateStart, 'handoff must keep a bounded Codex candidate section');
const codexCandidateSection = handoff.slice(codexCandidateStart, codexCandidateEnd);
assert.doesNotMatch(codexCandidateSection, /读取顺序固定为/, 'handoff must not reintroduce a second universal document-loading checklist');
assert.match(codexCandidateSection, /唯一入口/);
assert.match(codexCandidateSection, /不另设第二套固定清单/);
assert.ok(codexCandidateSection.indexOf('2. 本任务相关的本地 Skill') < codexCandidateSection.indexOf('3. `docs/architecture/ai-development-rule-system.md`'), 'handoff must load the relevant Skill before the detailed rule system');
assert.ok(codexCandidateSection.indexOf('3. `docs/architecture/ai-development-rule-system.md`') < codexCandidateSection.indexOf('4. Git 分支'), 'handoff must inspect Git after loading the detailed rule system');
assert.match(codexCandidateSection, /选择最小任务路线/);
assert.match(codexCandidateSection, /有界模块实现或修复/);
assert.match(codexCandidateSection, /master plan \/ roadmap 只有在账本或排序决策需要时才加载/);
assert.match(masterPlan, /Loading gate — 2026-07-17/);
assert.match(masterPlan, /long-term product\/work ledger, not universal task context/);
assert.match(phpVerification, /Current verification playbook/);
assert.match(phpVerification, /real process exit code/);
assert.match(phpVerification, /Do not convert missing evidence into pass/);
assert.match(phpVerification, /PHP verification: INCOMPLETE/);
assert.doesNotMatch(history, /§1\.5 GLM 单 Agent 闭环规则 \(current\)/);
assert.match(legacy, /Status — 2026-07-17/);
assert.match(legacy, /Detailed legacy operational appendix/);
assert.match(legacy, /Current authority.*AGENTS\.md.*ai-development-rule-system\.md/s);
assert.match(legacy, /cites that exact section/);
assert.match(legacy, /broad reference.*does not activate the whole document/i);

console.log('AI development rule-system guard passed.');
