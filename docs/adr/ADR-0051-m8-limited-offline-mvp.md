# ADR-0051 — M8 Limited Offline MVP

## Status

Accepted / Closed — implementation, rendered-WebView and Android 12 emulator
evidence complete

## Context

M3 provides bounded article/review packages and M4 provides deterministic,
idempotent queued-action sync. The Android connected MVP currently consumes
those reads only while online and sends ratings directly, so it cannot satisfy
the roadmap's short-term offline workflow.

## Decision

1. Persist non-secret M3 package snapshots in IndexedDB under an authenticated
   `user id + language` scope.
2. Persist Sense rating actions before changing the visible local review queue.
3. Submit queued actions only through `POST /api/v1/mobile/sync/actions`; never
   calculate or mutate FSRS state on device.
4. Allocate a durable, strictly increasing sequence per scope. Reuse the same
   `client_action_id` and payload on every retry.
5. Run one serialized foreground sync on startup, navigation, explicit retry and
   the browser `online` event. Background execution is not promised in V1.
6. Remove `applied` and `replayed` actions. Keep `retryable` actions. Remove
   terminal conflict/rejection actions from the active queue while retaining a
   visible issue record with the stable server error code.
7. Online reads refresh the cache; network failures fall back to the last scoped
   snapshot and visibly identify offline data.
8. Cache the last validated bootstrap identity/language metadata without a
   credential. On a network-only startup failure, use it only to reopen the same
   `user id + language` IndexedDB scope. Invalid/401/revoked sessions clear both
   token and cached scope metadata.

## Consequences

- The server remains authoritative and existing M4 ordering/conflict rules are
  reused without a second sync engine.
- Short-term offline reading and review are available after one successful
  package download.
- IndexedDB loss clears only disposable snapshots and unsynced actions; the UI
  must expose pending state so users can avoid clearing app data prematurely.
- Native device evidence is required to close the device slice; the accepted
  Android 12 emulator workflow supplies that evidence.
