# Review Card Browser Recent Review Search Semantic Freeze

> **Status**: Phase 8H complete — semantics frozen; runtime implementation not authorized
> **Date**: 2026-07-17
> **Scope**: Browser Search recent formal-review windows reconnaissance and contract freeze only
> **Related**: [ADR-0012](../adr/ADR-0012-review-card-browser-search.md), [ADR-0013](../adr/ADR-0013-review-card-browser-search-execution-pipeline.md), [Anki maximal-alignment forward plan](anki-maximal-alignment-gap-audit-and-forward-plan.md)

## 1. Goal

Freeze the smallest Anki-aligned recent-review grammar before implementation, without changing the existing lifetime rating tokens:

```text
rated:again
rated:hard
rated:good
rated:easy
```

Phase 8H is documentation and read-only reconnaissance only. It does **not** modify the parser, criteria object, query applier, routes, UI, exports, ReviewLog, FSRS, database schema, or runtime search behavior.

## 2. Repository and Anki facts

1. LinguaCafe already uses `rated:again|hard|good|easy` for lifetime existence of a formal rating. Those forms must remain backward compatible.
2. All Browser conditions use global AND semantics and all four consumers share one Parser/Criteria/Query-Applier pipeline.
3. Formal product analytics already define a real review as a `ReviewLog` with:
   - `source = sense_review`;
   - `rating` in `again|hard|good|easy`;
   - `undone_at IS NULL`;
   - current user and current language;
   - a confirmed sense card.
