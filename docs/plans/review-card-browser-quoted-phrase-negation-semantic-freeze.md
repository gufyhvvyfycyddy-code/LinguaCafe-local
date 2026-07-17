# Review Card Browser Quoted Phrase and Negation Semantic Freeze

> **Status**: Phase 8J complete — semantics frozen; runtime implementation not authorized
> **Date**: 2026-07-17
> **Scope**: Browser Search quoted phrase and text-negation reconnaissance and contract freeze only
> **Related**: [ADR-0012](../adr/ADR-0012-review-card-browser-search.md), [ADR-0013](../adr/ADR-0013-review-card-browser-search-execution-pipeline.md), [Anki maximal-alignment forward plan](anki-maximal-alignment-gap-audit-and-forward-plan.md)

## 1. Goal

Freeze the smallest safe quoted-phrase and negation extension after the linear Browser term grammar became stable.

Phase 8J is documentation and read-only reconnaissance only. It does **not** modify the parser, criteria object, query applier, frontend, routes, exports, database schema, Saved Search, Custom Study, Reviewer, ReviewLog, FSRS, or runtime search behavior.

The first future runtime slice is intentionally narrower than Anki's full query language:

```text
"take charge"
-charge
-"take charge"
```

It does not add OR, parentheses, a general AST, or negation of existing advanced tokens.

## 2. Repository and Anki facts

1. LinguaCafe's current parser splits on whitespace after collapsing whitespace globally. It cannot keep a multi-word quoted phrase as one search item.
2. Current plain text remains one `textQuery` and is matched as one substring against the five existing WordSense fields:
   - `lemma`;
   - `surface_form`;
   - `sense_zh`;
   - `sense_en`;
   - `example_sentence_en`.
