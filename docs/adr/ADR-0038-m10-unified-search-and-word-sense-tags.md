# ADR-0038: M10 Unified Search and WordSense Tags

## Status

Accepted / Implemented / Closed

## Context

M10 must establish one query and knowledge-organization foundation for Browser,
Saved Search, export, Statistics, Custom Study and mobile read-only search.
The repository already has a versioned `ReviewCardManageFilterState`, one
single-parse Browser search pipeline and shared management/export/overview
queries. Creating a second generic AST would duplicate those semantics.

Product decision PD-002 assigns content tags to `WordSense`, while Card Marker
remains a per-`ReviewCard` attention flag. The Anki reference likewise keeps
note tags separate from card flags and reuses Browser searches as saved
searches. LinguaCafe does not adopt Anki deck, note-type or sibling-card models.

## Decision

1. `ReviewCardManageFilterState` remains the canonical versioned Criteria V1
   value object. M10 adds normalized `tag_ids` with AND semantics. Existing
   Browser text/tokens, lifecycle, FSRS, due, reps/lapses, sort and paging retain
   their meaning.
2. `ReviewCardManageQueryService::buildFromFilterState()` remains the single
   scoped query owner. Browser data, JSON/CSV/TSV export, Saved Search,
   Study Overview, future Custom Study/Statistics consumers and the mobile
   read-only adapter reuse it.
3. Saved Search schema version 2 adds `tag_ids`. Version 1 rows remain readable
   as the same state with an empty tag list and are upgraded only when edited.
   Saved Searches continue to store criteria, never card IDs or SQL.
4. `WordSenseTag` is user-and-language-owned content metadata. A tag has a
   display name and a Unicode-normalized, case-insensitive key. A many-to-many
   assignment joins tags to WordSenses. Every list, rename, delete and bulk
   assignment validates the authenticated user, selected language and target
   sense/card scope before mutation.
5. Tag names may contain `::` as an Anki-familiar hierarchy path, but M10 treats
   the stored tag as an exact selectable value. Hierarchy editing, colors,
   aliases and merge semantics remain out of scope.
6. Bulk add/remove is idempotent and bounded. Deleting a tag removes only its
   assignments; it never deletes a WordSense, ReviewCard or learning history.
7. Browser responses add ordered tag summaries. Browser exposes tag filtering,
   tag management, bulk add/remove and a configurable tag column. Card Marker
   stays visually and behaviorally separate.
8. Mobile adds one authenticated, device-bound, read-only paginated search
   endpoint with a hard page-size cap. It accepts the same normalized criteria
   fields, returns the same current-membership semantics and exposes no write
   method.

## Rejected alternatives

- A new general parser/AST beside FilterState: duplicates established
  authorization, validation and query behavior.
- A JSON `tags` column on `word_senses`: makes rename, uniqueness, indexing and
  scoped bulk operations fragile.
- Reusing `review_cards.marker`: conflates content classification with a
  per-card attention signal.
- Full Anki Boolean grammar, decks or note types in M10: exceeds the accepted
  LinguaCafe scope and is unnecessary for cross-consumer parity.

## Security and compatibility

- All tag and query paths begin with authenticated `user_id` and selected
  `language`; supplied IDs never define authority.
- Cross-user/language tag, card or sense IDs fail without partial assignment.
- Criteria are allowlisted and canonicalized; sorting remains whitelist-only.
- The migration is additive and reversible. It may be applied only to the
  dedicated testing database.
- No tag action writes ReviewLog, FSRS, lifecycle, operation-ledger or source
  context.

## Verification

- Migration/model/service tests cover normalization, uniqueness, rename,
  delete, bounded/idempotent bulk mutation and isolation.
- Criteria and query parity tests cover Browser, all exports, Saved Search,
  Study Overview compatibility and mobile pagination.
- UI build and real-browser tests cover loading/empty/error, create/rename/
  delete, bulk add/remove, tag filtering, Saved Search restore and configurable
  tag column.
- Protected WordSense, ReviewCard, FSRS, Mobile and export regressions pass.
