# ADR-0013: Review Card Browser Search Execution Pipeline

**Status**: Accepted
**Date**: 2026-07-13
**Related**: `docs/adr/ADR-0012-review-card-browser-search.md`, `docs/adr/ADR-0010-review-card-lifecycle-state-machine.md`, `docs/adr/ADR-0011-sense-leech-governance-and-rewrite-package.md`

## Context

ADR-0012 introduced the V1 browser search grammar (`is:` / `rated:` / `prop:lapses`) with a pure-function parser, a read-only criteria value object, and a query applier. The four HTTP endpoints (`data`, `export` JSON, `exportAnkiTsv`, `exportCsv`) each independently parse the `q` query string and then delegate to `ReviewCardManageQueryService::build()`, which **parses the same `q` string a second time** internally.

### Problems identified (Phase A read-only review)

1. **Double parsing per request.** Each endpoint calls `parseCriteria($request)` for the 422 guard and `search_meta`, then calls `build($request, ...)` which re-invokes `$this->searchParser->parse($q)` (line 55 of `ReviewCardManageQueryService`). The parser is pure and fast, but running it twice per request is wasteful and obscures the single-source-of-truth contract.

2. **Duplicated 422 handling across four endpoints.** Each of `data()`, `export()`, `exportAnkiTsv()`, `exportCsv()` repeats the same `try { parseCriteria } catch (InvalidBrowserSearchException)` block. Any change to the 422 contract must be applied in four places.

3. **`normalizedTokens` not deduplicated.** `is:leech is:leech` produces `normalizedTokens = ['is:leech', 'is:leech']`, which would render two identical chips on the frontend. The criteria *fields* (`governanceStatus`, `ratings`) are structurally deduplicated, but the display list is not.

4. **`propertyConditions` not deduplicated.** `prop:lapses>=2 prop:lapses>=2` produces two identical entries, which the applier would translate into two identical SQL `WHERE` clauses.

5. **No single execution entry point.** The Controller is the only actor that knows about the criteria; the QueryService re-derives it from the Request. The criteria is the natural execution context but is thrown away after the 422 guard.

## Decision

### 1. Each request parses exactly once

The Controller parses `q` into a `ReviewCardBrowserSearchCriteria` exactly once per HTTP request, then passes the **already-parsed criteria** to `ReviewCardManageQueryService::buildFromCriteria()`. The QueryService never reads `q` from the Request and never calls the parser.

The existing `build(Request $request, ...)` method becomes a **thin wrapper** that parses and delegates to `buildFromCriteria()`, retained only for backward compatibility with any caller that has not migrated. It is **not** the Controller's main path.

### 2. Criteria is the single execution context

`ReviewCardBrowserSearchCriteria` (ADR-0012, read-only value object) becomes the **single shared context** between the Controller and the QueryService:

- **Creator**: `ReviewCardBrowserSearchParser::parse()` (pure function, no DB / Request / Auth).
- **Consumers**:
  - Controller: reads `toSearchMeta()` for the `data()` response, passes it to `buildFromCriteria()`.
  - `ReviewCardManageQueryService::buildFromCriteria()`: reads criteria fields to drive governance resolution + applier.
  - `ReviewCardBrowserSearchQueryApplier::apply()`: reads textQuery / lifecycle / governance / ratings / propertyConditions to apply WHERE clauses.

The criteria object is **not** a database record, **not** a Request wrapper, and **not** mutable.

### 3. Page and three exports share one execution path

All four endpoints follow the same shape:

```
Controller endpoint
  → parse once (try/catch InvalidBrowserSearchException → 422)
  → buildFromCriteria($request, $criteria, $userId, $language)
  → endpoint-specific serialization (paginate / JSON / CSV / TSV)
```

The 422 guard is still at the Controller level (one try/catch per endpoint), but the criteria is reused for both the guard and the query build. The QueryService no longer has a second parse call.

### 4. Unified 422 contract

`InvalidBrowserSearchException::toResponseArray()` (ADR-0012) remains the single source of the 422 body shape:

```json
{
  "message": "高级搜索语法有误。",
  "code": "invalid_browser_search",
  "errors": [{"token": "...", "reason": "...", "example": "..."}]
}
```

All four endpoints return this exact shape on invalid grammar. Export-limit-exceeded and field-resolution errors keep their own 422 shapes (unchanged from ADR-0012 Task 2000-6 fix).

### 5. Governance classification stays single-batch

`resolveGovernanceMatchingIds()` (ADR-0012) remains inside `buildFromCriteria()`. It runs at most once per request: if both the `filter` button and the `is:` token request the same status, the IDs are computed once and reused; if they request different statuses, both are computed (AND). No per-card ReviewLog query, no N+1 with card count.

### 6. Token deduplication rules

The parser deduplicates by first-occurrence order:

| Input | `normalizedTokens` | Criteria fields |
|---|---|---|
| `is:leech is:leech` | `['is:leech']` | `governanceStatus = 'leech'` |
| `rated:again rated:again` | `['rated:again']` | `ratings = ['again']` |
| `prop:lapses>=2 prop:lapses>=2` | `['prop:lapses>=2']` | `propertyConditions = [{field:'lapses',op:'>=',value:2}]` |

Different same-category tokens still return 422 (e.g. `is:leech is:struggling`, `is:suspended is:archived`). This is unchanged from ADR-0012.

### 7. No V2 grammar

This ADR does **not** introduce any new search syntax. No OR, no NOT, no parentheses, no date search, no saved searches, no `rated:good` / `rated:easy`, no new `prop:` fields. The grammar is frozen at ADR-0012 V1.

## Rollback

Revert the refactor commit. The thin `build(Request $request, ...)` wrapper remains functional, so reverting the Controller changes restores the pre-refactor behavior (double parse). No migration, no schema change, no FSRS / lifecycle / ReviewLog change — rollback is a pure code revert.

## Prohibited scope

- No new migration.
- No FSRS algorithm / parameter / scheduling change.
- No ReviewLog schema or historical log change.
- No lifecycle state machine change.
- No new search syntax (no V2 grammar).
- No frontend layout / copy change (unless a no-visual-change compatibility adjustment is proven necessary by tests).
- No Leech Policy duplication.
- No per-card ReviewLog query.
