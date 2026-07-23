# Reader Sidebar Action Hierarchy and Focus — Browser Acceptance

Date: 2026-07-23

Status: **Accepted / Production Closed**

Scope: Phase 6F, one Reader UI responsibility only

## Delivered boundary

`VocabularySideBox.vue` now presents one Anki-aligned action hierarchy on the wide Reader surface:

- one inline pronunciation action replaces the duplicated header action;
- ordinary study-state actions remain grouped under `学习状态`;
- the destructive `回归为新词` recovery action is visually separated below the ordinary and AI actions;
- opening `添加新释义` moves focus to the accessible dictionary-search input.

The quiet, information-dense direction deliberately reuses the existing Vue 2, Vuetify, color, spacing, and feature-island language. No external visual reference was available or required. The narrow `VocabularyBox` fallback, action owners, props/events, Vuex, endpoints, payloads, tokenizer, WordSense, ReviewCard, ReviewLog, FSRS, AI-provider, and non-English behavior did not change.

## Automated evidence

- Sidebar source guard plus stage-authority guard: 6/6 passed.
- `VocabularySideBoxChineseTextIntegrityTest`: 5 tests / 42 assertions passed.
- `InlineSensePreviewUiGuardTest`: 17 / 81 passed.
- `MorphologyMatrixUiGuardTest`: 2 / 21 passed.
- `LegacyEntryUiGuardTest`: 1 / 12 passed.
- AI Study Card desktop architecture guards passed.
- `npm run development`: compiled successfully; only existing Sass deprecation notices remained.
- `git diff --check`: passed.

The focused guard was observed RED before implementation for duplicate pronunciation, missing hierarchy, and missing focus contract; all five focused cases passed after the scoped UI adapter change.

## Authenticated official-browser evidence

An isolated testing-MySQL fixture and the official OpenAI Chrome connection were used. The first 1280px probe correctly exercised the established narrow fallback rather than the wide sidebar; the acceptance viewport was therefore aligned with the actual Reader workspace-fit rule.

At 1920×1000, a real click on `beta` proved:

- exactly one pronunciation action was exposed;
- `学习状态` contained `忽略`, `标为已知`, and the existing AI feature island;
- `回归为新词` occupied a separate recovery group below the ordinary group;
- both hierarchy groups existed exactly once and their measured rectangles did not overlap;
- clicking `添加新释义` focused an `INPUT` with `aria-label="搜索词典"`, placeholder `搜索词典...`, and value `beta`;
- the page had zero horizontal overflow and `完成阅读` remained available.

At 900×900, the established `VocabularyBox` fallback remained active, selecting `beta` still opened lookup, horizontal overflow remained zero, and `完成阅读` remained available.

Console output contained the existing local settings request 500; no sidebar-action, focus, Reader, or Vue exception appeared. The batch closed only its automation-owned tabs, reset the viewport, and left user tabs untouched.

## Protected-write proof

Before cleanup, both user-level and global snapshots remained:

| Table | User | Global |
|---|---:|---:|
| WordSense | 0 | 0 |
| ReviewCard | 0 | 0 |
| ReviewLog | 0 | 0 |

The fixture was cleaned twice with `remaining_users=0` both times. No scoring, scheduling, lifecycle, AI-provider, migration, or production-database write was introduced.

## Scoped review

The five-axis review found no required changes: the slice removes one duplicate control, clarifies destructive intent, restores deterministic keyboard focus, adds no request or state owner, preserves the narrow fallback, and stays within the single accepted UI seam.

Phase 6F is closed. This completes the two scheduled Reader product-small-step bullets; remaining Phase 6 work is structural convergence, one characterized responsibility at a time.
