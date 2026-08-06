# M3 Mobile Download Packages V1 — Implementation Plan

## Status

Accepted / Closed

Acceptance evidence:
`docs/testing/m3-mobile-download-packages-acceptance-2026-07-29.md`

## Goal and non-goals

Deliver bounded, checksummed article and short-term Sense review packages
through the existing authenticated Mobile API. Generation is read-only.

M3 does not implement a native client database, background download, queued
offline actions, offline rating upload, full collection sync, media download,
FSRS changes or a new content authority.

## Architecture gate

Risk: high. This slice adds public mobile endpoints and touches article,
WordSense, ReviewCard and FSRS read seams.

Responsibilities:

- `MobileArticlePackageService`: scope Books/Chapters, decode bounded processed
  text, derive stable identities, translations, sense summaries and canonical
  checksums.
- `MobileReviewPackageService`: freeze the UTC snapshot cursor, select existing
  eligible Sense cards and project the shared batch serializer into a package
  read model.
- mobile controllers: validate transport inputs, translate domain failures to
  the common envelope and never perform domain queries/writes directly.
- `MobileBootstrapController`: advertise only accepted capabilities.

Seams and data flow:

`auth:sanctum + mobile.device -> controller -> package service -> existing
Book/Chapter/AI assist/WordSenseOccurrence or SenseReview eligibility queries
-> MobileApiResponse`.

The services have no write dependency. Review-package generation never calls
`ReviewCardService`, `FsrsSchedulingService` or the operation ledger.

## Allowed files

- `app/Http/Controllers/Mobile/MobileArticlePackageController.php`
- `app/Http/Controllers/Mobile/MobileReviewPackageController.php`
- `app/Http/Controllers/Mobile/MobileBootstrapController.php`
- `app/Services/MobileArticlePackageService.php`
- `app/Services/MobileReviewPackageService.php`
- `app/Services/InvalidMobilePackageCursorException.php`
- `app/Services/InvalidMobilePackageSourceException.php`
- `routes/api.php`
- `tests/Feature/M3MobileDownloadPackageTest.php`
- M3 contract/plan/acceptance and current roadmap/index/handoff documents

## Forbidden files

- migrations and existing Book/Chapter/WordSense/ReviewCard/ReviewLog models;
- FSRS scheduling and formal rating services;
- Web Reader/Reviewer components;
- M4 queued-action or conflict implementation;
- `.env`, credentials, development/production data and unrelated dirty files.

## Frozen API contract

- `GET /api/v1/mobile/article-packages?page=1&per_page=20`
- `GET /api/v1/mobile/article-packages/{book}?chapter_page=1&chapters_per_page=50`
- `GET /api/v1/mobile/article-packages/{book}/chapters/{chapter}?cursor=...&token_limit=500`
- `GET /api/v1/mobile/review-packages/short-term?horizon_days=7&limit=50&cursor=...`

All responses use `MobileApiResponse`. Article and review package schema version
is `mobile_download_package_v1`. Cursor values are opaque and versioned.
Article content uses deterministic SHA-256 versions. Review continuation
preserves `as_of`, horizon and package version.

## Compatibility and failure rules

- selected-language scoping applies independently to Book, Chapter,
  WordSenseOccurrence, WordSense and ReviewCard;
- foreign and wrongly nested resources return the same not-found envelope;
- invalid cursors/limits return `422`;
- corrupt or expanded-over-limit processed text returns a safe source-invalid
  error without leaking compressed content;
- article version mismatch means full affected-package re-download;
- bootstrap keeps `offline_queue=false`.

## Minimal verification

Success requires:

1. focused M3 Feature tests, including 5,000-token and 250-card fixtures;
2. zero writes across package generation;
3. deterministic checksums and invalidation;
4. bounded response size and correct cursors;
5. Mobile API foundation/ledger plus Review FSRS, scheduler and WordSense
   protected tests;
6. PHP lint, route inspection, documentation guards and `git diff --check`;
7. fresh-context adversarial review with all behavior/security findings closed.

Failure is any cross-user/language leak, payload beyond the bound, unstable
continuation identity, package-generation write, unsupported source corruption,
or regression in protected rating/scheduling paths.
