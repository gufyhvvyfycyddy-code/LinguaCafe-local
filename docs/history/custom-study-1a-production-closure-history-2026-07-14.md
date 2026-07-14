# Custom Study 1A production-closure history

This file preserves the phase-by-phase execution history that was removed from the current authoritative project documents during the 2026-07-15 status convergence.

## Timeline

- Task 2000-15 froze the shared `SenseStudyCard.vue` presentation contract and the Anki-first decision rule.
- Task 2000-16 implemented criteria, validation, the chapter-locator contract, and structured validation errors.
- Tasks 2000-17 and 2000-18 implemented candidate queries, chapter location, source-chapter mode, leech-attention mode, and the unified query dispatcher.
- Tasks 2000-19 through 2000-22 implemented immutable session state, encrypted tokens, pure preview transitions, session-internal ordering, eligibility revalidation, the stateless session service, controller, and API routes.
- Later production work added the authenticated chapter-options endpoint, `CustomStudy.vue`, `SenseStudyCard.vue`, `SenseSentencePreview.vue` reuse, sessionStorage token handling, resume/expiry flows, and browser acceptance at 1920×1080 and 900×900.
- The web-side process designer subsequently issued final product acceptance for Custom Study 1A.

## Superseded interim states

Earlier documents described backend-only phases, an absent frontend, and pending final acceptance. Those statements were accurate at their original dates but are no longer current project status. They remain historical evidence only and must not be copied back into the authoritative status blocks.

## Current authority

Current status is maintained in:

- `docs/plans/linguacafe-master-plan.md`
- `docs/plans/current-working-handoff.md`
- `docs/DOCUMENTATION_INDEX.md`
- `docs/adr/ADR-0016-custom-study-preview-session.md`
- `docs/plans/custom-study-1a-implementation-plan.md`

Current status:

- Production closure: complete
- Custom Study 1A: Accepted / Production Closed
- Custom Study 1B: not started
