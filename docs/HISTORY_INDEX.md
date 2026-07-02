# LinguaCafe History Index

> **Status**: Historical document index.
> **Last updated**: 2026-07-02.

This file keeps old documents discoverable while lowering their context priority. Do not delete these files, and do not start a new task from them.

Current entry documents are listed in `docs/DOCUMENTATION_INDEX.md`.

## 1. Highest-Risk Historical Names

These files have names that sound current but are no longer current entry points:

| File | Historical stage | Can still be referenced? | Why not current | Current replacement |
|---|---|---|---|---|
| `docs/NEXT_TASK.md` | Early FSRS / local modification phase | Only for historical audit | Name suggests current next task, but its task order is stale | `docs/plans/current-working-handoff.md` |
| `docs/CURRENT_STATUS.md` | 2026-06-17 era local status | Only for historical audit | Name suggests current state, but project has moved through many later phases | `docs/plans/current-working-handoff.md` |
| `docs/FSRS_FINAL_STATUS.md` | Old FSRS phase summary | Only for old FSRS phase context | "Final" means final for that old phase, not final architecture | `docs/plans/linguacafe-master-plan.md` |
| `docs/CODEX_HANDOFF.md` | 2026-06-23 Codex handoff | Partial background only | Old handoff can mislead Codex into starting from obsolete tasks | `docs/plans/current-working-handoff.md` |

## 2. FSRS Phase History

The following files document earlier FSRS implementation phases. They are retained as historical evidence, not as current instructions:

- `docs/FSRS_PHASE1_STATUS.md`
- `docs/FSRS_PHASE2_STATUS.md`
- `docs/FSRS_PHASE3_STATUS.md`
- `docs/FSRS_PHASE4_STATUS.md`
- `docs/FSRS_PHASE5_STATUS.md`
- `docs/FSRS_PHASE6_STATUS.md`
- `docs/FSRS_PHASE7_STATUS.md`
- `docs/FSRS_PHASE8_STATUS.md`
- `docs/FSRS_PHASE8_QUICKER_WORKFLOW.md`
- `docs/FSRS_PHASE9_STATUS.md`
- `docs/FSRS_NEXT_STEPS.md`
- `docs/FSRS_USER_GUIDE.md`

Current FSRS / sense-only direction lives in:

- `docs/plans/linguacafe-master-plan.md`
- `docs/plans/current-working-handoff.md`
- `docs/plans/repo-architecture-hotspot-audit.md`
- `docs/plans/spec-to-harness-candidates.md`

## 3. Old Handoffs And Completed Scouting

| File | Status | Notes |
|---|---|---|
| `docs/handovers/2026-06-24-c12-c-handoff.md` | Historical handoff | Do not use as a current Codex entry point |
| `docs/convergence/reader-workspace-sizing-convergence-1.md` | Historical completed convergence plan | Useful to understand ReaderWorkspaceSizingService rationale |
| `docs/plans/linguacafe-fsrs-roadmap.md` | Historical FSRS roadmap | Many items were completed or superseded; master plan is authoritative |

## 4. Rule For Agents

When a historical document says "next", "current", "final", or "must do", treat that wording as scoped to the old date and phase. Re-check the current entry documents before acting.

Historical documents may explain why a decision was made. They do not authorize code changes, database changes, browser actions, or new tasks.
