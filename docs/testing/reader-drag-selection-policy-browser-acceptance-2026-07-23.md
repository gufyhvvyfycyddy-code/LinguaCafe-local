# Reader Drag Selection Policy — Browser Acceptance

Date: 2026-07-23

Status: **Accepted / Production Closed**

Scope: Phase 6D, one Reader responsibility only

## Delivered boundary

`ReaderDragSelectionPolicy.js` now owns the pure range-selection decision used by Reader mouse and touch drag: endpoint guards, forward/reverse normalization, the established phrase-length boundary, newline filtering, selected-word ordering, and immutable output.

`TextBlockGroup.vue` still owns mouse/touch events, the touch timer, current words, applying `word.selected`, clearing selection, opening the vocabulary surface, DOM, Vuex, HTTP, and learning state.

No gesture timing, event contract, endpoint, payload, tokenizer, surface/lemma, WordSense, ReviewCard, ReviewLog, FSRS, backend, or non-English behavior changed. Anki has no equivalent continuous Reader drag-selection surface, so the established LinguaCafe behavior was characterized and preserved, including its existing phrase-length formula.

## Automated evidence

- Drag-selection policy and source-integration guard: 12/12 passed.
- Combined Reader Node loop: 46/46 passed.
- `TestingDatabaseHealthTest`: 6 tests / 47 assertions passed.
- `TestingDatabaseHealthConfigTest`: 6 / 50 passed.
- `ReaderFsrsHighlightTest`: 28 / 132 passed.
- `TextBlockPhraseIndexingTest`: 5 / 18 passed.
- `npm run development`: compiled successfully; only pre-existing Sass deprecation notices remained.
- `git diff --check`: passed for the implementation commit and closure worktree.

The focused test was observed RED before implementation (`ERR_MODULE_NOT_FOUND`). Eleven behavior cases then passed while the component integration guard remained RED; after the adapter change all 12 passed.

## Authenticated official-browser evidence

An isolated testing-MySQL fixture and the official OpenAI browser plugin were used. The in-app Browser could not attach a new test page after two attempts, so the same official plugin's Chrome connection was used. The batch recorded no pre-existing Chrome tabs, created one automation-owned page, reset the viewport, and closed the page at the end.

At the actual 1280×900 viewport:

- Real drag from `beta` through `delta` highlighted exactly `beta`, `gamma`, and `delta`.
- The vocabulary surface opened with the search value `beta gamma delta ` and visible phrase `beta gamma delta`.
- After a Reader reload, real reverse drag from `delta` back to `beta` produced the same source-order phrase and the same three highlighted words.
- The completion action remained visible and `clientWidth === scrollWidth` (`1280`).

At 900×900, the same real drag again opened `beta gamma delta`, highlighted the same three words, kept the completion action visible, and preserved `clientWidth === scrollWidth` (`900`).

Console output contained the existing local settings request 500; no drag-policy or Reader exception appeared. The drag flow emitted only the existing local dictionary lookup requests.

## Protected-write proof

Before cleanup, both user-level and global snapshots remained:

| Table | User | Global |
|---|---:|---:|
| WordSense | 0 | 0 |
| ReviewCard | 0 | 0 |
| ReviewLog | 0 | 0 |

The fixture was cleaned twice with `remaining_users=0` both times. No ReviewLog, scheduling, lifecycle, AI-provider, migration, or production-database write was introduced.

## Quantified architecture result

- `TextBlockGroup.vue`: 2,212 → 2,188 lines.
- Phase 6A–6D: 2,514 → 2,188 lines.
- The component keeps a small adapter that delegates range resolution and applies the returned selection.
- The pure policy is 59 lines and has eleven behavior tests plus one integration guard.
- Events, timers, Vuex, HTTP, DOM, lookup orchestration, and public component contracts remain in the component.

The five-axis scoped review found no required changes: the policy matches the characterized behavior, reduces component concepts, adds no dependency or capability, does not mutate inputs, and keeps the same bounded scan.

Phase 6D is closed. This does **not** close Phase 6; the next Reader slice must pass its own scoped architecture gate and move only one characterized responsibility.
