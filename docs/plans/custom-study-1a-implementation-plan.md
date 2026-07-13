# Custom Study 1A Implementation Plan

> **Status**: Architecture complete (ADR-0016 accepted). **Development NOT started** — this plan is a TDD roadmap for a future authorized round. No Custom Study business code, API, page, or migration is authorized by this plan alone.

**Goal**: Implement Custom Study 1A — a preview-only temporary session that lets the user review a curated set of sense cards outside the normal due queue, without moving cards, building a filtered deck, writing ReviewLog, or running FSRS scheduling.

**Tech stack**: Laravel PHP + Vue 2 + Vuetify + PHPUnit + Node.js built-in `assert` guard tests.

**Related ADR**: `docs/adr/ADR-0016-custom-study-preview-session.md`

**Authorization required before any task below can start**:
1. A separate task authorization from the 网页端总流程设计师.
2. Architecture Gate review per `AGENTS.md`.
3. Confirmation that Queue Order production acceptance (Task 2000-10A) is closed.
4. Confirmation that the `Card Marker` 1B prerequisite is NOT being snuck into 1A.

---

## Architecture Summary (from ADR-0016)

### Preview-only temporary session — 10 rules
1. Does NOT move ReviewCard.
2. Does NOT build a deck / filtered deck (LinguaCafe has no deck model).
3. Does NOT change normal due queue membership.
4. Does NOT modify `lifecycle_state` (ADR-0010).
5. Does NOT modify `fsrs_due_at` or any FSRS field.
6. Does NOT write `ReviewLog`.
7. Does NOT run formal FSRS scheduling.
8. Defaults to preview-only.
9. On session exit, the normal queue is completely unchanged.
10. Scoped to current user + current language + sense cards only.

### Four frozen criteria
| Criterion | Definition |
|---|---|
| `today_forgotten` | `ReviewLog.source = 'sense_review'` AND `rating = 'again'` AND `undone_at IS NULL` AND `reviewed_at` within current learning-timezone natural day. Distinct `review_card_id`. Card is still current user's + current language's confirmed sense card. Suspended/archived/buried-not-expired excluded. |
| `overdue` | `fsrs_due_at < start_of_local_natural_day` (strict — cards due later today NOT included). Card is eligible (`scopeSenseReviewEligible`). Confirmed sense only. |
| `source_chapter` | `WordSense.source_chapter_id` matches selected chapter, OR `WordSenseOccurrence` with `status=bound` and `chapter_id` matching exists. Chapter must belong to current user + current language. Distinct `review_card_id`. No per-card query. |
| `leech_attention` | Reuses `SenseReviewLeechQueryService` (does NOT duplicate Leech Policy). Supports `leech only` and `leech + struggling` sub-modes. Card is currently eligible. Suspended/archived NOT auto-added. |

### Recommended V1 architecture — Option B (server-signed criteria token, no DB session)
Target service pipeline (NOT yet implemented):
```
CustomStudyCriteria (value object, no DB)
  → CustomStudyCriteriaValidator (pure, no DB)
  → CustomStudyQueryService (builds candidate query, applies criteria, no write)
  → CustomStudySessionTokenService (signs/verifies token, no DB)
  → CustomStudySessionService (orchestrates: validate token → re-run query → apply order → return next card; no write)
  → SenseReviewCardSerializerService (serializes next card, same shape as /reviews/senses)
  → Custom Study page (NOT yet implemented; NOT authorized by this plan)
```

### Session-internal ordering (mode-specific override, does NOT modify global Queue Order)
| Mode | Default order override |
|---|---|
| `today_forgotten` | Most recent Again first, fallback to Queue Order |
| `overdue` | Ascending retrievability (most forgotten first), fallback to Queue Order |
| `source_chapter` | Current Queue Order (no override) |
| `leech_attention` | Severity DESC (leech before struggling), then Queue Order |

### Daily limits
Do NOT apply to Custom Study 1A preview sessions. Preview sessions do not consume the due queue, do not write ReviewLog, and do not advance daily reviewed count.

### Lifecycle
Only shows cards currently eligible per `scopeSenseReviewEligible` (ADR-0010). Session does NOT change lifecycle state. For `leech_attention`: suspended/archived leech cards remain diagnosable via `SenseReviewLeechQueryService` but are NOT auto-added.

