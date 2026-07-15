# Preset V1B Management Operations and UI Execution Plan

> **Status**: Current / Direct execution authorized — 2026-07-15
> **Baseline**: `dc1f37c1` (`docs: close preset v1a and schedule settings ux`)
> **Depends on**: ADR-0024 and `review-settings-preset-v1-plan.md`
> **DCP_ALLOWED**: false

## Goal

Add Anki-aligned Preset management without changing FSRS scheduling semantics: list, create from system defaults, clone current/selected Preset, rename ordinary Presets, delete ordinary Presets with transactional Default rebinding, and switch only the current language binding.

## Architecture

- Keep `ReviewSettingsResolver` as the only effective-config entry.
- Extend the Preset domain with explicit management methods; do not route management through legacy global settings.
- Add a dedicated HTTP controller and dedicated route file.
- Load the new route file through Laravel 11 `withRouting(web: [...])`; do not modify the protected `routes/web.php`.
- Keep `AdminReviewSettings.vue` as a thin composition container. A focused Preset manager component owns management state and uses `AdminReviewSettingsApi.js`.
- Switching Preset increments a parent refresh key so the existing settings panels remount and load the newly selected configuration.

## Frozen product contract

1. Default belongs to the user, keeps the exact name `Default`, and cannot be renamed or deleted.
2. Add creates a named Preset from system defaults, not from the current Preset.
3. Clone copies the selected Preset configuration and requires a new unique name.
4. Names are trimmed, 1–120 characters, unique per user, and may not use `Default` case-insensitively.
5. Switch changes only `user_id + current language` binding. It does not copy values, modify cards, write ReviewLog, change lifecycle, or reschedule.
6. A Preset may be shared by multiple languages. The UI shows all bound languages and warns that editing affects them together.
7. Deleting an ordinary Preset transactionally rebinds all its languages to the user Default, then deletes it.
8. Every action is user-scoped; unknown and cross-owner IDs return 404 without leaking another user’s data.
9. Existing Settings endpoint/payload contracts remain unchanged.
10. V1C and Settings UX-1 remain out of scope.

## API contract

All routes require `auth`, `auth.session`, and `admin`.

- `GET /settings/review-presets`
- `POST /settings/review-presets` body `{name}`
- `POST /settings/review-presets/{presetId}/clone` body `{name}`
- `PATCH /settings/review-presets/{presetId}` body `{name}`
- `DELETE /settings/review-presets/{presetId}`
- `PUT /settings/review-presets/current-language` body `{preset_id}`

Canonical response:

```json
{
  "current_language": "english",
  "current_preset_id": 1,
  "presets": [
    {
      "id": 1,
      "name": "Default",
      "is_default": true,
      "is_current": true,
      "bound_languages": ["english", "french"],
      "bound_language_count": 2
    }
  ]
}
```

Validation failure: HTTP 422 with `{message, errors}`. Cross-owner/unknown: HTTP 404. Successful mutations return the canonical current state.

## Files

Create:

- `routes/review-settings-presets.php`
- `app/Http/Controllers/ReviewSettingsPresetController.php`
- `app/Exceptions/ReviewSettingsPresetException.php`
- `resources/js/components/Admin/ReviewSettings/ReviewSettingsPresetManager.vue`
- `tests/Feature/ReviewSettingsPresetManagementTest.php`
- `tests/js/ReviewSettingsPresetManagementUiGuard.test.mjs`
- `docs/adr/ADR-0025-review-settings-preset-v1b-management.md`

Modify:

- `bootstrap/app.php`
- `app/Services/Settings/Presets/ReviewSettingsPresetService.php`
- `app/Services/Settings/Presets/ReviewSettingsPresetBindingService.php`
- `app/Services/Settings/Presets/ReviewSettingsResolver.php`
- `resources/js/components/Admin/AdminReviewSettings.vue`
- `resources/js/services/AdminReviewSettingsApi.js`
- current plan/index/registry and integrity guard after implementation.

Protected and forbidden:

- `routes/web.php`
- `tests/Unit/CustomStudyCriteriaTest.php`
- `.env`, `AGENTS.md`, `.omo/`, `.playwright-cli/`, `nul`
- FSRS algorithm, rating contract, ReviewLog, lifecycle, WordSense, ReviewCard schema
- V1C global cleanup and V1D Settings UX-1 implementation

## TDD sequence

1. RED: management feature tests for routes, owner isolation, defaults, clone, rename, delete/rebind, switch, no learning-data deltas.
2. GREEN: domain methods, controller, independent route file, route loading.
3. RED/GREEN: UI guard and focused manager component/API client.
4. Regression: Preset V1A, Settings/FSRS, Unit, Feature, Node guards, build, DB doctor, diff check.
5. Chrome: 1920×1080 and 900×900; create, clone, rename, switch English/French, delete/rebind, refresh persistence, Console/Network, restore final language English.
6. Docs/ADR, precise commits, push, remote verification. Do not enter V1C.
