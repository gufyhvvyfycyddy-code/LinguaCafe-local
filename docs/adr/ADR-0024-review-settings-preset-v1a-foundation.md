# ADR-0024: Review Settings Default Preset and Language Binding

## Status

Accepted / Production Closed — 2026-07-15

## Context

ADR-0023 split the review settings surface into focused frontend and backend modules, but desired retention, FSRS parameters, daily limits, and queue order still used `settings.user_id = -1` as their runtime authority. That global shape could not isolate users or languages and would make Preset management unsafe.

Anki groups scheduling options into shared presets: changing a preset affects all decks that use it, newly created decks use Default, and option changes do not retroactively reschedule existing cards unless the user explicitly requests rescheduling. LinguaCafe has no stable deck tree, so its sharing boundary is a user-owned Preset bound to one or more learning languages.

## Decision

### Persistence and identity

- `review_setting_presets` stores user-owned named config documents. V1A lazily creates exactly one `Default` after the user's first resolved settings request.
- `review_setting_preset_bindings` stores one binding for each `user_id + language_id`.
- Unique keys protect user/name, at-most-one non-null default marker, and user/language binding.
- A composite foreign key from binding `(preset_id, user_id)` to preset `(id, user_id)` enforces same-owner binding. Preset deletion is restricted; user deletion cascades.
- `is_default = true` is reserved for `Default`; future non-default rows use `NULL`, allowing V1B to add multiple named presets without weakening the V1A unique key.

### Config schema v1

`ReviewSettingsPresetConfig` is the only schema authority. Its normalized document contains:

- `fsrs`: desired retention, parameters, parameters source, optimized timestamp;
- `daily_limits`: permanent new/review limits and new-card limit interaction;
- `queue_order`: the four ADR-0015 values.

Today-only overrides, Custom Study, lifecycle, leech, Card Marker, Saved Search, UI preferences, legacy SRS intervals, and `fsrs_parameters_previous` are excluded.

### Initialization and legacy compatibility

`ReviewSettingsResolver` resolves explicit `user_id + language` to binding, preset, and validated config. If a binding exists, any missing, foreign, or invalid preset fails closed. If no binding exists, the resolver reuses the user's Default. `LegacyReviewSettingsSnapshotService` reads the old global rows only when that user has no Default; adding another language never resnapshots or overwrites it.

Unique constraints are the concurrency authority. Creation uses transaction-safe create-or-read behavior, so repeated initialization produces one Default and one binding. A Default is guaranteed after first resolution, not before a user has ever opened a settings-backed flow.

### Reads, writes, and compatibility

- All formal scheduling/settings consumers pass explicit user and language context.
- `SettingsService` remains the compatibility facade; existing routes and response/request field names do not change.
- The generic global endpoint splits an explicit preset-owned key allowlist from genuinely global keys. Mixed requests merge their results under the original keys.
- Preset-owned writes never write their current value back to legacy global rows.
- Every config mutation runs in a transaction, locks the bound preset row, merges only the caller-owned leaves, validates the full schema, and saves once. This prevents independent panels from overwriting one another.
- `fsrs_parameters_previous` remains an operation-only legacy snapshot for the existing optimization workflow and is never read as current scheduling configuration.

### Frontend

The existing settings page adds a small read-only identity surface showing `当前 Preset：Default` and the current language. It obtains metadata through the existing global settings endpoint and shared API client. V1A adds no create, clone, rename, delete, or switch action.

## Safety boundaries

- No FSRS formula, rating key, score, label, or hotkey changes.
- Initialization and saves do not write ReviewLog, change lifecycle, or create/delete WordSense or ReviewCard.
- No automatic rescheduling and no `fsrs_due_at` mutation.
- Existing reschedule preview/confirm/undo endpoints keep their contracts; browser acceptance must not execute formal reschedule, restore-default, or undo.
- Old global setting rows remain available for rollback and first snapshot.

## Verification

Executable contracts cover config normalization, idempotent initialization, user/language isolation, ownership rejection, legacy equivalence, no re-snapshot on a new language, invalid-config fail-closed behavior, mixed endpoint compatibility, disjoint update merging, learning-data safety, UI scope, and absence of direct runtime legacy reads. Existing scheduling, optimization, daily-limit, queue-order, workload, reschedule, Study Overview, WordSense, and Custom Study regressions remain required.

Web-side acceptance is complete. DevSpace5 verified the latest master, migrations, constraints, resolver consumers and automatic regression; Chrome DevTools verified Default identity, real settings saves, 1920×1080 and 900×900 layouts, English/French binding creation, no horizontal overflow and no new Console error/warning. Preset V1A is **Accepted / Production Closed**. V1B is authorized only through the separate current plan.

## Consequences

Review settings now have a user/language-aware runtime authority without removing rollback data or changing public routes. The two-table and five-service boundary is the minimum needed for identity, binding, snapshot, validation, and locked mutation; no repository, event, cache, management controller, or deck abstraction is introduced.
