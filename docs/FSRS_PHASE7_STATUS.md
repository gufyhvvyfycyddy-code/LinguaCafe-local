# FSRS Phase 7 Status

## Scope

- Added a GPT work package generator.
- Added Markdown and JSON package formats.
- Added examples for a GPT package and a GPT-produced sense mapping file.
- Kept validate/import/review flows unchanged.
- Did not add Quicker automation.
- Did not add GPT web upload/download automation.
- Did not add phrase FSRS.

## Generate a Markdown package

```bash
php artisan senses:make-gpt-package --user_id=1 --language=english --input=storage/app/new-material.txt --output=storage/app/gpt-sense-package.md
```

The Markdown package is intended for manual copy/paste into GPT web. It includes:

- task instructions
- judgment rules
- required output schema
- learned senses for the selected user and language
- new English material

## Generate a JSON package

```bash
php artisan senses:make-gpt-package --user_id=1 --language=english --input=storage/app/new-material.txt --output=storage/app/gpt-sense-package.json --format=json
```

The JSON package has:

- `package_schema_version`
- `created_at`
- `user_id`
- `language`
- `instructions`
- `output_schema`
- `learned_senses`
- `new_material`

## Options

- `--max_senses=1000`
  - Limits embedded learned senses.
  - If the learned sense table is truncated, the package says to increase `--max_senses` or split work by lemma.
- `--include_examples=true`
  - Includes learned sense examples by default.
- `--confidence_threshold=0.90`
  - Controls the instruction that low-confidence matches must not auto-enable FSRS.

## GPT output requirements

GPT should output strict JSON only.

It must include:

- `schema_version`
- `document_id`
- `language`
- `sentences`
- each sentence's `sentence_id`, `en`, optional `zh`, and `matches`

Allowed decisions:

- `match_existing_sense`
- `new_sense`
- `uncertain`
- `ignore`
- `phrase_match`

Rules emphasized in the package:

- Prefer matching existing `sense_id` when context clearly matches.
- Do not match only because Chinese glosses look similar.
- Use lemma, part of speech, English meaning, Chinese meaning, aliases, collocations, examples, and context together.
- Use `uncertain` instead of forcing a match.
- If `confidence < 0.90`, `auto_fsrs_allowed` must be `false`.
- `phrase_match` is only a marker and does not enter FSRS.

## Save GPT output

Save GPT's JSON output as:

```bash
storage/app/sense-mapping.json
```

## Validate

```bash
php artisan senses:validate-mapping storage/app/sense-mapping.json --user_id=1 --language=english
```

The validator accepts both `items` and `sentences` as the sentence container.

## Import

Dry run:

```bash
php artisan senses:import-mapping storage/app/sense-mapping.json --user_id=1 --language=english --dry-run
```

Real import:

```bash
php artisan senses:import-mapping storage/app/sense-mapping.json --user_id=1 --language=english
```

## Manual confirmation and review

After import:

1. Open `/senses/review`.
2. Confirm, bind, create, ignore, or reject occurrences.
3. Use bulk tools for high-volume cleanup.
4. Open `/reviews/senses` to review due confirmed sense cards.

## Examples

- `docs/examples/gpt-sense-package.example.md`
- `docs/examples/sense-mapping.example.json`

The example `sense-mapping.example.json` validates with the current `senses:validate-mapping` command.

## Verification

- `php artisan migrate --force`
  - Succeeded.
- `php artisan test --filter=WordSense`
  - `42 passed`
  - `207 assertions`
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

- This phase intentionally does not automate GPT web upload/download.
- This phase intentionally does not add Quicker.
- The package embeds learned senses by simple limit only; no vector or semantic filtering is implemented.
- Phrase matches remain markers and do not enter FSRS.

## Next phase suggestion

- Add a Quicker semi-automatic workflow that helps move the generated package into GPT web and bring the JSON response back, while still keeping validate/import explicit and user-controlled.
