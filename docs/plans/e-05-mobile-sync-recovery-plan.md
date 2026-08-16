# E-05 Mobile Sync Recovery — Implementation Plan

## Status

Accepted under current Goal authorization.

## Goal and non-goals

Make the existing limited-offline queue understandable after retry, terminal
conflict and app restart. Pending actions remain in the existing user/language
IndexedDB scope, retry with the same client identity, and terminal server
decisions are shown in ordinary-user language without exposing protocol codes or
server messages.

This slice does not add a retry worker, watchdog, scheduler, queue, store,
database field, server endpoint, merge rule or client-side authority. It does not
silently retry terminal results or overwrite server state.

## Architecture gate

Risk: high because the UI represents offline mutation and conflict authority.

- `OfflineRepository` remains the only durable queue/issue owner. Its existing
  retryable-retain and terminal-issue behavior is unchanged.
- `LinguaCafeApp` maps stable issue codes to bounded user copy only at render
  time. Raw codes remain internal classification data and raw server messages are
  never rendered.
- Manual sync reports three existing outcomes: applied/replayed removed,
  retryable retained for later, and terminal result removed into a visible issue.
- Restart evidence creates a new repository instance over the same store and
  proves the original action id, sequence and payload remain queued.

## Allowlist

- `mobile/src/ui.ts`
- `mobile/src/offlineRepository.test.ts`
- `tests/js/E05MobileSyncRecoveryGuard.test.mjs`
- this plan and the Goal ledger

Forbidden: backend sync/idempotency behavior, schemas, new persistence owners,
background execution, native projects, credentials and non-testing data.

## Verification

1. Mobile tests prove retryable retention, terminal issue recording and a new
   repository instance recovering the same queued action identity;
2. static guard proves raw `issue.code` / `issue.message` are not rendered and
   retry/terminal copy states that server state is preserved;
3. Mobile production build and M4/E-04 regressions remain green;
4. testing-bound browser queues an action offline, closes/reopens the app,
   observes it still pending, then shows understandable retry/conflict handling
   through real DOM events with exact fixture cleanup.
