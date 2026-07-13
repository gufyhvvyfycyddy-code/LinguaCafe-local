# ADR-0012: Review Card Browser Search Grammar

Date: 2026-07-13
Status: Accepted
Supersedes: —
Related: ADR-0010 (review card lifecycle state machine), ADR-0011 (sense leech governance and rewrite package)

## Context

The management page (`/review-cards/manage`) currently supports a single
plain-text `q` parameter that performs a `LIKE %q%` match against
`word_senses.lemma`, `surface_form`, `sense_zh`, `sense_en`, and
`example_sentence_en`. Standard filter buttons (`active`, `suspended`,
`leech`, etc.) and an advanced filter panel (FSRS state, due range, reps
min, lapses min) are AND-combined with `q`.

Users have no way to perform composable queries like "show me all leech
cards that are also suspended" or "cards with lapses >= 2 that were rated
hard". Anki's browser supports `is:suspended`, `rated:again`, `prop:lapses`
syntax. LinguaCafe needs a comparable, minimal, composable search grammar
that coexists with the existing plain-text search and the existing filter
buttons / advanced panel.

## Decision

### 1. Pure-function parser (no DB, no Request, no Auth)

`ReviewCardBrowserSearchParser` is a pure function:
- Input: raw string
- Output: `ReviewCardBrowserSearchCriteria` (read-only value object)
- Does NOT query DB, read Request, read Auth, write any state, or modify FSRS.

The parser is the single source of truth for token recognition,
normalization, conflict detection, and error generation. Both the page
data endpoint and the three export endpoints (JSON / CSV / Anki TSV) call
the same parser via `ReviewCardManageQueryService::build()`.

### 2. Frontend must NOT implement a second full parser

The frontend may use simple string helpers (split on whitespace, regex
for `is:` / `rated:` / `prop:` display, chip removal) for UX, but must NOT
replicate the full grammar validation. Validation is server-side only.
The frontend displays server-returned `search_meta.tokens` and
`search_meta.errors`.

### 3. V1 grammar (frozen)

Supported tokens (case-insensitive, normalized to lowercase):

```
is:leech
is:struggling
is:active
is:buried
is:suspended
is:archived
rated:again
rated:hard
prop:lapses=<int>
prop:lapses><int>
prop:lapses>=<int>
prop:lapses<<int>
prop:lapses<=<int>
```

All conditions use AND semantics.

### 4. AND combination

All tokens (and the remaining plain text) are AND-combined. There is no
OR, NOT, or parenthesis grouping in V1.

### 5. Plain text coexistence

After removing all recognized tokens, the remaining text (trimmed,
whitespace-collapsed) becomes `textQuery`. If non-empty, it is applied to
the SAME five fields as today: `lemma`, `surface_form`, `sense_zh`,
`sense_en`, `example_sentence_en` via `LIKE %textQuery%` inside the
existing `whereHas('sense')` security scope.

Plain-text search behavior is preserved exactly — a query with no tokens
behaves identically to today.

### 6. is token categories

Two categories of `is:` tokens:

- **governance**: `is:leech`, `is:struggling` (ADR-0011 classification)
- **lifecycle**: `is:active`, `is:buried`, `is:suspended`, `is:archived`
  (ADR-0010 lifecycle_state)

### 7. Same-category conflict handling

- At most ONE governance token. `is:leech is:struggling` → 422.
- At most ONE lifecycle token. `is:suspended is:archived` → 422.
- Same token repeated is de-duplicated (not an error):
  `is:leech is:leech` → one `is:leech`.
- Governance + lifecycle is a legal combination:
  `is:leech is:suspended` → valid.

### 8. is:leech + is:suspended is legal

`is:leech is:suspended` returns cards that are BOTH classified as leech
AND in `lifecycle_state = suspended`. This is a common real-world query
("suspended leech cards I already paused").

### 9. rated:again / rated:hard log boundary

`rated:again` / `rated:hard` means: there exists at least one ReviewLog
row where:
- `review_logs.review_card_id = review_cards.id`
- `review_logs.user_id = <current user>`
- `review_logs.language_id = <current language>`
- `review_logs.source = 'sense_review'` (positive filter)
- `review_logs.rating = 'again'` (or `'hard'`)
- `review_logs.undone_at IS NULL`
- Excludes `source = reset`, `rating = reset`, all other sources

Implemented via `whereExists` subquery — does NOT load all logs into
memory. Read-only: never writes ReviewLog, never modifies FSRS.

### 10. prop:lapses operators and value range

Only `prop:lapses` is supported in V1. Operators: `=`, `>`, `>=`, `<`,
`<=`. Value must be `0` or a positive integer (no negative, no float, no
empty). Applies directly to `review_cards.fsrs_lapses`.

