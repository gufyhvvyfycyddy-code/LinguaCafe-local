# ADR-0025 — Review Settings Preset V1B Management

## Status

Accepted / Production Closed — 2026-07-15

## Context

Preset V1A established one user-owned Default Preset, one binding per user + learning language, a schema-v1 config object, and one runtime resolver. V1B adds the Anki-like management actions needed to make Presets useful without introducing a deck/subdeck tree.

Anki treats Presets as shared configuration objects and keeps Add, Clone, Rename, Delete, and assignment as distinct operations. LinguaCafe keeps that product meaning while binding Presets to learning languages because the project has no stable deck boundary.

## Decision

### Domain rules

- Presets belong to one user.
- `Default` is the exact protected name and cannot be renamed or deleted.
- Ordinary names are trimmed, 1–120 characters, unique per user, and cannot use `Default` case-insensitively.
- Add creates system defaults.
- Clone copies the selected Preset config.
- Switching changes only the current user + current language binding.
- A Preset may be shared by multiple languages; the UI exposes every bound language and warns that editing affects all of them.
- Deleting an ordinary Preset transactionally rebinds all of its languages to the user's Default before deletion.

### Architecture

- `ReviewSettingsResolver` remains the only effective-config entry.
- `ReviewSettingsPresetManagementService` orchestrates list/create/clone/rename/switch/delete.
- Ownership checks live in `ReviewSettingsPresetService`; binding writes live in `ReviewSettingsPresetBindingService`.
- Management has a dedicated controller and route file.
- Laravel 11 loads `routes/review-settings-presets.php` beside the protected `routes/web.php` through `bootstrap/app.php`.
- The settings container stays thin. The focused Preset manager uses the shared API client and emits one refresh event after current-binding changes.

### API

Authenticated administrator routes:

- `GET /settings/review-presets`
- `POST /settings/review-presets`
- `POST /settings/review-presets/{presetId}/clone`
- `PATCH /settings/review-presets/{presetId}`
- `DELETE /settings/review-presets/{presetId}`
- `PUT /settings/review-presets/current-language`

Unknown and cross-owner IDs return 404. Product validation returns 422. Existing Settings endpoints and payloads remain unchanged.

## Safety properties

Management actions do not:

- write ReviewLog;
- change WordSense or ReviewCard content;
- change `fsrs_due_at` or FSRS scheduling values;
- change lifecycle state;
- reschedule existing cards;
- call an AI provider.

The real Chrome acceptance created, cloned, renamed, shared, switched, and deleted temporary Presets, then restored the local account to English with only Default bound to English and French. Learning-data counts did not change.

## Alternatives rejected

- Putting management into legacy `/settings/global/*`: rejected because management has different ownership and transaction semantics.
- Modifying protected `routes/web.php`: rejected; Laravel supports multiple web route files.
- Creating deck/subdeck abstractions: rejected because LinguaCafe's stable scope is user + learning language.
- Auto-selecting every newly created Preset: rejected; creation and current-language assignment remain separate actions.
- Implementing Advanced Tools UX cleanup in this round: deferred to Settings UX-1 in Preset V1D.

## Verification

- Management feature tests: 9 tests / 43 assertions.
- Preset + Settings/FSRS focused regression: 280 tests / 1456 assertions.
- Full Unit: 652 tests / 1518 assertions.
- Full Feature: 2596 passed / 14 skipped / 11615 assertions.
- 47 Node guards passed.
- Laravel Mix build succeeded; existing Sass deprecation warnings remain.
- DB doctor healthy.
- Chrome: 1920×1080 and 900×900, full management flow, shared-language warning, delete/rebind, no overflow, clean Console, final data restored.

## Consequences

Preset V1B is closed. Preset V1C now owns consumer convergence and the remaining `fsrs_parameters_previous` global orphan-state decision. Preset V1D owns Settings UX-1 and final Preset production closure.
