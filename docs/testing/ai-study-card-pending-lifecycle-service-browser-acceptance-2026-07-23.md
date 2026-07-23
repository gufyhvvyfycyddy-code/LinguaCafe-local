# AI Study Card Pending Lifecycle Service — Browser Acceptance

Date: 2026-07-23

Status: Accepted / Production Closed

Implementation commit: `7214c3a`

## Result

`AiStudyCardPendingLifecycleService` now owns create/reuse/list/dismiss/restore/processed transitions and lifecycle metadata. `AiStudyCardPendingItemService` keeps the same public methods and generation response assembly as a compatibility coordinator.

Quantitative result:

- coordinator: 1,065 → 784 lines;
- lifecycle owner: 283 lines;
- no controller, route, request, response, model, migration, frontend, provider, WordSense, ReviewCard, ReviewLog, or FSRS change.

## Automated evidence

- Direct lifecycle owner: 5 tests / 24 assertions.
- Combined testing DB, Pending V1–V5 lifecycle/generation, and V6 provider/fail-closed matrix: 197 / 1,519.
- The matrix exposed and fixed a missing `Throwable` import in the still-coordinator-owned generation catch after the move; the failure-path regression then passed.
- The existing V6 documentation guard exposed a missing exact index link; `DOCUMENTATION_INDEX.md` now registers the existing ADR-0004 and preflight plan by exact path.
- PHP syntax checks and `git diff --check` passed.
- `npm run development` compiled successfully in 13.57s.

## Official Browser acceptance

An isolated testing-MySQL English chapter and one `Watches (watch)` pending item were opened through the visible Reader workflow.

- 1920×1000: the “待 AI 解释列表” dialog showed `待解释 (1)`, the source sentence, and the pending status.
- Clicking “取消” moved it to `已取消 (1)` and showed the existing success message.
- Clicking “恢复” returned the same row to `pending`; no replacement row was created.
- 900×900 retained the Reader workflow with `0` horizontal overflow.
- Final protected counts remained WordSense `0`, ReviewCard `0`, ReviewLog `0`.
- The fixture was cleaned twice, the viewport reset, the automation page closed, and no Browser pages remained.

## Scoped five-axis review

- Responsibility: lifecycle queries/transitions have one owner.
- Seam: coordinator public methods and generation result assembly remain unchanged.
- Coupling: the lifecycle owner depends only on pending/chapter/user data and Laravel query primitives.
- Risk: isolation, idempotence, unique-conflict recovery, failure fallback, processed metadata, V5 generation, and V6 fail-closed behavior are covered.
- Scope/ADR: one frozen seam, no public/data semantic change, no new ADR required.

Phase 7 next moves preview/final package construction only.
