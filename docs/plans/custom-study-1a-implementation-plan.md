# Custom Study 1A Implementation Plan

> **Status**: Architecture complete (ADR-0016 accepted). **Phase 1 (Task CS-1 + CS-2) ✅ Accepted (Task 2000-16 + Task 2000-17 error contract fix). Phase 2A (CS-3 `TodayForgottenQuery` + CS-4 `OverdueQuery`) ✅ Accepted (Task 2000-17 + Task 2000-18 docs fix). Phase 2B (`EloquentChapterLocator` + `SourceChapterQuery` + `LeechAttentionQuery` + `CustomStudyQueryService`) ✅ Accepted (Task 2000-18). Phase 3A (`CustomStudySessionState` immutable value object + `CustomStudySessionTokenService` encrypt/decrypt/verify only + `CustomStudySessionStateException` + 2 unit test files) ✅ Accepted (Task 2000-19 + Task 2000-20 docs closure). Phase 3B (`CustomStudyPreviewPolicy` pure state transition function + `CustomStudyPreviewPolicyException` + `CustomStudySessionState::withProgress()` + `waitUntil()` + `isCompleted()` + Token constant single-source reference + 2 unit test files) code and tests completed in Task 2000-20; docs/harness status drift closed in Task 2000-21, awaiting 网页端总流程设计师 final closure. Phase 4A (`CustomStudySessionOrder` session-internal ordering service + 1 feature test file) code and tests completed in Task 2000-21, awaiting web-side acceptance. Phase 4B-7 NOT started. Overall feature NOT usable. This plan is a TDD roadmap; no Custom Study API, page, route, controller, or migration is authorized by this plan alone beyond Phase 1 value objects/validator, Phase 2A/2B queries + candidate ID dispatcher, Phase 3A immutable session state + encrypted token service, Phase 3B pure state transition policy, and Phase 4A session-internal ordering.**

**Goal**: Implement Custom Study 1A — a preview-only temporary session that lets the user review a curated set of sense cards outside the normal due queue, without moving cards, building a filtered deck, writing ReviewLog, or running FSRS scheduling.

**Tech stack**: Laravel PHP + Vue 2 + Vuetify + PHPUnit + Node.js built-in `assert` guard tests.

**Related ADR**: `docs/adr/ADR-0016-custom-study-preview-session.md`

**Authorization required before any task below can start**:
1. A separate task authorization from the 网页端总流程设计师.
2. Architecture Gate review per `AGENTS.md`.
3. Confirmation that Queue Order production acceptance (Task 2000-10A) is closed.
4. Confirmation that the `Card Marker` 1B prerequisite is NOT being snuck into 1A.

