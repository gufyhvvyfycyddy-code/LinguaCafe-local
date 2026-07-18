# Goal Mode Stage Preauthorization Design

## Status

Approved in principle by the user on 2026-07-18. This document freezes the written design before rule implementation.

## Goal

Allow an explicit `/goal` request to progress through an ordered, authoritative milestone roadmap without requiring a second approval for every already-defined high-risk slice.

This design removes repetitive approval pauses. It does not remove architecture review, scope control, verification, product gates, or irreversible-risk stops.

## Explicit exclusions

- No automatic invention of milestones, requirements, payloads, schemas, or acceptance criteria.
- No authorization to modify `.env`, secrets, credentials, production data, or external systems.
- No automatic database migration execution.
- No automatic real AI provider activation, secret use, paid calls, or external data transmission.
- No bypass of user/language isolation, formal rating entry points, FSRS, ReviewLog, WordSense binding, or lifecycle invariants.
- No force-push, destructive Git operation, database reset, truncate, drop, or wipe.

## Decision

### 1. Goal-level authorization

An explicit goal that names an authoritative roadmap or an ordered set of milestones constitutes user authorization to:

1. inspect the current authoritative state;
2. perform the required architecture review for each slice;
3. freeze the slice scope from already-accepted plans, ADRs, contracts, and tests;
4. implement that slice without asking for another routine approval;
5. run proportionate automated and real-browser verification;
6. close the slice when its pre-existing acceptance evidence is satisfied; and
7. continue to the next authorized milestone.

Authorization applies only while the implementation remains inside the frozen milestone semantics and file/data boundaries established by the slice review.

### 2. Mandatory stop conditions

Goal-level authorization does not cover a newly discovered decision in any of these categories:

- destructive or production database action;
- migration execution, new table, or data backfill;
- new or changed data-model semantics not already frozen by an accepted design;
- new or changed public endpoint, request payload, response payload, or compatibility semantics not already frozen by an accepted design;
- FSRS scheduling, formal rating, ReviewLog, WordSense binding, review-card identity, or lifecycle semantic change;
- real AI provider, API key, external transmission, paid usage, model choice, cost ceiling, or secret-storage decision;
- change crossing multiple unreviewed architecture seams;
- a required new ADR whose decision has not been approved;
- an unresolved product choice or conflict between authoritative sources;
- expansion beyond the goal's named roadmap or ordered milestones.

At a mandatory stop, the agent must present the decision and wait for explicit user approval. Read-only investigation and design work may continue when it can reduce uncertainty without prejudging the decision.

### 3. Approval inheritance

Once the user approves a design, ADR, migration plan, or interface contract for a mandatory stop, that approval joins the active goal authorization. The agent may implement and verify the approved decision without requesting the same approval again.

Approval never silently transfers to a materially different schema, interface, provider, data flow, or product behavior.

### 4. Completion and acceptance language

The agent may report a slice as technically verified or goal-authorized closed only when current evidence satisfies every frozen success criterion. It may not invent product acceptance or claim evidence it did not observe.

The full goal is complete only after a requirement-by-requirement audit proves every named milestone is closed and no required work remains.

### 5. Non-goal tasks

Tasks without an explicit persistent goal retain the existing Architecture Gate behavior, including per-task user confirmation for high-risk implementation.

## Rule placement

The implementation will:

1. add a new ADR that supersedes only the approval-repeat semantics of ADR-0001;
2. update `AGENTS.md` with the compact trigger, authorization boundary, and mandatory stops;
3. update `docs/plans/vibe-coding-collaboration-rules.md` with operational detail;
4. keep `docs/DOCUMENTATION_INDEX.md` as a routing index rather than duplicating the rule; and
5. add a small executable documentation guard only if it protects against a meaningful contradiction among these three authoritative files.

## Verification

- `git diff --check` passes.
- Rule references and ADR supersession links resolve.
- Searches find no active contradiction requiring routine re-approval inside an authorized goal.
- Searches still find the mandatory-stop requirements for destructive data actions, schema/interface semantics, FSRS/ReviewLog/WordSense/lifecycle, and real AI external transmission.
- A fresh-context adversarial review finds no unresolved authority, safety, scope, or verification defect. Review stops after at most three cycles and does not churn wording.

## Consequences

- Long-running goals can advance across already-defined milestones without artificial pauses.
- Architecture and evidence gates remain intact.
- New product decisions and irreversible risks remain human decisions.
- The rule change is narrow: it changes approval reuse, not product or data semantics.
