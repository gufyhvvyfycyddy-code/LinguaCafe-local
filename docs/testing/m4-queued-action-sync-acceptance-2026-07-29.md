# M4 Queued Action Sync + Conflict Simulator — Acceptance

Date: 2026-07-29
Result: Accepted / Closed

## Accepted behavior

- Active Mobile devices can submit 1–100 independently idempotent queued
  actions through `POST /api/v1/mobile/sync/actions`; the outer body is capped
  at 1 MiB.
- Supported actions are formal Sense rating, optimistic WordSense text update,
  and optimistic WordSense delete. No client-authored FSRS field or arbitrary
  endpoint dispatch is accepted.
- Actions execute independently in deterministic occurrence-time/device-
  sequence/request-order order. Results remain in request order and expose the
  processing order, stable outcome/error code, replay state and retry guidance.
- Exact retries replay the stored success or domain result across batch ids.
  Changed canonical payloads return `IDEMPOTENCY_KEY_REUSED`; unexpected
  failures roll back the claim and are retryable.
- Queued ratings use the same locked
  `ReviewCardService::recordReviewWithLog()` →
  `FsrsSchedulingService::schedule()` write path as online Mobile ratings.
  Client time is strictly ISO-8601, bounded to five minutes in the future and
  thirty days in the past, and cannot be applied before a newer accepted rating
  from either Mobile or Web.
- WordSense edit/delete uses the deterministic version supplied in M3 packages,
  row locking, user/language isolation and the existing text/delete semantics.
  Stale edits never overwrite newer content.
- Bootstrap `offline_queue` and review-package
  `offline_rating_upload_supported` are now true. These flags describe server
  upload support only; native local persistence/background synchronization
  remain M7/M8.
- The authenticated `/mobile-sync-simulator` page obtains a real Mobile token,
  loads a real short-term package, builds typed actions and submits the real
  batch endpoint. Password/token are component-memory only and are cleared when
  the page is destroyed.

## Automated evidence

- M4 + Mobile compatibility matrix: 46 passed, 482 assertions.
- Focused M4 matrix: 14 passed, 140 assertions, including testing-schema
  migration rollback/restore.
- Protected scheduling/Sense matrix: 276 passed, 1 external-tokenizer-dependent
  test skipped, 1,300 assertions:
  - `ReviewFsrsTest`: 63 passed, 375 assertions;
  - `FsrsSchedulingServiceTest`: 9 passed, 46 assertions;
  - `--filter=WordSense`: 204 passed, 1 skipped, 879 assertions.
- Frontend contract guard passed and `npm run development` compiled
  successfully. Sass legacy-API/deprecation warnings were non-failing and
  unrelated to M4 behavior.

The skipped morphology import test requires the external Python tokenizer and
does not exercise queue sync, FSRS ordering, WordSense conflicts or the Web
simulator. It did not defer an M4 acceptance behavior.

## Real-browser acceptance

The test server was started as a dedicated `APP_ENV=testing` process on port
8774 and proved, through the testing-only same-server sentinel, that its active
database name was testing-specific and its unique sentinel row was present.
Writes were performed only after that server-bound proof.

Official OpenAI Browser was attempted first and could not reach loopback.
Official OpenAI Chrome was attempted next after recording all ten pre-existing
user tabs; it returned `ERR_BLOCKED_BY_CLIENT` for loopback and timed out on the
LAN address. The official Playwright CLI rendered registration/login but its
session daemon repeatedly closed during fixture coordination. ADR-0033 then
allowed the next real-browser channel: one headless system-Chrome context/page
driven by Playwright, with page/context/browser cleanup in `finally`.

Through rendered UI and user events, that browser:

1. registered and logged in a task-only testing identity;
2. connected a real Mobile test device and loaded the real short-term review
   package;
3. queued one valid rating and one deliberately stale WordSense update;
4. submitted the batch and visibly observed `partial`, success 1 / failure 1,
   and `STALE_WORD_SENSE`;
5. submitted a valid package-version update and observed `completed`;
6. resubmitted the exact same action and observed replay count 1;
7. navigated away and back, then verified the password field was empty and the
   device was disconnected.

Network evidence contained token creation `201`, package download `200`, and
three sync responses `200`. The only Console errors were the expected
pre-authentication `401` and the already-protected local Pusher-unavailable
fallback; no simulator exception occurred.

The data checkpoint after UI actions showed the requested WordSense text, one
ReviewLog, one formal operation, one device and three client-action claims.
Cleanup then removed the exact task identity, token, device, client actions,
operations, ReviewLog, task WordSenses/occurrences and sentinel. Verification
returned zero task users, zero task senses and zero sentinel rows; the port
listener was stopped. No pre-existing user tab was closed.

## Adversarial closeout

Two substantive findings were fixed before closure:

1. queued rating ordering now also compares the locked card's
   `fsrs_last_reviewed_at`, preventing an older offline rating from being
   applied after a newer Web rating that has no Mobile operation-ledger row;
2. `occurred_at` now requires strict ISO-8601 shape and a valid calendar date,
   so permissive parser inputs such as `tomorrow` or February 30 cannot enter
   the scheduler.

Follow-up coverage added same-timestamp sequence ordering, Web-to-Mobile
ordering, strict timestamp rejection, JSON-list/count/byte limits and
zero-side-effect assertions. The final review found no remaining behavior,
isolation, credential, idempotency, transaction or payload issue requiring a
contract change.
