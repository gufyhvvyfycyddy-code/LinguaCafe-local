# Review Card Browser `missing:` Search Semantic Freeze

> **Status**: Phase 8F semantic freeze complete; Phase 8G minimal runtime implementation verified by task-specific tests and real browser acceptance
> **Date**: 2026-07-17
> **Scope**: Browser Search missing-field semantic authority and Phase 8G acceptance record
> **Related**: [ADR-0012](../adr/ADR-0012-review-card-browser-search.md), [ADR-0013](../adr/ADR-0013-review-card-browser-search-execution-pipeline.md), [source search semantic freeze](review-card-browser-source-search-semantic-freeze.md), [Anki maximal-alignment forward plan](anki-maximal-alignment-gap-audit-and-forward-plan.md)

## 1. Goal

Freeze the smallest safe meaning of Browser Search `missing:` tokens and record the bounded Phase 8G implementation against that contract.

Phase 8F was documentation and read-only reconnaissance only. Phase 8G subsequently implemented exactly the three frozen tokens through the existing Browser pipeline and one shared missing-field predicate owner. It did not add routes, migrations, dependencies, export fields, Saved Search schema, Custom Study behavior, Reviewer behavior, FSRS scheduling, ReviewLog writes, recent windows, quoted phrases, negation, OR, or parentheses.

## 2. Repository facts found during reconnaissance

1. `/review-cards/manage` already exposes three mutually exclusive top-level filters:
   - `missing_definition` — 缺释义;
   - `missing_example` — 缺例句;
   - `missing_source` — 缺溯源.
2. `ReviewCardManageQueryService` owns the current SQL meaning of those filters.
3. `ReviewCardManageItemSerializerService` exposes the corresponding boolean fields in list/detail/export payloads, and `ReviewCardExportService` already allows all three fields in exports.
4. The top-level filter state can select only one missing category at a time. Browser Search tokens are the existing composable mechanism for combining several conditions.
5. Browser Search already has one pure parser, one read-only criteria object, one query applier, and one shared list/JSON/CSV/Anki-TSV execution path.
6. The Browser base query already enforces current user, current language, `target_type=sense`, and confirmed `WordSense` ownership.
7. Phase 8E separately froze and implemented positive source membership through `source:chapter:<id>` and `source:book:<id>`. Missing-source search must not redefine those positive-source tokens or add a hidden negation system.
8. No `missing:` runtime prefix exists in the current parser.

## 3. Frozen syntax for the first implementation slice

Phase 8G implements exactly these forms:

```text
missing:definition
missing:example
missing:source
```

Examples:

```text
missing:definition
missing:example state:review
missing:definition missing:example
missing:source prop:lapses>=2
```

Rules:

- Prefix and value are case-insensitive.
- Normalized tokens are lowercase.
- Exact duplicate normalized tokens are removed in first-occurrence order.
- Different `missing:` tokens are legal and use the Browser grammar's existing global AND semantics.
- Only the exact singular values `definition`, `example`, and `source` are valid.
- Empty values, extra segments, plurals, aliases, and unknown values are malformed grammar and must use the existing structured `invalid_browser_search` 422 response.

The first implementation must **not** add `is:missing`, `source:missing`, `missing:none`, `missing:any`, `missing:all`, `has:*`, negation, OR, parentheses, quoted phrases, or custom field names.

## 4. Frozen field meanings

### 4.1 `missing:definition`

A card matches only when both definition fields on its confirmed `WordSense` are absent under the existing management-filter rule:

```text
(sense_zh IS NULL OR sense_zh = '')
AND
(sense_en IS NULL OR sense_en = '')
```

Consequences:

- Either Chinese or English definition being present means the definition is not missing.
- `aliases_zh`, `collocations`, `pos`, lemma, surface form, examples, and source context do not satisfy the definition requirement.
- Phase 8G must preserve the existing exact null/empty-string behavior. Trimming whitespace, rewriting stored content, or introducing quality scoring is outside this contract.

### 4.2 `missing:example`

A card matches when `example_sentence_en` is `NULL` or the empty string.

Consequences:

- `example_sentence_zh` alone does not satisfy the example requirement.
- A source chapter, occurrence sentence, display fallback, or dynamically located context does not silently fill the stored card example field.
- Example quality, sentence length, translation presence, and tokenizer coverage are outside this contract.

### 4.3 `missing:source`

A card matches under the existing `missing_source` management-filter rule when both conditions hold:

1. `word_senses.source_chapter_id IS NULL`; and
2. no current-user/current-language `WordSenseOccurrence` exists for the sense with `status=bound` and a non-null `chapter_id`.

Consequences:

- A saved English or Chinese example sentence alone is not real source provenance.
- Pending, rejected, ignored, or chapter-less occurrences do not satisfy source presence.
- The token does not inspect chapter titles, book titles, sentence text, display labels, import-provider names, or ReviewLog history.
- Phase 8G must match the existing top-filter behavior exactly. It must not perform a new stale-pointer cleanup, cross-table integrity repair, or source ownership migration inside the search feature.

## 5. Combination and filter interaction

