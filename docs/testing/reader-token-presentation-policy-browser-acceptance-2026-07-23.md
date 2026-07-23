# Reader Token Presentation Policy — Browser Acceptance

Date: 2026-07-23

Status: **Accepted / Production Closed**

Scope: Phase 6J, one Reader responsibility only

## Delivered boundary

`ReaderTokenPresentationPolicy.js` now owns the pure token-presentation decisions for spaceless languages, section markers, sentence ends, strict AI-translation lookup, token classes, and the two established furigana predicates.

`TextBlockGroup.vue` still owns every template node, key, attribute, style, whitespace comment, input event, reactive adapter, selection/hover effect, lookup, Vuex/store interaction, HTTP request, timer, and persistence effect.

No rendered node, class name, visible copy, gesture, endpoint, payload, Vuex/store, tokenizer, surface/lemma, WordSense, ReviewCard, ReviewLog, FSRS, source-context, AI write, or non-English product behavior changed.

## Automated evidence

- Focused token-presentation policy and source-integration suite: 14/14 passed.
- Combined Reader Node loop: 104/104 passed.
- `TestingDatabaseHealthTest` plus config guard: 12 tests / 97 assertions passed.
- `ReaderFsrsHighlightTest`: 28 / 132 passed.
- `TextBlockPhraseIndexingTest`: 5 / 18 passed.
- `VocabularySideBoxChineseTextIntegrityTest`: 5 / 42 passed.
- `InlineSensePreviewUiGuardTest`: 17 / 81 passed.
- `MorphologyMatrixUiGuardTest`: 2 / 21 passed.
- `LegacyEntryUiGuardTest`: 1 / 12 passed.
- `npm run development`: compiled successfully in 12.07 seconds; only existing Sass deprecation notices remained.
- `git diff --check`: passed.

After correcting a harness regex before implementation, the focused suite was observed RED for the missing module. The first policy run exposed one boolean-contract mismatch and the still-inline component boundary; after returning explicit booleans and adding thin component adapters all fourteen tests passed.

## Authenticated official-browser evidence

An isolated testing-MySQL fixture and the official OpenAI in-app Browser connection were used. The successful batch began after the automated database suites completed, recorded an empty pre-existing managed-tab set, created one automation-owned page, reset both viewports, and finalized with no kept page.

At 1920×1000:

- the real Reader rendered `Alpha` and `beta` as two `.word` tokens with their established `stage` and `wordindex` attributes;
- `Alpha` initially had `word selected-font space-after`;
- a real click added only `highlighted` and opened the wide side panel for `alpha`;
- toggling the real plain-text control added `plain-text-mode` to the root and retained a non-breaking space after each spaced token (character code 160);
- horizontal overflow remained zero.

At 900×900:

- switching back to interactive mode removed `plain-text-mode`;
- a real click on `beta` added `highlighted` only to `beta` and opened the narrow popup for `beta`;
- `Alpha` remained unhighlighted;
- horizontal overflow remained zero.

Console output contained only the existing local settings/Anki 401/500 responses and Vue development information; no token-presentation, render, Reader, or Vue exception appeared.

## Protected-write proof

Before and after browser interaction:

- `alpha` remained stage `2`, read count `0`;
- `beta` remained stage `-2`, read count `0`;
- chapter read count remained `0`.

Both user-level and global protected snapshots remained:

| Table | User | Global |
|---|---:|---:|
| WordSense | 0 | 0 |
| ReviewCard | 0 | 0 |
| ReviewLog | 0 | 0 |

The fixture was cleaned twice with `remaining_users=0` both times. No scoring, scheduling, lifecycle, completion, AI-provider, migration, or production-database write was introduced.

## Quantified architecture result

- `TextBlockGroup.vue`: 2,035 → 2,033 lines.
- Phase 6A–6J: 2,514 → 2,033 lines.
- Seven token-presentation rules now have one cohesive pure owner while the template remains component-owned.
- The policy is 89 lines and has thirteen behavior tests plus one integration guard.

The five-axis scoped review found no required changes: legacy strict/loose comparisons and class values are characterized; all DOM and effect capabilities remain in the component; the policy is deterministic and input-immutable; no dependency or public contract was added; and sentence-end work retains the same bounded scan while all other decisions remain constant-time.

Phase 6J is closed. This does not close Phase 6; lookup orchestration remains for one separately characterized slice.
