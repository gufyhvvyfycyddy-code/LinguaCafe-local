# Spec To Tests / Smoke / Harness Candidates

> **Status**: Candidate list plus completed conversions.
> **Last updated**: 2026-07-02 (Codex-FinalArchitectureClosureTargetMode-1).

This document turns soft project rules into executable verification candidates. Completed rows record what has already moved from prose into tests/smoke/harness; open rows remain candidates and do not authorize code changes by themselves.

## 1. Principle

Natural-language specs reduce ambiguity, but they are not hard constraints. High-risk rules should eventually become one of:

- PHPUnit / unit / feature contract tests.
- MCP Chrome real-page smoke.
- A local smoke guard or harness script.
- A documented manual gate when automation is not yet practical.

## 2. Candidate Table

| Candidate | Current soft rule | Suggested hard check | Scope | Priority |
|---|---|---|---|---|
| TextBlock fallback tokenizer | `fallbackEnglishTokenize` remains callable and should not silently drift | Completed in `tests/Unit/TextBlockFallbackTokenizerTest.php`: conservative lemma behavior, irregular table use, structural markers, numbers/punctuation, blank text exception | `TextBlockService` / tokenizer fallback | Done |
| ReviewCardManage logs payload | Logs drawer payload should keep stable fields, ordering, and user/language/card isolation | Completed in `tests/Feature/ReviewCardManageTest.php`: exact payload fields/date shape plus same-card user/language filtering; existing tests cover order, limit, empty state, cross-card, legacy/rejected cards | `ReviewCardManageController::logs()` | Done |
| SenseReview full menu and occurrence writes | Sense review and occurrence actions must work in real UI, not just backend tests | Completed by `smoke:sense-review-data`, `tests/Feature/SenseReviewSmokeDataCommandTest.php`, and `docs/plans/sense-review-real-workflow-smoke-playbook.md`: marker data plus real-page rating, More/source fallback, confirm/reject/ignore/rebind/create-new smoke | `/reviews/senses`, `/senses/review` | Done |
| AI recommended words exclude user selections | AI suggestions must not duplicate words/phrases manually selected by the user | Future contract tests plus MCP Chrome confirmation modal smoke | Planned AI study card flow | P1 before implementation |
| AI recommended words default unchecked | User confirmation must be explicit | MCP Chrome smoke for modal initial state | Planned AI study card flow | P1 before implementation |
| AI translations do not create review cards | Reading aid must not write learning data by itself | Existing tests plus a regression test around confirm/current endpoints if gaps appear | AI reading assist | P1 |
| Legacy word cards stay out of daily mainline | New review flow should stay sense-only | Feature tests for queue filters and daily review entry | Review queue / FSRS | P1 |
| ReviewLog preserved by default | Delete/archive/reset flows must not silently erase history | Existing WordSense tests plus ReviewCardManage logs regression coverage | WordSense / ReviewCardManage | P1 |
| Delete / archive / restore semantics | Archive pauses review; permanent delete follows accepted restore/log semantics | Existing contract tests; add UI smoke where menu flows are user-facing | WordSense / ReviewCardManage / SenseReview | P1 |
| MCP Chrome not replaced by API | Page tasks need true browser evidence | Final-report checklist and possible scriptable smoke templates | Browser-facing tasks | P2 |
| DCP default forbidden | DCP cannot run unless a task explicitly authorizes it | Final-report checklist; no automated command needed | Process compliance | P2 |
| Notification script default forbidden | `notify.ps1` and any OS notification scripts are not part of task completion | Final-report checklist; no automated command needed | Process compliance | P2 |

## 3. Current Implementation Notes

1. Codex-SpecToHarnessHardeningTargetMode-1 converted the TextBlock fallback tokenizer and ReviewCardManage logs payload soft rules into PHPUnit coverage.
2. Codex-SenseReviewRealWorkflowHardeningTargetMode-1 converted the SenseReview full menu and occurrence write soft rule into a marker-data command, a command feature test, and a real-page smoke playbook.
3. These conversions did not change `TextBlockService`, `ReviewCardManageController`, API response semantics, tokenizer/import behavior, FSRS, WordSense, ReviewLog preservation, Vue, routes, or schema.
4. Do not weaken existing tests to make a future candidate easier.
5. For browser flows, do not replace MCP Chrome with API calls.
6. AI study card architecture scouting is complete (`docs/plans/ai-study-card-architecture-scout.md`). The scout covers code access points, danger zones, and a minimum target proposal. Do not implement before product decision approval. Do not invent DB schema or endpoint names in this candidate list.
7. Codex-FinalArchitectureClosureTargetMode-1 froze the AI study card v1 plan (`docs/plans/ai-study-card-v1-frozen-plan.md`) and the frontend review entry unification plan (`docs/plans/frontend-review-entry-unification-plan.md`). Both are route-frozen only — no schema, API, Vue, or route changes were made. Implementation rounds must still pass Architecture Gate and ADR review before code changes.

## 4. Next Candidate Shortlist

If the project owner asks for the next hardening task, the lowest-risk remaining candidates are:

1. `AIStudyCard-PendingItem-ContractTests-1` — once AI study card v1 implementation begins, add contract tests including reverse-contract tests asserting no writes to `ReviewCard` / `ReviewLog` / `WordSense` / `WordSenseOccurrence` / `EncounteredWord`. Route-frozen plan: `docs/plans/ai-study-card-v1-frozen-plan.md`.
2. `FrontendReviewEntry-Unification-McpSmoke-1` — once frontend review entry unification round 1 is implemented, add MCP Chrome smoke covering: homepage "开始复习" → `/reviews/senses`, nav "复习" → `/reviews/senses`, `/senses/review` and `/review/false/-1/-1` and `/review-cards/manage` routes still accessible. Route-frozen plan: `docs/plans/frontend-review-entry-unification-plan.md`.
3. `LegacyWordCards-DailyMainlineGuard-1` only if existing ReviewFsrs coverage is judged insufficient.
4. `SenseReview-SmokeReplay-1` only when the real page flow needs to be replayed with a fresh marker.

Selection still belongs to the project owner / webpage-side designer. Agents must not auto-enter the next task.

## 5. Deferred Candidate Rationale

| Candidate | Deferred reason |
|---|---|
| SenseReview full menu and occurrence writes | Completed as a standalone page-smoke task; future work should replay the playbook only when this surface changes. |
| Legacy word cards stay out of daily mainline | Existing `ReviewFsrsTest` already covers sense-only queue targeting and word-card exclusion; add more only if a concrete gap appears. |
| ReviewLog preserved by default | Existing `ReviewCardManageTest` and `WordSenseDestroyRestoreTest` already cover preserve-by-default paths; duplicating them would add noise. |
| AI study card future hardening | Product boundary is documented in ADR-0002, but implementation/schema/API do not exist yet; only docs/harness scouting is safe before architecture approval. |
