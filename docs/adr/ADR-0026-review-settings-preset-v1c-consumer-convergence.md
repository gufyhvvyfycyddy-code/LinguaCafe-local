# ADR-0026 — Review Settings Preset V1C Consumer Convergence

## Status

Accepted / Production Closed — 2026-07-15

## Context

Preset V1A made the user + learning-language Preset the runtime authority for FSRS parameters, desired retention, daily limits, and queue order. Preset V1B added safe management actions. One historical global Setting remained misleading: `fsrs_parameters_previous`.

The current code never read that value to perform undo or restore. Parameter restore uses system defaults, and card reschedule undo uses the independent snapshot subsystem. In a multi-user, multi-language Preset model, one global “previous parameters” row cannot identify which user, language, or Preset it belongs to.

## Decision

- Stop writing `fsrs_parameters_previous` when optimization is applied.
- Stop deleting it when a user restores default parameters.
- Stop listing it in `saved_keys` or `deleted_keys` responses.
- Preserve any pre-existing database row unchanged as an ignored historical residue. V1C performs no destructive cleanup migration.
- Remove the now-unused generic `SettingValueService::upsertGlobal()` and `deleteGlobal()` methods.
- Keep `ReviewSettingsResolver` and the current bound Preset as the only runtime authority for active FSRS parameters and metadata.

## User-visible behavior

- Parameter optimization still previews and applies to the current user + current language Preset.
- Restore default still restores only the current bound Preset’s FSRS section.
- Existing cards are not automatically rescheduled.
- Response fields remain compatible; `deleted_count` remains and is always `0` for Preset restore.
- No management or Advanced Tools layout change is included. Settings UX-1 remains Preset V1D.

## Safety

V1C does not:

- delete an existing legacy row;
- alter a database schema;
- write ReviewLog;
- change WordSense, ReviewCard, lifecycle, or `fsrs_due_at`;
- change the FSRS algorithm or optimization threshold;
- execute reschedule, restore-default, or optimization against local learning data during browser smoke.

## Alternatives rejected

### Keep one global previous row

Rejected because later users overwrite earlier users and the row has no valid ownership scope.

### Move previous parameters into the Preset schema now

Rejected because there is no product action that consumes it. Adding unused history to the stable schema creates speculative state.

### Delete all old rows in a migration

Rejected because V1C can safely ignore historical residue without destructive data cleanup.

### Reuse the reschedule undo snapshot system

Rejected because parameter configuration history and card due-date snapshots are distinct concepts.

## Verification

- RED proved apply still claimed/saved the global previous state and restore deleted it.
- Optimization test suite: 39 passed / 221 assertions.
- Preset/FSRS focused regression: 174 passed / 924 assertions.
- Full Unit: 652 passed / 1518 assertions.
- Full Feature: 2596 passed / 14 skipped / 11617 assertions.
- 48 Node guards passed, including a V1C static boundary guard.
- Chrome read-only smoke confirmed the Settings page, current Preset, shared-language warning, Advanced Tools, parameter status, and diagnostics still load with no Console errors.
- DB doctor remained Healthy.

## Consequences

Preset V1A–V1C are production closed. Preset V1D is the next and final Preset phase. It owns Settings UX-1, the Advanced Tools empty-state/action-safety repair requested from the real screenshot, and final end-to-end Preset production closure. V1D is planned and was not executed in this task.
