# Spec To Tests / Smoke / Harness Candidates

> **Status**: Candidate list plus completed conversions.
> **Last updated**: 2026-07-02 (GLM-AIStudyCardV3-SafePreviewPackage-1).

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
| AI study card pending marker | Reading-page "待 AI 解释" must only record user intent, not create learning/review data | Completed in `tests/Feature/AiStudyCardPendingItemTest.php`: auth, user/language isolation, duplicate idempotency, no WordSense/ReviewCard/ReviewLog/EncounteredWord writes | AI study card v1 | Done |
| AI study card pending item list | List endpoint must only return current user's pending items, filtered by language and optionally chapter | Completed in `tests/Feature/AiStudyCardPendingItemTest.php`: list auth, user isolation, language isolation, chapter filter, 404 for other users' chapters, only-pending not dismissed | AI study card v2 | Done |
| AI study card dismiss/restore | Dismiss must not physically delete; restore must not create duplicate; neither must write learning data | Completed in `tests/Feature/AiStudyCardPendingItemTest.php`: dismiss idempotent, restore checks unique conflict, reverse contracts for no WordSense/ReviewCard/ReviewLog, dismissed re-mark via store restores | AI study card v2 | Done |
| AI study card dismissed list and restore button | Dismissed items must be visible in a separate view with a restore button; restore returns dismissed → pending without re-clicking the word in text; user/language isolation must hold | Completed in `tests/Feature/AiStudyCardPendingItemTest.php`: `status=dismissed` returns only dismissed items of current user/language, `status=all` returns both but still user/language filtered; restore endpoint covered for user isolation and no learning data writes; MCP Chrome real-page smoke confirmed dismissed view, restore button, restore returns item to pending list | AI study card v3 | Done |
| AI study card preview modal safety | Preview modal must not call AI, must not generate cards, must show safety notice and disabled confirm | Completed by MCP Chrome real-page smoke: no AI network requests, no WordSense/ReviewCard/ReviewLog writes, safety notice visible, confirm button disabled | AI study card v2 | Done |
| AI study card real preview content | Preview modal must show real user-selected words, source sentence, chapter position, count, status, safety notice, per-item checkbox, AI-recommended words placeholder, future generation rules; "准备生成" disabled when all unchecked | Completed by MCP Chrome real-page smoke: preview modal shows real pending items list with checkbox per item, source sentence, chapter id, text block index, status, count; "全不选" disables "准备生成"; re-check re-enables; AI-recommended area is placeholder only | AI study card v3 | Done |
| AI study card safe preview package | `POST /ai-study-card/pending-items/preview-package` must return a safe JSON package with selected_items, generation_rules, safety_flags; must not call AI, must not create WordSense/ReviewCard/ReviewLog, must not trigger FSRS, must not change pending item status; user/language/status isolation must hold; empty item_ids rejected; max 100 items | Completed in `tests/Feature/AiStudyCardPendingItemTest.php`: auth, user isolation, language isolation, status isolation (dismissed items cannot enter package), empty item_ids returns 422, max 100 items returns 422, reverse contracts for no WordSense/ReviewCard/ReviewLog/FSRS changes, no pending status change; MCP Chrome real-page smoke confirmed safe package JSON displayed with schema_version/selected_items/generation_rules/safety_flags and copy button works | AI study card v3 | Done |
| Frontend review entry unification | Daily review entry should be user-facing "复习" and enter sense-only mainline | Completed by code change plus MCP Chrome smoke: homepage/nav → `/reviews/senses`; old `/senses/review`, `/review/false/-1/-1`, `/review-cards/manage` remain accessible | Homepage/nav/review routes | Done |
| AI recommended words exclude user selections | AI suggestions must not duplicate words/phrases manually selected by the user | Future contract tests plus MCP Chrome confirmation modal smoke | Future AI study card recommendation flow | P1 before implementation |
| AI recommended words default unchecked | User confirmation must be explicit | MCP Chrome smoke for modal initial state | Future AI study card recommendation flow | P1 before implementation |
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
7. Codex-FinalArchitectureClosureTargetMode-1 froze the AI study card v1 plan (`docs/plans/ai-study-card-v1-frozen-plan.md`) and the frontend review entry unification plan (`docs/plans/frontend-review-entry-unification-plan.md`).
8. Codex-AIStudyCardV1-And-ReviewEntryUnification-1 implemented the first executable harness for AI study card pending markers and completed frontend review entry round-1 browser smoke. The future AI recommendation modal and card generation loop still need separate tests/smoke before implementation.
9. GLM-AIStudyCardV2-GenerationLoop-1 extended the pending marker harness to cover list, dismiss/restore, and preview modal safety. The preview modal is a placeholder only — no AI calls, no card generation. The future AI recommendation, AI meaning generation, and WordSense/ReviewCard generation loop still need separate architecture review and harness before implementation.
10. GLM-AIStudyCardV3-SafePreviewPackage-1 closed the V2 P2 (no dismissed-view restore button) and P3 (preview modal was pure placeholder). New harness: dismissed list with restore button (status filter), real preview content (user-selected words list with checkbox, source sentence, chapter position, count, status, AI-recommended placeholder, generation rules), and safe preview package (`POST /ai-study-card/pending-items/preview-package` with `schema_version=ai-study-card-preview-package-v1`, `selected_items`, `generation_rules`, `safety_flags`). 14 new feature tests cover auth/user/language/status isolation, empty item_ids, max 100 items, and reverse contracts (no WordSense/ReviewCard/ReviewLog/FSRS changes, no pending status change). MCP Chrome real-page smoke 28/28 passed. The future AI recommendation, AI meaning generation, WordSense/ReviewCard generation loop, and real AI calls still need separate architecture review and harness before implementation.

