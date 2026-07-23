# Reader Lookup Response Policy — Browser Acceptance

Date: 2026-07-23

Status: **Accepted / Production Closed**

Scope: Phase 6K, one Reader responsibility only

## Delivered boundary

`ReaderLookupResponsePolicy.js` now owns the pure response rules for stale local-dictionary responses, local-definition joining, API-definition flattening, and displayed-inflection parsing/grouping.

`TextBlockGroup.vue` still owns the language guard, initial inflection reset, all four lookup endpoints and payloads, Vuex commits, catches, keys, `$nextTick`, hover positioning, timers, selection orchestration, and persistence effects.

No endpoint, payload, request order, store key, visible copy, gesture, tokenizer, surface/lemma, WordSense, ReviewCard, ReviewLog, FSRS, source-context, AI write, or non-English product behavior changed.

## Automated evidence

- Focused lookup-response policy and source-integration suite: 13/13 passed.
- Combined Reader Node loop: 117/117 passed.
- `TestingDatabaseHealthTest` plus config guard: 12 tests / 97 assertions passed.
- `ReaderFsrsHighlightTest`: 28 / 132 passed.
- `TextBlockPhraseIndexingTest`: 5 / 18 passed.
- `VocabularySideBoxChineseTextIntegrityTest`: 5 / 42 passed.
- `InlineSensePreviewUiGuardTest`: 17 / 81 passed.
- `MorphologyMatrixUiGuardTest`: 2 / 21 passed.
- `LegacyEntryUiGuardTest`: 1 / 12 passed.
- `npm run development`: compiled successfully in 8.13 seconds; only existing warnings remained.
- `git diff --check`: passed.

The focused suite was first observed RED because the policy module did not exist. After the pure policy was added, twelve behavior tests passed while the component integration guard remained RED; the thin component adaptation then made all thirteen tests pass.

## Authenticated official-browser evidence

An isolated testing-MySQL fixture and the official OpenAI in-app Browser connection were used. The batch recorded an empty pre-existing managed-tab set, created one automation-owned page, and finalized with no kept page.

At 1920×1000:

- a real pointer hover on `Alpha` opened `#vocab-hover-box`;
- a real click selected only `Alpha` and opened the wide lookup panel for `alpha`;
- horizontal overflow remained zero.

At 900×900:

- a real pointer hover on `beta` opened `#vocab-hover-box`;
- a real click selected only `beta` and opened the narrow lookup surface for `beta`;
- the empty local dictionary produced the established configuration message without a Reader exception;
- horizontal overflow remained zero.

Console output contained only the existing local settings/Anki 401/500 responses and Vue development information; no lookup-response, Reader, render, or Vue exception appeared.

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

- `TextBlockGroup.vue`: 2,033 → 2,000 lines.
- Phase 6A–6K: 2,514 → 2,000 lines.
- Four response-normalization rules now have one cohesive pure owner.
- The policy is 70 code lines and has twelve behavior tests plus one integration guard.

The five-axis scoped review found no required changes: strict and loose legacy comparisons, ordering, overwrite behavior, parse failures, and `Array.concat` behavior are characterized; the policy has no I/O or mutation capability; response processing retains the same linear complexity; all transport and effects remain component-owned; and no dependency or public contract was added.

Phase 6K is closed. This does not close Phase 6; lookup HTTP transport remains a distinct, separately characterized seam.