### Undo inapplicability
ADR-0009 stack-based undo does NOT apply. Preview sessions write no ReviewLog; "undo" = "exit session".

### Token payload (V1: 4-hour expiry)
- `version`, `user_id`, `language`, `mode` (preview-only), `parameters` (criteria + sub-mode + order override), `issued_at`, `expires_at`, `nonce`/`session_id` (UUID v4)
- Signed via Laravel `Crypt::encryptString()` or project's existing secure token mechanism.
- Server re-validates `user_id` + `language` on every request.
- Candidate cards re-run through eligibility on every request.
- Client cannot pass arbitrary `card_id` to bypass criteria query.

---

## TDD Breakdown

This plan follows strict TDD (red → green → refactor). Each task lists the test file first, then the implementation file. No implementation file may be written before its test file fails.

### Phase 1: Value objects and validators (pure, no DB)

#### Task CS-1: `CustomStudyCriteria` value object
**Test first**: `tests/Unit/CustomStudyCriteriaTest.php`
- `fromArray()` accepts valid criteria for each of the 4 modes.
- Rejects unknown mode (throws `InvalidArgumentException`).
- Rejects missing required parameters per mode.
  - `today_forgotten`: no parameters required.
  - `overdue`: no parameters required.
  - `source_chapter`: requires `chapter_id` (integer > 0).
  - `leech_attention`: requires `sub_mode` ∈ {`leech_only`, `leech_plus_struggling`}.
- `toArray()` round-trips.
- `mode()` accessor.
- `parameters()` accessor.
- Unknown keys ignored (not stored).
- Immutable — no setters.

**Then implement**: `app/Services/CustomStudy/CustomStudyCriteria.php`

**Tests count**: ~10

#### Task CS-2: `CustomStudyCriteriaValidator` (pure, no DB)
**Test first**: `tests/Unit/CustomStudyCriteriaValidatorTest.php`
- Validates `user_id` matches authenticated user (passed in, no Auth dependency).
- Validates `language` is non-empty string.
- Validates `mode` is one of the 4 frozen criteria.
- Validates `chapter_id` belongs to `user_id` + `language` (Chapter lookup — this is the only non-pure check; inject a `ChapterLocatorInterface` so the validator stays unit-testable with a stub).
- Validates `sub_mode` for `leech_attention`.
- Returns validated `CustomStudyCriteria` or throws `CustomStudyValidationException`.
- Does NOT query ReviewLog, ReviewCard, or WordSense — only validates shape + chapter ownership.

**Then implement**: `app/Services/CustomStudy/CustomStudyCriteriaValidator.php` + `app/Services/CustomStudy/ChapterLocatorInterface.php`

**Tests count**: ~12

### Phase 2: Query services (read-only, no write)

#### Task CS-3: `CustomStudyQueryService` — `today_forgotten`
**Test first**: `tests/Feature/CustomStudyTodayForgottenQueryTest.php`
- Returns distinct `review_card_id` for `source=sense_review`, `rating=again`, `undone_at IS NULL`, `reviewed_at` within learning-timezone today.
- Excludes cards whose `undone_at` is non-null (undone Again does NOT count).
- Excludes cards not belonging to current user + language.
- Excludes cards that are not confirmed sense cards (`target_type=sense`, WordSense confirmed).
- Excludes suspended/archived/buried-not-expired cards (reuse `scopeSenseReviewEligible`).
- Uses `ReviewStudyTimezoneService` for the day boundary (NOT `Carbon::today()`).
- Returns empty collection when no matches.
- Does NOT write anything.
- Query count: 1 ReviewLog query + 1 ReviewCard query (batch by `review_card_id`).

**Then implement**: `app/Services/CustomStudy/Queries/TodayForgottenQuery.php`

**Tests count**: ~9

#### Task CS-4: `CustomStudyQueryService` — `overdue`
**Test first**: `tests/Feature/CustomStudyOverdueQueryTest.php`
- Returns cards with `fsrs_due_at < start_of_local_natural_day` (strict — cards due later today excluded).
- Uses `ReviewStudyTimezoneService::dayStart()` for the boundary.
- Excludes ineligible cards (suspended/archived/buried-not-expired).
- Confirmed sense only.
- Empty collection when nothing overdue.
- Does NOT write anything.
- Query count: 1.

**Then implement**: `app/Services/CustomStudy/Queries/OverdueQuery.php`

