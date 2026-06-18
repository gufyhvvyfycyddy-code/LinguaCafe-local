# FSRS Phase 8 Status

## Scope

- Added local semi-automatic GPT workflow commands.
- Added Windows scripts for Quicker integration.
- Added Quicker workflow documentation.
- Kept browser interaction manual.
- Did not add ChatGPT web auto-clicking.
- Did not store user accounts, cookies, or browser state.
- Did not add phrase FSRS.

## Workflow Commands

Prepare package:

```bash
php artisan senses:gpt-workflow prepare --user_id=1 --language=english --input=storage/app/gpt-workflow/input/new-material.txt
```

Validate latest GPT download:

```bash
php artisan senses:gpt-workflow validate-latest --user_id=1 --language=english
```

Dry-run latest validated import:

```bash
php artisan senses:gpt-workflow import-latest --user_id=1 --language=english --dry-run
```

Import latest validated mapping:

```bash
php artisan senses:gpt-workflow import-latest --user_id=1 --language=english
```

## Windows Scripts

- `scripts/windows/gpt-workflow-config.bat`
- `scripts/windows/gpt-workflow-prepare.bat`
- `scripts/windows/gpt-workflow-validate-latest.bat`
- `scripts/windows/gpt-workflow-import-latest-dry-run.bat`
- `scripts/windows/gpt-workflow-import-latest.bat`
- `scripts/windows/open-sense-review.bat`
- `scripts/windows/open-chatgpt.bat`

## Directory Roles

- `storage/app/gpt-workflow/input`
  - New English material.
- `storage/app/gpt-workflow/package`
  - Generated GPT package and `prompt.txt`.
- `storage/app/gpt-workflow/downloads`
  - JSON files downloaded manually from GPT web.
- `storage/app/gpt-workflow/validated`
  - Mapping files that passed validation.
- `storage/app/gpt-workflow/imported`
  - Mapping files imported successfully.
- `storage/app/gpt-workflow/failed`
  - Failed mapping files and error reports.

## Safety Rules

- Validate before import.
- Run dry-run before formal import.
- Validation failure copies the file to `failed/` and writes an error report.
- Import reads from `validated/`, not directly from `downloads/`.
- The formal import script pauses before writing the database.
- The workflow never clicks ChatGPT or stores browser credentials.

## Quicker Recommendation

Recommended actions:

1. Prepare GPT package.
2. Validate GPT download.
3. Import dry-run.
4. Formal import.
5. Open `/senses/review`.

Details are in `docs/FSRS_PHASE8_QUICKER_WORKFLOW.md`.

## Verification

- `php artisan migrate --force`
  - Succeeded.
- `php artisan test --filter=WordSense`
  - `48 passed`
  - `226 assertions`
- `php artisan test --filter=ReviewFsrsTest`
  - `5 passed`
  - `92 assertions`
- `php artisan test --filter=FsrsSchedulingServiceTest`
  - `3 passed`
  - `10 assertions`
- `npm run development`
  - Compiled successfully.
  - Existing Sass and Bootstrap deprecation warnings remain.

## Current limitations

- No full browser automation.
- No ChatGPT upload/download automation.
- No phrase FSRS.
- Quicker scripts assume local PHP is available as `php` unless configured otherwise.

## Next phase suggestion

- Add optional UI status indicators for workflow folders.
- Add a safer import history viewer.