3. Current advanced tokens and the remaining plain text use global AND semantics. List, JSON export, CSV export, and Anki TSV share one Parser/Criteria/Query-Applier pipeline.
4. A leading minus sign currently has no negation meaning in LinguaCafe. `-charge` is treated as literal plain text, and `-is:suspended` is not recognized as an advanced token.
5. The official [Anki manual — Searching / Simple searches](https://docs.ankiweb.net/searching.html#simple-searches) defines double quotes as an exact sequence search and a leading `-` as exclusion. It also permits `-"a dog"` for excluding an exact phrase.
6. The same Anki language also supports OR, parentheses, field searches, wildcards, regular expressions, and advanced-token negation. Those broader features are not authorized by Phase 8J.

## 3. Frozen syntax for the first implementation slice

A future Phase 8K implementation may add exactly three text forms:

```text
"<phrase>"
-<plain-term>
-"<phrase>"
```

Examples:

```text
"take charge"
charge "take responsibility"
-charge
-"take charge"
charge -burden
"take charge" -"avoid responsibility"
"take charge" is:active rated:7
```

Rules:

- A quoted phrase starts with `"` at a term boundary and ends at the next unescaped `"`.
- A negative phrase starts with `-"` at a term boundary.
- A negative plain term starts with one leading `-` and continues until the next unquoted whitespace boundary.
- Phrase content must be non-empty after parsing.
- Phrase text is preserved in original case and character order after escape decoding.
- Matching remains case-insensitive where the current database collation is case-insensitive; Phase 8K does not add a case-sensitive mode.
- Existing unquoted positive plain text remains backward compatible and keeps its current single-`textQuery` behavior.

The first slice supports only these escapes inside a quoted phrase:

```text
\"   literal double quote
\\   literal backslash
```

No other backslash escape is interpreted. A backslash before any other character remains part of the phrase text.

## 4. Quoted phrase meaning

A positive quoted phrase means that at least one of the five existing searchable WordSense fields contains the exact decoded character sequence as a contiguous substring.

Conceptually:

```text
field LIKE %literal_phrase%
```

The implementation must escape SQL wildcard characters and the chosen SQL escape character so `%` and `_` inside a quoted phrase are treated literally, not as SQL wildcards.

Consequences:

- `"take charge"` matches `please take charge now`.
- It does not match `charge take`.
- It does not match `take full charge`.
- Multiple quoted phrases are independent global-AND predicates and may be satisfied by different searchable fields on the same WordSense.
- The phrase does not search chapter text, source labels, lifecycle data, FSRS fields, ReviewLog, or exports as raw files.

Quoted phrases are text predicates, not new advanced-token families.

## 5. Frozen negation meaning

The first runtime slice supports negation only for text predicates:

```text
-charge
-"take charge"
```

A card matches a negative text predicate only when none of the five searchable WordSense fields contains that literal substring or phrase.

Conceptually, each negative item is an independent `NOT EXISTS` / `whereDoesntHave` text predicate. The implementation must not rely on a chain of raw `NOT LIKE` checks whose NULL behavior can accidentally hide cards.

Rules:

- `-charge` excludes any card whose searchable WordSense fields contain the literal substring `charge`.
- `-"take charge"` excludes the exact contiguous phrase.
- `%`, `_`, and the SQL escape character are treated literally in all newly introduced negative text predicates.
- Multiple negatives use global AND: `-cat -mouse` means neither substring may appear.
- Positive and negative conditions remain independent. `charge -charge` is legal and naturally returns no result; it is not a parser conflict.
- Negation never changes user, language, sense-confirmed, target-type, lifecycle-visibility, or export boundaries.

## 6. Advanced-token negation is explicitly deferred

The first runtime slice must reject, with the existing structured `invalid_browser_search` 422 shape, attempts to negate recognized advanced tokens, including:

```text
-is:suspended
-rated:again
-rated:7
-prop:lapses>=2
-flag:1
-state:review
-due:today
-source:chapter:46
-missing:source
```

This is deliberate. Inverting each token family has different NULL, existence, range, lifecycle, and governance semantics. Silent treatment as plain text would hide user mistakes, while implementing all inversions in the same slice would exceed the bounded linear-grammar task.

Advanced-token negation requires a separately authorized semantic decision after text negation is proven. It must not be smuggled into Phase 8K.

## 7. Tokenization and normalization

A future implementation requires a small deterministic lexer, not a general expression parser.

The lexer must distinguish:

1. existing advanced tokens;
2. the existing unquoted positive plain-text remainder;
3. positive quoted phrases;
4. negative plain terms;
5. negative quoted phrases.

Frozen compatibility rules:

- Existing advanced token parsing, normalization, deduplication, conflicts, and structured errors remain unchanged.
- Existing unquoted positive text segments are rejoined in original order into the current single `textQuery` after recognized advanced tokens and the new phrase/negative items are removed.
- Positive quoted phrases and negative text predicates are stored as ordered read-only criteria collections.
- Exact duplicate phrase/negative predicates are removed in first-occurrence order after escape decoding.
- Positive and negative versions of the same text are not duplicates of each other.
- Text predicates are not added to `normalizedTokens`; that list remains the server-authoritative advanced-token chip list. Phase 8K therefore does not require the frontend to implement quote-aware chip removal.
- The raw query remains available unchanged for the input field and diagnostics.

The lexer must operate without DB, Request, Auth, session, filesystem, or network access.

## 8. Structured error rules

Malformed recognized quote or negation grammar must return the existing `invalid_browser_search` 422 response before query execution.

Invalid examples include:

```text
"unterminated
-"unterminated
""
-""
-
--charge
foo"bar
-is:suspended
```

Frozen reasons:

- unclosed quote;
- empty quoted phrase;
- dangling standalone minus;
- repeated leading minus;
- quote beginning inside an unquoted term;
- unsupported negation of a recognized advanced token.

A literal hyphen inside a normal term remains ordinary text:

```text
well-being
state-of-the-art
```

A term that begins with a literal hyphen is not supported in the first slice; escaping a leading hyphen is deferred with broader special-character support.

## 9. Combination semantics

All conditions remain global AND predicates.

Examples:

- `"take charge" -burden` means the phrase exists and `burden` appears nowhere in the five searchable fields.
- `"take charge" rated:7:3` means the phrase exists and the card has a qualifying recent Good review.
- `charge "take responsibility"` means the existing positive `textQuery=charge` predicate and the quoted phrase predicate must both match.
- `-charge -"take responsibility"` means neither negative predicate may match.
- Positive text, quoted phrases, negative text, lifecycle, governance, marker, state, due, property, source, missing-field, and rating conditions remain independently AND-combined.

No same-field requirement, implicit OR, precedence rule, or shared-expression grouping is introduced.

## 10. Architecture placement for Phase 8K

A future minimal runtime implementation must extend the existing Browser pipeline:

1. `ReviewCardBrowserSearchParser` replaces whitespace-only tokenization with one small pure lexer while preserving existing advanced-token parsing methods.
2. `ReviewCardBrowserSearchCriteria` gains ordered read-only positive-phrase and negative-text collections while retaining the existing `textQuery` contract.
3. `ReviewCardBrowserSearchQueryApplier` owns all five-field positive phrase and negative text predicates.
4. A single literal-LIKE escaping helper must be used by all newly introduced phrase/negative predicates.
5. List, JSON export, CSV export, and Anki TSV continue to consume the same Criteria and query path.
6. Frontend work is limited to help text and examples. Server parsing remains authoritative; no frontend lexer or full parser is allowed.

No AST is needed for this linear slice. The lexer output must remain smaller and simpler than a general boolean expression model.

## 11. Phase 8K acceptance matrix

A separately authorized implementation must prove:

- positive quoted phrase parsing and contiguous matching;
- negative plain-term and negative quoted-phrase parsing;
- `\"` and `\\` decoding;
- literal handling of `%`, `_`, and SQL escape characters in all new predicates;
- existing unquoted positive text behavior remains unchanged;
- existing advanced tokens, normalization, conflicts, and 422 errors remain unchanged;
- malformed quote/minus grammar returns structured 422 before DB query execution;
- recognized advanced-token negation returns structured 422 rather than silently becoming text;
- phrase and negative deduplication in first-occurrence order;
- global AND behavior across positive phrase, negative text, existing textQuery, and current token families;
- user, language, confirmed-sense, sense-only, lifecycle, and export isolation remain unchanged;
- identical membership for list, JSON, CSV, and Anki TSV;
- constant query shape and zero business writes;
- help copy without frontend parser duplication;
- real localhost Browser, Network, Console, malformed-input, and zero-write acceptance because Phase 8K changes visible search behavior.

## 12. Explicit exclusions

Phase 8J and the first runtime slice do not add:

- OR, explicit AND keywords, parentheses, precedence, or a general AST;
- negation of lifecycle, governance, rating, due, property, marker, state, source, or missing-field tokens;
- field-specific text syntax;
- wildcard, word-boundary, regex, accent-insensitive, or cloze-stripping searches;
- escaped leading-hyphen literal terms;
- single-quote delimiters;
- multiline phrase normalization or HTML-aware text normalization;
- new route, migration, index, dependency, export field, Saved Search schema, Custom Study mode, Reviewer behavior, FSRS change, or ReviewLog write.

## 13. Phase 8J conclusion

Phase 8J freezes a bounded linear extension: positive quoted phrases plus negated plain text and negated quoted phrases. It preserves the existing single unquoted `textQuery`, all current advanced-token contracts, global AND behavior, and the shared four-consumer execution pipeline.

No runtime grammar, query, UI, route, export, schema, Saved Search, Custom Study, Reviewer, FSRS, ReviewLog, advanced-token negation, OR, parentheses, or AST work was performed.

The only next entry is **Phase 8K — Browser Search quoted phrase and text-negation minimal runtime implementation**. It requires separate authorization and must implement only this frozen contract.