**Tests count**: ~7

#### Task CS-5: `CustomStudyQueryService` — `source_chapter`
**Test first**: `tests/Feature/CustomStudySourceChapterQueryTest.php`
- Returns cards whose `WordSense.source_chapter_id` matches.
- OR cards with a `WordSenseOccurrence` (`status=bound`, `chapter_id` matches).
- Chapter must belong to current user + language (404 if not — tested via Validator in CS-2, here just assert no leakage).
- Distinct `review_card_id` (no duplicates even with multiple occurrences).
- Excludes ineligible cards.
- Confirmed sense only.
- No per-card query (batch by `word_sense_id`).
- Does NOT write anything.
- Query count: 1 WordSense/Occurrence query + 1 ReviewCard query.

**Then implement**: `app/Services/CustomStudy/Queries/SourceChapterQuery.php`

**Tests count**: ~9

#### Task CS-6: `CustomStudyQueryService` — `leech_attention`
**Test first**: `tests/Feature/CustomStudyLeechAttentionQueryTest.php`
- `leech_only` sub-mode: returns cards classified as `leech` by `SenseReviewLeechPolicy`.
- `leech_plus_struggling` sub-mode: returns `leech` + `struggling`.
- Reuses `SenseReviewLeechQueryService` — does NOT duplicate Leech Policy.
- Currently eligible cards only (suspended/archived leech cards NOT auto-added — they remain diagnosable on management page but not in session).
- Confirmed sense only.
- Does NOT write anything.
- Does NOT modify Leech Policy.
- Query count: reuses `SenseReviewLeechQueryService` batch path (no N+1).

**Then implement**: `app/Services/CustomStudy/Queries/LeechAttentionQuery.php`

**Tests count**: ~8

### Phase 3: Token service (no DB, no write)

#### Task CS-7: `CustomStudySessionTokenService`
**Test first**: `tests/Unit/CustomStudySessionTokenServiceTest.php`
- `issue()` returns an opaque encrypted string.
- `verify()` returns the decoded payload for a valid token.
- `verify()` returns null for:
  - Tampered token.
  - Expired token (`expires_at` < now).
  - Token with wrong `user_id` (does not match the passed-in user).
  - Token with wrong `language` (does not match the passed-in language).
  - Token with unsupported `version`.
