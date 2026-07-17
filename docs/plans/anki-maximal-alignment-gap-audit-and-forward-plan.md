# Anki Maximal Alignment Gap Audit and Forward Plan

> **Status**: Draft planning input; not implementation authorization
> **Date**: 2026-07-17
> **Scope**: Product lifecycle, user workflows, and architecture after the current Phase 3–7 line
> **Invariant**: LinguaCafe remains reading-first and sense-only for formal review. Anki capabilities are adapted to `WordSense` / sense `ReviewCard`, not copied into a second generic Note/Card/Deck domain.

## 1. Official baseline

Primary sources used for this audit:

- [Anki repository](https://github.com/ankitects/anki): `rslib` backend/domain logic, `qt/aqt` coordinators and operations, and `ts` web surfaces.
- [Browsing](https://docs.ankiweb.net/browsing.html), [Searching](https://docs.ankiweb.net/searching.html), and [Filtered Decks](https://docs.ankiweb.net/filtered-decks.html).
- [Studying](https://docs.ankiweb.net/studying.html), [Deck Options](https://docs.ankiweb.net/deck-options.html), and [Statistics](https://docs.ankiweb.net/stats.html).
- [Adding/Editing](https://docs.ankiweb.net/editing.html), [Card Templates](https://docs.ankiweb.net/templates/intro.html), and [Card Generation](https://docs.ankiweb.net/templates/generation.html).
- [Importing](https://docs.ankiweb.net/importing/intro.html), [Text Import](https://docs.ankiweb.net/importing/text-files.html), [Exporting](https://docs.ankiweb.net/exporting.html), [Backups](https://docs.ankiweb.net/backups.html), and [Syncing](https://docs.ankiweb.net/syncing.html).
- [Add-on architecture](https://addon-docs.ankiweb.net/), [Collection boundary](https://addon-docs.ankiweb.net/the-anki-module.html), [hooks](https://addon-docs.ankiweb.net/hooks-and-filters.html), and [background operations](https://addon-docs.ankiweb.net/background-ops.html).

The comparison target is capability and responsibility placement, not Anki's desktop technology stack.

## 2. Current LinguaCafe facts that change the old roadmap

The existing roadmap is behind the code in several places:

- Browser Search, Saved Search, table, Card Info, scheduling, lifecycle, Leech, and marker mutations already have separate owners.
- Phase 3C-2 now has authenticated real-page single and bulk lifecycle evidence at desktop and `900×900`; delete remains unapproved.
- Rating request coordination and failure recovery are shared by `SenseReview.vue` and legacy `Review.vue`; Sense Review also exposes the accepted Card Info and shortcut surface.
- Reader hover display and automatic dictionary-search toggles preserve explicit false values; inactive sidebar space is released and reader projection has one owner behind the compatibility facade.
- AI Study Card V6 already has request-package, provider-preview, security policy, prompt/parser/schema services, an OpenAI-compatible backend transport, explicit UI trigger, and default-unchecked import into the existing confirmation flow.
- FSRS parameter optimization, desired retention, presets, reschedule preview/confirm/undo, daily limits, queue order, and retention workload simulation already exist.
- Study Overview already includes future-due, stability, difficulty, retrievability, and true-retention evidence.
- A local database backup command and admin trigger already exist, but restore and recovery UX are not equivalent to Anki.
- ReviewCard Browser exports CSV, generic export, and Anki TSV; there is no scheduling-preserving round trip comparable to `.apkg`/`.colpkg`.

## 3. Gap classification

| Class | Meaning | Examples |
|---|---|---|
| Replicate | Generic Anki capability that fits the sense-card domain | card flags, richer search, Card Info access, reviewer shortcuts, filtered study controls, statistics |
| Adapt | Preserve the user outcome with LinguaCafe ownership | books/chapters instead of decks; WordSense tags instead of note tags; central web persistence instead of AnkiWeb sync |
| Deliberate deviation | Copying would create a second product model or weaken the reading workflow | generic note types, arbitrary card templates, legacy word-card mainline, deck/subdeck ownership |
| Environment gate | Code exists; completion depends on local external state | real AI provider enablement, provider cost/usage evidence, browser Network capture |

## 4. Product and user-workflow gaps

### 4.1 Browser and card management

Already present: sense-card table, selection, columns, pagination, Saved Search, Card Info, export, edit, due-now, reset, lifecycle, Leech diagnostics, finite card marker, `flag:` search, shared marker mutation UI, and Card Info marker visibility.

Still missing or incomplete:

1. Anki-scale search grammar. LinguaCafe V1 supports plain text plus lifecycle/Leech/marker, `rated:again|hard`, and `prop:lapses`; it lacks boolean grouping, negation, phrases, regex, field/source/date/state terms, all ratings, recent-event terms, and most FSRS properties.
2. One search contract reused by Browser, Custom Study, and scoped statistics. Today these consumers have related but separate criteria.
3. Bulk edit for the safe WordSense fields and future WordSense tags.
4. Keyboard-first row/current-card/selection actions and predictable focus restoration.
5. Delete ownership extraction, disposable-card browser acceptance, and recovery semantics remain gated.

### 4.2 Custom Study / filtered study

Already present: five stateless preview-only modes including marked cards, source chapter selection, limits, deterministic order, eligibility recheck, resumable signed session, and no ReviewLog/FSRS/lifecycle writes.

Still missing or incomplete:

1. Advanced Browser-search-based mode using the same server-authoritative criteria.
2. User-selectable order/limit with a preview count before opening.
3. Optional second filter equivalent only if a real user need appears; do not add it merely because Anki has it.
4. Explicit product split between preview-only study and formal rescheduling. Preview-only remains the default; formal mode requires a separate confirmation and must use the normal rating endpoint.
5. Create Custom Study directly from the current Browser search.

### 4.3 Reviewer

Already present: sense-only formal entry, four ratings, interval preview, duration, source context, reports, session action timeline, stack undo, Leech/lifecycle actions, shared request coordination and failure recovery, Card Info reuse, and accepted Sense Review shortcuts.

Still missing or incomplete:

1. Shared session identity and duration lifecycle where semantics are truly common.
2. Interval-preview normalization and presentation in legacy Review when the current card is a sense card.
3. Consistent focus restoration after every dialog and keyboard-first actions on the Browser table.
4. A formal retirement boundary for legacy word/phrase behavior: compatibility only, no new product features.

### 4.4 Reader

Already present: token rendering, selection, click lookup, hover lookup/search toggles with false-value persistence, delay/position settings, active-only lookup sidebar, dictionary search, sense confirmation/editing, multi-example/source context, a single reader-projection owner, and AI Study Card entry.

Still missing or incomplete:

1. Reduce remaining low-value panel density and make dictionary/source detail progressive.
2. Keep lookup context stable while moving between surface form, lemma, sense, and source occurrence.
3. Extract only proven responsibilities from `TextBlockGroup.vue`: token interaction, selection state, hover lookup orchestration, and completion reporting.
4. Extract only proven responsibilities from `TextBlockService.php`: tokenizer transport/fallback and occurrence persistence; reader-facing projection already has one owner.
5. Mobile/touch parity, offline reading, and accessible keyboard navigation remain later gaps.

### 4.5 AI Study Card

Already present: separated pending lifecycle, preview/final package, candidate normalization/deduplication, confirmed-card generation, source binding, V6 request/provider/security/parser/schema services, backend-only provider calls, explicit UI trigger, default-unchecked recommendations, and manual V5 confirmation.

Deferred by the user's decision to keep the real provider disabled:

1. Provider usage/token/cost metadata and a configured per-request ceiling; current code exposes model/timeout/quota failure but not cost evidence.
2. Durable provider request audit metadata without raw prompt, response, source text, headers, or secret values.
3. Real configured-provider Network acceptance in the current environment, including exactly one local browser request and zero browser calls to provider domains.
4. Clear retry UX that remains explicit and never turns into background generation.

The disabled-provider request package already provides the user-visible preflight for provider/model, item count, external fields, timeout, configured/unknown cost ceiling, cost-estimate availability, secret-source category, failure policy, and blocking reasons.

### 4.6 Statistics and planning

Already present: daily report, seven-day trend, thirty-day calendar, Study Overview future-due/FSRS distribution/true retention, and retention workload simulation.

Still missing or incomplete:

1. Review-time distribution, interval distribution/history, answer-button distribution, hourly performance, and learning/relearning composition.
2. Arbitrary Browser-search-scoped statistics.
3. Drill-down from every aggregate to the exact Browser query/card set.
4. Shareable report export after the interactive report is accepted.
5. A longer-horizon workload simulator using actual card memory states and explicit new-card assumptions.

### 4.7 Portability, recovery, and cross-device use

Already present: central web persistence, local database backup trigger, vocabulary CSV import/export, library import, and Browser exports including Anki TSV.

Still missing or incomplete:

1. Backup inventory, download, retention policy, integrity check, restore preview, and explicit restore confirmation.
2. Versioned LinguaCafe study package containing WordSense, occurrences, sense ReviewCards, lifecycle, ReviewLog, presets, and source bindings.
3. Import dry-run with duplicate/conflict classification and user/language isolation before writes.
4. Anki interchange: stable one-way sense-note export first; scheduling-preserving round trip only after a field/state mapping is proven.
5. Deleted-item recovery ledger or trash window. Permanent delete without recovery is weaker than Anki backups/deletion log.
6. The central web database already provides online cross-device consistency; do not clone AnkiWeb sync unless offline clients are introduced.

### 4.8 Content organization and extensibility

Adapt rather than clone:

1. Add WordSense tags as multi-valued content organization, separate from the single card marker and from lifecycle/Leech.
2. Use books, chapters, source occurrences, and Saved Search as the organization model; do not add generic decks/subdecks.
3. Add safe sense-card presentation preferences for existing fields; do not add arbitrary HTML/JavaScript templates.
4. Add typed domain/application events and documented extension points only when a second consumer exists; do not build an add-on framework speculatively.

Explicit non-goals unless the product contract changes:

- generic Anki Note/NoteType/CardTemplate domain;
- cloze and image-occlusion generators unrelated to reading-derived WordSense learning;
- legacy word cards as a new mainline;
- deck/subdeck ownership parallel to books/chapters;
- arbitrary add-on code execution in the web process.

## 5. Architecture alignment

Borrow these Anki patterns:

1. Backend domain/application services remain authoritative for search, scheduling, lifecycle, marker, import, and statistics.
2. Page components coordinate; operation modules own mutations; table/card components do not duplicate requests.
3. One search grammar and query engine serve Browser, filtered study, and scoped analytics.
4. Long-running optimization/import/export/AI work runs outside the interactive request and reports progress.
5. Mutations expose idempotency, optimistic conflict handling, audit evidence, and undo/recovery where the action can lose learning state.
6. Heavy presentation surfaces receive typed, versioned payloads rather than querying models ad hoc.

Do not copy these desktop-specific details:

- Python/Qt/Rust/TypeScript process boundaries;
- local collection file ownership;
- AnkiWeb synchronization protocol;
- add-on monkey-patching of arbitrary UI/domain internals.

## 6. Forward execution plan

Every phase is separately authorized, implemented, tested, browser-accepted, and promoted to the authority ledger before the next phase.

### Phase 3 completion

- 3C-2 lifecycle, 3C-4 Leech, and 3D container closure are accepted with browser evidence.
- 3C-3 remains separately gated: extract existing delete requests/dialogs into one mutation owner without changing delete semantics. Real acceptance deletes only a newly created disposable card and proves user/language isolation.

### Phase 4 — Marker + Custom Study 1B — Accepted / Production Closed

- Add one finite card marker value (`0` unmarked, `1..7` colors) to sense ReviewCard ownership.
- Reuse existing access, single/bulk mutation, table current/selection, Saved Search, and Card Info patterns.
- Add `flag:` search terms and marked Custom Study criteria through the same query authority.
- Show/change the marker in Browser and Sense Review.
- Keep marker independent of lifecycle, Leech, WordSense status, and future WordSense tags.

### Phase 5 — Reviewer convergence — Accepted / Production Closed

- Extend the existing recovery helper only with state that both pages already duplicate: request sequence, rating lock, authoritative reload, and stable error classification.
- Share session identity/duration utilities and interval presentation where contracts match.
- Normalize rating success/error consumption without merging the two page components.
- Add marker/Card Info/edit/lifecycle keyboard actions to Sense Review first; legacy receives only compatible shared behavior.

### Phase 6 — Reader UX and responsibility extraction — Accepted / Production Closed

- First repair/verify hover-toggle persistence and browser acceptance.
- Then reduce panel density and stabilize lookup context.
- Extract one responsibility at a time from `TextBlockGroup.vue` and `TextBlockService.php`; no rewrite and no empty pass-through layers.
- Close desktop, narrow, touch, keyboard, tokenizer fallback, dictionary, occurrence, and source-context regression after each slice.

### Phase 7 — Service convergence and disabled-provider preflight accepted

- Pending lifecycle, preview/final package and candidate normalization, confirmed-card generation, source binding, and disabled-provider browser acceptance are accepted under ADR-0032; the original service is now a compatibility facade.
- The request package now shows provider/model, item count, external fields, timeout, cost ceiling, cost-estimate availability, secret-source category, failure policy, and blocking reasons without exposing secret material.
- The user explicitly chose to keep the real provider disabled. A configured external request is deferred unless a future task separately authorizes provider, pricing, timeout, secret storage, and Network acceptance.
- Keep recommendations unchecked and require the existing V5 manual Chinese-definition confirmation.

### Phase 8 — Search and filtered-study parity

- Expand terms first: marker, all ratings, due/state, FSRS properties, source book/chapter, missing-field, and recent review windows.
- Add quoted phrases and negation.
- Add OR/parentheses only after the linear grammar is stable; represent it as a small AST consumed by one query applier.
- Reuse the same criteria for Browser, Saved Search, Custom Study, and scoped statistics.

### Phase 9 — Browser/reviewer lifecycle parity

- Complete keyboard command map, focus restoration, Card Info, marker, edit, bury/suspend, set-due/reset, and undo affordances.
- Add recovery/trash semantics before treating permanent delete as routine.
- Do not add sibling bury until LinguaCafe has a real sibling-card concept.

### Phase 10 — Analytics parity

- Add review time, interval, answer-button, hourly, and learning-state reports.
- Make every chart filterable and drillable to Browser.
- Extend workload simulation only with validated user inputs and actual memory states.
- Add report export last.

### Phase 11 — Portability and recovery

- Close backup inventory/download/integrity/restore UX.
- Define and implement a versioned LinguaCafe study package with dry-run import.
- Add stable Anki TSV re-import mapping, then evaluate `.apkg` only if round-trip demand justifies the dependency and mapping cost.
- Add deletion recovery evidence.

### Phase 12 — Organization, presentation, and cross-device UX

- Add WordSense tags and shared tag search/autocomplete.
- Add safe sense-card presentation preferences for existing content.
- Close responsive/touch/accessibility behavior.
- Evaluate offline/PWA review only after a conflict-safe sync contract exists.

### Phase 13 — Extension boundary

- Publish stable read/query and command contracts only for proven consumers.
- Add typed events/hooks when the second internal or external consumer appears.
- Keep scheduling/lifecycle writes behind application commands; no arbitrary model mutation.

## 7. Re-evaluation passes

### Pass 1 — feature inventory

Added missing Browser grammar, marker, filtered study, reviewer actions, analytics, portability, backup/restore, tags, presentation, and offline gaps.

### Pass 2 — user journeys

Added end-to-end journeys that a feature list missed: Browser search → Custom Study; aggregate chart → exact cards; marker in Browser → reviewer → filtered study; backup → preview → restore; AI preflight → recommendation → manual confirmation.

### Pass 3 — architecture

Added shared search authority, operation ownership, background work, versioned payloads, idempotency/conflicts, audit/undo, and extension boundaries. Removed the idea of reproducing Anki's implementation languages or local sync protocol.

### Pass 4 — diminishing returns

The remaining differences are either LinguaCafe's deliberate reading/sense model, desktop-specific Anki implementation details, or large optional capabilities whose need must be proven (`.apkg` round trip, offline sync, generic extension host). Further planning without phase evidence would add speculative detail rather than improve execution. Stop revising here until a completed phase produces new facts.

### Pass 5 — official-source recheck after Phases 4–7

The current Anki manual still treats four ratings and shortcuts, flags, lifecycle actions, Card Info, Browser search, filtered study, and Leech handling as separate but connected workflows. LinguaCafe now covers the highest-value shared outcomes through sense-card ratings/shortcuts, finite markers, Browser/Custom Study integration, lifecycle/Leech surfaces, and a fail-closed AI boundary. The remaining high-value gaps are the existing Phase 8 search authority, Phase 9 recovery-safe lifecycle parity, analytics drill-down, and portability/recovery. Generic decks, note types, templates, siblings, add-on execution, and AnkiWeb-style sync remain deliberate deviations because LinguaCafe's ownership is reading source → WordSense → sense ReviewCard.

No new implementation phase is justified by this recheck. The provider is deliberately disabled; the next action is separately authorizing Phase 8. Extending the comparison further now would be speculative.

## 8. Completion definition

“Maximal Anki alignment” is reached when:

- every Replicate/Adapt item above is implemented or explicitly rejected by a recorded product decision;
- Browser, Custom Study, Reviewer, analytics, and import/export share the intended backend authorities;
- all destructive and scheduling actions have browser and data evidence;
- real AI, if later authorized, has cost/security/Network evidence and remains user-confirmed; the current disabled-provider decision is recorded and fail-closed;
- current docs and executable guards agree with code and Git;
- the remaining gaps are only deliberate deviations or optional items rejected by measured demand.

This document does not authorize Phase 3C-3, a migration, real deletion, `.env` changes, external provider calls, or automatic phase progression.
