# Reader Sentence Context Policy — Browser Acceptance

Date: 2026-07-23

Status: **Accepted / Production Closed**

Scope: Phase 6C, one Reader responsibility only

## Delivered boundary

`ReaderSentenceContextPolicy.js` now owns the pure sentence-context decision used by Reader click lookup: selected-token resolution, token-window scanning, punctuation and abbreviation classification, hard boundaries, spacing, token limits, character limits, and the existing `sentence_index` fallback.

`TextBlockGroup.vue` still owns the current words and selection, section-marker rendering, the Vuex `setSentenceText` commit, click lookup, vocabulary-sidebox orchestration, HTTP, DOM, and learning state.

No tokenizer, processed-text, endpoint, payload, Vuex contract, backend, or non-English behavior changed. Anki has no equivalent continuous Reader token-window context, so the established LinguaCafe behavior from commit `42b60c72` was characterized and preserved.

## Automated evidence

- Sentence policy and source-integration guard: 14/14 passed.
- Combined Reader Node loop: 34/34 passed.
- `TestingDatabaseHealthTest`: 6 tests / 47 assertions passed.
- `TestingDatabaseHealthConfigTest`: 6 / 50 passed.
- `ReaderFsrsHighlightTest`: 28 / 132 passed.
- `TextBlockPhraseIndexingTest`: 5 / 18 passed.
- `npm run development`: compiled successfully; only pre-existing Sass deprecation notices remained.
- `git diff --check`: passed for the implementation commit and closure worktree.

The focused test was observed RED before implementation (`ERR_MODULE_NOT_FOUND`). Thirteen behavior cases then passed while the component integration guard remained RED; after the adapter change all 14 passed.

## Authenticated official-browser evidence

An isolated testing-MySQL fixture and the official OpenAI in-app Browser were used. The browser batch started with no pre-existing user or session tabs, reused one automation-owned page, reset the viewport, and closed the page at the end.

At the actual 1280px-wide viewport, real clicks produced these exact read-only candidate-preview contexts:

- `Smith` → `Mr. Smith stayed.`
- `retail` → `U.S. retail grew 15.2 percent.`
- `Target` after `[A]` → `Target works!`
- `Next` after the exclamation boundary → `Next begins.`

The abbreviation, initialism, decimal, section-marker, exclamation, and ordinary-period boundaries therefore remained visible through the real Reader → vocabulary-sidebox flow. The search field matched each clicked term and the surface retained the explicit `read-only preview` marker.

At 900×900, `Next begins.` and the read-only preview remained visible, the completion action remained present, and `clientWidth === scrollWidth` (`900`).

Console output contained the initial unauthenticated 401 and the existing local `GET /settings/get-anki-settings` 500. No new sentence-policy or Reader exception appeared. The established click flow also emitted its existing `POST /vocabulary/word/update` lookup-count requests; Phase 6C did not move or change that ownership.

## Protected-write proof

Before cleanup, both user-level and global snapshots remained:

| Table | User | Global |
|---|---:|---:|
| WordSense | 0 | 0 |
| ReviewCard | 0 | 0 |
| ReviewLog | 0 | 0 |

The fixture was cleaned twice with `remaining_users=0` both times. No source-context endpoint write, ReviewLog, scheduling, lifecycle, AI-provider, migration, or production-database write was introduced.

## Quantified architecture result

- `TextBlockGroup.vue`: 2,452 → 2,212 lines.
- The component replaced 247 lines of sentence policy and constants with a seven-line adapter.
- The pure policy is 199 lines and has thirteen behavior tests plus one integration guard.
- Vuex, HTTP, DOM, click flow, lookup count, and public component contracts remain in the component.

The five-axis scoped review found no required changes: the policy matches the characterized behavior, reduces component concepts, adds no dependency or capability, does not mutate inputs, and keeps the same bounded scans.

Phase 6C is closed. This does **not** close Phase 6; the next Reader slice must pass its own scoped architecture gate and move only one characterized responsibility.
