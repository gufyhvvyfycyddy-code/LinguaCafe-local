# M15 Browser Knowledge Hygiene V3 Plan

> Status: Accepted / Closed
> Date: 2026-07-29
> Architecture: ADR-0047

## Goal and non-goals

Add bounded Browser views, previewed text replacement, deterministic duplicate
review, backup-gated merge and restorable deletion while reusing M10 search and
M11 mutation owners.

Do not add arbitrary fields, Note Types, raw queries, AI-applied merges,
unpreviewed bulk mutation or direct FSRS writes.

## Ordered slices

1. M15A - operation schema/model and preference contract.
2. M15B - find/replace preview/apply/undo.
3. M15C - duplicate analysis and backup-gated merge/undo.
4. M15D - safe delete log/recent restore and Browser UI.
5. M15E - protected tests, build, server-bound browser and documentation.

## Allowed files

- one additive M15 migration and one operation model;
- one focused `KnowledgeHygieneService` and one focused controller;
- `ReviewCardManageController.php`,
  `ReviewCardManageMutationService.php`, `Operation.php`, `routes/web.php` only
  for safe-delete integration and bounded constants;
- `ReviewCardManage.vue`, `ReviewCardTableSurface.vue`,
  `ReviewCardDeleteMutationSurface.vue`,
  `ReviewCardLifecycleMutationSurface.vue`（仅同步最近删除说明文案）and at
  most one focused M15 panel;
- direct M15 PHP/JS tests;
- ADR-0047, this plan, acceptance evidence, roadmap/master plan/current
  context/handoff/documentation index.

Existing unrelated worktree changes remain user assets. Files outside this
allowlist are forbidden.

## Minimum verification

- focused preferences/find-replace/duplicate/merge/delete/undo tests;
- M10 unified query/tag/Saved Search and M11 operation tests;
- ReviewCard management, ReviewLog, WordSense and formal-rating regressions;
- frontend guard and `npm run development`;
- server-bound testing-database browser acceptance;
- migration/PHP/route/allowlist/`git diff --check`.

Closed on 2026-07-29. Automated, build, server-bound real-browser and cleanup
evidence is recorded in
`docs/testing/m15-browser-knowledge-hygiene-v3-acceptance-2026-07-29.md`.
