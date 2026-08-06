# ADR-0042: M4 Queued Action Sync and Conflict Simulator

## Status

Accepted under the current roadmap goal authorization

## Context

M1 established active-device authentication and per-action idempotency. M2
established the formal-rating operation ledger and linear undo/redo. M3
established bounded article/review packages but intentionally kept offline
rating upload disabled.

M4 must verify limited-offline upload semantics before a native client exists.
It must not copy Anki's full collection synchronization protocol: Anki keeps a
collection copy on each client/server and merges collection changes, whereas
LinguaCafe keeps Laravel/MariaDB authoritative and uploads a bounded action
queue.

Reference: <https://docs.ankiweb.net/syncing.html>

## Decision

1. M4 adds `POST /api/v1/mobile/sync/actions`. It accepts one `batch_id` and
   1–100 queued actions. The active Mobile device middleware remains the only
   device authority; a revoked device rejects the whole request before any
   action runs.
2. V1 action types are:
   - `sense_review.rating`;
   - `word_sense.update`;
   - `word_sense.delete`.
   M4 does not accept article edits, client-authored FSRS fields, bulk lifecycle
   changes or arbitrary endpoint tunnelling.
3. Each action has its own `client_action_id`, client `occurred_at`, monotonic
   device `sequence`, type and typed payload. The existing retry identity
   remains authenticated user + active device + action type + client action id.
   Batch id is transport metadata and does not change action identity, so an
   action retried in another batch replays its original result.
4. A batch is not one database transaction. Structurally valid actions are
   processed independently in deterministic `(occurred_at, sequence,
   original_index)` order. Results are returned in original request order with
   `processed_order`. One conflict or transient failure never rolls back
   successful actions on other cards.
5. A repeated exact action replays its stored success or domain-conflict result.
   Reusing its id with another canonical payload returns
   `IDEMPOTENCY_KEY_REUSED`. Unexpected failures roll back the action claim and
   return a retryable result with bounded exponential-backoff guidance.
6. Queued formal ratings call the same
   `ReviewCardService::recordReviewWithLog()` → `FsrsSchedulingService::schedule`
   path as online Mobile ratings. `recordReviewWithLog()` gains an optional
   reviewed-at input; online callers retain server-now behavior, while queued
   ratings schedule at the validated client occurrence time.
7. Formal rating operations persist client occurrence time, device sequence and
   batch id. Under the locked ReviewCard transaction, a queued rating is
   rejected as `OUT_OF_ORDER_ACTION` when a later rating for that card has
   already been accepted. Same-device equal timestamps use sequence as the
   tie-breaker; cross-device equal timestamps serialize in server arrival order.
8. Client occurrence time may be no more than five minutes in the future and no
   more than thirty days old. Outside that window is a non-retryable validation
   result. This bounds clock error and limited-offline history without accepting
   arbitrary historical rescheduling.
9. WordSense optimistic concurrency uses a deterministic
   `word_sense_version=sha256:<canonical editable content and status>`, exposed
   by M3 review/article payloads. Update/delete must supply the expected version
   and lock a user/language-scoped WordSense before comparing.
10. `word_sense.update` may change only the existing text whitelist: POS,
    Chinese/English meaning, English/Chinese example, aliases and collocations.
    It never changes identity, source, confirmation status, ReviewCard or FSRS.
11. `word_sense.delete` reuses
    `WordSenseService::removeSenseFromReviewSystem($sense, true)`: it rejects the
    WordSense, removes its ReviewCard, preserves ReviewLog and occurrence rows,
    clears occurrence card links and never deletes reading material.
12. A stale version returns `STALE_WORD_SENSE`; update after accepted delete
    returns `WORD_SENSE_DELETED`; inaccessible or cross-scope ids return the
    indistinguishable `WORD_SENSE_NOT_FOUND`. Delete/edit races are resolved by
    the row lock and expected version, not last-write-wins.
13. Batch response status is `completed`, `partial` or `failed` with per-action
    HTTP-equivalent status, stable code, retryability and optional
    `retry_after_ms`. The HTTP request itself returns 200 when its outer
    structure is valid, even for partial action failure.
14. M4 adds an authenticated Web test client at `/mobile-sync-simulator`. It
    obtains a Mobile device token through the normal token endpoint, keeps the
    plaintext token/password only in component memory, lets the operator build
    typed actions and submits the real batch endpoint. It is not a second write
    API.
15. After backend, frontend and real-browser acceptance,
    `offline_queue=true` is advertised. This means queued actions are accepted
    by the server; native background sync/local storage remain M7/M8 work.

## Compatibility and exclusions

- Existing single-action rating, Web rating, ReviewLog, operation undo/redo and
  M3 package contracts remain compatible.
- No full collection database merge, media sync, native local queue, background
  job, push notification or unbounded offline history.
- WordSense update/delete actions are independently idempotent but are not added
  to M2's rating/manual undo stack in M4. M15 owns delete recovery and knowledge
  hygiene.
- No client can write due/stability/difficulty/reps/lapses directly.

## Verification

- migration rollback/restore on an empty testing schema;
- exact replay, changed-payload conflict and retryable rollback tests;
- partial/all-success/all-failed batches and original/processed ordering;
- same-card chronological ratings using client occurrence time, late/out-of-
  order rejection, different-card merge and cross-device locking tests;
- stale edit, sequential edit, delete/edit and cross-scope tests;
- revoked device rejection and zero-side-effect validation tests;
- existing Mobile rating/ledger, FSRS, WordSense and Web compatibility tests;
- frontend build plus real-browser simulator authentication, queue construction,
  successful/partial result and memory-only credential behavior on a
  server-bound testing database.
