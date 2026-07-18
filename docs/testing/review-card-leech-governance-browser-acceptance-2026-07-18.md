# ReviewCard Leech Governance Mutation Browser Acceptance

**Date**: 2026-07-18
**Status**: Passed / Production Closure Evidence
**Scope**: Browser / ReviewCardManage Phase 3C-4 — Leech Governance Mutation Family

## 1. Accepted boundary

`ReviewCardLeechGovernanceMutationSurface.vue` is the singular owner inside the ReviewCardManage domain for:

- Leech summary loading;
- bulk rewrite-package request and response state;
- single rewrite-package dialog orchestration through the existing `SenseReviewLeechRewritePackageDialog.vue`;
- bulk rewrite-package dialog and copy actions;
- selected-card Leech suspend confirmation and orchestration.

Lifecycle HTTP writes remain owned by `ReviewCardLifecycleMutationSurface.vue`. The Leech surface receives narrow callback bridges and contains no lifecycle endpoint.

## 2. Automated verification

- New architecture guard demonstrated RED before implementation and GREEN after extraction.
- `ReviewCardLeechGovernanceMutationSurfaceGuard.test.mjs`: passed.
- `ReviewCardManageLeechGuard.test.mjs`: 18 passed.
- `ReviewCardLifecycleBulkGuard.test.mjs`: 32 passed.
- `ReviewCardManageLifecycleGuard.test.mjs`: 29 passed.
- `TestingDatabaseHealthConfigTest`: 6 passed / 50 assertions.
- `TestingDatabaseHealthTest`: 6 passed / 47 assertions.
- `ReviewCardManageUiGuardTest`: 17 passed / 22 assertions.
- `npm run development`: passed; only existing Sass deprecation warnings were emitted.

## 3. Authenticated MCP Chrome acceptance

The existing local development account was used to authenticate. The page under test was:

`/review-cards/manage`

### 3.1 Page baseline

The page rendered the ReviewCard management interface, FSRS statistics and Leech summary chips. The visible baseline was:

- total cards: 4;
- active: 4;
- suspended: 0;
- Leech: 0;
- struggling: 0.

### 3.2 Single rewrite package

From a row's `更多` menu, `生成重写包` opened the existing rewrite-package dialog.

Verified:

- no write occurred before opening;
- JSON and Markdown package views rendered;
- copy controls remained available;
- safety facts rendered as false: `provider_called=false`, `card_created=false`, `review_log_created=false`;
- the dialog explicitly stated that LinguaCafe does not call AI, create learning cards or write review records.

### 3.3 Two-card bulk rewrite package

Two rows were selected and `批量生成重写包` was used.

Verified:

- exactly two packages were returned and rendered;
- partial-failure presentation remained available;
- JSON and Markdown copy controls rendered per package;
- no external provider request was observed;
- no learning card or ReviewLog write was triggered.

### 3.4 Two-card bulk suspend and restoration

The same two selected rows were passed to `批量暂停高遗忘卡`.

Verified:

- opening the confirmation created no lifecycle write;
- the confirmation explicitly stated that only the two selected cards would be processed;
- confirming applied the existing lifecycle suspend operation;
- the visible counts changed from active 4 / suspended 0 to active 2 / suspended 2;
- selection cleared after success.

To avoid leaving acceptance data changed, the two cards were then restored through the existing `批量生命周期` → `批量恢复复习` flow.

Final visible baseline:

- active: 4;
- suspended: 0.

## 4. Viewport, Console and Network

- The management page and active rewrite-package dialog were inspected after resizing the browser.
- The active dialog remained inside the viewport.
- The document had no page-level horizontal overflow.
- Console errors: 0.
- Console warnings: 0.
- Observed application resources were local to `127.0.0.1:8000`.
- No OpenAI, DeepSeek, Anthropic or other provider domain was observed.

## 5. Data and product safety

This phase did not change:

- Leech classification rules;
- lifecycle state-machine semantics;
- ReviewLog write or retention rules;
- FSRS fields or scheduling;
- backend routes or payloads;
- database schema;
- WordSense or ReviewCard creation behavior;
- provider configuration or external AI behavior.

The deliberate lifecycle smoke mutation was fully restored before closure.

## 6. Conclusion

Phase 3C-4 — Leech Governance Mutation Family is **Accepted / Production Closed**.

Phase 3D — Container Closure remains **Planned / Not Authorized**. No later phase was entered.
