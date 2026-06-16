# FSRS review

LinguaCafe now stores review scheduling data in `review_cards` and `review_logs`.
The first FSRS phase supports word review cards only.

## Data model

- `review_cards.target_type` and `review_cards.target_id` identify the reviewed item.
- Phase one writes `target_type = word`.
- The same shape is reserved for future `sense` and `phrase` cards.
- FSRS scheduling fields are not stored on `encountered_words`.
- Disabled cards, ignored words, and words that are no longer learning items are excluded from the review queue.

## Initializing existing vocabulary

Run this after migrating an existing installation:

```bash
php artisan reviews:initialize-cards --dry-run
php artisan reviews:initialize-cards
```

Optional filters:

```bash
php artisan reviews:initialize-cards --user_id=1
php artisan reviews:initialize-cards --language=japanese
```

## FSRS runtime

Production FSRS scheduling requires the `fsrs-rs-php` extension.
Install and enable the extension from:

https://github.com/open-spaced-repetition/fsrs-rs-php

The service layer owns all scheduling calls, so controllers and Vue components only pass ratings:
`again`, `hard`, `good`, or `easy`.

Tests can use the internal fallback by running under `APP_ENV=testing`. For local development without the extension, set `FSRS_ALLOW_INTERNAL_FALLBACK=true` only when you explicitly want non-production scheduling behavior.

## Next extension points

- `App\Services\ReviewCardService::ensureWordCard()` is the word-card creation path.
- `App\Services\ReviewService::getReviewItems()` is the due-card query path.
- `App\Services\ReviewCardService::recordReview()` is the rating/logging path.
- Add future sense-level or phrase-level review by adding target resolvers beside the existing word path.
