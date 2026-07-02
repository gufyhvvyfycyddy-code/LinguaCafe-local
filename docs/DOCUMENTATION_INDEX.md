# LinguaCafe Documentation Index

> **Status**: Current entry index.
> **Last updated**: 2026-07-03 (Trae-LemmaKnownSenseBridge-1).

This file is the lightweight entry map for humans and agents. It exists to prevent context flooding: read the current layer first, then load module or history documents only when the task actually needs them.

## 1. Current Entry Order

Read these in order for a new Codex / OpenCode / CodeBuddy / WorkBuddy task:

1. `docs/plans/current-working-handoff.md` — current short-term workbench, current decisions, and next candidates.
2. `docs/plans/linguacafe-master-plan.md` — long-term ledger of product lines, completed work, and preserved future directions.
3. `docs/plans/vibe-coding-collaboration-rules.md` — collaboration rules, safety boundaries, and verification rules.
4. `docs/plans/repo-architecture-hotspot-audit.md` — architecture risk map and candidate backlog.
5. `docs/plans/final-architecture-closure-report.md` — architecture-closure phase conclusion (read when judging whether to start AI study card v1 or new feature work).

Do not start from `docs/CODEX_HANDOFF.md`, `docs/NEXT_TASK.md`, `docs/CURRENT_STATUS.md`, or `docs/FSRS_PHASE*.md`. Those are historical references.

## 2. Document Layers

| Layer | Purpose | Primary files |
|---|---|---|
| Entry / current state | Decide what is current and what to read next | `current-working-handoff.md`, this file |
| Long-term ledger | Preserve product directions and completed task history | `linguacafe-master-plan.md` |
| Collaboration rules | Role boundaries, safety red lines, test/smoke/report rules | `vibe-coding-collaboration-rules.md` |
| Architecture risks | Module responsibilities, hotspots, candidate routes | `repo-architecture-hotspot-audit.md` |
| Architecture closure | Final closure conclusion and next-stage roadmap | `final-architecture-closure-report.md` |
| Frozen plans | Route-frozen plans for upcoming minimum implementation | `ai-study-card-v1-frozen-plan.md`, `frontend-review-entry-unification-plan.md` |
| ADR / stable decisions | Accepted decisions that should not be re-litigated each task | `docs/adr/*.md` |
| Module contracts | Stable module boundaries and output contracts | `docs/plans/*contract*.md`, `docs/plans/*boundaries*.md` |
| Test / smoke / harness | Executable or semi-executable verification playbooks | `docs/testing/*`, `docs/plans/*smoke*`, `docs/plans/mcp-chrome-local-smoke-playbook.md`, `docs/plans/sense-review-real-workflow-smoke-playbook.md` |
| Architecture scout | Read-only architecture investigation reports | `docs/plans/ai-study-card-architecture-scout.md` |
| Product principles | Long-term product direction, function constraints, and legacy cleanup plan | `docs/plans/product-principles-and-legacy-cleanup-plan.md` |
| History | Old handoffs, old status files, old phase notes | `docs/HISTORY_INDEX.md` |

## 2.5 Key Rule References

New rules and process notes are documented in:
- `vibe-coding-collaboration-rules.md` §22 — 总设计师提示词前进度说明规则（每次给提示词前必须先说明当前进度）。
- `vibe-coding-collaboration-rules.md` §23 — 三方架构侦查规则（网页端总设计师/CodeBuddy/OpenCode 三方分工）。
- `current-working-handoff.md` §7 — 当前主线进度估算。

## 2.6 Frozen Plans (Route-Frozen, Not Implemented)

| Plan | Status | What it freezes |
|---|---|---|
| `ai-study-card-v1-frozen-plan.md` | Frozen, implemented (2026-07-02) | AI study card v1 target, user flow, data/frontend/backend boundaries, forbidden scope, acceptance. Implementation round must still pass Architecture Gate and ADR. |
| `frontend-review-entry-unification-plan.md` | Frozen, round-1 implemented (2026-07-02) | Frontend review entry unification direction, current entry state, future unified layout, round-1 minimum change, forbidden one-shot deletion of old pages, MCP Chrome / WorkBuddy acceptance. |
| `ai-study-card-v2-generation-loop-plan.md` | Frozen, implemented (2026-07-02) | AI study card v2 generation loop phase 1: pending item list, dismiss/restore, preview modal placeholder. |
| `ai-study-card-v3-safe-preview-package-plan.md` | Frozen, implemented (2026-07-02) | AI study card v3 safe preview package: dismissed-view restore button, real preview content, safe preview package (`schema_version=ai-study-card-preview-package-v1`). |
| `ai-recommendation-confirmation-loop-plan.md` | Frozen, implemented (2026-07-02) | AI study card v4 AI recommendation confirmation loop: paste AI recommendation JSON, dedupe, default unchecked, user confirmation, final candidates package (`schema_version=ai-study-card-final-candidates-v1`). |
| `reading-inline-review-and-example-pool-plan.md` | Frozen, partially implemented (2026-07-03) | Reading inline review and multi-example pool route: WordSense-only inline review (still frozen), real-source example rotation (implemented 2026-07-02), known-sense-new-meaning bridge front-only structure (implemented 2026-07-03, no AI judgment), and surface/lemma binding rules (front-end display + lemma-prefer search implemented 2026-07-03). |

Do not treat frozen plans as implementation authorization. They only freeze the route; the next implementation round must still pass Architecture Gate and ADR review.

## 3. Current ADRs

| ADR | Status | Scope |
|---|---|---|
| `docs/adr/ADR-0001-architecture-gate-workflow.md` | Accepted | Architecture gate workflow for high-risk work |
| `docs/adr/ADR-0002-sense-only-and-ai-study-card-boundaries.md` | Accepted / Planned split | Sense-only review boundaries and AI study card product boundaries |

## 4. Soft Rules Awaiting Hard Verification

Use `docs/plans/spec-to-harness-candidates.md` when a task asks what should become tests, smoke checks, or harness checks next. Do not treat that file as implementation permission; it is a candidate list.

## 5. History Handling

Use `docs/HISTORY_INDEX.md` to understand old documents. Historical documents are retained because they contain useful context, but they are not current task entry points.

If a historical document conflicts with current entry documents, follow this priority:

1. Current task prompt and explicit user decision.
2. `current-working-handoff.md`.
3. `linguacafe-master-plan.md`.
4. `vibe-coding-collaboration-rules.md`.
5. Module contract / ADR files.
6. Historical documents only as background.
