# ADR-0027 — Settings Advanced Tools UX State Model

## Status

Accepted — Settings UX-1 / 2026-07-15

Preset V1D final production closure remains open for the broader cross-user and cross-language Preset acceptance matrix. Browser / ReviewCardManage work is not authorized by this ADR.

## Context

The Advanced Tools section mixed several different concerns into one visual level:

- optimization availability;
- detailed diagnostics;
- current parameter source;
- restore-default action;
- independent card rescheduling.

When the user had no usable review history or fewer than 300 eligible reviews, the page repeated the same conclusion, displayed large zero-value blocks, and kept actions visually prominent even though the backend would reject them. A diagnostic load failure could also leave stale numbers visible.

The existing backend response already exposes the required facts: optimization eligibility, eligible review count, minimum count, trainable cards, parameter source, parameter count, last optimization time, and detailed diagnostics. No backend contract or FSRS rule change is required.

## Decision

### Pure presentation state module

Add `resources/js/services/FsrsAdvancedToolsPresentation.js` as a pure presentation module. It receives the existing response and derives two independent dimensions:

Data state:

- `loading`
- `error`
- `empty`
- `insufficient`
- `ready`

Parameter state:

- `default`
- `optimized`
- `unknown`

The module:

- does not import Vue;
- does not access axios, the DOM, the database, ReviewLog, lifecycle, or FSRS algorithms;
- does not mutate its input;
- sanitizes missing or invalid numeric values;
- is the single source for primary copy and action availability.

### Progressive disclosure

The Advanced Tools order is:

1. one primary conclusion;
2. progress or readiness;
3. primary optimization action;
4. parameter source and restore-default action;
5. collapsed diagnostic details;
6. the independent existing-card reschedule area.

For zero eligible reviews, the page shows one empty state and no zero-value diagnostic grid. For 1–299 records, the main line is `有效记录 N / 300，还差 M 条；目前有 K 张卡可用于训练。` Detailed values remain behind `查看诊断详情`.

### Action safety

- Optimization preview is disabled when `can_optimize=false`.
- Restore default is disabled when the current source is `default`.
- Both methods repeat the presentation-state guard before calling the API, so a programmatic click cannot send a request.
- Diagnostic failure hides stale statistics and parameter actions and exposes only `重新加载诊断`.
- The existing reschedule area remains separate and unchanged.

## Anki alignment

The Anki manual treats presets as shared configuration, warns that option changes are generally not retroactive, requires a substantial review history for FSRS optimization, and keeps rescheduling as a separate explicit choice. LinguaCafe therefore keeps optimization, parameter source, and rescheduling visibly distinct.

Anki's implementation also separates the desktop host from the web deck-options UI and separates deck-configuration schema/service/update/undo responsibilities in Rust. LinguaCafe adopts the same responsibility split at its current scale: the Vue component renders, the API client transports, the backend owns domain facts, and the new pure module owns presentation-state derivation. LinguaCafe does not introduce deck/subdeck semantics.

## Spec-to-harness rule

The project subtitle review reinforces the post-MVP rule: stable boundaries must be executable. Documentation alone does not prevent an agent from duplicating state logic or re-enabling unsafe actions. This ADR is backed by:

- pure state tests for loading, error, empty, insufficient, ready, default, optimized, unknown, malformed data, and immutability;
- a UI guard for disabled actions, collapsed details, no direct axios, no new FSRS/lifecycle rules, and preservation of the reschedule area;
- real Chrome checks at 1920×1080 and 900×900.

## Rejected alternatives

### Add more backend flags

Rejected because the current response already distinguishes every required state.

### Repeat conditions in the Vue template

Rejected because parallel checks drift and make disabled-action safety difficult to test.

### Merge rescheduling into the optimization empty state

Rejected because rescheduling is an independent, explicit, higher-risk operation.

### Create 300 production ReviewLog rows for screenshots

Rejected because it would contaminate learning history. Ready, optimized, zero, and failure states are covered by deterministic state tests.

## Verification

- `FsrsAdvancedToolsPresentation.test.mjs`: 17 state contracts passed.
- `FsrsAdvancedToolsUxGuard.test.mjs`: 18 UI contracts passed.
- Testing database health: 12 tests passed.
- Settings/FSRS/Preset focused regression: 151 tests / 757 assertions passed.
- Frontend build passed.
- Chrome 1920×1080 and 900×900: no horizontal overflow; current 117/300 state is concise; diagnostic details default closed and can be opened; both unavailable actions are disabled; programmatic clicks produced zero XHR/fetch requests; Console has no new messages; all loaded HTTP resources are local.

## Consequences

Settings UX-1 is accepted. Preset V1D remains the current phase until its broader production-closure matrix is completed. No Browser, ReviewCardManage, Card Marker, Reviewer, Reader, provider, FSRS algorithm, ReviewLog, lifecycle, or database work is authorized by this decision.
