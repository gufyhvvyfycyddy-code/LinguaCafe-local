# FSRS Phase 5 Status

## Scope

- Added a dedicated Sense Review page for `target_type=sense` review cards.
- Kept the original Review page word-only.
- Did not add Quicker automation.
- Did not add GPT/browser upload or download automation.
- Did not add bulk confirmation.
- Did not add phrase FSRS.

## Page entry

- Sense Review page:
  - `/reviews/senses`
- Navigation entry:
  - `Sense FSRS Review`

The same path returns the Vue app for normal browser navigation and returns JSON queue data for axios/XHR requests.

## Difference from original Review

- Original Review page:
  - Uses the existing `/reviews` word queue.
  - Only returns `review_cards.target_type = word`.
- Sense Review page:
  - Uses `/reviews/senses`.
  - Only returns `review_cards.target_type = sense`.
  - Only reviews confirmed `word_senses`.

## Sense cards that enter review

Included:

- current authenticated user
- current selected language
- `review_cards.target_type = sense`
- `review_cards.fsrs_enabled = true`
- `review_cards.fsrs_due_at <= now`
- linked `word_senses.status = confirmed`

Excluded:

- word cards
- phrase cards
- other users
- other languages
- disabled cards
- future-due cards
- rejected senses
- ai-suggested senses

## Rating behavior

Endpoint:

- `POST /reviews/senses/{reviewCardId}/rate`

Body:

- `rating`: `again`, `hard`, `good`, or `easy`

Rules:

- The card must belong to the current user and selected language.
- The card must be `target_type=sense`.
- The linked sense must be confirmed.
- The controller explicitly rejects word cards.
- Scheduling still runs through `ReviewCardService::recordReview()`.
- FSRS fields on `review_cards` are updated.
- A `review_logs` row is written with source `sense_review`.
- Word cards are not affected.

## Frontend behavior

- Loads due sense cards.
- Displays lemma, surface form, part of speech, Chinese sense, English sense, aliases, collocations, examples, and current FSRS fields.
- Provides `Again`, `Hard`, `Good`, and `Easy` buttons.
- Submits only the rating to the backend.
- Shows simple summary:
  - due count
  - reviewed count
  - remaining count
- Shows `当前没有到期词义卡。` when no due sense cards exist.

## Verification

- `php artisan migrate --force`
  - Succeeded.
- `php artisan test --filter=WordSense`
  - `33 passed`
  - `150 assertions`
- `php artisan test --filter=ReviewFsrsTest`
  - `5 passed`
  - `92 assertions`
- `php artisan test --filter=FsrsSchedulingServiceTest`
  - `3 passed`
  - `10 assertions`
- `npm run development`
  - Compiled successfully.
  - Existing Sass and Bootstrap deprecation warnings remain.
- Browser check:
  - Opening `/reviews/senses` while unauthenticated redirects to the existing login page.
  - Authenticated route behavior is covered by the feature test and returns 200.

## Current risks

- The Sense Review page is intentionally separate from the old word Review page and does not yet support mixed review sessions.
- Phrase occurrences and phrase cards remain deferred.
- The page is simple and does not include keyboard shortcuts yet.
- Full test suite may still include unrelated legacy Auth/homepage failures from earlier phase notes.

## Next phase suggestion

- Add keyboard shortcuts for Sense Review.
- Add a compact session-complete screen.
- Consider a future combined review mode only after word and sense queues are both stable.
