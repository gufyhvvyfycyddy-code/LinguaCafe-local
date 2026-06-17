# FSRS Phase 3 Status

## Scope

- Added real `sense-mapping.json` import for the backend data layer.
- Did not add Quicker automation.
- Did not add GPT/browser upload or download automation.
- Did not change the existing Review page flow.
- Kept Phase 1 word-only Review queue isolated from sense cards.

## Import format

The importer accepts schema version `1` with either a top-level `items` array or one item object.

Each item requires:

- `sentence_id`
- `en`
- `matches`

Each match requires:

- `decision`
- `confidence`
- `auto_fsrs_allowed`

Supported `decision` values:

- `match_existing_sense`
- `new_sense`
- `uncertain`
- `ignore`
- `phrase_match`

## Commands

- Dry run:
  - `php artisan senses:import-mapping storage/app/sense-mapping.json --user_id=1 --language=english --dry-run`
- Real import:
  - `php artisan senses:import-mapping storage/app/sense-mapping.json --user_id=1 --language=english`
- Validation remains available:
  - `php artisan senses:validate-mapping storage/app/sense-mapping.json --user_id=1 --language=english`

## Import behavior

- Import always runs the same validation logic used by `senses:validate-mapping` first.
- `--dry-run` returns the same import summary shape but does not write `word_senses`, `word_sense_occurrences`, or `review_cards`.
- `match_existing_sense`:
  - `confidence >= 0.90` creates a `bound` occurrence.
  - `confidence < 0.90` creates a `pending` occurrence.
  - `auto_fsrs_allowed=true` only creates or links a sense review card when the confidence is high and the matched sense is confirmed.
  - Matched sense IDs must belong to the provided `user_id + language_id`.
- `new_sense`:
  - Creates an `ai_suggested` `word_sense`.
  - Creates a `pending` occurrence.
  - Does not create a review card.
- `uncertain`:
  - Creates a `pending` occurrence.
  - Does not create a sense or review card.
- `ignore`:
  - Creates an `ignored` occurrence.
  - Does not create a review card.
- `phrase_match`:
  - Creates a `pending` occurrence with `type=phrase`.
  - Does not create a phrase review card.

## Summary output

`senses:import-mapping` outputs:

- `total_items`
- `imported_occurrences`
- `bound_existing_senses`
- `created_new_senses`
- `pending_confirmations`
- `ignored_items`
- `phrase_deferred`
- `created_sense_cards`
- `errors`

## Added files

- `app/Console/Commands/ImportSenseMapping.php`
- `app/Models/WordSenseOccurrence.php`
- `app/Services/SenseMappingImportService.php`
- `app/Services/SenseMappingValidationService.php`
- `app/Services/WordSenseOccurrenceService.php`
- `database/migrations/2026_06_17_000006_create_word_sense_occurrences_table.php`
- `docs/FSRS_PHASE3_STATUS.md`

## Modified files

- `app/Console/Commands/ValidateSenseMapping.php`
- `docs/FSRS_PHASE2_STATUS.md`
- `tests/Feature/WordSenseTest.php`

## Verification

- `php artisan migrate --force`
  - Succeeded.
  - Created `word_sense_occurrences`.
- `php artisan test --filter=WordSense`
  - `21 passed`
  - `72 assertions`
- `php artisan test --filter=ReviewFsrsTest`
  - `5 passed`
  - `92 assertions`
- `php artisan test --filter=FsrsSchedulingServiceTest`
  - `3 passed`
  - `10 assertions`
- `npm run development`
  - Compiled successfully.
  - Existing Sass and Bootstrap deprecation warnings remain.

## Current risks

- The full Laravel test suite may still contain unrelated legacy Auth/homepage failures recorded in earlier phase notes.
- Phrase support is intentionally deferred; phrase occurrences are stored for later confirmation but never enter FSRS.
- There is no frontend confirmation page yet.

## Next phase

- Build a frontend or admin confirmation page for pending `word_sense_occurrences`.
- The page should call service-backed actions equivalent to:
  - confirm occurrence
  - reject occurrence
  - bind occurrence to an existing sense
  - create a sense from an occurrence
  - enable FSRS after confirmation