**Phase status (Task 2000-21)**:
- Phase 1 (CS-1 `CustomStudyCriteria` + CS-2 `CustomStudyCriteriaValidator` + `ChapterLocatorInterface` + `CustomStudyValidationException` + 2 unit test files): ✅ Code and tests completed in Task 2000-16. ✅ Error contract architecture fixed in Task 2000-17 (Criteria throws structured `CustomStudyValidationException` directly with stable `field`/`reason`; Validator no longer parses message text). ✅ Accepted by web-side.
- Phase 2A (CS-3 `TodayForgottenQuery` + CS-4 `OverdueQuery`): ✅ Code and tests completed in Task 2000-17. ✅ Accepted by web-side. ✅ Phase 2A 文档旧契约 (CS-3/CS-4 "返回空集合"描述) 在 Task 2000-18 修正为 "返回可组合 Builder".
- Phase 2B (CS-5 `SourceChapterQuery` + CS-6 `LeechAttentionQuery` + `EloquentChapterLocator` + `CustomStudyQueryService`): ✅ Code and tests completed in Task 2000-18. ✅ Accepted by web-side (Task 2000-19 docs closure). Two-layer boundary frozen: SQL-native Queries return Builder (today_forgotten/overdue/source_chapter); `LeechAttentionQuery` is Policy-derived returning `list<int>` (复用 `SenseReviewLeechQueryService` + `SenseReviewLeechPolicy`, no Policy duplication). `CustomStudyQueryService` is the unified `candidateIds()` boundary for the four modes — no `QueryInterface`, no DTO, no Repository, no Adapter.
- Phase 3A (`CustomStudySessionState` + `CustomStudySessionTokenService` + `CustomStudySessionStateException` + 2 unit test files): ✅ Code and tests completed in Task 2000-19. ✅ Accepted by web-side (Task 2000-20). State is an immutable value object with `completed_ids` + `skipped_ineligible_ids` (five-state union + mutual exclusion invariants verifiable from state alone). TokenService only encrypts/decrypts/verifies (no `rotate(answer)`, no rating/answer branching, no SessionService/PreviewPolicy/Controller/routes/Vue). Injects `Illuminate\Contracts\Encryption\Encrypter`. `MAX_TOKEN_BYTES=65536`, `DEFAULT_TTL_SECONDS=14400`, `MAX_CANDIDATE_COUNT=500`.
- Phase 3B (`CustomStudyPreviewPolicy` + `CustomStudyPreviewPolicyException` + `CustomStudySessionState::withProgress()` + `waitUntil()` + `isCompleted()` + Token constant single-source reference + 2 unit test files): ✅ Code and tests completed in Task 2000-20. ✅ Docs/harness status drift closed in Task 2000-21, awaiting web-side final closure. Phase 3B is the **pure state transition layer** — `CustomStudyPreviewPolicy::applyRating(state, rating, now)` and `CustomStudyPreviewPolicy::resume(state, now)` are pure functions that consume an immutable `CustomStudySessionState` and return a new `CustomStudySessionState` via `withProgress()`. They do NOT touch DB / Auth / Request / Crypt / ReviewLog / FSRS / lifecycle / AI / SessionService / Controller / routes / Vue. The Policy only accepts four frozen lowercase ratings (`again` / `hard` / `good` / `easy`); Again/Hard move the current card to `delayed_repeat_queue`, Good/Easy move it to `completed_ids`. The Policy does NOT call `toArray()` / `fromArray()` — it goes through `withProgress()`. Token constants (`VERSION`, `MAX_CANDIDATE_COUNT`) now reference `CustomStudySessionState` (single source of truth). Phase 3B does NOT implement SessionService, Controller, routes, or any HTTP surface.
- Phase 4A (`CustomStudySessionOrder` session-internal ordering service + 1 feature test file): ✅ Code and tests completed in Task 2000-21, awaiting web-side acceptance. Phase 4A is the **session-internal ordering layer** — `CustomStudySessionOrder::order(candidateIds, criteria, userId, language, now, queueOptions)` takes unordered candidate IDs from `CustomStudyQueryService` and returns an ordered `list<int>` of sense-card IDs ready for `CustomStudySessionState::createInitial()`. Batch-loads ReviewCard once (filters `user_id` + `language` + `target_type=sense`), computes one canonical fallback rank via `ReviewQueueOrderService::order()`, applies per-mode primary sort key (source_chapter = canonical; overdue = retrievability ASC; today_forgotten = latest today-again DESC; leech_attention = severity DESC), tie-breaks on canonical fallback. Does NOT apply `card_limit`, does NOT create `SessionState`, does NOT create token, does NOT write any table, does NOT modify Queue Order settings, does NOT re-run Criteria queries, does NOT call `QueryService`. Phase 4A does NOT implement SessionService, Controller, routes, or any HTTP surface.
- Phase 4B (Session orchestration / `CustomStudySessionService`): NOT started.
- Phase 5 (Frontend / SenseStudyCard / Chapter picker): NOT started. AI translation card display registered as future requirement (ADR-0016 §20.7.1), NOT implemented. Chapter picker future contract registered (ADR-0016 §21, Task 2000-19 + Task 2000-21 `candidate_count` display contract), NOT implemented. OPEN PRODUCT GATE: `candidate_count` semantics (A: total available vs B: after `card_limit`) registered by Task 2000-21, awaiting user decision before Phase 5/6 implementation.
- Phase 6 (Routes): NOT started.
- Overall feature: NOT usable. No route, no controller, no page, no API endpoint exists yet.
- `ChapterLocatorInterface` has production binding in Task 2000-18 (`EloquentChapterLocator` in `AppServiceProvider`). `app(ChapterLocatorInterface::class)` and `app(CustomStudyCriteriaValidator::class)` are resolvable. `app(CustomStudySessionTokenService::class)` is resolvable via auto-concrete-resolution + `Illuminate\Contracts\Encryption\Encrypter` (Laravel default registered).
- **Error contract (frozen by Task 2000-17)**: `field`/`reason` are the machine protocol. `message` is for human reading only. Callers MUST NOT parse `message` text to derive `field`/`reason`. The old `translateCriteriaException()` / `str_contains($message, ...)` control flow has been abolished and is guarded against by source-level tests.
- **Phase 2 architecture boundary (frozen by Task 2000-18)**: SQL-native Queries return composable Builder; `LeechAttentionQuery` returns `list<int>` derived from real `SenseReviewLeechPolicy`. `CustomStudyQueryService::candidateIds()` is the unified output boundary. No new `QueryInterface`, DTO, Repository, or Adapter. No sorting, no `card_limit`, no serializer, no session, no token at the Query layer.
- **Phase 3A architecture boundary (frozen by Task 2000-19)**: `CustomStudySessionState` is a pure immutable value object — no DB, no Auth, no Request, no Crypt, no ReviewLog, no FSRS, no AI, no setter, no answer/rate/resume/nextCard/transition/rotate. `CustomStudySessionTokenService` only encrypts/decrypts/verifies — no `rotate(answer)`, no rating/answer branching, no SessionService, no PreviewPolicy, no Controller, no routes, no Vue. V1 payload includes explicit `completed_ids` + `skipped_ineligible_ids` (architectural gap from Task 2000-18 closed). Five-state union + mutual exclusion invariants are verifiable from state alone. Time fields are UTC Unix seconds. `session_id` is strict UUID v4. `step` is non-negative integer token revision (Phase 3A validates only, does NOT implement rotation).

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

