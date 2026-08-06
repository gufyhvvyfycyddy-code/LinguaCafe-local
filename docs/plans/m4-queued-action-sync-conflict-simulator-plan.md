# M4 Queued Action Sync + Conflict Simulator — Implementation Plan

## Status

Accepted / Closed under the current roadmap goal authorization

## Goal and non-goals

Implement a server-authoritative, independently idempotent action-batch API and
an authenticated Web simulator that exercises that exact API. M4 covers queued
Sense ratings and optimistic WordSense update/delete conflicts.

It does not build native storage/background sync, full collection merge,
arbitrary endpoint dispatch, media sync, a second scheduler or delete recovery.

## Architecture gate

Risk: high. This slice changes a public Mobile API, formal rating time input,
WordSense mutation conflict semantics, the operation schema and a visible Vue
route.

Responsibilities:

- `MobileSenseReviewMutationService`: the single Mobile rating mutation path,
  including eligibility, card lock, queued-time ordering and operation
  registration.
- `MobileQueuedActionSyncService`: validate/normalize action types, order a
  batch, execute each action independently through
  `MobileIdempotencyService`, and build stable per-action results.
- `WordSenseContentVersionService`: pure canonical version generation shared by
  M3 payloads and M4 optimistic mutation checks.
- `MobileSyncController`: validate outer transport structure only.
- `MobileSyncSimulator.vue`: in-memory credentials/token, typed queue builder,
  loading/error/empty/partial states and real endpoint submission.

Data flow:

`active mobile device -> batch controller -> ordered per-action dispatcher ->
existing idempotency transaction -> locked rating or WordSense mutation ->
common Mobile response`.

Rating flow remains:

`MobileSenseReviewMutationService -> ReviewCardService::recordReviewWithLog ->
FsrsSchedulingService::schedule -> ReviewLog -> MobileOperationLedgerService`.

## Allowed files

- one additive operations metadata migration;
- `Operation` fillable/casts/constants;
- `ReviewCardService` optional reviewed-at parameter;
- `MobileOperationLedgerService` additive queued metadata arguments;
- `MobileSenseReviewController` delegation-only refactor;
- new M4 Mobile controller/services/exceptions;
- M3 package serializers for additive WordSense version fields;
- `routes/api.php`, `routes/web.php`;
- `resources/js/app.js`;
- `resources/js/components/Mobile/MobileSyncSimulator.vue`;
- focused M4 backend/frontend tests;
- M4 ADR/plan/contract/acceptance and current roadmap/index/handoff documents.

## Forbidden files

- FSRS algorithm/parameters or client-authored scheduling fields;
- Reader/Reviewer/Custom Study UI and Vuex stores;
- WordSense/ReviewCard/ReviewLog schema semantics beyond queued metadata;
- native Android/iOS code, M8 local database/background sync;
- delete-recovery or general Browser hygiene;
- `.env`, credentials and development/production data.

## Frozen transport contract

`POST /api/v1/mobile/sync/actions`

```json
{
  "batch_id": "UUID",
  "actions": [{
    "client_action_id": "UUID",
    "type": "sense_review.rating|word_sense.update|word_sense.delete",
    "occurred_at": "ISO-8601",
    "sequence": 1,
    "payload": {}
  }]
}
```

The outer request is limited to 100 actions and 1 MiB. Per-action validation and
domain conflicts appear in the 200 response; invalid outer structure is 422.
Results preserve request order and include processing order, outcome, replay,
stable error code, retryability and retry delay when applicable.

## Minimal verification

Success requires:

1. focused backend tests covering all ADR-0042 outcomes and transaction
   rollback;
2. migration rollback/restore only on testing schema;
3. existing Mobile rating/operation ledger + Review FSRS + scheduler +
   WordSense regressions;
4. frontend component guard and production build;
5. real-browser testing identity/device flow, successful and partial queue,
   Console/Network/data-delta evidence and complete cleanup;
6. PHP/JS syntax, routes, documentation guards and scoped diff check;
7. fresh-context adversarial review with every substantive issue closed.

## Completion evidence

- Backend batch sync, queued rating mutation, optimistic WordSense mutation,
  additive operation metadata and the authenticated simulator are implemented.
- M4 + Mobile compatibility matrix: 46 passed, 482 assertions.
- Protected Review FSRS / scheduler / WordSense matrix: 276 passed, one
  external-tokenizer-dependent test skipped, 1,300 assertions.
- Frontend contract guard and production development build passed.
- Real-browser testing-database acceptance completed the package → partial
  conflict → success → exact replay flow and verified component-memory
  credential cleanup.
- Two adversarial findings—cross-entry rating chronology and permissive
  timestamp parsing—were fixed and regression-tested. The follow-up review
  found no remaining substantive M4 issue.
- Full evidence: `docs/testing/m4-queued-action-sync-acceptance-2026-07-29.md`.
