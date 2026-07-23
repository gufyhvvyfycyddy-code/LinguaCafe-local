# Reader Hover Position Policy — Browser Acceptance

Date: 2026-07-23

Status: **Accepted / Production Closed**

Scope: Phase 6B, one Reader responsibility only

## Delivered boundary

`HoverVocabularyPositionPolicy.js` now owns the pure hover-card geometry decision: horizontal centering and bounds, preferred top/bottom placement, available-space correction, and reader scroll offsets. `TextBlockGroup.vue` still owns DOM measurement, Vuex commits, timers, HTTP, click lookup, and stale-response protection.

This is an architecture extraction with no endpoint, payload, setting, or user-flow change. The real Reader Settings preference remains the only top/bottom control. Anki has no equivalent continuous-reader hover card, so the established LinguaCafe behavior was preserved, including the observable first-word anchor for phrase hover.

## Automated evidence

- Position policy and source-integration guard: 10/10 passed.
- Combined Reader Node loop: 20/20 passed.
- `TestingDatabaseHealthTest`: 6 tests / 47 assertions passed.
- `TestingDatabaseHealthConfigTest`: 6 / 50 passed.
- `ReaderFsrsHighlightTest`: 28 / 132 passed.
- `TextBlockPhraseIndexingTest`: 5 / 18 passed.
- `npm run development`: compiled successfully; only pre-existing Sass deprecation notices remained.
- `git diff --check`: passed for the implementation range and closure worktree.

The policy test was observed RED before implementation (`ERR_MODULE_NOT_FOUND`), then GREEN. The integration guard was observed RED before the adapter change and GREEN afterward.

## Authenticated official-browser evidence

An isolated testing-MySQL fixture and the official OpenAI in-app Browser were used. The fixture was removed twice after acceptance, with `remaining_users=0` both times. The official browser session began with no pre-existing user tabs; both automation-owned pages, including one failed `127.0.0.1` probe, were closed at the end.

The official browser provided an actual 1280px-wide desktop viewport and an exact 900×900 narrow viewport:

- Wide default-bottom hover rendered a 300px card below the word and inside the reader.
- Selecting the real “显示在词上方” setting near the top corrected the card back below the word when top space was insufficient.
- Restoring “显示在词下方” and hovering a taller test translation near the bottom corrected the card above the word: card bottom `642.67`, word top `667.67`, `arrowTop=true`.
- The corrected bottom-edge card remained horizontally inside the reader (`508.49–808.49` within `52.67–875.33`).
- Both wide and narrow layouts had `clientWidth === scrollWidth`.
- Clicking a word at the wide viewport opened the vocabulary detail surface with search value `positionword`, candidate preview, and the explicit `read-only preview` marker.

Console output contained the initial unauthenticated 401 and local fixture dictionary-search 500 responses; the vocabulary surface itself reported that dictionary data was not configured. These environment responses did not produce a new Reader positioning exception and were not hidden by changing business code.

## Protected-write proof

Before cleanup, both user-level and global snapshots remained:

| Table | User | Global |
|---|---:|---:|
| WordSense | 0 | 0 |
| ReviewCard | 0 | 0 |
| ReviewLog | 0 | 0 |

No sense creation, ReviewLog, scheduling, lifecycle, AI-provider, migration, or production-database write was introduced.

## Quantified architecture result

- `TextBlockGroup.vue`: 2,502 → 2,452 lines.
- The adapter replaced 79 lines of inline geometry and duplicate anchor selection with 28 lines of measurement and delegation.
- The pure policy is 47 lines and is covered by nine behavior tests plus one integration guard.
- HTTP, request count, timers, stale-response protection, public props, DOM access, and Vuex ownership remain in the component.

Phase 6B is closed. This does **not** close Phase 6; the next Reader slice must pass its own scoped architecture gate and move only one characterized responsibility.
