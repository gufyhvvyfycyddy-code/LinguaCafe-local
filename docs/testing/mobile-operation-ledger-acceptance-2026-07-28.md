# Mobile Operation Ledger M2 Acceptance

Date: 2026-07-28
Roadmap: `docs/plans/cloud-first-mobile-product-and-technical-milestones-2026-07-28.md`
Architecture: `docs/adr/ADR-0035-mobile-operation-ledger-and-linear-undo-redo.md`
Contract: `docs/plans/mobile-api-v1-contract.md`
Status: **Accepted / Closed.**

## Scope

This acceptance covers only M2:

- an account/language-isolated operation and append-only change ledger;
- recent operation history with stable cursor pagination;
- session-scoped or device-scoped linear LIFO undo/redo;
- formal Mobile Sense rating as the first adopter;
- idempotent transitions, optimistic version checks, and card-state conflicts;
- unchanged legacy Web rating and undo contracts.

It does not migrate Web actions, deletion, import, bulk operations, legacy word
rating, offline queues, package downloads, native clients, or FSRS behavior.

## Requirement evidence

| M2 requirement | Evidence | Result |
|---|---|---|
| Operation main/change records, status, source device/session | M2 migration/models and rating-registration assertions | Pass |
| Same operation ID as the M1 rating claim | `MobileSenseReviewController` registration and exact-ID assertion | Pass |
| Recent account history after refresh | `GET /api/v1/mobile/operations` feature tests | Pass |
| Stable bounded pagination | newest-first `before_sequence` cursor test | Pass |
| Linear LIFO undo and redo | multi-step session test with snapshot restoration | Pass |
| New action invalidates the old redo future | superseded-branch test | Pass |
| Retry returns the original transition | undo replay test; no duplicate change or ReviewLog | Pass |
| Changed retry payload conflicts | `IDEMPOTENCY_KEY_REUSED` with no extra side effect | Pass |
| Version and current-state protection | `OPERATION_VERSION_CONFLICT` and `OPERATION_STATE_CHANGED` tests | Pass |
| Lifecycle/target protection | archived-target and deleted-ReviewLog rejection tests | Pass |
| Durable operation history | ReviewLog deletion leaves the operation/change history readable | Pass |
| Isolation and cross-device account visibility | user/language rejection and actor-device audit assertions | Pass |
| M1 transaction atomicity | forced ledger failure rolls back claim, card, ReviewLog, and ledger | Pass |
| Existing Web compatibility | legacy rating and stack-undo regression suites | Pass |

## Automated results

All database tests ran against the dedicated MySQL testing database. No
development or production migration was executed.

- `php artisan test --filter=MobileOperationLedgerTest --stop-on-failure` —
  11 tests, 120 assertions.
- `php artisan test --filter=MobileApiFoundationTest --stop-on-failure` —
  15 tests, 133 assertions.
- Protected regressions — 310 passed, 1 existing skipped, 1,482 assertions:
  `SenseReviewActionTransactionTest`, `SenseReviewStackUndoTest`,
  `ReviewFsrsTest`, `FsrsSchedulingServiceTest`, `WordSense`, and
  `TestingDatabaseHealthConfigTest`.
- PHP syntax checks passed for the M2 migration, service, controller, models,
  exception, and feature test.
- `php artisan route:list --path=api/v1/mobile` listed all seven M1/M2 routes.
- `npm run development` compiled successfully; only existing Sass deprecation
  warnings were reported.
- `git diff --check` passed for the frozen M2 file set.

## Review result

The implementation review covered correctness, security/isolation,
transactionality/concurrency, compatibility, performance, and maintenance:

- all writes remain inside the M1 idempotency transaction;
- transition queries lock the operation, ReviewLog, and ReviewCard and reject
  stale versions or snapshots;
- recent-operation candidate lookup is bounded and grouped rather than N+1;
- deleting a ReviewLog now nulls its operation reference instead of deleting
  the durable history;
- no Web route/controller/service, Vue file, scheduler, or development data was
  changed.

No acceptance-blocking finding remains. M1's separate deferred real-Web-page
evidence remains open and is not reclassified by closing M2.
