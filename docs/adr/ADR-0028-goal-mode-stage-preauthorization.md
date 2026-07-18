# ADR-0028: Goal Mode Stage Preauthorization

## Status

Accepted — 2026-07-18

Acceptance evidence: in Codex task `019f73c2-ea29-7aa3-93ec-1e90bc920c4e`, the user approved the design and then confirmed the written spec with the decision `确认`. The reviewed spec is commit `762aec4` at `docs/superpowers/specs/2026-07-18-goal-stage-preauthorization-design.md`.

## Context

ADR-0001 requires a new user confirmation after every high-risk architecture review. That is appropriate for ordinary tasks, but an explicit persistent goal that already names an authoritative ordered roadmap repeatedly stops for the same authorization. The repetition prevents unattended progress without adding a new product or safety decision.

## Decision

This ADR supersedes ADR-0001's approval-repeat requirement only for an explicit persistent goal.

When the user creates or resumes a goal that names an authoritative roadmap or ordered milestones, the goal authorizes the staged workflow. Implementation authorization for a slice becomes active only after its architecture review freezes the exact scope inside the named roadmap or milestones, using accepted plans, ADRs, contracts, and tests only as constraints and evidence, and no unresolved mandatory stop applies. Implementation, verification, and a slice completion audit remain mandatory before advancing.

Goal authorization stops before destructive or production data action; migration execution, new table, or backfill; unfrozen data-model or public interface semantics; FSRS, formal rating, ReviewLog, WordSense binding, review-card identity, or lifecycle semantics; real AI provider, secret, external transmission, paid usage, model, or cost limit; multiple unreviewed seams; an unapproved ADR; an unresolved product choice or authority conflict; or work outside the named goal.

Only the specific decision explicitly approved at a stop joins the active goal authorization. Approval of a design, ADR, migration plan, or interface contract does not authorize adjacent decisions; approval of a migration plan does not by itself authorize migration execution. Confirmation is required again after any material change to authority, scope, risk, data handling, external transmission, cost assumptions, or implementation.

The agent may report a slice as technically verified or goal-authorized closed only after its frozen criteria pass a requirement-by-requirement completion audit from current evidence. It may not invent product acceptance. The full goal closes only after a separate audit proves every named milestone is closed and no required work remains.

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
