# M10 Unified Search, Tags, and Browser Foundation Acceptance — 2026-07-28

Status: **Accepted / Closed**
Architecture: `docs/adr/ADR-0038-m10-unified-search-and-word-sense-tags.md`
Implementation plan: `docs/plans/m10-unified-search-tags-browser-foundation-plan.md`

## 1. Accepted scope

M10 closes the shared search and WordSense organization foundation:

- `ReviewCardManageFilterState` v2 adds canonical, bounded `tag_ids`; the shared
  query owner applies AND semantics and fails closed for missing or foreign tags;
- isolated WordSense tag and assignment tables enforce user/language uniqueness
  and referential cleanup without touching ReviewCard, ReviewLog or FSRS state;
- scoped web CRUD and idempotent bounded bulk assignment support Browser tag
  management, filtering, Saved Search restoration and a configurable Tags column;
- Browser, Saved Search, Study Overview, JSON, CSV and Anki TSV reuse the same
  criteria and serialization path;
- Anki TSV emits space-separated tags, preserves the valid tag name `0`, converts
  internal whitespace to `_`, and retains `::` hierarchy paths;
- the device-bound Mobile endpoint is read-only, shares the same criteria/query
  owner and caps pagination at 100 rows.

M10 does not add Deck/Subdeck, Note Type, sibling cards, arbitrary Anki Boolean
grammar, tag hierarchy editing, colors, merge semantics or a second query AST.

## 2. Focused and protected verification

The final affected matrix passed **424 tests / 2 skipped / 1,616 assertions**.
It covered ReviewCard management and Browser grammar, Saved Search v1-to-v2
compatibility, Study Overview parity, Mobile foundation, canonical filter state,
tag domain/API/query/export behavior, UI guards and migration rollback/restore.

The protected FSRS, scheduling, WordSense and Mobile operation-ledger matrix
passed **285 tests / 1,413 assertions**. The final frontend build passed with
only the repository's existing Sass deprecation warnings.

Additional checks passed:

- PHP syntax checks for all affected PHP files;
- the additive migration rolls back and restores on an empty testing schema and
  remains `Ran` after the test;
- route listing exposes the five scoped web tag operations and one Mobile GET
  search operation;
- allowlist `git diff --check` found no whitespace errors.

## 3. Real-browser and cleanup evidence

Channel: official OpenAI Browser plugin against one task-owned testing listener
at `127.0.0.1:8092`, using one sequential automation page at a time.

Through rendered UI and user events, the acceptance batch:

1. registered and logged in a testing-only account;
2. opened Advanced Review Card Manager and observed the seeded task card;
3. created a hierarchical tag and observed the case-insensitive duplicate error;
4. bulk-added the tag, filtered by it, and observed the Tags table chip;
5. saved and restored the v2 search and hid/restored the Tags column;
6. renamed the tag and verified the table refreshed automatically without reload;
7. bulk-removed the tag and observed the filtered empty state;
8. deleted the Saved Search and tag through their visible confirmation flows.

No direct API write substituted for a button action. A native-confirm provider
timeout was recovered by closing only the automation-owned page, opening one
replacement page in the same official browser session, and accepting the dialog
through the supported dialog handle. Final console errors were zero.

Closeout closed every automation-owned page, verified no official-browser tabs
remained, stopped the exact listener and verified its port closed. It deleted
only the exact testing account and task-owned sense/card/tag/search fixtures,
then verified those rows and ReviewLog residue were all zero.

## 4. Quality review

- **Correctness:** canonical criteria, query ownership and cross-consumer parity
  are executable; rename/delete now refresh table data rather than leaving stale
  tag labels.
- **Security:** every mutation and query binds authenticated user and selected
  language; unknown/foreign IDs fail closed; foreign keys prevent orphan rows.
- **Compatibility:** Saved Search v1 remains readable and upgrades on edit;
  existing Browser/export/Mobile contracts and protected review paths pass.
- **Side effects:** tag/search/read-only Mobile paths prove zero ReviewLog and
  zero FSRS/lifecycle mutation; cleanup touched only task-owned testing data.
- **Architecture:** controllers remain adapters; the FilterState, query, tag and
  export services retain one responsibility each; no second search engine or
  formal-rating entrance was introduced.

Verdict: **Approve M10A–M10D. M10 is Accepted / Closed.**