- Token payload contains: `version`, `user_id`, `language`, `mode`, `parameters`, `issued_at`, `expires_at`, `nonce`.
- `expires_at` defaults to `issued_at + 4 hours`.
- `nonce` is UUID v4.
- Uses Laravel `Crypt::encryptString()` / `Crypt::decryptString()` (or project's existing secure token mechanism — verify before implementing).
- Does NOT persist anything.
- Does NOT call AI.

**Then implement**: `app/Services/CustomStudy/CustomStudySessionTokenService.php`

**Tests count**: ~12

### Phase 4: Session orchestration (read-only, no write)

#### Task CS-8: `CustomStudySessionService` — `openSession`
**Test first**: `tests/Feature/CustomStudyOpenSessionTest.php`
- POST `/custom-study/sessions` with valid criteria → 200 + `{ token, first_card, summary }`.
- Calls `CustomStudyCriteriaValidator` → `CustomStudyQueryService` (per mode) → `CustomStudySessionTokenService::issue()`.
- `first_card` is serialized via `SenseReviewCardSerializerService` (same shape as `/reviews/senses`).
- `summary` includes `{ total_candidates, mode, expires_at }`.
- 422 on invalid criteria (structured errors, no partial save).
- 401 if not authenticated.
- 404 if chapter does not belong to user + language (do NOT leak existence).
- Does NOT write ReviewLog.
- Does NOT modify ReviewCard / lifecycle / FSRS.
- Does NOT call AI.
- Query budget: 1-2 (validate) + 1-2 (query candidates, page size 1-2) ≤ 4 total.

**Then implement**: `app/Services/CustomStudy/CustomStudySessionService.php` + `app/Http/Controllers/CustomStudyController.php` (open action only)

**Tests count**: ~10

#### Task CS-9: `CustomStudySessionService` — `nextCard`
**Test first**: `tests/Feature/CustomStudyNextCardTest.php`
- GET `/custom-study/sessions/next?token=...` → 200 + `{ next_card, summary }`.
- Re-validates token (user, language, expiry).
- Re-runs criteria query (does NOT trust a frozen card list).
- Applies mode-specific order override (§7 of ADR-0016).
- Returns the first card after order override.
- Excludes the previously-shown card via `?exclude=card_id` (client-supplied exclude is allowed because server re-validates the card is in the candidate set).
- Returns `{ next_card: null }` when the session is exhausted.
- 401/404 on invalid token.
- Does NOT write anything.
- Does NOT call AI.
- Query budget: 2-4 total.

**Then implement**: extend `CustomStudySessionService` + `CustomStudyController` (next action)

**Tests count**: ~11

#### Task CS-10: Session-internal ordering
**Test first**: `tests/Unit/CustomStudySessionOrderTest.php`
- `today_forgotten` order: most recent Again first (by `ReviewLog.reviewed_at DESC`), fallback to Queue Order.
- `overdue` order: ascending retrievability (reuse `ReviewQueueOrderService` retrievability computation), fallback to Queue Order.
- `source_chapter` order: current Queue Order (no override).
- `leech_attention` order: severity DESC (leech=2, struggling=1, stable=0), then Queue Order.
- Override only applies within the session.
- Does NOT modify global Queue Order settings.
- Does NOT write settings.
- Deterministic for the same input.

**Then implement**: `app/Services/CustomStudy/CustomStudySessionOrder.php`

**Tests count**: ~10

### Phase 5: Frontend (Vue 2 + Vuetify)

#### Task CS-11: `CustomStudy.vue` page skeleton
**Guard test first**: `tests/js/CustomStudyPageGuard.test.mjs`
- Page exists at `resources/js/components/CustomStudy/CustomStudy.vue`.
- Imports `VSelect`, `VCard`, `VBtn` from Vuetify (no global Vue UI framework import).
- No `axios.get('/custom-study/sessions')` outside of methods (must be in a named method, not inline).
- No `Math.random()` for card selection.
- No `localStorage` for token storage (token goes in URL query or Vuex, NOT localStorage — security rule).
- No `eval`, no `v-html` with user input.

**Then implement**: `resources/js/components/CustomStudy/CustomStudy.vue` — criteria selector (4 modes) + sub-mode selector for `leech_attention` + chapter picker for `source_chapter` + "Start session" button.

**Tests count**: ~8 guard tests

#### Task CS-12: Session UI — show card + advance
**Guard test first**: `tests/js/CustomStudySessionUiGuard.test.mjs`
- Reuses `SenseReviewCard` display component (does NOT duplicate card rendering).
- "Next" button calls `/custom-study/sessions/next?token=...&exclude=...`.
- Stale-response guard: `nextCardLoading` flag + `nextCardRequestSequence` counter.
- Double-click does NOT fire two requests.
- `next_card === null` shows "session complete" state.
- "Exit session" button navigates back to home (no API call needed — preview-only, nothing to clean up).
- Token stays in URL query (refresh-safe).
- No external AI call.

**Then implement**: extend `CustomStudy.vue` + a child `CustomStudySession.vue` component.

**Tests count**: ~10 guard tests

### Phase 6: Routes and integration

#### Task CS-13: Routes + middleware
**Test first**: `tests/Feature/CustomStudyRoutesTest.php`
- `POST /custom-study/sessions` requires auth.
- `GET /custom-study/sessions/next` requires auth.
- Routes registered in `routes/web.php` inside `auth` middleware group.
- No admin-only restriction (any authenticated user can use Custom Study).
- 405 on wrong method.
- No new middleware added.

**Then implement**: add 2 routes to `routes/web.php`.

**Tests count**: ~6

### Phase 7: Regression and full suite

#### Task CS-14: Regression — existing flows unchanged
**Test**: run full `php artisan test` suite.
- All 669+ existing tests still pass.
- No new ReviewLog written by Custom Study (assert via test DB row count before/after).
- No lifecycle change (assert `lifecycle_state` unchanged).
- No FSRS change (assert `fsrs_due_at` unchanged).
- Normal `/reviews` and `/reviews/senses` queues unchanged.

**Tests count**: 0 new (regression only)

---

## Allowed files (for a future authorized implementation round)

### Backend — create
- `app/Services/CustomStudy/CustomStudyCriteria.php`
- `app/Services/CustomStudy/CustomStudyCriteriaValidator.php`
- `app/Services/CustomStudy/ChapterLocatorInterface.php`
- `app/Services/CustomStudy/Queries/TodayForgottenQuery.php`
- `app/Services/CustomStudy/Queries/OverdueQuery.php`
- `app/Services/CustomStudy/Queries/SourceChapterQuery.php`
- `app/Services/CustomStudy/Queries/LeechAttentionQuery.php`
- `app/Services/CustomStudy/CustomStudySessionTokenService.php`
- `app/Services/CustomStudy/CustomStudySessionService.php`
- `app/Services/CustomStudy/CustomStudySessionOrder.php`
- `app/Http/Controllers/CustomStudyController.php`
- `app/Exceptions/CustomStudyValidationException.php`

### Backend — modify (extend only)
- `routes/web.php` — add 2 routes inside `auth` group.

### Frontend — create
- `resources/js/components/CustomStudy/CustomStudy.vue`
- `resources/js/components/CustomStudy/CustomStudySession.vue`

### Tests — create
- `tests/Unit/CustomStudyCriteriaTest.php`
- `tests/Unit/CustomStudyCriteriaValidatorTest.php`
- `tests/Unit/CustomStudySessionTokenServiceTest.php`
- `tests/Unit/CustomStudySessionOrderTest.php`
- `tests/Feature/CustomStudyTodayForgottenQueryTest.php`
- `tests/Feature/CustomStudyOverdueQueryTest.php`
- `tests/Feature/CustomStudySourceChapterQueryTest.php`
- `tests/Feature/CustomStudyLeechAttentionQueryTest.php`
- `tests/Feature/CustomStudyOpenSessionTest.php`
- `tests/Feature/CustomStudyNextCardTest.php`
- `tests/Feature/CustomStudyRoutesTest.php`
- `tests/js/CustomStudyPageGuard.test.mjs`
- `tests/js/CustomStudySessionUiGuard.test.mjs`

### Docs — create/modify
- `docs/adr/ADR-0016-custom-study-preview-session.md` (already created in 2000-10A)
- `docs/plans/custom-study-1a-implementation-plan.md` (this file — already created in 2000-10A)
- `docs/plans/linguacafe-master-plan.md` (update status when implementation starts)
- `docs/plans/current-working-handoff.md` (update when implementation starts)
- `docs/DOCUMENTATION_INDEX.md` (update when implementation starts)

---

## Forbidden files (must NOT be touched in 1A)

- Any migration file (no `custom_study_sessions` table, no `card_marker` column).
- `app/Services/ReviewCardService.php` (recordReview unchanged).
- `app/Services/ReviewQueueOrderService.php` (no changes — Custom Study reuses it read-only).
- `app/Services/ReviewQueueOrderPolicy.php` (no changes).
- `app/Services/ReviewQueueOrderOptions.php` (no changes).
- `app/Services/ReviewStudyTimezoneService.php` (no changes — Custom Study reuses it read-only).
- `app/Services/SenseReviewLeechPolicy.php` (no changes — Custom Study reuses it read-only).
- `app/Services/SenseReviewLeechQueryService.php` (no changes — Custom Study reuses it read-only).
- `app/Services/SenseReviewService.php` (no changes to normal due queue).
- `app/Models/ReviewCard.php` (scope unchanged).
- `app/Models/ReviewLog.php` (no schema change, no new fillable).
- `app/Models/WordSense.php` (no schema change).
- `app/Models/WordSenseOccurrence.php` (no schema change).
- Any FSRS-related file (`app/Services/Fsrs*`).
- `resources/js/components/Senses/SenseReview.vue` (no changes to normal sense review).
- `resources/js/components/Review/Review.vue` (no changes to legacy review).
- `app/Http/Controllers/ReviewController.php` (no changes).
- `app/Http/Controllers/SenseReviewController.php` (no changes).
- `app/Http/Controllers/SettingsController.php` (no new settings for Custom Study 1A).
- `app/Http/Controllers/ReviewCardManageController.php` (no Browser Search changes).
- `app/Services/ReviewCardBrowserSearchParser.php` (no syntax changes).
- `app/Services/ReviewCardManageQueryService.php` (no changes).
- `.env`, `AGENTS.md`, `.playwright-cli/`, `.omo/`.

---

## Backend test matrix

| Test file | Type | Count | Key assertions |
|---|---|---|---|
| `CustomStudyCriteriaTest` | Unit | ~10 | fromArray, toArray, invalid mode, missing params, unknown keys ignored, immutable |
| `CustomStudyCriteriaValidatorTest` | Unit | ~12 | user/language/mode validation, chapter ownership, sub_mode, returns criteria or throws |
| `CustomStudySessionTokenServiceTest` | Unit | ~12 | issue, verify valid, tampered, expired, wrong user, wrong language, wrong version, payload shape, 4h expiry, UUID nonce |
| `CustomStudySessionOrderTest` | Unit | ~10 | per-mode order, fallback to Queue Order, deterministic, no global setting change |
| `CustomStudyTodayForgottenQueryTest` | Feature | ~9 | source/rating/undone_at filter, day boundary via ReviewStudyTimezoneService, eligibility, confirmed sense, no write, query count |
| `CustomStudyOverdueQueryTest` | Feature | ~7 | strict < dayStart, eligibility, confirmed sense, empty, no write, query count |
| `CustomStudySourceChapterQueryTest` | Feature | ~9 | source_chapter_id match, occurrence match, distinct, no leakage, no per-card query, eligibility, no write |
| `CustomStudyLeechAttentionQueryTest` | Feature | ~8 | leech_only, leech_plus_struggling, reuse SenseReviewLeechQueryService, no Policy duplication, eligibility, no auto-add suspended, no write |
| `CustomStudyOpenSessionTest` | Feature | ~10 | 200 + token + first_card + summary, 422 invalid, 401 unauth, 404 chapter not owned, no ReviewLog, no lifecycle change, no FSRS change, no AI, query budget |
| `CustomStudyNextCardTest` | Feature | ~11 | 200 + next_card, re-validate token, re-run query, order override, exclude param, null when exhausted, 401/404, no write, no AI, query budget |
| `CustomStudyRoutesTest` | Feature | ~6 | POST/GET require auth, registered in auth group, not admin-only, 405 wrong method, no new middleware |
| **Subtotal** | | **~104** | |

## Frontend guard test matrix

| Test file | Count | Key assertions |
|---|---|---|
| `CustomStudyPageGuard.test.mjs` | ~8 | page exists, Vuetify imports, no inline axios, no Math.random, no localStorage token, no eval/v-html |
| `CustomStudySessionUiGuard.test.mjs` | ~10 | reuses SenseReviewCard, next button calls correct endpoint, stale-response guard, double-click guard, null next_card handling, exit button, token in URL, no AI |
| **Subtotal** | **~18** | |

---

## MCP Chrome matrix (real browser acceptance — to be run when implementation is authorized)

### Setup
- Account: `1816529781@qq.com` (or local admin fallback).
- Two viewports: 1920×1080 and 900×900.
- Test data: prepare a chapter with confirmed sense cards including overdue, today-forgotten (via prior Again ratings), and leech-classified cards.

### Page 1: Custom Study entry
1. Open Custom Study page.
2. Mode selector shows 4 options.
3. `source_chapter` mode shows chapter picker.
4. `leech_attention` mode shows sub-mode picker.
5. "Start session" button disabled until valid input.
6. Console: no errors.
7. Network: no external AI request.

### Page 2: Session in progress
1. First card matches the criteria.
2. "Next" advances to the next card in mode-specific order.
3. Card display identical to normal sense review (same serializer).
4. Refresh preserves session (token in URL).
5. "Exit session" returns to home.
6. No ReviewLog written (verify via DB before/after).
7. No lifecycle change (verify via DB).
8. No FSRS change (verify via DB).
9. Console: no errors.
10. Network: no external AI request.

### Page 3: After session exit
1. Normal `/reviews/senses` queue is unchanged.
2. Normal `/reviews` queue is unchanged.
3. No new ReviewLog rows.
4. No lifecycle state change.
5. No `fsrs_due_at` change.

### Cross-mode checks
1. `today_forgotten` — most recent Again first.
2. `overdue` — ascending retrievability first.
3. `source_chapter` — current Queue Order.
4. `leech_attention` — leech before struggling, then Queue Order.

### Two-viewport checks
1. 1920×1080: no overflow, all controls visible.
2. 900×900: responsive layout, no horizontal scroll.

---

## Commit plan (for a future authorized implementation round)

Suggested commits (do NOT use `git add -A` or `git add .` — stage files explicitly):

### Commit 1: `feat: add custom study criteria and query services`
- `app/Services/CustomStudy/CustomStudyCriteria.php`
- `app/Services/CustomStudy/CustomStudyCriteriaValidator.php`
- `app/Services/CustomStudy/ChapterLocatorInterface.php`
- `app/Services/CustomStudy/Queries/TodayForgottenQuery.php`
- `app/Services/CustomStudy/Queries/OverdueQuery.php`
- `app/Services/CustomStudy/Queries/SourceChapterQuery.php`
- `app/Services/CustomStudy/Queries/LeechAttentionQuery.php`
- `app/Exceptions/CustomStudyValidationException.php`
- `tests/Unit/CustomStudyCriteriaTest.php`
- `tests/Unit/CustomStudyCriteriaValidatorTest.php`
- `tests/Feature/CustomStudyTodayForgottenQueryTest.php`
- `tests/Feature/CustomStudyOverdueQueryTest.php`
- `tests/Feature/CustomStudySourceChapterQueryTest.php`
- `tests/Feature/CustomStudyLeechAttentionQueryTest.php`

### Commit 2: `feat: add custom study session token and orchestration`
- `app/Services/CustomStudy/CustomStudySessionTokenService.php`
- `app/Services/CustomStudy/CustomStudySessionService.php`
- `app/Services/CustomStudy/CustomStudySessionOrder.php`
- `app/Http/Controllers/CustomStudyController.php`
- `routes/web.php` (2 routes added)
- `app/Exceptions/CustomStudyValidationException.php` (if not in commit 1)
- `tests/Unit/CustomStudySessionTokenServiceTest.php`
- `tests/Unit/CustomStudySessionOrderTest.php`
- `tests/Feature/CustomStudyOpenSessionTest.php`
- `tests/Feature/CustomStudyNextCardTest.php`
- `tests/Feature/CustomStudyRoutesTest.php`

### Commit 3: `feat: add custom study frontend page and session ui`
- `resources/js/components/CustomStudy/CustomStudy.vue`
- `resources/js/components/CustomStudy/CustomStudySession.vue`
- `tests/js/CustomStudyPageGuard.test.mjs`
- `tests/js/CustomStudySessionUiGuard.test.mjs`

### Commit 4: `docs: update custom study 1a status after implementation`
- `docs/plans/linguacafe-master-plan.md`
- `docs/plans/current-working-handoff.md`
- `docs/DOCUMENTATION_INDEX.md`
- `docs/adr/ADR-0016-custom-study-preview-session.md` (status → Implemented)

---

## Explicit exclusions (re-stated per task spec)

This plan does NOT authorize and does NOT cover:

1. **Saved Search** — separate main-line; not in 1A.
2. **today-only limits** — separate main-line; not in 1A.
3. **Review Ahead** — later main-line.
4. **Preview recently added new cards** — later sub-mode.
5. **Arbitrary Browser Search string as Custom Study source** — belongs to Saved Search.
6. **Preset** — FSRS-Anki-Mgmt-9.
7. **deck / filtered deck data model** — LinguaCafe has no deck model.
8. **rescheduling mode** — future, requires separate ADR + risk confirmation.
9. **Card Marker** — preserved as `Custom Study 1B prerequisite: Card Marker`. No migration, no lifecycle-as-marker, no leech-as-marker.
10. **Study Overview** — separate main-line.
11. **Increasing today's new/review limit** — belongs to today-only limits.
12. **Any FSRS algorithm or parameter change.**
13. **Any ReviewLog schema change.**
14. **Any lifecycle state machine change.**
15. **Any Leech Policy change.**
16. **Any Browser Search syntax change.**
17. **Any Card Info read model change.**
18. **Any external AI provider call.**
19. **Any `.env` change.**
20. **Any `AGENTS.md` change.**
21. **Force push.**

---

## V1 boundary reminder

This plan is a **TDD roadmap only**. It is not authorization to start coding. Starting implementation requires:

1. A separate task authorization from the 网页端总流程设计师.
2. Architecture Gate review per `AGENTS.md`.
3. Confirmation that Queue Order production acceptance (Task 2000-10A) is closed.
4. Confirmation that no Card Marker code is being snuck into 1A.

Until all four are satisfied, Custom Study 1A remains **architecture complete, development not started**.
