# Review Card Browser `source:` Search Semantic Freeze

> **Status**: Phase 8D semantic freeze complete; Phase 8E minimal runtime implementation verified locally
> **Date**: 2026-07-17
> **Scope**: Browser Search source book/chapter semantic authority and Phase 8E acceptance record
> **Related**: [ADR-0012](../adr/ADR-0012-review-card-browser-search.md), [ADR-0013](../adr/ADR-0013-review-card-browser-search-execution-pipeline.md), [Anki maximal-alignment forward plan](anki-maximal-alignment-gap-audit-and-forward-plan.md)

## 1. Goal

Freeze the smallest safe meaning of a future Browser Search `source:` token before implementation. This phase records current repository facts, chooses syntax and matching semantics, and defines the future acceptance boundary.

Phase 8D does **not** add search syntax to the running application. It does not change the parser, criteria object, query applier, UI, routes, exports, database schema, Saved Search, Custom Study, Reviewer, FSRS scheduling, ReviewLog, or lifecycle behavior.

## 2. Repository facts found during reconnaissance

1. Browser Search already has one pure parser, one immutable criteria object, one query applier, and one shared execution path for the management list plus JSON, CSV, and Anki TSV exports.
2. The Browser base query already enforces current user, current language, `target_type=sense`, and confirmed `WordSense` ownership.
3. A sense can have real chapter provenance through either:
   - `word_senses.source_chapter_id`; or
   - a bound `word_sense_occurrences.chapter_id` row belonging to the same user and language.
4. A single sense may have multiple bound occurrences and therefore may legitimately belong to multiple chapters or books.
5. `ReviewCardManageItemSerializerService` already exposes `source_chapter_id`, `source_chapter_title`, `source_kind`, `source_display_status`, `source_display_label`, and `missing_source` for presentation/export. Those output fields are not the search authority.
6. The existing `missing_source` management filter defines real source absence as both no direct source chapter and no bound occurrence with a chapter. A saved example sentence alone is not real chapter provenance.
7. `CustomStudy\Queries\SourceChapterQuery` already proves the canonical direct-or-bound-occurrence membership model and deduplication behavior. Its complete query cannot be reused directly by Browser Search because Custom Study additionally applies formal-review eligibility and excludes lifecycle states that Browser must still be able to find.
8. Chapters and books have stable numeric IDs and user/language ownership. Their names can contain spaces, can be renamed, and are not guaranteed unique.

## 3. Frozen syntax for the first implementation slice

Phase 8E adds exactly these forms:

```text
source:chapter:<positive-integer-id>
source:book:<positive-integer-id>
```

Examples:

```text
source:chapter:46
source:book:12
source:book:12 state:review prop:lapses>=2
source:chapter:46 source:chapter:52
```

Rules:

- Prefix and target kind are case-insensitive.
- Normalized tokens are lowercase and numeric IDs are canonical integers: `SOURCE:CHAPTER:0046` normalizes to `source:chapter:46`.
- IDs must be positive integers. Zero, negative values, decimals, empty IDs, extra segments, and non-numeric IDs are invalid grammar.
- Exact duplicate normalized tokens are removed in first-occurrence order.
- Distinct source tokens are allowed and use the existing global AND semantics.

The first implementation must **not** support bare names or free text such as `source:My Book`, `source:chapter:"Chapter One"`, or `source:book-name:...`. Names with spaces and quoted phrases belong to a later grammar phase. Stable IDs avoid rename and duplicate-name ambiguity.

## 4. Frozen matching semantics

### 4.1 `source:chapter:<id>`

A card matches when its confirmed sense has real provenance to that exact owned chapter through at least one of these paths:

1. direct path: `word_senses.source_chapter_id = <id>`; or
2. occurrence path: a `word_sense_occurrences` row with:
   - `word_sense_id = review_cards.target_id`;
   - `status = bound`;
   - matching current `user_id` and current `language_id`;
   - `chapter_id = <id>`.

The chapter itself must belong to the current user and current language. A stale or cross-scope chapter reference must fail closed and must not match.

### 4.2 `source:book:<id>`

A card matches when at least one valid direct or bound-occurrence chapter provenance belongs to the exact owned book. Both the book and matched chapter must belong to the current user and current language.

A chapter without that book does not match. A card with provenance in several chapters matches if any valid provenance path reaches a chapter in the requested book.

### 4.3 Multiple source tokens

Different source tokens are AND-combined, like the rest of the linear grammar. Each condition may be satisfied by a different valid provenance path for the same sense.

Examples:

- `source:chapter:46 source:chapter:52` means the sense has valid provenance in both chapters.
- `source:book:12 source:chapter:46` means the sense has valid provenance in book 12 and in chapter 46; the same chapter may satisfy both when chapter 46 belongs to book 12.

