# ADR-0023: Settings architecture convergence

## Status

Accepted / Production Closed — 2026-07-15

## Context

The review settings surface had accumulated FSRS target retention, daily limits, queue order, statistics, parameter optimization, rescheduling, undo, and legacy SRS controls in one Vue file. `AdminReviewSettings.vue` had 2,164 lines and 15 direct HTTP calls. `SettingsService.php` had 1,006 lines and mixed generic setting persistence, FSRS optimization queries, daily-limit validation, and queue-order validation.

This made the next Anki-aligned Preset phase unsafe: adding shared, cloneable settings would have expanded two already broad modules and made it difficult to prove which settings belong to a preset.

Anki's current Deck Options design uses a thin desktop shell that opens a dedicated settings surface, while the option domains and preset semantics are handled separately. LinguaCafe follows the same responsibility direction without importing Anki's deck/subdeck model.

## Decision

### Frontend

`AdminReviewSettings.vue` is a thin composition container. It owns only the latest FSRS statistics snapshot and the refresh signal between child panels.

The settings surface is split into five product areas:

1. `FsrsGoalSettingsPanel.vue` — desired retention, workload simulation, and daily limits.
2. `FsrsQueueOrderSettingsPanel.vue` — the four ADR-0015 queue settings.
3. `FsrsStatusPanel.vue` — current sense-card FSRS statistics.
4. `FsrsAdvancedToolsPanel.vue` — optimization preview/apply, parameter source, reschedule preview/confirm, and undo.
5. `LegacySrsSettingsPanel.vue` — old word-card and phrase intervals.

All settings HTTP requests go through `AdminReviewSettingsApi.js`. Child panels own their local drafts, loading state, success state, and error state. The container does not know endpoint names.

### Backend

`SettingsService` remains the public compatibility facade used by controllers and existing callers. It contains no persistence or domain queries.

Implementation is split into:

- `SettingValueService` — generic global and per-user setting values.
- `FsrsOptimizationSettingsService` — optimization eligibility, diagnostics, preview, apply, parameter-source metadata, and restore-default.
- `FsrsDailyLimitsSettingsService` — daily-limit defaults, validation, read, and write.
- `FsrsQueueOrderSettingsService` — queue-order defaults, validation, read, and write.

Existing `SettingsService` method names and optimization constants remain available. Controllers, routes, request payloads, response payloads, setting names, and database schema remain unchanged.

## Product boundaries

- No Preset is implemented in this ADR.
- No deck, subdeck, or collection hierarchy is added.
- No FSRS algorithm or parameter meaning changes.
- No ReviewLog, ReviewCard lifecycle, queue eligibility, or rating behavior changes.
- No migration is added.
- Formal rescheduling is not executed during browser acceptance; only the read-only preview is exercised.

## Verification

- Architecture contracts cover the thin container, five panels, single API client, compatibility facade, and focused backend services.
- Settings, reschedule, retention simulation, and queue-consumer tests pass.
- Laravel Unit and Feature suites pass.
- Laravel Mix build succeeds.
- DB doctor is healthy.
- Real Chrome acceptance covers 1920×1080 and 900×900, saving unchanged target retention, daily limits, and queue order; workload simulation; advanced-tools expansion; reschedule preview; no horizontal overflow; and zero new application console errors.

## Consequences

Preset V1 can now be added as a settings-domain feature without extending the old monolith. The next phase must reuse these modules and explicitly decide which values are preset-owned, language bindings, clone/switch behavior, and fallback behavior.
