# M8 Limited Offline MVP — Implementation Plan

## Status

Accepted / Closed — implementation, rendered WebView and Android 12 emulator
acceptance complete

## Goal and non-goals

Add short-term, user/language-scoped native storage for article packages and
review packages, queue formal Sense ratings while offline, automatically submit
them through the accepted M4 batch endpoint when connectivity returns, and show
pending/conflict state in the mobile UI.

This slice does not create a local authoritative database, reproduce FSRS on the
client, change scheduling semantics, add arbitrary offline mutations, cache
credentials, or implement iOS signing/publication.

## Architecture gate

Risk: high. This slice consumes review packages offline and changes the mobile
formal-rating transport from the online-only rating endpoint to the accepted M4
queued-action endpoint.

Responsibilities:

- `MobileApiClient`: transport-only M4 batch submission.
- `OfflineRepository`: IndexedDB persistence, scoped by authenticated user and
  language; article/review snapshots, monotonic action sequence and terminal
  sync issues.
- `LinguaCafeApp`: online-first reads with cache fallback, optimistic local queue
  progression, one serialized sync loop, visible connectivity/pending/conflict
  state and retry controls.

Data flow:

`M3 package response -> scoped IndexedDB snapshot -> offline reader/reviewer`.

`rating UI -> durable local action -> M4 /sync/actions -> server idempotency ->
ReviewCardService -> FsrsSchedulingService -> per-action result -> local queue
removal or visible retry/conflict state`.

The client never computes due dates or writes FSRS fields. Applied and replayed
results leave the queue. Retryable results remain queued. Rejected/conflict
results leave the active queue but remain visible as terminal issues so a loop
cannot silently retry forever.

## Allowed files

- `mobile/src/api.ts`, `mobile/src/types.ts`, `mobile/src/ui.ts`;
- `mobile/src/storage.ts` for validated cached bootstrap scope recovery;
- new `mobile/src/offlineRepository.ts` and focused tests;
- mobile styles only for connectivity/sync presentation;
- M8 ADR/plan/acceptance and roadmap/index/handoff status documents.

## Forbidden files

- server controllers/services/models/routes and FSRS semantics;
- database migrations or real data;
- Web reader/reviewer/Vuex;
- credentials, `.env`, deployment, signing and store submission;
- M9 native publication files except later documentation of prerequisites.

## Compatibility boundary

- Existing online read responses remain unchanged.
- Formal offline ratings use the accepted M4 action schema and stable action
  identity. The legacy direct Mobile rating endpoint remains supported for old
  clients.
- Cache scope includes authenticated user id and current language. Logging out
  removes credentials but intentionally retains non-secret short-term packages
  for the same scope; another identity cannot address them.

## Minimal verification

Success requires focused unit tests for scope isolation, cache fallback data,
validated offline startup scope, monotonic queue order, applied/replayed removal,
retry retention and terminal issue capture; existing Mobile API/media tests;
TypeScript/Vite build; Android sync/debug APK; and device/emulator acceptance.
All named checks are complete; evidence is recorded in the M8 acceptance report.
