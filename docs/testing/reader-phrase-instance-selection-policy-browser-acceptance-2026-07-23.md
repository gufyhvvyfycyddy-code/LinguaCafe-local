# Reader Phrase Instance Selection Policy — Browser Acceptance

Date: 2026-07-23

Status: **Accepted / Production Closed**

Scope: Phase 6E, one Reader responsibility only

## Delivered boundary

`ReaderPhraseInstanceSelectionPolicy.js` now owns the pure phrase-instance range resolution used after a single Reader word selection: backward start resolution, newline-aware forward scanning, exact phrase-index membership, normalized unique-word lookup, missing-record filtering, enrichment, and source-order descriptors.

`TextBlockGroup.vue` still owns mouse/touch events, choosing and cycling nested phrase indexes, current selection state, applying `word.selected`, lookup counts, phrase borders, Vuex, HTTP, persistence, and vocabulary-sidebox orchestration.

No phrase identity, cycling order, length, endpoint, payload, tokenizer, surface/lemma, WordSense, ReviewCard, ReviewLog, FSRS, backend, or non-English behavior changed. Anki has no equivalent continuous Reader phrase-instance selection, so the established LinguaCafe behavior was characterized and preserved.

## Automated evidence

- Phrase-instance policy and source-integration guard: 11/11 passed.
- Combined Reader Node loop: 56/56 passed.
- `TestingDatabaseHealthTest`: 6 tests / 47 assertions passed.
- `TestingDatabaseHealthConfigTest`: 6 / 50 passed.
- `ReaderFsrsHighlightTest`: 28 / 132 passed.
- `TextBlockPhraseIndexingTest`: 5 / 18 passed.
- `npm run development`: compiled successfully; only pre-existing Sass deprecation notices remained.
- `git diff --check`: passed for the implementation commit and closure worktree.

The focused test was observed RED before implementation (`ERR_MODULE_NOT_FOUND`). Ten behavior cases then passed while the component integration guard remained RED; after the adapter change all 11 passed.

## Authenticated official-browser evidence

An isolated testing-MySQL fixture and the official OpenAI browser plugin were used. The in-app Browser could not attach a new test page, so the same official plugin's Chrome connection was used. The Chrome batch recorded nine pre-existing user tabs and no automation tabs, created one automation-owned page, reset the viewport, and closed only that page at the end.

At the actual 1280×900 viewport, a real click on `beta`:

- expanded the stored phrase instance to exactly `beta` and `gamma`;
- highlighted exactly those two source words;
- opened the vocabulary surface as a stored phrase with visible text `beta gamma`;
- produced the search value `beta gamma `;
- kept the completion action visible and preserved `clientWidth === scrollWidth` (`1280`).

At 900×900, the same real click again selected exactly `beta` and `gamma`, produced the same search value, kept the completion action visible, and preserved `clientWidth === scrollWidth` (`900`).

Console output contained the existing local settings request 500; no phrase-policy or Reader exception appeared. The click flow emitted only the existing local phrase and dictionary lookup requests.

## Protected-write proof

Before cleanup, both user-level and global snapshots remained:

| Table | User | Global |
|---|---:|---:|
| WordSense | 0 | 0 |
| ReviewCard | 0 | 0 |
| ReviewLog | 0 | 0 |

The fixture was cleaned twice with `remaining_users=0` both times. No ReviewLog, scheduling, lifecycle, AI-provider, migration, or production-database write was introduced.

## Quantified architecture result

- `TextBlockGroup.vue`: 2,188 → 2,163 lines.
- Phase 6A–6E: 2,514 → 2,163 lines.
- The component replaced the phrase-instance scan and descriptor loop with one resolver call.
- The pure policy is 51 lines and has ten behavior tests plus one integration guard.
- Phrase cycling, effects, Vuex, HTTP, DOM, persistence, and public component contracts remain in the component.

The five-axis scoped review found no required changes: the policy matches the characterized behavior, reduces component concepts, adds no dependency or capability, does not mutate inputs, and keeps the same bounded scan.

Phase 6E is closed. This does **not** close Phase 6; the next Reader slice must pass its own scoped architecture gate and move only one characterized responsibility.
