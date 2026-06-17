# FSRS Phase 6 Status

## Scope

- Added bulk handling to the Sense Mapping Review page.
- Added read-only possible duplicate sense detection.
- Kept word-only Review unchanged.
- Kept Sense Review unchanged.
- Did not add Quicker automation.
- Did not add GPT/browser upload or download automation.
- Did not add phrase FSRS.

## Bulk confirmation

Endpoint:

- `POST /senses/occurrences/bulk-confirm`

Body:

- `occurrence_ids`: array of occurrence IDs
- `auto_fsrs_allowed`: optional boolean

Rules:

- Only current authenticated user and selected language are processed.
- Only `pending` and `bound` occurrences can be confirmed.
- An occurrence already bound to a confirmed sense becomes `bound`.
- An occurrence bound to an `ai_suggested` sense confirms that sense first.
- Invalid, cross-user, cross-language, or unsupported occurrences are skipped and reported in `errors`.

## Bulk confirmation with FSRS

The same endpoint is used with `auto_fsrs_allowed=true`.

Difference:

- Confirmation also ensures a `target_type=sense` review card exists.
- Newly created review cards are counted in `created_review_cards`.

## Bulk ignore and reject

Endpoints:

- `POST /senses/occurrences/bulk-ignore`
- `POST /senses/occurrences/bulk-reject`

Rules:

- `bulk-ignore` marks occurrences as `ignored`.
- `bulk-reject` marks occurrences as `rejected`.
- Neither endpoint creates review cards.
- Both endpoints are scoped to the current authenticated user and selected language.

## High-confidence bulk confirmation

Endpoint:

- `POST /senses/occurrences/bulk-confirm-high-confidence`

Body:

- `confidence_min`: optional, default `0.90`
- `decision`: optional, defaults to `match_existing_sense`
- `lemma`: optional
- `only_auto_fsrs_allowed`: optional boolean

Rules:

- Only current authenticated user and selected language are processed.
- Only `decision=match_existing_sense` occurrences are processed.
- Only occurrences with `confidence >= confidence_min` are processed.
- `uncertain`, `new_sense`, and `phrase_match` are not processed.
- If an occurrence has `auto_fsrs_allowed=true`, confirmation ensures the sense review card.

## Bulk summary

All bulk endpoints return:

- `requested_count`
- `processed_count`
- `skipped_count`
- `confirmed_count`
- `ignored_count`
- `rejected_count`
- `created_review_cards`
- `errors`

## Frontend changes

Page:

- `/senses/review`

Added:

- checkbox per occurrence
- select all current page
- bulk confirm
- bulk confirm and enable FSRS
- bulk ignore
- bulk reject
- bulk confirm high confidence
- filters for status, decision, confidence minimum, lemma, and auto FSRS
- summary display for the latest bulk result

High-risk actions use browser confirmation prompts:

- bulk ignore
- bulk reject
- bulk confirm high confidence

## Possible duplicates

Endpoint:

- `GET /senses/possible-duplicates`

Parameters:

- `language`: optional, must match the current selected language when provided
- `lemma`: optional

Simple duplicate rules:

- same user
- same language
- same lemma
- same part of speech, or one side has empty part of speech
- identical `sense_zh`, or intersecting `aliases_zh`

This feature only displays possible duplicates. It does not merge, delete, or modify senses.

## Verification

- `php artisan migrate --force`
  - Succeeded.
- `php artisan test --filter=WordSense`
  - `39 passed`
  - `184 assertions`
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

- Phrase occurrences remain deferred and do not enter FSRS.
- Duplicate detection is intentionally simple and read-only.
- No automatic merge is implemented.
- Bulk operations do not span all pages by selection; selected bulk actions act on current selected IDs, while high-confidence confirmation acts by filters.

## Next phase suggestion

- Add keyboard shortcuts and a compact workflow for Sense Mapping Review.
- Add an explicit duplicate merge proposal page, still requiring manual user confirmation.
- Add a session-complete state for Sense Review.
