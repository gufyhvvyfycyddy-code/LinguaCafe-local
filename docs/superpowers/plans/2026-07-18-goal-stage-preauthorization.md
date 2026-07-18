# Goal Mode Stage Preauthorization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reuse explicit persistent-goal authorization across already-defined roadmap slices while preserving mandatory stops for new or irreversible decisions.

**Architecture:** ADR-0028 supersedes only ADR-0001's repeated-approval semantics. `AGENTS.md` carries the compact hard rule, the collaboration guide carries operational detail, and one zero-dependency Node guard prevents authority drift.

**Tech Stack:** Markdown ADR/rules, Node.js standard library, Git.

## Global Constraints

- Approved design: `docs/superpowers/specs/2026-07-18-goal-stage-preauthorization-design.md`.
- No product, API, database, FSRS, ReviewLog, WordSense, lifecycle, AI-provider, or external-system behavior change.
- Do not touch `.env`, secrets, `.playwright-cli`, `nul`, generated bundles, or unrelated worktree changes.
- Goal authorization removes repeated approval only; every mandatory stop in the approved design remains a human decision.
- Modify and stage only files listed below.

---

### Task 1: Add a failing authority-drift guard

**Files:**
- Create: `tests/js/GoalStagePreauthorizationDocsGuard.test.mjs`
- Read: `AGENTS.md`
- Read: `docs/adr/ADR-0001-architecture-gate-workflow.md`
- Read: `docs/plans/vibe-coding-collaboration-rules.md`

**Interfaces:**
- Consumes: four authoritative Markdown files at exact repository-relative paths.
- Produces: a zero-dependency guard that fails when authorization or mandatory-stop semantics drift.

- [ ] **Step 1: Write the failing guard**

```js
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
```

- [ ] **Step 2: Verify RED**

Run: `node tests/js/GoalStagePreauthorizationDocsGuard.test.mjs`

Expected: non-zero exit with missing ADR-0028 or absent-contract assertion.

---

### Task 2: Record and apply the approved semantics

**Files:**
- Create: `docs/adr/ADR-0028-goal-mode-stage-preauthorization.md`
- Modify: `docs/adr/ADR-0001-architecture-gate-workflow.md`
- Modify: `AGENTS.md`
- Modify: `docs/plans/vibe-coding-collaboration-rules.md`
- Test: `tests/js/GoalStagePreauthorizationDocsGuard.test.mjs`

**Interfaces:**
- Consumes: approved design plus ADR-0001.
- Produces: one accepted ADR, one compact root rule, and one matching operational rule.

- [ ] **Step 1: Create ADR-0028**

Use these exact decision paragraphs:

```markdown
# ADR-0028: Goal Mode Stage Preauthorization

## Status
Accepted — 2026-07-18

## Context
ADR-0001 requires a new user confirmation after every high-risk architecture review. That is appropriate for ordinary tasks, but an explicit persistent goal that already names an authoritative ordered roadmap repeatedly stops for the same authorization. The repetition prevents unattended progress without adding a new product or safety decision.

## Decision
This ADR supersedes ADR-0001's approval-repeat requirement only for an explicit persistent goal.

When the user creates or resumes a goal that names an authoritative roadmap or ordered milestones, the goal itself is implementation authorization for each already-defined slice. Architecture review, scope freeze, implementation, verification, and closure audit remain mandatory.

Goal authorization stops before destructive or production data action; migration execution, new table, or backfill; unfrozen data-model or public interface semantics; FSRS, formal rating, ReviewLog, WordSense binding, review-card identity, or lifecycle semantics; real AI provider, secret, external transmission, paid usage, model, or cost limit; multiple unreviewed seams; an unapproved ADR; an unresolved product choice or authority conflict; or work outside the named goal.

Approval of a stopped design, ADR, migration plan, or interface contract joins the active goal authorization and is requested again only after a material change.

## Non-goal tasks
Tasks without an explicit persistent goal retain ADR-0001's per-task confirmation rule.

## Alternatives
- Keep per-slice confirmation: safe but prevents persistent-goal execution.
- Blanket autonomy: rejected because it would hide new product, schema, external-data, and irreversible decisions.

## Consequences
- Already-authorized roadmap work can continue without duplicate pauses.
- Architecture, scope, verification, and mandatory human decisions remain intact.
- Goal authorization cannot expand the roadmap or reinterpret an unresolved requirement.

## Validation
- `node tests/js/GoalStagePreauthorizationDocsGuard.test.mjs`
- `git diff --check`
- Fresh-context adversarial review of authority, safety, scope, and verification semantics.
```

- [ ] **Step 2: Add ADR-0001's partial-supersession note**

```markdown
> **Partial supersession:** ADR-0028 replaces only repeated routine approval inside an explicitly authorized persistent goal. Architecture Gate review, scope, safety, and verification remain active; non-goal tasks keep this workflow unchanged.
```

