# PAB R3 Integration Regression Matrix

Purpose: hand off the R3 executable harness to the single Integration owner. This Harness lane does not run shared-testing-DB tests, migrations, or browser writes.

## 1. Required-suite rule

A zero exit code alone is insufficient. Every required suite must execute real tests/assertions. Any unexpected `incomplete`, `skipped`, `pending`, `No tests found`, or zero-assertion result is a gate failure.

Run the meta gate from a worktree with normal Composer dependencies available:

- parallel-safe: `node tests/harness/run-pab-r3-required-suites.mjs`
- after acquiring the exclusive testing-DB lease: `node tests/harness/run-pab-r3-required-suites.mjs --integration`

The runner includes the actual V2 strict/batching seams, DB ownership/write boundaries, true process/barrier concurrency, unfamiliar-snapshot optimistic conflict, undo/analytics, and all static source guards.

## 2. Parallel-safe checks before DB ownership

Run first:

- `php -l` over every changed R3 PHP test/support file;
- `node --check tests/harness/run-pab-r3-required-suites.mjs`;
- `node tests/js/AiReadingAssistV1CompatibilityGuard.test.mjs`;
- `node tests/js/PhaseAReviewWriteSurfaceGuard.test.mjs`;
- `node tests/js/PhaseBFormalRatingWriteSurfaceGuard.test.mjs`;
- `node tests/js/ReadingRatingSourceContractGuard.test.mjs`;
- `git diff --check`.

Required pure PHP suites once `vendor/` is present:

- `AiReadingAssistV1CompatParserTest`;
- `AiReadingAssistV2StrictParserTest`;
- `AiReadingAssistV2BatchingTest`.

The R3 Phase A guard must resolve and scan the concrete production owners `AiReadingAssistV2Service`, `ReadingOccurrenceSenseEvidenceService`, and `ReadingUnfamiliarTargetService`. A missing owner is a hard failure.

## 3. Exclusive testing DB — Phase A

Acquire the official machine-global testing-DB lease and prove APP_ENV/testing sentinel before any DB-backed test. Run serially.

Required suites:

- `AiReadingAssistV2CandidateOwnershipTest`;
- `AiReadingAssistV2WriteBoundaryTest`;
- `ReadingUnfamiliarTargetSnapshotConflictTest`;
- existing V1 preview/confirm/current/lookup/sentence-alignment regressions.

Required evidence:

- strict V2 parser rejects repair/fuzzy input and numeric-string type drift;
- candidate IDs are current, confirmed, same-user, same-language and server-scoped;
- 20/49/50/51/100/101 target partitioning is exact; missing/duplicate/stale parts fail closed;
- preview is zero-write and invalid confirm is atomic zero-write;
- Trust AI changes only allowed assist/evidence state; medium/low/ambiguous/new-sense do not auto-bind or rate;
- user evidence remains stronger than Trust AI;
- every Phase A path leaves ReviewLog count and full ReviewCard FSRS snapshot unchanged;
- changed source/target/candidate scope invalidates stale manifests;
- stale whole-snapshot replacement is rejected and cannot erase newer user intent;
- legacy numeric confidence / `auto_fsrs_allowed` paths do not become V2 formal-rating paths.

Phase A is not green until all applicable assertions execute with zero unexpected incomplete/skip/pending.

## 4. Exclusive testing DB — Phase B

Run only after Phase A is green and Backend + Reader candidates are integrated.

Required suites:

- `ReadingReviewSettlementContractTest`;
- `ReadingReviewConcurrencyContractTest`;
- `ReadingReviewSourceUndoAnalyticsTest`;
- existing FSRS writer, undo, and analytics regressions.

Required evidence:

- start/resume returns one current active session; a completed resume returns the stored completion result;
- explicit Again/Hard/Good/Easy uses the canonical endpoint and produces one `reading_explicit` ReviewLog with exact before-snapshot;
- sequential and true simultaneous duplicate explicit requests create one formal action/log;
- preflight with unresolved=0 is still zero-write;
- old `trust` settlement mode is rejected;
- unresolved commit is zero-write;
- true simultaneous Finish commits produce one completion, one legacy finish effect, at most one passive log/card;
- explicit-vs-Finish, opened/helped-vs-Finish, evidence-correction-vs-Finish, and source-change-vs-Finish use real two-process barriers and admit only serialized terminal states;
- explicit wins over passive when it is the recorded session action; opened/helped suppress passive;
- same-sense multiple occurrences collapse to one passive Good per session; a truly new later session may rate again;
- marked/newly-resolved same-reading items remain excluded;
- cross-user/language/session replay fails closed;
- reading formal sources remain in `ReviewLog::FORMAL_RATING_SOURCES` and `SenseReviewUndoPolicy`; analytics consume centralized formal-source/not-undone semantics;
- undo restores the complete before-card snapshot and retains the audit ReviewLog.

The concurrency suite deliberately uses `proc_open` workers released by a parent `go` barrier. Sequential calls do not count as concurrency evidence.

## 5. Browser gate

Only after all automated Phase A and Phase B gates are green, use one sentinel-bound APP_ENV=testing server and a real browser. Preserve the Frozen Contract’s desktop/430/390 Reader flows, refresh recovery pointer, ambiguous Finish-response recovery, candidate filtering/new-sense continuation, preflight summary, retry/idempotency, undo, Console, Network, and DB evidence requirements.

## 6. Stop line

Integration may accept Phase A first. It may accept Phase B only after every applicable DB/concurrency/browser gate is green. It must not enter Phase C automatically. Any missing dependency, non-executed required suite, unexpected incomplete/skip/pending, or unavailable browser/DB evidence must remain explicitly incomplete rather than being converted to a pass.