### Recommended V1 architecture — Option B (server-signed rotating session-state token, no DB session)
Target service pipeline (NOT yet implemented):
```
CustomStudyCriteria (value object, no DB)
  → CustomStudyCriteriaValidator (pure, no DB, no Auth)
  → CustomStudyQueryService (builds the candidate query, applies criteria, no write)
  → CustomStudySessionState (value object: ordered_candidate_ids, ready_queue,
      delayed_repeat_queue, completed_ids, skipped_ineligible_ids, completed_count,
      total_count, current_card_id, step, preview_delay_config)
  → CustomStudySessionTokenService (issue/verify only, no DB; signs and verifies the
      encrypted token via injected Illuminate\Contracts\Encryption\Encrypter — does NOT
      implement rotate(answer), rating, or any state transition)
  → CustomStudyPreviewPolicy (pure state transition function: applies a rating or resume
      to the current state and returns a new immutable CustomStudySessionState via
      withProgress(); Again/Hard → delayed_repeat_queue, Good/Easy → completed_ids;
      picks the next current_card_id from ready_queue first, else the earliest
      available delayed_repeat_queue entry whose available_at <= now; returns
      waitUntil() + isCompleted() via the new state — NOT a token signer)
  → CustomStudySessionService (future orchestrator: validate token → re-validate
      eligibility → call CustomStudyPreviewPolicy → call
      CustomStudySessionTokenService::issue(newState) → return refreshed_token;
      no write to ReviewLog / FSRS / lifecycle / DB)
  → CustomStudySessionOrder (pure function: mode-specific order override at creation only)
  → SenseReviewCardSerializerService (serializes next card, same shape as /reviews/senses)
  → shared card presentation component (SenseStudyCard.vue — see Frontend section)
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

### Token payload (V1: server-signed encrypted session-state token, 4-hour expiry)
- `version`, `user_id`, `language`, `mode` (one of the four Criteria modes: `today_forgotten` / `overdue` / `source_chapter` / `leech_attention` — NOT the literal string "preview-only"; "preview-only" describes the whole Custom Study 1A feature, not a value of `mode`), `parameters` (criteria + sub-mode + order override), `session_id` (UUID v4), `issued_at`, `expires_at`
- `ordered_candidate_ids` (ordered snapshot of candidate card IDs at session creation — up to `card_limit` max 500)
- `ready_queue` (card IDs not yet answered, in session order)
- `delayed_repeat_queue` (card IDs that received Again/Hard, with `available_at` timestamps)
- `completed_ids` (card IDs that received Good/Easy — explicit ID list, not just a count)
- `skipped_ineligible_ids` (card IDs that failed eligibility re-validation mid-session — explicit ID list)
- `completed_count`, `total_count`, `current_card_id`, `step`
- `preview_delay_config` (again_secs=60, hard_secs=600, good_secs=0, easy_secs=0)
- Encrypted via the injected `Illuminate\Contracts\Encryption\Encrypter` contract (Laravel default binding). The implementation MUST NOT call the static `Crypt::encryptString()` / `Crypt::decryptString()` facade directly — the Encrypter is injected in the `CustomStudySessionTokenService` constructor so the key/cipher can be substituted in tests.
- Server re-validates `user_id` + `language` on every request.
- Server re-validates each card's eligibility (confirmed sense, lifecycle, fsrs_enabled) before showing it — ineligible cards silently skipped.
- Client cannot pass arbitrary `card_id` — server always picks next from token's `ready_queue` or `delayed_repeat_queue`.
- Every answer returns a **new encrypted token**; the previous token becomes **client-obsolete** — the client discards it and uses only the new token. The server does NOT maintain a session table and cannot actively invalidate old tokens. Stale responses with old token rejected by client stale-response guard.
- Token stored in `sessionStorage` (not `localStorage`). URL carries only `session_id` or route info. Full token goes in request body only — never in URL or query string.
- **No A→B→A loop**: `ready_queue` consumed in order; Again/Hard moves card to `delayed_repeat_queue` (not back to `ready_queue`); Good/Easy completes card. Session ends when both queues empty.

### V1 card_limit freeze
- Default: 100.
- Minimum: 1.
- Maximum: 500.
- Anki's 99,999 default is NOT used (relies on DB filtered deck; LinguaCafe uses stateless encrypted token).
- `card_limit < 1` → 422. `card_limit > 500` → 422. Non-integer → 422. Omitted → default 100.

### Three frozen API routes (see ADR-0016 §16)
1. `POST /custom-study/sessions` — create session. Request: `{ mode, parameters, card_limit }`. Response: `{ token, session_id, current_card, summary, expires_at }`.
2. `POST /custom-study/sessions/answer` — answer current card. Request: `{ token, rating }`. Response: `{ refreshed_token, current_card, summary, wait_until, completed }`.
3. `POST /custom-study/sessions/resume` — resume/advance session. Request: `{ token }`. Response: `{ refreshed_token, current_card, summary, wait_until, completed }`.

Prohibited: `GET /custom-study/sessions/next`, `exclude=card_id`, full token in URL/query string.
All three routes: auth middleware, not admin-only. 401 unauthenticated. 404 tampered/expired/wrong user/wrong language. 422 invalid criteria/rating/card_limit.

---

## TDD Breakdown

This plan follows strict TDD (red → green → refactor). Each task lists the test file first, then the implementation file. No implementation file may be written before its test file fails.

### Phase 1: Value objects and validators (pure, no DB)

#### Task CS-1: `CustomStudyCriteria` value object
**Test first**: `tests/Unit/CustomStudyCriteriaTest.php`
- `fromArray()` accepts valid criteria for each of the 4 modes.
- Rejects unknown mode (throws `CustomStudyValidationException` with stable `field`/`reason`).
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
- **Error contract (Task 2000-17 fix)**: `fromArray()` throws `CustomStudyValidationException` directly with `field`/`reason` set at each throw site. The `message` is human-readable only and may change without affecting the contract. Stable `field`/`reason` pairs: `mode/missing_mode`, `mode/unknown_mode`, `criteria/invalid_parameters`, `chapter_id/missing_chapter_id`, `chapter_id/invalid_chapter_id`, `sub_mode/missing_sub_mode`, `sub_mode/invalid_sub_mode`.

**Then implement**: `app/Services/CustomStudy/CustomStudyCriteria.php`

**Tests count**: ~10 (Task 2000-16) + ~15 stability + source-guard tests (Task 2000-17 error contract fix) = ~41

#### Task CS-2: `CustomStudyCriteriaValidator` (pure, no DB)
**Test first**: `tests/Unit/CustomStudyCriteriaValidatorTest.php`
- Validates `user_id` matches authenticated user (passed in, no Auth dependency).
- Validates `language` is non-empty string.
- Validates `mode` is one of the 4 frozen criteria.
- Validates `chapter_id` belongs to `user_id` + `language` (Chapter lookup — this is the only non-pure check; inject a `ChapterLocatorInterface` so the validator stays unit-testable with a stub).
- Validates `sub_mode` for `leech_attention`.
- Returns validated `CustomStudyCriteria` or throws `CustomStudyValidationException`.
- Does NOT query ReviewLog, ReviewCard, or WordSense — only validates shape + chapter ownership.
- **Error contract (Task 2000-17 fix)**: The validator NO LONGER catches a plain SPL exception and NO LONGER parses `message` text to derive `field`/`reason`. `CustomStudyCriteria::fromArray()` throws `CustomStudyValidationException` directly, and the validator lets it propagate unchanged. The validator's own failures (invalid `user_id`, empty `language`, chapter not owned) throw `CustomStudyValidationException` directly with stable `field`/`reason`. Source-level guard tests forbid `translateCriteriaException`, `str_contains($message`, `getMessage()` control branches, and `InvalidArgumentException` catch blocks from reappearing in the validator source.

**Then implement**: `app/Services/CustomStudy/CustomStudyCriteriaValidator.php` + `app/Services/CustomStudy/ChapterLocatorInterface.php`

**Tests count**: ~12 (Task 2000-16) + ~16 source-guard + pass-through + stability tests (Task 2000-17 error contract fix) = ~42

### Phase 2: Query services (read-only, no write)

#### Task CS-3: `CustomStudyQueryService` — `today_forgotten`
**Test first**: `tests/Feature/CustomStudyTodayForgottenQueryTest.php`
- Returns a composable Eloquent `Builder<ReviewCard>` (does NOT load models, does NOT apply `card_limit`, does NOT implement `SessionOrder`).
- Filters via `whereExists` subquery on `review_logs` for `source=sense_review`, `rating=again`, `undone_at IS NULL`, `reviewed_at` within the current learning-timezone natural day (`dayStart <= reviewed_at < nextDayStart`).
- Excludes cards whose `undone_at` is non-null (undone Again does NOT count).
- Excludes cards not belonging to current user + language.
- Excludes cards that are not confirmed sense cards (`target_type=sense`, WordSense confirmed).
- Excludes suspended/archived/buried-not-expired cards (reuse `scopeSenseReviewEligible`).
- Uses `ReviewStudyTimezoneService::dayStart()` for the boundary (NOT `Carbon::today()`).
- Terminates as a **single candidate-card SQL** when `pluck('review_cards.id')` is called — the `whereExists` subquery is correlated, NOT a separate query.
- Empty result is observed only after the Builder is terminated (e.g. `pluck`/`get`/`count`); the Query object itself never holds a "result set".
- Does NOT write anything.
- No N+1: one composable SQL when terminated.

**Then implement**: `app/Services/CustomStudy/Queries/TodayForgottenQuery.php`

**Tests count**: ~9 base + Task 2000-17 expanded to ~29 (timezones, DST, lifecycle, fsrs_enabled, no-write, no-N+1)

#### Task CS-4: `CustomStudyQueryService` — `overdue`
**Test first**: `tests/Feature/CustomStudyOverdueQueryTest.php`
- Returns a composable Eloquent `Builder<ReviewCard>` (does NOT load models, does NOT apply `card_limit`, does NOT implement `SessionOrder`).
- Strict `review_cards.fsrs_due_at < dayStart` (cards due exactly at `dayStart`, later today, or tomorrow are NOT included; `NULL` `fsrs_due_at` is NOT included).
- Uses `ReviewStudyTimezoneService::dayStart()` for the boundary (NOT `Carbon::today()`).
- Excludes ineligible cards (suspended/archived/buried-not-expired) via `scopeSenseReviewEligible`.
- Confirmed sense only via `confirmedSenseCardQuery`.
- Empty result is observed only after the Builder is terminated.
- Does NOT write anything.
- Query count: 1 when terminated. No N+1.

**Then implement**: `app/Services/CustomStudy/Queries/OverdueQuery.php`

**Tests count**: ~7 base + Task 2000-17 expanded to ~24 (strict `<`, DST, lifecycle, fsrs_enabled, no-write, no-N+1)

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

### Phase 3: Token service (no DB, no write) — frozen as issue()/verify() only

> **Task 2000-19 + Task 2000-20 contract freeze**: `CustomStudySessionTokenService` exposes ONLY `issue(CustomStudySessionState): string` and `verify(string, int, string, Carbon): ?CustomStudySessionState`. There is **NO `rotate(answer)` method** and **NO rating/answer/resume/transition/pickNext logic** in TokenService. The future `CustomStudySessionService` (Phase 4) will orchestrate: it calls `CustomStudyPreviewPolicy::applyRating(state, rating, now)` (or `resume`) to obtain a new `CustomStudySessionState`, then calls `TokenService::issue(newState)` to produce the refreshed token. The Policy is the **only** state transition layer; TokenService is the **only** signer/verifier. This contract is enforced by `tests/Unit/CustomStudySessionTokenServiceTest.php` (no `rotate()` method) and `tests/js/CustomStudySessionArchitectureDocsGuard.test.mjs`.

#### Task CS-7: `CustomStudySessionTokenService`
**Test first**: `tests/Unit/CustomStudySessionTokenServiceTest.php`
- `issue()` returns an opaque encrypted string containing the full session state.
- `verify()` returns the decoded payload for a valid token.
- `verify()` returns null for:
  - Tampered token.
  - Expired token (`expires_at` < now).
  - Token with wrong `user_id` (does not match the passed-in user).
  - Token with wrong `language` (does not match the passed-in language).
  - Token with unsupported `version`.
- Token payload contains: `version`, `user_id`, `language`, `mode`, `parameters`, `session_id`, `issued_at`, `expires_at`, `ordered_candidate_ids`, `ready_queue`, `delayed_repeat_queue`, `completed_ids`, `skipped_ineligible_ids`, `completed_count`, `total_count`, `current_card_id`, `step`, `preview_delay_config`.
- `expires_at` defaults to `issued_at + 4 hours`.
- `session_id` is UUID v4.
- Token size must have a max (candidate count capped; oversized tokens rejected at creation).
- Uses the injected `Illuminate\Contracts\Encryption\Encrypter` contract (Laravel default binding). The implementation MUST NOT call the static `Crypt::encryptString()` / `Crypt::decryptString()` facade directly — the Encrypter is injected in the constructor so the key/cipher can be substituted in tests.
- Does NOT persist anything.
- Does NOT call AI.
- Does NOT implement `rotate(answer)` / `applyRating()` / `resume()` / `nextCard()` / `transition()` — those belong to `CustomStudyPreviewPolicy` (Phase 3B) and `CustomStudySessionService` (Phase 4).

**Then implement**: `app/Services/CustomStudy/CustomStudySessionTokenService.php`

**Tests count**: ~16

### Phase 4: Session orchestration (read-only, no write)

#### Task CS-8: `CustomStudySessionService` — `openSession`
**Test first**: `tests/Feature/CustomStudyOpenSessionTest.php`
- POST `/custom-study/sessions` with valid criteria → 200 + `{ token, session_id, current_card, summary, expires_at }`.
- Calls `CustomStudyCriteriaValidator` → `CustomStudyQueryService` (per mode) → `CustomStudySessionState` (populates ordered_candidate_ids + ready_queue from criteria query) → `CustomStudySessionTokenService::issue()`.
- `current_card` is serialized via `SenseReviewCardSerializerService` (same shape as `/reviews/senses`).
- `summary` includes `{ total_candidates, mode, completed_count, total_count }`.
- 422 on invalid criteria (structured errors, no partial save).
- 401 if not authenticated.
- 404 if chapter does not belong to user + language (do NOT leak existence).
- Does NOT write ReviewLog.
- Does NOT modify ReviewCard / lifecycle / FSRS.
- Does NOT call AI.
- Query budget (per ADR-0016 §12): criteria query runs ONCE at session creation, fetching up to `card_limit` (max 500) ordered card IDs in a single batched query. Does NOT load 500 full serializer payloads — only the current card is serialized. answer / resume do NOT re-run the full criteria query; they only re-validate the next candidate's eligibility via a batched window (no per-card N+1).
- `card_limit` validation: default 100, min 1, max 500. `card_limit < 1` → 422. `card_limit > 500` → 422. Non-integer → 422. Omitted → default 100.

**Then implement**: `app/Services/CustomStudy/CustomStudySessionState.php` + `app/Services/CustomStudy/CustomStudySessionService.php` + `app/Http/Controllers/CustomStudyController.php` (open action only)

**Tests count**: ~10

#### Task CS-9: `CustomStudySessionService` — `answer` (rotating token)
**Test first**: `tests/Feature/CustomStudyAnswerTest.php`
- POST `/custom-study/sessions/answer` with `{ token, rating }` → 200 + `{ refreshed_token, current_card, summary, wait_until, completed }`.
- Re-validates token (user, language, expiry, version).
- Applies `CustomStudyPreviewPolicy`:
  - `again` → move `current_card_id` to `delayed_repeat_queue` with `available_at = now + 60s`.
  - `hard` → move to `delayed_repeat_queue` with `available_at = now + 600s`.
  - `good` → remove from both queues (`completed_count++`).
  - `easy` → remove from both queues (`completed_count++`).
- Picks next card from `ready_queue` (front), or from `delayed_repeat_queue` if `ready_queue` empty and a card's `available_at` has passed.
- If only delayed cards remain and none available yet → `{ current_card: null, wait_until: <next available_at> }`.
- If both queues empty → `{ completed: true, current_card: null }`.
- Returns new encrypted `refreshed_token` with updated session state.
- Re-validates each card's eligibility before showing — ineligible cards silently skipped.
- 401/404 on invalid token.
- 422 on invalid rating.
- Does NOT write anything.
- Does NOT call AI.
- No A→B→A loop (ready_queue consumed; delayed cards don't re-enter ready_queue).
- Uses injected clock in tests (no real sleep).

**Then implement**: `app/Services/CustomStudy/CustomStudyPreviewPolicy.php` + extend `CustomStudySessionService` + `CustomStudyController` (answer action)

**Tests count**: ~18

#### Task CS-9.5: `CustomStudySessionService` — `resume` (rotating token)
**Test first**: `tests/Feature/CustomStudyResumeTest.php`
- POST `/custom-study/sessions/resume` with `{ token }` → 200 + `{ refreshed_token, current_card, summary, wait_until, completed }`.
- Re-validates token (user, language, expiry, version).
- Resume semantics (per ADR-0016 §18 invariant 10):
  - If session has `current_card_id` → returns the same current card.
  - If no current card and `ready_queue` non-empty → pops front of `ready_queue` as new current card.
  - If `ready_queue` empty and a delayed card's `available_at` has passed → pops earliest-available delayed card as new current card.
  - If only un-ready delayed cards remain → `{ current_card: null, wait_until: <earliest available_at>, completed: false }`.
  - If all queues empty and no current card → `{ completed: true, current_card: null }`.
- Re-validates the next candidate's eligibility (confirmed sense, lifecycle, fsrs_enabled) via batched window before showing — ineligible cards move to `skipped_ineligible`, do NOT reappear.
- Returns new encrypted `refreshed_token` with updated session state.
- 401/404 on invalid token (tampered / expired / wrong user / wrong language).
- 422 only if token payload is structurally malformed (not for normal resume).
- Does NOT write anything.
- Does NOT call AI.
- Does NOT re-run the full criteria query (uses the ordered_candidate_ids snapshot from the token).
- Uses injected clock in tests (no real sleep).

**Then implement**: extend `CustomStudySessionService` (resume action) + `CustomStudyController` (resume action)

**Tests count**: ~12

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
- No `localStorage` for token storage (token is stored in sessionStorage for the current tab. The full token never appears in the URL, route params, or query string. The URL may contain only session_id or ordinary route information. Do NOT use localStorage or Vuex for the full token.)
- No `eval`, no `v-html` with user input.

**Then implement**: `resources/js/components/CustomStudy/CustomStudy.vue` — criteria selector (4 modes) + sub-mode selector for `leech_attention` + chapter picker for `source_chapter` + "Start session" button.

**Tests count**: ~8 guard tests

#### Task CS-11.5: Shared card presentation component `SenseStudyCard.vue`
**Guard test first**: `tests/js/SenseStudyCardGuard.test.mjs`
- Component exists at `resources/js/components/Senses/SenseStudyCard.vue`.
- Pure presentation: receives `card` prop, emits no rating events (rating buttons are owned by the parent container).
- Does NOT call axios, does NOT own queue state, does NOT write ReviewLog, does NOT run FSRS.
- Displays: example sentence, surface form, sense (zh/en), source context entry.
- No `Math.random()`, no `localStorage`, no `eval`, no `v-html` with user input.

**Then implement**: Extract shared presentation from `SenseReview.vue` into `SenseStudyCard.vue`. `SenseReview.vue` uses this component (refactor must preserve observable behavior — existing guard tests + MCP Chrome regression must pass).

**Presentation contract (frozen by Task 2000-15 — see ADR-0016 §20.5 / §20.6 / §20.7 / §20.8)**:

- **Reading-style token sentence presentation** (`阅读式 token 句子展示`):
  - Reuse existing `resources/js/components/Review/SenseSentencePreview.vue` for sentence rendering. Do NOT create a parallel token component.
  - Data source: `card.example_sentence_tokens`, `card.example_sentence_en`, `card.surface_form`, `card.lemma`. No new API, no new migration, no tokenizer re-call.
  - Tokens present: render each token with existing reading-page `stage` color, preserve spaces/punctuation, mark target word (prefer `token.is_target`, fall back to surface/lemma match).
  - Tokens missing: fall back to plain-text `example_sentence_en`. No blank card face. Do NOT block rating.
  - Read-only: no click-to-look-up, no dictionary open, no `EncounteredWord` change, no familiarity change, no `ReviewLog` write, no FSRS change.
  - No `v-html` for sentence rendering (use `v-for` over token array).
  - Question side and answer side MUST reuse the same sub-component.
  - Token color is LinguaCafe project adaptation (Anki supports custom HTML/CSS but does not freeze a default "target word color highlight" style).

- **Show-answer empty-field hiding** (Anki conditional replacement semantic):
  - `show-answer === false`: only question-side info shown. No `sense_zh`, no `sense_en`, no `aliases_zh`, no `collocations`, no learning-feedback answer content.
  - `show-answer === true`: `sense_zh` always shown. `sense_en` / `aliases_zh` / `collocations` shown **only when non-empty after trim/normalization** — hide the **entire** block (including label) when empty. Do NOT show "暂无英文释义。" / "无" placeholders.
  - Do NOT delete any database field. Do NOT auto-generate missing content. Do NOT call AI. Do NOT make these fields required.
  - Scope limit: supplementary example, understanding aid, learning feedback continue to follow their existing conditional display rules.

**Future implementation test matrix (registered by Task 2000-15, MUST be created and passed when CS-11.5 is actually implemented)**:

Functional tests:
1. `SenseStudyCard.vue` uses `SenseSentencePreview` (or equivalent shared sub-component) for sentence rendering.
2. Token payload present → renders tokens.
3. Target token is marked (via `token.is_target` or surface/lemma fallback).
4. Token `stage` color is preserved.
5. No tokens → falls back to plain-text `example_sentence_en`.
6. No use of `v-html` anywhere in `SenseStudyCard.vue` or its sentence sub-component.
7. Token display is read-only — no click-to-look-up, no dictionary open, no `EncounteredWord` change.
8. `sense_en` empty → entire block hidden (including label).
9. `aliases_zh` empty → entire block hidden (including label).
10. `collocations` empty → entire block hidden (including label).
11. All three field types with content → blocks render normally with labels and chips/text.
12. `sense_zh` is always shown after Show Answer.
13. `show-answer === false` → all answer fields hidden.
14. Normal Sense Review and Custom Study share the same `SenseStudyCard.vue` component.

Non-regression tests:
15. No changes to rating, undo, interval preview, FSRS, ReviewLog, or lifecycle in either Sense Review or Custom Study.
16. MCP Chrome acceptance uses both a real empty-field card and a real populated-field card to verify §20.6 hiding behavior.
17. Two viewports: 1920×1080 and 900×900.

AI translation tests (registered by Task 2000-16 for future CS-11.5, NOT executed in Task 2000-16; see ADR-0016 §20.7.1):
18. `show-answer === false` → main example sentence translation hidden.
19. `show-answer === false` → supplementary example sentence translations hidden.
20. `show-answer === true` + main sentence has translation → translation rendered directly beneath English sentence.
21. `show-answer === true` + supplementary sentence has translation → translation rendered beneath its own English sentence.
22. `show-answer === true` + no translation → no empty block, no "暂无译文" / "无" placeholder.
23. Main example sentence translation shown only once (not duplicated on question side and answer side).
24. Translation text and English tokens are read-only — no click, no dictionary open, no familiarity change.
25. No AI provider call, no WordSense/WordSenseOccurrence/ReviewCard write, no FSRS change, no ReviewLog write.
26. Translation visual uses existing reading-page vertical-stacked style (LinguaCafe adaptation, not Anki default).

**AI translation pre-implementation Gate (registered by Task 2000-16, MUST be satisfied before CS-11.5 implementation can touch this feature)**:
1. Task 2000-16 does NOT implement AI translation display.
2. Before entering CS-11.5, the real translation data chain MUST be investigated.
3. Do NOT assume `example_sentence_zh` is the AI translation without verification.
4. The currently-displayed rotating example sentence and its translation MUST be precisely correlated — no cross-occurrence mismatch.
5. Do NOT guess translations by lemma or surface.
6. If AI Reading Assist fallback is needed, a separate data-contract Gate MUST be done first.
7. Do NOT temporarily call external AI to display translations.
8. Do NOT auto-write WordSense / WordSenseOccurrence / ReviewCard to fill translations.
9. Only a future CS-11.5 implementation prompt is allowed to handle this feature.

**Current status (Task 2000-16)**:
```
AI 译文卡面显示：
已登记到未来 CS-11.5；
本轮未研究数据链；
本轮未实现；
不属于 Phase 1。
```

**Tests count**: ~8 base guard tests + 17 future implementation matrix tests (Task 2000-15) + 9 AI translation tests (Task 2000-16) = 26 future tests registered, not yet executed.

#### Task CS-12: Session UI — show card + advance
**Guard test first**: `tests/js/CustomStudySessionUiGuard.test.mjs`
- Reuses `SenseStudyCard` display component (does NOT duplicate card rendering).
- Four buttons: Again / Hard / Good / Easy (calls `POST /custom-study/sessions/answer` with `{ token, rating }`).
- Stale-response guard: `answerLoading` flag + `answerRequestSequence` counter. Slow responses with old token are dropped.
- Double-click does NOT fire two requests.
- `completed === true` shows "session complete" state.
- `current_card === null && wait_until` shows countdown to next available card.
- "Exit session" button navigates back (no API call — preview-only, nothing to clean up).
- Token stored in `sessionStorage` (NOT `localStorage`). URL carries only `session_id`.
- Refresh recovers from `sessionStorage` via `POST /custom-study/sessions/resume` with `{ token }` in request body.
- Explicit text: "本次为临时预览学习，不会改变正式复习进度。"
- No external AI call.

**Then implement**: extend `CustomStudy.vue` + a child `CustomStudySession.vue` component.

**Tests count**: ~14 guard tests

### Phase 6: Routes and integration

#### Task CS-13: Routes + middleware
**Test first**: `tests/Feature/CustomStudyRoutesTest.php`
- `POST /custom-study/sessions` requires auth.
- `POST /custom-study/sessions/answer` requires auth.
- `POST /custom-study/sessions/resume` requires auth.
- Routes registered in `routes/web.php` inside `auth` middleware group.
- No admin-only restriction (any authenticated user can use Custom Study).
- 405 on wrong method (e.g., GET on any of the three routes).
- 401 unauthenticated.
- 404 tampered / expired / wrong user / wrong language token.
- 422 invalid criteria / rating / card_limit.
- No new middleware added.
- Prohibited routes do NOT exist: `GET /custom-study/sessions/next`, `exclude=card_id` query param.

**Then implement**: add 3 routes to `routes/web.php`.

**Tests count**: ~8

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
- `app/Services/CustomStudy/CustomStudySessionState.php`
- `app/Services/CustomStudy/CustomStudyPreviewPolicy.php`
- `app/Services/CustomStudy/CustomStudySessionTokenService.php`
- `app/Services/CustomStudy/CustomStudySessionService.php`
- `app/Services/CustomStudy/CustomStudySessionOrder.php`
- `app/Http/Controllers/CustomStudyController.php`
- `app/Exceptions/CustomStudyValidationException.php`

### Backend — modify (extend only)
- `routes/web.php` — add 3 routes inside `auth` group (`POST /custom-study/sessions`, `POST /custom-study/sessions/answer`, `POST /custom-study/sessions/resume`).

### Frontend — create
- `resources/js/components/CustomStudy/CustomStudy.vue`
- `resources/js/components/CustomStudy/CustomStudySession.vue`
- `resources/js/components/Senses/SenseStudyCard.vue` (shared presentation component — see ADR-0016 §20)

### Frontend — modify (SenseStudyCard extraction only)
- `resources/js/components/Senses/SenseReview.vue` — refactor to use `SenseStudyCard.vue` for shared card presentation. Must preserve observable behavior (existing guard tests + MCP Chrome regression must pass). No changes to normal sense review rating logic, undo, interval preview, or lifecycle operations.

### Tests — create
- `tests/Unit/CustomStudyCriteriaTest.php`
- `tests/Unit/CustomStudyCriteriaValidatorTest.php`
- `tests/Unit/CustomStudySessionStateTest.php`
- `tests/Unit/CustomStudyPreviewPolicyTest.php`
- `tests/Unit/CustomStudySessionTokenServiceTest.php`
- `tests/Unit/CustomStudySessionOrderTest.php`
- `tests/Feature/CustomStudyTodayForgottenQueryTest.php`
- `tests/Feature/CustomStudyOverdueQueryTest.php`
- `tests/Feature/CustomStudySourceChapterQueryTest.php`
- `tests/Feature/CustomStudyLeechAttentionQueryTest.php`
- `tests/Feature/CustomStudyOpenSessionTest.php`
- `tests/Feature/CustomStudyAnswerTest.php`
- `tests/Feature/CustomStudyResumeTest.php`
- `tests/Feature/CustomStudyRoutesTest.php`
- `tests/js/CustomStudyPageGuard.test.mjs`
- `tests/js/SenseStudyCardGuard.test.mjs`
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
- `resources/js/components/Senses/SenseReview.vue` — **exception**: only the SenseStudyCard extraction refactor is allowed (see "Frontend — modify" above). No changes to normal sense review rating logic, undo, interval preview, lifecycle operations, or error recovery. All existing SenseReview guard tests + MCP Chrome regression must pass.
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
| `CustomStudySessionStateTest` | Unit | ~14 | current_card_id exclusivity (invariant 1), state transitions (invariants 6-8), no loss/duplication (invariant 4), completed_count consistency (invariant 5), skipped_ineligible (invariant 11), reliable ending (invariant 12) |
| `CustomStudyPreviewPolicyTest` | Unit | ~10 | again→delayed(60s), hard→delayed(600s), good→completed, easy→completed, wait_until computation, no re-enter ready, pure function |
| `CustomStudySessionTokenServiceTest` | Unit | ~12 | issue, verify valid, tampered, expired, wrong user, wrong language, wrong version, payload shape, 4h expiry, UUID v4 session_id, token size cap |
| `CustomStudySessionOrderTest` | Unit | ~10 | per-mode order, fallback to Queue Order, deterministic, no global setting change |
| `CustomStudyTodayForgottenQueryTest` | Feature | ~9 | source/rating/undone_at filter, day boundary via ReviewStudyTimezoneService, eligibility, confirmed sense, no write, query count |
| `CustomStudyOverdueQueryTest` | Feature | ~7 | strict < dayStart, eligibility, confirmed sense, empty, no write, query count |
| `CustomStudySourceChapterQueryTest` | Feature | ~9 | source_chapter_id match, occurrence match, distinct, no leakage, no per-card query, eligibility, no write |
| `CustomStudyLeechAttentionQueryTest` | Feature | ~8 | leech_only, leech_plus_struggling, reuse SenseReviewLeechQueryService, no Policy duplication, eligibility, no auto-add suspended, no write |
| `CustomStudyOpenSessionTest` | Feature | ~10 | 200 + token + session_id + current_card + summary + expires_at, card_limit validation (422 for <1, >500, non-integer), 422 invalid criteria, 401 unauth, 404 chapter not owned, no ReviewLog, no lifecycle change, no FSRS change, no AI, query budget (criteria query ONCE) |
| `CustomStudyAnswerTest` | Feature | ~18 | 200 + refreshed_token, preview policy (again/hard/good/easy), rotating token, no A→B→A loop, wait_until, completed, 401/404, 422, no write, no AI, injected clock |
| `CustomStudyResumeTest` | Feature | ~12 | resume semantics (invariant 10: current card / ready pop / delayed pop / wait_until / completed), batched eligibility re-validation, no criteria re-query, 401/404, no write, no AI |
| `CustomStudyRoutesTest` | Feature | ~8 | 3 POST routes require auth, registered in auth group, not admin-only, 405 wrong method, 401 unauth, 404 bad token, 422 invalid input, no GET /next route exists, no exclude param |
| **Subtotal** | | **~139** | |

## Frontend guard test matrix

| Test file | Count | Key assertions |
|---|---|---|
| `CustomStudyPageGuard.test.mjs` | ~8 | page exists, Vuetify imports, no inline axios, no Math.random, no localStorage token, no eval/v-html |
| `SenseStudyCardGuard.test.mjs` | ~8 | pure presentation, no axios, no queue, no ReviewLog, no FSRS, no Math.random, no localStorage, no eval/v-html |
| `CustomStudySessionUiGuard.test.mjs` | ~14 | reuses SenseStudyCard, four rating buttons, rotating token, stale-response guard, double-click guard, completed state, wait_until countdown, token in sessionStorage, exit button, no AI |
| **Subtotal** | **~30** | |

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
2. Four rating buttons (Again / Hard / Good / Easy) call `POST /custom-study/sessions/answer` with `{ token, rating }` in request body — advances to the next card in mode-specific order.
3. Card display identical to normal sense review (same serializer, shared SenseStudyCard component).
4. Refresh preserves session: token read from `sessionStorage`, recovery via `POST /custom-study/sessions/resume` with `{ token }` in request body (NOT in URL/query string).
5. "Exit session" returns to home.
6. No ReviewLog written (verify via DB before/after).
7. No lifecycle change (verify via DB).
8. No FSRS change (verify via DB).
9. Console: no errors.
10. Network: no external AI request.
11. Network: no `GET /custom-study/sessions/next` request, no `exclude=` query param, no token in URL.

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

### Commit 2: `feat: add custom study session state, policy, token and orchestration`
- `app/Services/CustomStudy/CustomStudySessionState.php`
- `app/Services/CustomStudy/CustomStudyPreviewPolicy.php`
- `app/Services/CustomStudy/CustomStudySessionTokenService.php`
- `app/Services/CustomStudy/CustomStudySessionService.php`
- `app/Services/CustomStudy/CustomStudySessionOrder.php`
- `app/Http/Controllers/CustomStudyController.php`
- `routes/web.php` (3 routes added: `POST /custom-study/sessions`, `POST /custom-study/sessions/answer`, `POST /custom-study/sessions/resume`)
- `tests/Unit/CustomStudySessionStateTest.php`
- `tests/Unit/CustomStudyPreviewPolicyTest.php`
- `tests/Unit/CustomStudySessionTokenServiceTest.php`
- `tests/Unit/CustomStudySessionOrderTest.php`
- `tests/Feature/CustomStudyOpenSessionTest.php`
- `tests/Feature/CustomStudyAnswerTest.php`
- `tests/Feature/CustomStudyResumeTest.php`
- `tests/Feature/CustomStudyRoutesTest.php`

### Commit 3: `feat: add custom study frontend page, session ui and shared card component`
- `resources/js/components/Senses/SenseStudyCard.vue` (shared presentation component)
- `resources/js/components/CustomStudy/CustomStudy.vue`
- `resources/js/components/CustomStudy/CustomStudySession.vue`
- `resources/js/components/Senses/SenseReview.vue` (refactor to use SenseStudyCard — behavior-preserving)
- `tests/js/SenseStudyCardGuard.test.mjs`
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
