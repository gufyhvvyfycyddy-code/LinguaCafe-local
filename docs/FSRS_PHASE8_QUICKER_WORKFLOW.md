# FSRS Phase 8 Quicker Workflow

This workflow is semi-automatic. Quicker can call local scripts, open folders, and open browser pages, but it should not click through ChatGPT, bypass browser limits, store accounts or cookies, or automatically import GPT output without validation.

## Working Directory

Root:

```text
storage/app/gpt-workflow/
```

Subdirectories:

- `input`: put new English material here.
- `package`: generated GPT package and prompt.
- `downloads`: put GPT-downloaded `sense-mapping.json` here.
- `validated`: validated mapping files.
- `imported`: successfully imported mapping files.
- `failed`: failed mapping files and error reports.

## Windows Scripts

Configuration:

```text
scripts/windows/gpt-workflow-config.bat
```

Actions:

- `scripts/windows/gpt-workflow-prepare.bat`
- `scripts/windows/gpt-workflow-validate-latest.bat`
- `scripts/windows/gpt-workflow-import-latest-dry-run.bat`
- `scripts/windows/gpt-workflow-import-latest.bat`
- `scripts/windows/open-sense-review.bat`
- `scripts/windows/open-chatgpt.bat`

Edit `gpt-workflow-config.bat` if the PHP binary, user ID, language, or local app URL differs.

## Quicker Action 1: Prepare GPT Package

Suggested steps:

1. Call `scripts/windows/gpt-workflow-prepare.bat`.
2. Open the package folder.
3. Open ChatGPT web.
4. User manually uploads or pastes `gpt-sense-package.md`.

Generated files:

- `storage/app/gpt-workflow/package/gpt-sense-package.md`
- `storage/app/gpt-workflow/package/prompt.txt`

## Quicker Action 2: Validate GPT Download

User step:

- Put GPT's downloaded `sense-mapping.json` into `storage/app/gpt-workflow/downloads/`.

Suggested Quicker step:

- Call `scripts/windows/gpt-workflow-validate-latest.bat`.

If validation passes:

- file is copied to `validated/`

If validation fails:

- file is copied to `failed/`
- error report is written next to it
- do not import

## Quicker Action 3: Import Dry Run

Suggested step:

- Call `scripts/windows/gpt-workflow-import-latest-dry-run.bat`.

The dry run prints the import summary and does not write the database.

## Quicker Action 4: Formal Import

Suggested step:

- Call `scripts/windows/gpt-workflow-import-latest.bat`.

The script pauses first so the user can confirm that dry-run has already been reviewed.

On success:

- file is copied to `imported/`
- `/senses/review` opens for manual confirmation

## Quicker Action 5: Open Confirmation Page

Suggested step:

- Call `scripts/windows/open-sense-review.bat`.

## Responsibilities

- Quicker does not judge word senses.
- GPT generates `sense-mapping.json`.
- LinguaCafe validates and imports.
- User performs final confirmation.
- Do not fully automate import of GPT output; validate first, then dry-run, then import.
