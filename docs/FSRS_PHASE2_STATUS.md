# FSRS Phase 2 Status

## Completed

- Created a Git checkpoint for Phase 1:
  - `c0f430a checkpoint: phase 1 word-only FSRS complete`
- Added `word_senses` table.
- Added `WordSense` model.
- Added `WordSenseService`.
- Extended `ReviewCardService` with sense-card support.
- Added `senses:export-learned`.
- Added `senses:validate-mapping`.
- Added WordSense feature tests.
- Confirmed Phase 1 FSRS tests still pass.
- Confirmed frontend build still passes.
- Committed Phase 2 as:
  - `b0929fc phase 2: add sense-level review foundation`

## Commands run

- `git init`
- `git add .`
- `git config user.name "Codex"`
- `git config user.email "codex@local"`
- `git commit -m "checkpoint: phase 1 word-only FSRS complete"`
- `php artisan migrate --force`
- `php artisan test --filter=WordSense`
- `php artisan test --filter=ReviewFsrsTest`
- `php artisan test --filter=FsrsSchedulingServiceTest`
- `npm run development`
- `php artisan list senses`
- `git status --short`
- `git add .`
- `git commit -m "phase 2: add sense-level review foundation"`

## Command results

- Migration succeeded:
  - `2026_06_17_000005_create_word_senses_table ... DONE`
- WordSense tests:
  - `10 passed`
  - `29 assertions`
- Review FSRS tests:
  - `5 passed`
  - `92 assertions`
- FSRS scheduling tests:
  - `3 passed`
  - `10 assertions`
- Frontend:
  - `npm run development` compiled successfully
  - existing Sass deprecation warnings remain
- Artisan sees both new commands:
  - `senses:export-learned`
  - `senses:validate-mapping`
- Phase 2 commit succeeded:
  - `b0929fc phase 2: add sense-level review foundation`

## Modified files

- `app/Console/Commands/ExportLearnedSenses.php`
- `app/Console/Commands/ValidateSenseMapping.php`
- `app/Models/WordSense.php`
- `app/Services/ReviewCardService.php`
- `app/Services/WordSenseService.php`
- `database/migrations/2026_06_17_000005_create_word_senses_table.php`
- `tests/Feature/WordSenseTest.php`
- `docs/FSRS_PHASE2_STATUS.md`

## Current risks

- Full Laravel test suite still has the known unrelated Auth/homepage failures from Phase 1.
- Docker/WSL is still unavailable on this Windows image.
- The exported JSON contains Unicode; PowerShell may display Chinese text with mojibake depending on the active code page.

## Next step

- Do not do Quicker.
- Do not do GPT/browser automation.
- Phase 3 may add real `sense-mapping.json` import into a separate occurrence table.
- Keep the existing learned-senses export and validate-mapping command behavior stable.
