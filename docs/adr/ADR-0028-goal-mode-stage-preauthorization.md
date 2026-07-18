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

The agent may report a slice as technically verified or goal-authorized closed only from current evidence. It may not invent product acceptance. The full goal closes only after a requirement-by-requirement completion audit.

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
