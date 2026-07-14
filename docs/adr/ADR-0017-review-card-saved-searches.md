# ADR-0017: Review Card Saved Searches and Canonical Filter State

**Status:** Accepted
**Date:** 2026-07-14

## Context

The management list and JSON/CSV/Anki TSV exports share search parsing, but the remaining filters and sort values are still read directly from `Request`. Persisting those request fragments independently would create multiple interpretations of the same search and make Study Overview filtering drift from management results.

## Decision

1. `ReviewCardManageFilterState` is the canonical, immutable representation of `q`, lifecycle filter, sort, FSRS states, due range, minimum reps, and minimum lapses. It has one normalization/validation path, stable key order, and a versioned persisted schema.
2. The V1 search parser remains unchanged. Each HTTP request parses `q` exactly once and passes the resulting criteria with the FilterState into `ReviewCardManageQueryService::buildFromFilterState()`.
3. Management data, JSON, CSV, Anki TSV, Saved Search apply, and Saved Search scoped Study Overview reuse that query core. Saved Searches never store card IDs, SQL, or parser tokens.
4. Saved Searches are scoped by authenticated `user_id` and selected language. Name uniqueness uses a separately stored normalized key. The 50-row cap is enforced transactionally with a stable user-row lock.
5. Applying a Saved Search replaces all manual management filters and sort values and clears selected cards. Subsequent manual edits remain local until explicitly saved.
6. Overview semantics are current-membership semantics: historical logs are limited to cards that match the Saved Search now. Without a Saved Search, Overview uses all confirmed sense cards rather than the management page's default enabled-only filter.

## Consequences

- One additive, reversible migration creates the Saved Search table.
- Unknown persisted schema versions fail explicitly; invalid API input returns structured 422 errors.
- The grammar frozen by ADR-0012/0013 is not expanded.
- A focused Saved Search Vue child may own CRUD UI, while the parent remains the sole owner of active filter state.

## Verification

- FilterState round-trip/stable JSON and invalid input tests.
- CRUD, duplicate, 50-cap, user/language 404 isolation tests.
- Exact result parity across data, JSON, CSV, and Anki TSV.
- Zero ReviewLog, FSRS, lifecycle, or settings writes.
