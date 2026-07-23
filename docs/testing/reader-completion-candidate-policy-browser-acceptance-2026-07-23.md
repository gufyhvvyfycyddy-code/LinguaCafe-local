# Reader Completion Candidate Policy — Browser Acceptance

Date: 2026-07-23

Status: **Accepted / Production Closed**

Scope: Phase 6I, one Reader responsibility only

## Delivered boundary

`ReaderCompletionCandidatePolicy.js` now owns the pure classification of completion candidates: established falsy `definitions_checked` handling, negative-stage coercion, word-before-phrase ordering, source indexes, types, and unchanged IDs.

`TextBlockGroup.vue` still maps descriptors to the original source objects, writes the legacy `type` marker, and returns the established `wordIds`, `phraseIds`, and `wordsAndPhrases` keys. `TextReader.vue` still owns confirmation, `/chapters/finish`, exact payload serialization, loading/error state, completion presentation, and every persistence effect.

No finish endpoint, payload field, confirmation, visible copy, Vuex/store, tokenizer, surface/lemma, WordSense, ReviewCard, ReviewLog, FSRS, source-context, AI, or non-English behavior changed.

## Automated evidence

- Focused completion policy and source-integration suite: 11/11 passed.
- Combined Reader Node loop: 90/90 passed.
- `FinishedReadingSafetyTest`: 2 tests / 27 assertions passed.
- `TestingDatabaseHealthTest` plus config guard: 12 / 97 passed.
- `ReaderFsrsHighlightTest`: 28 / 132 passed.
- `TextBlockPhraseIndexingTest`: 5 / 18 passed.
- `VocabularySideBoxChineseTextIntegrityTest`: 5 / 42 passed.
- `InlineSensePreviewUiGuardTest`: 17 / 81 passed.
- `MorphologyMatrixUiGuardTest`: 2 / 21 passed.
- `LegacyEntryUiGuardTest`: 1 / 12 passed.
- `npm run development`: compiled successfully in 13.65 seconds; only existing Sass deprecation notices remained.
- `git diff --check`: passed.

The focused suite was observed RED for the missing module. Nine pure behavior cases then passed while the component integration guard remained RED; after the compatibility adapter change all eleven tests passed. The parent boundary guard freezes the existing ref call and all `/chapters/finish` serialization fields.

## Authenticated official-browser evidence

An isolated testing-MySQL fixture and the official OpenAI in-app Browser connection were used. The batch recorded an empty pre-existing managed-tab set, created one automation-owned page, reset both viewports, and finalized with no kept page.

At 1920×1000:

- the real Reader displayed `Alpha beta` and retained the existing `完成阅读` action;
- the real action opened `确认完成阅读？` with `取消` and `确认完成`;
- after enabling the existing `自动提升词汇等级` setting, a real confirmation completed the chapter;
- the completion surface displayed only `alpha` in `升级的词汇`, with the established stage transition `2 → 1`;
- horizontal overflow remained zero.

The observed testing-database delta matched the visible result:

- candidate `alpha`: stage `-2 → -1`;
- excluded `beta`: stage remained `2`;
- chapter `read_count`: `0 → 1`.

At 900×900:

- the completed surface still displayed `alpha` and the same `2 → 1` presentation;
- a fresh Reader navigation reopened the existing confirmation dialog without submitting a second completion;
- both the completed surface and confirmation dialog had zero horizontal overflow.

The official Browser surface does not expose request-body events. Exact payload preservation is therefore evidenced by the executable parent source guard and unchanged `TextReader.vue`, while the real request is evidenced by the completion surface and database delta. Console output contained only the existing local settings/Anki 401/500 responses and Vue development information; no completion-policy, Reader, or Vue exception appeared.

## Protected-write proof

Before cleanup, both user-level and global snapshots remained:

| Table | User | Global |
|---|---:|---:|
| WordSense | 0 | 0 |
| ReviewCard | 0 | 0 |
| ReviewLog | 0 | 0 |

The isolated finish write changed only the expected candidate stage, chapter read count, and reading-goal bookkeeping. The fixture was cleaned twice with `remaining_users=0` both times. No scoring, ReviewLog, ReviewCard, WordSense, FSRS scheduling, lifecycle, AI-provider, migration, or production-database write was introduced.

## Quantified architecture result

- `TextBlockGroup.vue`: 2,041 → 2,035 lines.
- Phase 6A–6I: 2,514 → 2,035 lines.
- The inline word/phrase completion filters were replaced with one pure descriptor resolver and a compatibility adapter.
- The policy is 28 lines and has nine behavior tests plus two integration guards.

The five-axis scoped review found no required changes: eligibility, coercion, IDs and order are characterized; legacy source-object tagging and the parent contract remain intact; the policy is deterministic and input-immutable; no dependency or public contract was added; and runtime work remains linear in source and candidate counts.

Phase 6I is closed. This does not close Phase 6; token rendering and lookup orchestration remain for separate characterized slices.
