# PAB R2 Lane 4 Regression Matrix

Purpose: handoff from Lane 3 Safety Harness to the single Integration/Browser owner. Lane 3 does not run shared-testing-DB Feature tests or browser writes.

## 1. Pure / parallel-safe gate first

Run before any testing-DB ownership is acquired:

- `php -l tests/Fixtures/ai-assist-v2/v2-payloads.php`
- `php -l tests/Unit/AiReadingAssistV1CompatParserTest.php`
- `php -l tests/Unit/AiReadingAssistV2StrictParserTest.php`
- `php -l tests/Unit/AiReadingAssistV2CandidateOwnershipTest.php`
- `php -l tests/Unit/AiReadingAssistV2BatchingTest.php`
- `php -l tests/Feature/AiReadingAssistV2WriteBoundaryTest.php`
- `php -l tests/Feature/ReadingReviewSettlementContractTest.php`
- `php -l tests/Feature/ReadingReviewSourceUndoAnalyticsTest.php`
- `node tests/js/AiReadingAssistV1CompatibilityGuard.test.mjs`
- `node tests/js/PhaseAReviewWriteSurfaceGuard.test.mjs`
- `node tests/js/PhaseBFormalRatingWriteSurfaceGuard.test.mjs`
- `node tests/js/ReadingRatingSourceContractGuard.test.mjs`
- `git diff --check`

Expected before Backend Core merge:

- V1 parser/compatibility guards: GREEN.
- Phase A/B mutation-surface baseline guards: GREEN when no forbidden path exists.
- V2 service-dependent Unit tests: RED/PENDING until the concrete Backend Core seam exists.
- `ReadingRatingSourceContractGuard`: intentionally RED until `reading_passive` and `reading_explicit` are registered by Backend Core in both formal-rating and undo source authorities.

## 2. Exclusive testing DB — Phase A

Acquire the single shared testing-DB owner. Run serially; never in parallel with another Lane.

New contract suites:

- `AiReadingAssistV2StrictParserTest`
- `AiReadingAssistV2CandidateOwnershipTest`
- `AiReadingAssistV2BatchingTest`
- `AiReadingAssistV2WriteBoundaryTest`

Existing V1 regression suites:

- `AiReadingAssistPreviewTest`
- `AiReadingAssistConfirmTest`
- `AiReadingAssistCurrentTest`
- `AiReadingAssistLookupTest`
- `AiReadingAssistSentenceAlignmentTest`

Required Phase A evidence:

- strict V2 rejects code fences, prose and trailing-comma repair;
- exact target/part/source/candidate ownership failures are fail-closed;
- preview failure and preview success have zero business writes;
- invalid confirm has zero partial writes;
- valid confirm changes only the explicitly allowed raw-assist/evidence owners;
- trust-high matched_existing can create binding evidence only;
- medium/low/ambiguous/new_sense do not auto-bind or rate;
- user evidence overrides trust_ai and is not overwritten by AI re-import;
- ReviewLog count is unchanged through every Phase A path;
- capture an existing sense card with `ReviewCardFsrsSnapshotService::capture()` before each trust/manual confirmation and assert the complete snapshot is unchanged afterward;
- old numeric `confidence`, `auto_fsrs_allowed` and `bulkConfirmHighConfidence()` do not participate in V2 behavior;
- V1 source/preview/confirm/current/lookup and sentence translations remain compatible.

## 3. Exclusive testing DB — Phase B

Run only after Phase A gates are green and Backend Core + Reader UX are integrated.

New contract suites:

- `ReadingReviewSettlementContractTest`
- `ReadingReviewSourceUndoAnalyticsTest`

Existing formal-writer / undo / analytics regression suites:

- `FsrsSchedulingServiceTest`
- `ReviewFsrsTest`
- `SenseReviewStackUndoTest`
- `SenseReviewUndoneAnalyticsTest`
- `SenseReviewUndoPolicyTest`
- the relevant SenseReview analytics/report suites that consume `ReviewLog::FORMAL_RATING_SOURCES`

Required Phase B evidence:

- passive reliable/user-confirmed/trust-high single-sense evidence settles `Good` once with `source=reading_passive`;
- ambiguous/new_sense/excluded/medium/low/opened/helped create zero passive rating;
- explicit Again/Hard/Good/Easy writes exactly one `reading_explicit` log through the formal writer;
- explicit rating suppresses passive Good for the same sense/session;
- multiple occurrences of one sense collapse to one passive log per session;
- finish retry is idempotent by server-side session/request/card identity;
- a new reading session can rate the same sense again;
- marked_unknown/new_sense remains zero-passive in the same reading;
- cross-user/language/session replay fails closed;
- no reading code calls `ReviewLog::create()` or `FsrsSchedulingService` directly outside `ReviewCardService`;
- both reading sources are in the formal-rating source registry and the undo policy;
- undo restores the complete before snapshot, retains the original ReviewLog, sets `undone_at`, and never invents a redo rating;
- product analytics includes active reading ratings and excludes undone reading ratings via `notUndone` / equivalent centralized query behavior.

## 4. Browser gate — only after all automated gates are green

Use one sentinel-bound `APP_ENV=testing` server tied to the exclusive testing DB. Real browser only; API/fetch cannot substitute for the UI actions.

Phase A:

- explicit unknown marking remains distinct from ordinary lookup/open;
- V2 source/copy/import/preview/confirm flow;
- strict invalid JSON and stale/mismatched package error UX;
- verification list states for matched/new/ambiguous and high/medium/low;
- Trust AI only auto-verifies eligible high matched_existing;
- Finish Reading is not blocked by unresolved Phase A items;
- ReviewLog count and pre-existing ReviewCard FSRS snapshot unchanged.

Phase B:

- passive read + Finish Reading produces only eligible Good ratings;
- opened/helped and explicitly reviewed occurrences suppress passive Good;
- explicit Again/Hard/Good/Easy works from Reader and produces the correct source;
- double-click/retry/finish retry creates no duplicate rating;
- same-sense multiple occurrences create one passive rating per session;
- undo of a reading rating visibly restores the prior card state and keeps the audit action;
- Console has no blocking error; Network shows only expected local requests; no external AI provider call is introduced by this harness.

## 5. Stop line

Lane 4 may report `PHASE_A_AND_B_ACCEPTED` only when every applicable Phase A and Phase B automated + real-browser gate is green. If Phase B remains red/pending, use `PHASE_A_ACCEPTED_PHASE_B_INCOMPLETE` or a lower conclusion. Do not enter Phase C.
