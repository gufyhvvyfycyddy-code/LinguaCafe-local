# ADR-0030: Reviewer Request Coordination and Sense Review Shortcuts

> **Status**: Accepted / Production Closed
> **Date**: 2026-07-17
> **Scope**: Phase 5 Reviewer convergence

## Context

LinguaCafe has two review pages. `SenseReview.vue` is the formal sense-only mainline; `Review.vue` remains a compatibility surface. Both must reject duplicate/stale rating responses and recover by authoritative queue reload, but merging the pages would also merge product contracts that intentionally differ.

Anki's official reviewer exposes Edit (`E`), Card Info (`I`), Bury Card (`-`), Suspend Card (`@`), and card flags (`Ctrl+1..7`). LinguaCafe already owns equivalent edit, exact-card Card Info, lifecycle, and marker boundaries.

## Decision

1. Keep both page components. Do not create a generic Reviewer page or change either rating endpoint.
2. `ReviewRatingRecovery.js` owns the small shared rating-request coordinator: one in-flight lock, monotonically increasing request identity, stale-response rejection, stable error classification, and authoritative reload recovery.
3. Each page supplies its existing queue reload and error presentation callbacks. Page-specific queue/session semantics remain local.
4. Sense Review adds Anki-aligned shortcuts through existing owners: `E` edit, `I` exact Card Info deep link, `-` bury, `@` suspend, and `Ctrl/Cmd+1..7` marker toggle. Selecting the current marker clears it.
5. Keyboard actions are disabled while typing, while a dialog/report is open, or while rating/lifecycle/reset/delete work is in flight. Lifecycle shortcuts only run when the backend descriptor exposes that action.
6. The exact Card Info deep link adds `sense-review` to the existing finite source whitelist. It reuses `ReviewCardManage` and its canonical drawer; no second Card Info implementation is created.

## Safety and compatibility

- Sense Review remains the formal mainline; legacy Review gains no new product feature.
- FSRS scheduling and interval calculation remain backend-owned.
- Recovery never fabricates success, advances counters, or unlocks a newer request from a stale completion.
- Marker shortcuts use the marker API and do not write ReviewLog or lifecycle events.
- Lifecycle shortcuts use the existing lifecycle endpoint and confirmation policy.
- Delete is unchanged, not assigned a shortcut, and was not exercised.

## Acceptance

- Pure coordinator tests cover locking, request identity, error classification, stale responses, authoritative reload, sync throws, and concurrent recovery.
- Structural guards cover both page integrations and shortcut delegation.
- Authenticated browser acceptance covers marker toggle/clear, exact Card Info, edit, suspend confirmation, legacy-page smoke, a formal `good` rating, and stack undo.
- Database evidence proves the rating created one auditable ReviewLog which was marked undone, while the card's FSRS and lifecycle state returned exactly to baseline.