All Browser Search conditions remain AND-combined.

Examples:

- `missing:definition missing:example` means both definitions are absent and the stored English example is absent.
- `missing:source state:review` means a review-state card with no source under the frozen rule.
- `missing:source source:chapter:46` is legal grammar but normally returns zero because the conditions are contradictory.

The future token and the existing top-level missing filter must return the same membership for the same category. Combining a top-level missing filter with another `missing:` token remains a normal AND combination.

The implementation must not maintain two independently evolving SQL definitions. The standard filter and token path must delegate to one shared missing-field predicate owner or an equivalently single-sourced query boundary.

## 6. Validation, privacy, and side effects

- The parser validates token shape only and remains database-free.
- Malformed recognized `missing:` tokens return the existing structured 422 before the card query runs.
- Valid tokens reveal only matching cards already inside the Browser's user/language/sense-only/confirmed scope.
- No raw SQL, table names, ownership details, or hidden source records are exposed in errors.
- Search is read-only: no writes to `ReviewCard`, `WordSense`, `WordSenseOccurrence`, `ReviewLog`, lifecycle events, FSRS fields, examples, or source pointers.

## 7. Architecture placement for Phase 8G

A future implementation must extend the existing Browser Search pipeline:

1. `ReviewCardBrowserSearchParser` recognizes and normalizes the three exact forms.
2. `ReviewCardBrowserSearchCriteria` carries an ordered read-only list of missing fields, conceptually `definition|example|source`.
3. `ReviewCardBrowserSearchQueryApplier` applies the frozen predicates through the same predicate owner used by the standard missing filters.
4. `ReviewCardManageQueryService` remains the owner of the already-scoped Browser base query, top filters, advanced filters, and sorting.
5. List, JSON export, CSV export, and Anki TSV export continue to consume the same parsed criteria and query.

No new route, migration, dependency, AST, export field, Saved Search schema, Custom Study mode, Reviewer behavior, or frontend parser is justified by this slice.

The frontend may add help text, normalized chips, and simple prefix detection after implementation. Server-side parsing remains authoritative.

## 8. Query and performance boundary

Future matching must use SQL predicates on the existing scoped query:

- definition/example checks use existing `whereHas('sense')` conditions;
- source absence uses the existing direct-null plus scoped `whereNotExists` occurrence rule;
- no per-card lookup;
- no loading all cards or occurrences into PHP memory;
- no query count growth with result count;
- no duplicate ReviewCard rows.

## 9. Phase 8G acceptance matrix and evidence

Phase 8G proves:

- parser acceptance, case normalization, malformed-token 422, and duplicate removal;
- exact parity with each existing top-level missing filter;
- `missing:definition` requires both stored definition fields to be absent;
- `missing:example` checks only the stored English example field;
- `missing:source` excludes direct-source and valid bound-occurrence source cards;
- pending/rejected/ignored/chapter-less occurrences still count as missing source;
- different missing tokens use AND behavior;
- compatibility with plain text and existing `is:`, `rated:`, `prop:`, `flag:`, `state:`, `due:`, and `source:` tokens;
- current user, current language, confirmed sense, and sense-only isolation;
- identical result membership for list, JSON, CSV, and Anki TSV consumers;
- constant query behavior and zero business writes;
- frontend help/chips without a duplicated parser;
- real localhost Browser, Network, Console, and zero-write acceptance because Phase 8G changes user-visible search behavior.

Verified evidence:

- TDD RED: 14 failed / 6 passed / 78 assertions on the pre-implementation path after correcting one test fixture to respect the non-null database field;
- focused GREEN: 20 passed / 138 assertions / 0 failures;
- related Browser regression: 420 passed / 1,523 assertions / 2 existing slow export-limit tests skipped / 0 failures;
- all JS guards: 67/67 files passed;
- `npm run development`: successful, with existing Sass deprecation warnings only;
- real localhost Chrome: `MISSING:SOURCE` returned HTTP 200 and displayed normalized `missing:source`; the help dialog exposed all three tokens and AND semantics;
- the Browser action emitted one local GET, no write request, and no external-domain request; database counts before and after were identical;
- the full PHP suite was attempted twice and produced no functional assertion failure before both runs were terminated near the end by the test process's effective 128MB memory limit. The second command-line 512MB override was superseded by the test configuration, so no complete aggregate is claimed.

## 10. Phase 8F/8G conclusion

Phase 8F remains the semantic authority for a three-value `missing:` grammar that composes existing management-filter meanings instead of inventing a new content-quality model. Phase 8G implemented only that contract through the existing Browser pipeline and one shared predicate owner.

No recent-window, quoted-phrase, negation, OR, parentheses, route, migration, dependency, export-field, Saved Search schema, Custom Study, Reviewer, FSRS, or ReviewLog work was added. Commit/push was not authorized, so the implementation remains local.

The Phase 8H recent-review reconnaissance is now complete in `review-card-browser-recent-review-search-semantic-freeze.md`. The current next entry is **Phase 8I — Browser Search numeric `rated:` recent-window minimal runtime implementation**. It requires separate authorization and must implement only the frozen numeric window contract.
