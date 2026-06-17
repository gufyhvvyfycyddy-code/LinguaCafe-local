# FSRS Phase 4 Status

## Scope

- Added a frontend confirmation page for imported `word_sense_occurrences`.
- Added backend routes for listing, confirming, rebinding, creating senses, rejecting, and ignoring occurrences.
- Kept the existing word-only Review page unchanged.
- Did not add Quicker automation.
- Did not add GPT/browser upload or download automation.
- Did not add phrase FSRS.

## Page entry

- Authenticated SPA route:
  - `/senses/review`
- Navigation entry:
  - `Sense review`

Unauthenticated users are redirected to the existing login flow.

## Occurrence status

- `pending`: needs manual confirmation or binding.
- `bound`: linked to a confirmed or confirmed-at-action sense.
- `ignored`: intentionally excluded from sense review.
- `rejected`: rejected by the user.

## Backend endpoints

- `GET /senses/occurrences`
  - Lists occurrences for the current authenticated user and selected language.
  - Supports `status`, `language`, `lemma`, `decision`, `page`, and `per_page`.
- `GET /senses/candidates`
  - Lists candidate senses for the current authenticated user and selected language.
  - Supports `lemma`, `language`, and optional `pos`.
- `POST /senses/occurrences/{id}/confirm`
  - Confirms the current occurrence binding.
  - Confirms an `ai_suggested` sense when one is already attached.
- `POST /senses/occurrences/{id}/bind`
  - Binds an occurrence to another existing sense in the same user and language scope.
- `POST /senses/occurrences/{id}/create-sense`
  - Creates a confirmed `word_sense` from the occurrence and binds it.
- `POST /senses/occurrences/{id}/reject`
  - Marks the occurrence as rejected.
- `POST /senses/occurrences/{id}/ignore`
  - Marks the occurrence as ignored.

All write operations go through `WordSenseOccurrenceService`.

## FSRS behavior

- `confirm` creates or ensures a sense review card only when `auto_fsrs_allowed=true`.
- `bind` creates or ensures a sense review card only when the request sets `auto_fsrs_allowed=true`.
- `create-sense` creates or ensures a sense review card only when the request sets `auto_fsrs_allowed=true`.
- `reject` and `ignore` never create review cards.
- Sense cards are still not included in the existing Review page queue.

## Frontend behavior

- Shows pending occurrences by default.
- Shows summary counts for pending, bound, ignored, and rejected.
- Shows English sentence, Chinese sentence, surface, lemma, pos, decision, confidence, evidence, current sense, and raw payload.
- Supports:
  - confirm
  - bind to existing sense
  - create new sense
  - reject
  - ignore
- Successful actions refresh the list.

## Verification

- `php artisan migrate --force`
  - Succeeded.
- `php artisan test --filter=WordSense`
  - `29 passed`
  - `104 assertions`
- `php artisan test --filter=ReviewFsrsTest`
  - `5 passed`
  - `92 assertions`
- `php artisan test --filter=FsrsSchedulingServiceTest`
  - `3 passed`
  - `10 assertions`
- `npm run development`
  - Compiled successfully.
  - Existing Sass and Bootstrap deprecation warnings remain.
- Local route check:
  - `/senses/review` redirects to login when unauthenticated.
  - Authenticated route is covered by test and returns 200.

## Current risks

- The confirmation page is intentionally basic and does not yet support bulk actions.
- Phrase occurrences remain deferred and do not enter FSRS.
- Sense cards exist and can be scored through the backend, but the current Review page remains word-only by design.
- Full test suite may still include unrelated legacy Auth/homepage failures from earlier phase notes.

## Next phase suggestion

- Add bulk confirmation tools for pending occurrences.
- Add a more compact review workflow for high-volume sense mapping cleanup.
- Decide when and how sense cards should get their own review surface without changing the word-only Review queue.