4. `SenseReviewReportPeriodService::rollingDays()` already defines rolling natural-day windows as today plus the previous `n-1` days, with an inclusive start and exclusive next-day end, capped at 365 days.
5. The [Anki manual — Searching / Recent Events](https://docs.ankiweb.net/searching.html#recent-events) defines `rated:n` as cards answered in the last `n` days and `rated:n:r` as cards answered with rating code `r` in that window. Anki rating codes are 1=Again, 2=Hard, 3=Good, 4=Easy.
6. ADR-0012 explicitly deferred numeric `rated:` date search, so adding it later is an extension of the existing Browser grammar rather than a new search subsystem.

## 3. Frozen syntax for the first implementation slice

A future Phase 8I implementation may add exactly:

```text
rated:<days>
rated:<days>:<rating-code>
```

Where:

- `days` is an integer from 1 through 365;
- `rating-code` is exactly `1`, `2`, `3`, or `4`;
- mapping is `1=again`, `2=hard`, `3=good`, `4=easy`.

Examples:

```text
rated:1
rated:7
rated:30
rated:7:1
rated:30:4
rated:7:1 state:review
rated:30 rated:again
```

Normalization rules:

- Prefix is case-insensitive.
- Leading zeros are normalized: `RATED:007:01` becomes `rated:7:1`.
- Exact duplicate normalized tokens are removed in first-occurrence order.
- Existing symbolic tokens remain normalized as `rated:again|hard|good|easy` and are not rewritten into numeric-window forms.

Malformed recognized forms use the existing structured `invalid_browser_search` 422 response. Invalid examples include `rated:0`, `rated:-1`, `rated:366`, `rated:7:0`, `rated:7:5`, `rated:7:again`, `rated:7:1:2`, and an empty segment.

## 4. Frozen time-window meaning

`rated:n` means at least one qualifying formal sense-review log in the rolling `n`-natural-day window:

```text
start = local start of today - (n - 1) days
end   = local start of tomorrow
reviewed_at >= start
reviewed_at < end
```

The first implementation must use the same application-timezone boundary already used by `SenseReviewReportPeriodService` and the daily/7-day/30-day reports. It must not introduce a separate UTC cutoff, elapsed-hour window, user-timezone migration, or browser-only date calculation.

Consequences:

- `rated:1` means today, not the previous 24 elapsed hours.
- `rated:7` means today plus the previous six natural days.
- `rated:30` means today plus the previous twenty-nine natural days.
- The end boundary is exclusive, preventing tomorrow's midnight from entering the result.
- DST, month-end, and year-end behavior follows the existing period service.

## 5. Frozen ReviewLog membership

A log can satisfy a numeric recent-review token only when all of the following hold:

```text
review_logs.review_card_id = review_cards.id
review_logs.user_id = current user
review_logs.language_id = current language
review_logs.source = sense_review
review_logs.rating IN (again, hard, good, easy)
review_logs.undone_at IS NULL
reviewed_at is inside the frozen window
```

For `rated:n:r`, the mapped rating is additionally required.

Excluded:

- reset logs, regardless of their other fields;
- non-`sense_review` sources;
- undone formal ratings;
- other users and languages;
- legacy word-card history;
- rejected or unconfirmed senses through the existing Browser base scope;
- lifecycle events, inline confirmations, imports, and FSRS field changes without a qualifying ReviewLog.

The search remains an existence query. Multiple qualifying logs never duplicate a card row.

## 6. Combination semantics

Every token remains independently AND-combined.

Examples:

- `rated:7:1 rated:7:4` means at least one Again and at least one Easy in the last seven natural days.
- `rated:1 rated:7` is legal but redundant.
- `rated:30 rated:again` means at least one formal review in the last 30 days and at least one lifetime Again rating; they do not have to be the same log.
- `rated:7:1 rated:again` means at least one recent Again and at least one lifetime Again; the recent log can satisfy both predicates.
- Numeric recent tokens combine normally with plain text, lifecycle, governance, marker, state, due, property, source, and missing-field tokens.

No implicit OR or shared-log grouping is introduced.

## 7. Explicit exclusions

The first runtime slice must not add:

- `reviewed:*`, `recent:*`, `last-reviewed:*`, or `rated:today` aliases;
- `prop:rated`, exact-day offsets, arbitrary start/end dates, or date ranges;
- hours, weeks, months, timestamps, or elapsed-duration windows;
- negation, OR, parentheses, quoted phrases, or a general AST;
- rating-name suffixes such as `rated:7:again`;
- future-review, lifecycle-event, import, or inline-confirmation windows;
- new route, migration, index, dependency, export field, Saved Search schema, Custom Study mode, Reviewer behavior, or ReviewLog write.

A database index may be considered only after measured evidence in a separately authorized performance task.

## 8. Architecture placement for Phase 8I

A future implementation must extend the existing Browser pipeline:

1. `ReviewCardBrowserSearchParser` distinguishes existing symbolic rating tokens from numeric window forms and validates shape without DB access.
2. `ReviewCardBrowserSearchCriteria` carries ordered read-only recent-review conditions, conceptually `{days, rating|null}`.
3. `SenseReviewReportPeriodService` remains the time-boundary owner, or the Browser uses an equivalent single shared boundary that cannot drift from report semantics.
4. `ReviewCardBrowserSearchQueryApplier` adds one correlated `whereExists` predicate per recent condition.
5. List, JSON export, CSV export, and Anki TSV export continue to use the same criteria and query path.
6. Frontend work is limited to help text, normalized chips, and existing simple advanced-prefix detection; server parsing remains authoritative.

No per-card ReviewLog lookup, in-memory scan, duplicate parser, or second execution path is justified.

## 9. Phase 8I acceptance matrix

A separately authorized implementation must prove:

- numeric parser acceptance, leading-zero normalization, deduplication, and malformed-token 422;
- unchanged behavior for existing `rated:again|hard|good|easy` tokens;
- 1-, 7-, 30-, and 365-day natural-window boundaries;
- inclusive start and exclusive end, including DST/month/year boundaries;
- rating-code mapping 1 through 4;
- exclusion of reset, non-sense-review, undone, foreign-user, foreign-language, legacy-word, and unconfirmed-sense history;
- AND behavior between multiple numeric tokens and between numeric and symbolic rating tokens;
- compatibility with all existing token families and plain text;
- identical membership for list, JSON, CSV, and Anki TSV;
- constant query shape and zero business writes;
- frontend help/chips without parser duplication;
- real localhost Browser, Network, Console, and zero-write acceptance because Phase 8I would change user-visible search behavior.

## 10. Phase 8H conclusion

Phase 8H freezes an additive Anki-style numeric `rated:` grammar while preserving LinguaCafe's existing symbolic lifetime-rating tokens. The contract reuses the current formal-review log boundary and natural-day period semantics instead of inventing a parallel notion of “recent”.

No runtime grammar, query, UI, route, export, schema, Saved Search, Custom Study, Reviewer, FSRS, ReviewLog, quoted-phrase, negation, OR, or parentheses work was performed.

The only next entry is **Phase 8I — Browser Search numeric `rated:` recent-window minimal runtime implementation**. It requires separate authorization and must implement only the contract frozen here.