Examples: `prop:lapses=0`, `prop:lapses>=2`, `prop:lapses<5`.

### 11. Unknown token and format error handling

Any token that contains a colon and LOOKS like an advanced token but is
not recognized returns a structured 422:

```json
{
  "message": "高级搜索语法有误。",
  "code": "invalid_browser_search",
  "errors": [
    {
      "token": "prop:lapses>>2",
      "reason": "不支持的属性比较格式",
      "example": "prop:lapses>=2"
    }
  ]
}
```

The 422 is returned BEFORE any DB query. Unknown tokens are NOT silently
treated as plain text — that would hide typos from the user.

Detection rule for "looks like an advanced token": a whitespace-separated
segment that contains a colon (`:`) AND the part before the colon matches
`is` / `rated` / `prop` (case-insensitive). Any segment with a colon
whose prefix is NOT one of these three is treated as plain text (e.g.
`http://example.com` is plain text).

### 12. Page / pagination / export query consistency

All four consumers — `data()`, `export()` (JSON), `exportAnkiTsv()`,
`exportCsv()` — call `ReviewCardManageQueryService::build()` which:
1. Creates the security-scoped base query
2. Calls `ReviewCardBrowserSearchParser::parse($q)` → Criteria
3. If Criteria has errors → throws `InvalidBrowserSearchException` (caught
   by controller, returned as 422)
4. Calls `ReviewCardBrowserSearchQueryApplier::apply($query, $criteria, ...)`
5. Applies standard filter buttons + advanced filter panel (AND)
6. Applies sort

This guarantees identical query semantics across page and all exports.

> **Task 2000-6 fix**: `exportAnkiTsv()` and `exportCsv()` declare return type
> `\Symfony\Component\HttpFoundation\Response` (the common parent of
> `Illuminate\Http\Response` and `JsonResponse`) so that structured 422 JSON
> responses for invalid grammar and export-limit-exceeded are returned without
> PHP TypeError. CSV and TSV file responses continue to return 200 with
> `Content-Type` and `X-Export-Count` headers.

### 13. Performance and query budget

| Condition | Query cost |
|-----------|-----------|
| Plain text only | 1 query (existing whereHas) |
| `is:active` / `is:suspended` / etc. | 0 extra (direct column WHERE) |
| `is:leech` / `is:struggling` | 1 batch ReviewLog query (via `filterCardIdsByLeechStatus`) + 1 whereIn |
| `rated:again` / `rated:hard` | 1 `whereExists` subquery per condition (max 2) |
| `prop:lapses<op><n>` | 0 extra (direct column WHERE) |

**Leech classification runs AT MOST ONCE per request** — whether triggered
by the standard `filter=leech` button OR the `is:leech` token. The
`ReviewCardManageQueryService::build()` method checks if governance
classification is already needed (from filter button) and reuses the
result for the `is:leech` token, or vice versa.

Query count does NOT grow linearly with card count. The `whereExists`
subquery for `rated:` is correlated but uses indexes.

If data volume grows and `whereExists` becomes slow, a follow-up ADR may
add a composite index on `review_logs(review_card_id, source, rating,
undone_at)`. **No migration in this task.**

### 14. Rollback

If browser search needs to be rolled back:
1. Remove parser call from `ReviewCardManageQueryService::build()`.
2. Revert to using `q` as plain text only.
3. Remove `search_meta` from controller response.
4. Remove frontend chip/help UI.
5. No database rollback needed (no migration was added).

### 15. Prohibited scope (V1)

- OR / NOT / parenthesis grouping
- Date-based search (`rated:1` for "rated 1 day ago")
- `rated:good`, `rated:easy` (only `again` / `hard` in V1)
- `prop:stability`, `prop:difficulty`, `prop:reps` (only `lapses` in V1)
- Saved searches
- New migration
- New database index
- Frontend full parser replication
- Modifying lifecycle / FSRS / ReviewLog / undo ledger
- Modifying existing filter button semantics
- Modifying export field selection

## Consequences

### Positive

- Users can compose precise queries (leech + suspended, rated:again +
  lapses>=2, etc.).
- Anki-familiar syntax lowers learning curve.
- Single parser ensures page and exports stay consistent.
- Pure-function parser is trivially testable.
- `whereExists` avoids loading all logs into memory.

### Negative

- V1 is limited (no OR/NOT/dates). Users with complex needs must run
  multiple searches.
- `is:leech` requires a batch classification pass — acceptable for current
  data volume, may need optimization later.
- Frontend cannot validate locally before submit (intentional — avoids
  parser duplication).

### Rollback

See Section 14.