No duplicate ReviewCard row may be produced when direct and occurrence paths, or multiple occurrences, reach the same source.

## 5. What `source:` does not mean

The future token must not search or infer from:

- `example_sentence_en` or `example_sentence_zh`;
- `source_kind`, `source_display_status`, or display labels;
- chapter/book name substring;
- sentence text, sentence ID, document ID, raw payload, provider, or import source;
- card examples that have no owned chapter provenance;
- lifecycle state, Leech state, FSRS state, or ReviewLog history.

`source:` is a real book/chapter provenance filter only. Missing-field search remains a separate Phase 8 item; Phase 8E must not add `source:none`, `source:missing`, or a `missing:` grammar.

## 6. Validation and privacy behavior

- Malformed syntax is rejected by the pure parser with the existing structured `invalid_browser_search` 422 contract before the card query runs.
- A syntactically valid but nonexistent, deleted, foreign-user, or foreign-language source ID returns zero matching cards. It must not reveal whether that source exists outside the current scope.
- The parser remains database-free and validates only token shape. Ownership is enforced by scoped SQL predicates in the query layer.
- No source title, raw text, or ownership detail is added to error payloads.

## 7. Architecture placement for Phase 8E

The implementation must extend the existing pipeline rather than create a parallel search path:

1. `ReviewCardBrowserSearchParser` recognizes and normalizes the two forms.
2. `ReviewCardBrowserSearchCriteria` carries an ordered read-only list of source targets, conceptually `{kind: chapter|book, id: positive-int}`.
3. `ReviewCardBrowserSearchQueryApplier` applies one scoped existence condition per distinct target.
4. `ReviewCardManageQueryService` keeps ownership of the already-scoped Browser base query and normal filters.
5. The list, JSON export, CSV export, and Anki TSV export continue to consume the same criteria and query.

The implementation should reuse the **membership semantics** proven by `CustomStudy\Queries\SourceChapterQuery`, but must not call its complete eligible-card query because Browser Search must preserve Browser lifecycle visibility. No new service, route, dependency, AST, migration, or export field is justified by the frozen first slice.

The frontend may display normalized chips and help text after implementation, but must not implement a second grammar parser.

## 8. Query and side-effect boundary

Future matching must use scoped SQL existence/subquery predicates or an equivalent constant-query construction:

- no per-card source lookup;
- no loading all occurrences or chapter IDs into PHP memory;
- no N+1 growth with result count;
- no writes to `ReviewCard`, `WordSense`, `WordSenseOccurrence`, `Chapter`, `Book`, `ReviewLog`, lifecycle events, or FSRS fields.

Browser source search must not inherit Custom Study eligibility. With the normal Browser filter set to all, suspended and archived sense cards remain searchable by source. Existing Browser filter buttons and advanced tokens continue to decide lifecycle visibility.

## 9. Phase 8E acceptance matrix

Phase 8E was separately authorized by the user and completed against this matrix:

- parser acceptance, normalization, malformed-token 422, and duplicate removal;
- direct source chapter match;
- bound occurrence chapter match;
- direct plus occurrence deduplication;
- multiple source tokens with AND behavior;
- book membership through direct and occurrence paths;
- nonexistent/foreign user/foreign language IDs returning no matches without disclosure;
- pending/rejected/ignored occurrences not matching;
- current user, current language, confirmed sense, and sense-only isolation;
- suspended and archived cards remaining findable when Browser filters allow them;
- identical result membership for list, JSON, CSV, and Anki TSV consumers;
- constant query behavior and zero business writes;
- frontend help/chips without a duplicated parser;
- real localhost Browser, Network, Console, and zero-write acceptance because Phase 8E changes user-visible search behavior.

## 10. Phase 8D/8E conclusion

Phase 8D remains the semantic authority for source search. Phase 8E implemented only that frozen contract through the existing Browser search pipeline and verified parser validation, direct and occurrence provenance, scope isolation, deduplication, AND behavior, all four consumers, constant-query behavior, zero writes, frontend help, and real localhost Browser/Network/Console behavior.

No name search, missing-source grammar, quoted phrase, negation, recent window, OR, parentheses, Saved Search schema, Custom Study, Reviewer, route, dependency, migration, or export-field work was added. Commit/push was not authorized, so the implementation remains local.

The next entry recorded by this source-search closure was Phase 8F. Phase 8F semantic freeze and Phase 8G minimal `missing:` runtime implementation are recorded in `review-card-browser-missing-field-search-semantic-freeze.md`; Phase 8H recent-review semantics are frozen in `review-card-browser-recent-review-search-semantic-freeze.md`. The current next entry is the separately authorized Phase 8I numeric `rated:` recent-window minimal runtime implementation.
