# Reader Lookup API — Browser Acceptance

Date: 2026-07-23

Status: **Accepted / Production Closed**

Scope: Phase 6L, one Reader responsibility only

## Delivered boundary

`ReaderLookupApi.js` now owns the four established Reader dictionary axios expressions: API-dictionary availability, inflection lookup, local hover lookup, and API hover lookup.

`TextBlockGroup.vue` still owns lifecycle timing, guards, term lowercasing, request order, API-enabled gating, every `.then()` and `.catch()`, response normalization, Vuex commits, key increments, `$nextTick`, hover positioning, timers, selection, DOM, and persistence effects. The unrelated Anki-settings request remains inline.

No method, URL, payload key/value, request count/order/timing, Promise continuation, response, error state, store key, visible copy, gesture, tokenizer, surface/lemma, WordSense, ReviewCard, ReviewLog, FSRS, source-context, AI provider, secret, or external-request policy changed.

## Automated evidence

- Focused lookup API contract and component-boundary suite: 6/6 passed.
- Combined hover and Reader Node loop: 128/128 passed.
- `TestingDatabaseHealthTest` plus config guard: 12 tests / 97 assertions passed.
- `ReaderFsrsHighlightTest`: 28 / 132 passed.
- `TextBlockPhraseIndexingTest`: 5 / 18 passed.
- `VocabularySideBoxChineseTextIntegrityTest`: 5 / 42 passed.
- `InlineSensePreviewUiGuardTest`: 17 / 81 passed.
- `MorphologyMatrixUiGuardTest`: 2 / 21 passed.
- `LegacyEntryUiGuardTest`: 1 / 12 passed.
- `npm run development`: compiled successfully in 9.79 seconds; only existing warnings remained.
- `git diff --check`: passed.

The focused suite was first observed RED because `ReaderLookupApi.js` did not exist. After the four thin functions were added, five exact axios-contract tests passed while the component integration guard remained RED; the component adaptation then made 6/6 pass.

The first combined loop correctly exposed two older Phase 6A/6K ownership guards that required inline axios. They were not removed: their decision, orchestration, response, and effect assertions remain, while their transport assertions now require `ReaderLookupApi`. The exact methods, URLs, payloads, call counts, and return identities are covered by the new client tests. The full combined loop then passed 128/128.

## Authenticated official-browser evidence

An isolated testing-MySQL fixture and the official OpenAI in-app Browser connection were used. A service-start race produced one initial Chrome error page; that failed batch was finalized before the successful batch began. The successful batch recorded an empty pre-existing managed-tab set, created one automation-owned page, reset both viewports, and finalized with no kept page.

At 1920×1000:

- a real pointer hover on `Alpha` opened `#vocab-hover-box`;
- a real click selected only `Alpha` and opened the wide lookup panel for `alpha`;
- horizontal overflow remained zero.

At 900×900:

- a real pointer hover on `beta` opened `#vocab-hover-box`;
- a real click selected only `beta` and opened the narrow lookup surface;
- the established empty-dictionary message rendered normally;
- horizontal overflow remained zero.

Console output contained only the existing local settings/Anki 401/500 responses and Vue development information; no transport, lookup, Reader, render, or Vue exception appeared.

## Protected-write proof

Before and after browser interaction:

- `alpha` remained stage `2`, read count `0`, lookup count `0`;
- `beta` remained stage `-2`, read count `0`, lookup count `0`;
- chapter read count remained `0`.

Both user-level and global protected snapshots remained:

| Table | User | Global |
|---|---:|---:|
| WordSense | 0 | 0 |
| ReviewCard | 0 | 0 |
| ReviewLog | 0 | 0 |

The fixture was cleaned twice with `remaining_users=0` both times. No scoring, scheduling, lifecycle, completion, AI-provider, migration, or production-database write was introduced.

## Quantified architecture result

- `TextBlockGroup.vue`: 2,000 → 1,993 lines.
- Phase 6A–6L: 2,514 → 1,993 lines.
- Direct component dictionary axios expressions: 4 → 0.
- The dedicated client is 20 code lines and has five exact transport tests plus one component integration guard.

The five-axis scoped review found no required changes: axios Promise identity and all four contracts are exact; no auth, retry, cache, cancellation, or external capability was added; request count and complexity are unchanged; callers and all effects remain component-owned; and four thin named functions match the existing project API-module style without a generic abstraction.

Phase 6L and the Reader frontend lookup-orchestration target are closed. This does not yet close Phase 6: the authoritative roadmap also names `TextBlockService.php` tokenizer/fallback convergence as a separate backend target.