## 4. Next Candidate Shortlist

If the project owner asks for the next hardening task, the lowest-risk remaining candidates are:

1. `AIStudyCard-RecommendationModal-Harness-1` — before building the next AI study card step, add tests/smoke for user-selected-word exclusion, default-unchecked AI recommendations, explicit confirmation, and no automatic card generation. The V3 safe preview package already locks the future rules via `generation_rules` (no_auto_review_card / ai_recommended_default_unchecked / ai_recommended_exclude_user_selected / user_confirmation_required_before_generation); the next step is to wire real AI recommendation behind the same safety boundary.
2. `AIStudyCard-MeaningGeneration-Harness-1` — after recommendation modal, add tests/smoke for AI meaning generation that does not auto-create WordSense/ReviewCard without user confirmation. The V3 safe preview package `safety_flags` (no_ai_called / no_review_card_created / no_word_sense_created / no_fsrs_changed) is the contract baseline for this future step.
3. `LegacyWordCards-DailyMainlineGuard-1` only if existing ReviewFsrs coverage is judged insufficient.
4. `SenseReview-SmokeReplay-1` only when the real page flow needs to be replayed with a fresh marker.

Selection still belongs to the project owner / webpage-side designer. Agents must not auto-enter the next task.

## 5. Deferred Candidate Rationale

| Candidate | Deferred reason |
|---|---|
| SenseReview full menu and occurrence writes | Completed as a standalone page-smoke task; future work should replay the playbook only when this surface changes. |
| Legacy word cards stay out of daily mainline | Existing `ReviewFsrsTest` already covers sense-only queue targeting and word-card exclusion; add more only if a concrete gap appears. |
| ReviewLog preserved by default | Existing `ReviewCardManageTest` and `WordSenseDestroyRestoreTest` already cover preserve-by-default paths; duplicating them would add noise. |
| AI study card future hardening | V1 pending marker, V2 list/dismiss/restore/preview, and V3 dismissed-view restore button / real preview content / safe preview package are now implemented and tested. AI recommendation modal, AI meaning generation, WordSense/ReviewCard generation loop, and real AI calls remain future work requiring separate architecture review and harness. |
