# Reader Navigation Policy — Browser Acceptance

Date: 2026-07-23

Status: **Accepted / Production Closed**

Scope: Phase 6H, one Reader responsibility only

## Delivered boundary

`ReaderNavigationPolicy.js` now owns the pure candidate decision for previous/next selected-word navigation: direction-specific anchors, rendered-token skipping, the existing no-selection defaults, ordinary/new/highlighted filters, and the no-candidate result.

`TextBlockGroup.vue` still owns the keyboard dispatch, the single DOM inventory read, selection state, `unselectAllWords`, `$nextTick`, selection start/finish effects, Vuex, sidebar lookup, focus behavior, and every other effect.

No shortcut, visible copy, gesture, endpoint, payload, Vuex/store, tokenizer, surface/lemma, WordSense, ReviewCard, ReviewLog, FSRS, source-context, AI, or non-English behavior changed.

## Automated evidence

- Focused navigation policy and source-integration suite: 12/12 passed.
- Combined Reader Node loop: 66/66 passed.
- `TestingDatabaseHealthTest`: 6 tests / 47 assertions passed.
- `TestingDatabaseHealthConfigTest`: 6 / 50 passed.
- `ReaderFsrsHighlightTest`: 28 / 132 passed.
- `TextBlockPhraseIndexingTest`: 5 / 18 passed.
- `VocabularySideBoxChineseTextIntegrityTest`: 5 / 42 passed.
- `InlineSensePreviewUiGuardTest`: 17 / 81 passed.
- `MorphologyMatrixUiGuardTest`: 2 / 21 passed.
- `LegacyEntryUiGuardTest`: 1 / 12 passed.
- `npm run development`: compiled successfully in 17.66 seconds; only existing Sass deprecation notices remained.
- `git diff --check`: passed.

The focused suite was observed RED for the missing module. Eleven pure behavior cases then passed while the component integration guard remained RED; after the component adapter change all 12 passed.

## Authenticated official-browser evidence

An isolated testing-MySQL fixture and official OpenAI browser connections were used. The batch recorded pre-existing user tabs, reused one automation-owned page at a time, reset both viewports, and closed only automation-owned pages. The Chrome connection became unavailable during the batch, so the official in-app Browser connection completed the same scoped checks.

At 1920×1000:

- a real click selected `gamma` and opened the wide side panel for `gamma`;
- a real `ArrowLeft` keypress selected `beta` and updated the side panel to `beta`;
- a real `ArrowRight` keypress returned selection and the side panel to `gamma`;
- opening `添加新释义` focused the `搜索词典` input with value `gamma`;
- pressing `ArrowLeft` inside that input kept the Reader selection on `gamma`, preserved focus and value, and did not trigger Reader navigation;
- horizontal overflow remained zero and `完成阅读` remained available.

At 900×900:

- a real click selected `gamma` and opened the narrow vocabulary surface for `gamma`;
- a real `ArrowLeft` keypress selected `beta` and updated the popup to `beta`;
- focusing `词典搜索` and pressing `ArrowRight` kept the Reader selection on `beta`, preserved focus and value, and did not trigger Reader navigation;
- horizontal overflow remained zero and `完成阅读` remained available.

Console output contained only the existing local settings/Anki request 401/500 responses and Vue development information; no navigation-policy, selection, Reader, or Vue exception appeared. Provider telemetry timeouts belonged to the official browser runtime, not the application.

## Protected-write proof

Before cleanup, both user-level and global snapshots remained:

| Table | User | Global |
|---|---:|---:|
| WordSense | 0 | 0 |
| ReviewCard | 0 | 0 |
| ReviewLog | 0 | 0 |

The fixture was cleaned twice with `remaining_users=0` both times. No scoring, scheduling, lifecycle, AI-provider, migration, or production-database write was introduced.

## Quantified architecture result

- `TextBlockGroup.vue`: 2,099 → 2,041 lines.
- Phase 6A–6H: 2,514 → 2,041 lines.
- The component replaced duplicated direction-specific scans with one resolver call and one rendered-token inventory.
- The policy is 49 lines and has eleven behavior tests plus one integration guard.

The five-axis scoped review found no required changes: legacy anchors, filters, loose stage matching and no-selection defaults are characterized; the component keeps all capabilities and effects; the policy is deterministic and input-immutable; no dependency or public contract was added; and runtime work is one DOM query plus an O(n) in-memory scan instead of up to O(n) DOM queries.

Phase 6H is closed. This does not close Phase 6; the next structural slice must move only one characterized Reader responsibility.