- [ ] **Step 3: Update `AGENTS.md` section 5**

Replace the unconditional confirmation sentence with:

```markdown
所有高风险任务都必须先完成架构审查。普通任务在用户明确确认后实施；目标模式可使用“目标模式阶段预授权”：当用户明确创建或恢复一个指向权威 roadmap 或有序里程碑的持续目标时，目标本身即构成已冻结切片的实施授权，完成架构审查后无需重复确认，可按顺序实施、验证并进入下一切片。

目标模式阶段预授权不得覆盖新发现的强制停止项：破坏性或生产数据操作；migration 执行、新表或数据回填；未冻结的数据模型或公开 API/payload 语义；FSRS、正式评分、ReviewLog、WordSense 绑定、ReviewCard 身份或 lifecycle 语义；真实 AI provider、密钥、外发、付费、模型或成本上限；多个未审查 seam；未批准的新 ADR；未决产品选择、权威冲突或目标外扩张。用户批准该具体设计后，授权并入当前目标，除非实现发生实质变化，不得重复请求同一确认。依据见 ADR-0028。
```

Leave the existing specific stop bullets intact.

- [ ] **Step 4: Update collaboration section 5 and section 28.6**

Add:

```markdown
### 5.1 目标模式阶段预授权

用户明确创建或恢复一个指向权威 roadmap 或有序里程碑的持续目标时，目标本身即构成已冻结切片的实施授权。执行 Agent 仍须逐片完成架构审查、范围冻结、相称验证和完成审计，但不得为同一授权重复暂停。

强制停止条件为：破坏性或生产数据操作；migration 执行、新表或数据回填；未冻结的数据模型或公开接口语义；FSRS、正式评分、ReviewLog、WordSense 绑定、ReviewCard 身份或 lifecycle 语义；真实 AI provider、密钥、外发、付费、模型或成本上限；多个未审查 seam；未批准的新 ADR；未决产品选择、权威冲突或目标外扩张。具体决定获用户批准后并入当前目标，只有实质变化才重新确认。
```

Replace section 28.6 with:

```markdown
### 28.6 下一任务

普通任务完成后停止；不得把建议、future work 或报告中的 follow-up 自动实施。明确持续目标按 ADR-0028 依有序里程碑继续，但每个切片仍须满足范围、架构和验证门槛，遇到强制停止条件时等待用户决定。
```

- [ ] **Step 5: Verify GREEN and document consistency**

Run:

```powershell
node tests/js/GoalStagePreauthorizationDocsGuard.test.mjs
git diff --check -- AGENTS.md docs/adr/ADR-0001-architecture-gate-workflow.md docs/adr/ADR-0028-goal-mode-stage-preauthorization.md docs/plans/vibe-coding-collaboration-rules.md tests/js/GoalStagePreauthorizationDocsGuard.test.mjs
rg -n "目标模式阶段预授权|ADR-0028|强制停止条件|migration 执行|真实 AI" AGENTS.md docs/adr/ADR-0001-architecture-gate-workflow.md docs/adr/ADR-0028-goal-mode-stage-preauthorization.md docs/plans/vibe-coding-collaboration-rules.md
```

Expected: guard success, no whitespace errors, and all three authorities retain mandatory stops.

- [ ] **Step 6: Commit exact paths**

```powershell
git add -- AGENTS.md docs/adr/ADR-0001-architecture-gate-workflow.md docs/adr/ADR-0028-goal-mode-stage-preauthorization.md docs/plans/vibe-coding-collaboration-rules.md tests/js/GoalStagePreauthorizationDocsGuard.test.mjs
git diff --cached --name-status
git commit -m "docs: allow goal stage preauthorization"
```

Expected: exactly five staged paths.

---

### Task 3: Adversarially verify convergence

**Files:** Review the five Task 2 paths only.

**Interfaces:**
- Consumes: focused rule diff plus approved design.
- Produces: an evidence-backed verdict that approval reuse changed without weakening safety.

- [ ] **Step 1: Fresh-context adversarial review**

Give the reviewer only the five-file diff and this contract:

```text
Find authority conflicts, safety bypasses, scope expansion, unverifiable language, or contradictions. Approval may be reused only inside an explicit persistent goal. Mandatory stops and per-task approval for non-goal work must remain.
```

Classify every finding as contract misread, actionable, accepted trade-off, or noise. Fix actionable findings and repeat at most three cycles; stop when only wording remains.

- [ ] **Step 2: Final verification**

Run:

```powershell
node tests/js/GoalStagePreauthorizationDocsGuard.test.mjs
git diff --check
git status --short --branch
```

Expected: guard passes; no whitespace errors; unrelated pre-existing worktree files remain untouched and uncommitted.

- [ ] **Step 3: Advance the persistent goal plan**

Mark the rule-design step complete and Phase 4 architecture review as the next in-progress item. Do not mark the full goal complete.
