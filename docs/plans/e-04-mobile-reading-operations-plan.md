# E-04 Mobile Reading Operations — Implementation Plan

## Status

Accepted under current Goal authorization.

## Goal and non-goals

Connect the existing Mobile offline queue to the accepted Phase B reading-session
boundary. Mobile token lookup interactions and explicit Sense ratings must survive
disconnect/replay, and Mobile Finish must reuse the server-authoritative preflight
and commit calculation for passive Good.

This slice does not add a scheduler, operation ledger, sync engine, background
worker, client-side passive-rating rule, database migration, or offline Finish
guess. It does not change Web Reading/Finish behavior.

## Architecture gate

Risk: high. This slice touches ReadingSession, ReviewLog, Sense FSRS, Mobile API
payloads and the durable offline action queue.

Responsibilities:

- `MobileReadingSessionController` validates the Mobile boundary, delegates session
  start/resume and Finish preflight/commit to the existing services, and keeps the
  standard Mobile envelope/error shape.
- `MobileQueuedActionSyncService` remains the only queued-action dispatcher. It
  adds `reading_session.interaction`; reading explicit ratings remain
  `sense_review.rating` with additive reading context.
- `MobileSenseReviewMutationService` remains the only Mobile rating mutation. With
  complete reading context it uses the accepted `reading_explicit` source and
  records the existing ReadingSession interaction in the same transaction.
- `OfflineRepository` remains the only native durable queue/cache. The downloaded
  chapter package stores the active server ReadingSession projection; it does not
  become scheduling authority.
- Mobile UI queues opened interactions and explicit ratings, flushes them before
  Finish, then presents server preflight before the user can commit.

Data flow:

`Mobile reader -> ChapterPackage + ReadingSession projection -> IndexedDB queued
action -> /sync/actions -> MobileIdempotencyService -> existing ReadingSession or
Mobile rating owner -> ReviewCardService -> FsrsSchedulingService`.

Finish remains:

`Mobile confirmation -> MobileReadingSessionController ->
ReadingFinishSettlementService preflight -> user confirmation -> commit`.

## Frozen additive contracts

- `POST /api/v1/mobile/chapters/{chapter}/reading-sessions`
  accepts optional `resume_reading_session_id` and returns the existing serialized
  ReadingSession catalog in the Mobile envelope.
- `POST /api/v1/mobile/chapters/{chapter}/reading-sessions/{readingSession}/finish`
  accepts `settlement_mode=preflight|commit`. Mobile passes the existing legacy
  chapter-finish options as their no-op values; the server remains authoritative.
- `sense_review.rating` may add all-or-none `reading_session_id` and
  `occurrence_id`; its existing `client_action_id` is also the reading action ID.
- `reading_session.interaction` contains `reading_session_id`,
  `interaction_type=opened|helped`, and `occurrence_id`.

## Allowlist

- `app/Http/Controllers/Mobile/MobileReadingSessionController.php`
- `app/Http/Controllers/Mobile/MobileBootstrapController.php`
- `app/Http/Controllers/Mobile/MobileSenseReviewController.php`
- `app/Services/MobileQueuedActionSyncService.php`
- `app/Services/MobileSenseReviewMutationService.php`
- `app/Services/WordSenseKnownSenseService.php`
- `routes/api.php`
- `mobile/src/{api,types,offlineRepository,ui}.ts`
- focused Mobile/API/Reading tests and guards
- this plan and the Goal ledger

Forbidden: FSRS implementation/parameters, ReviewCard/ReviewLog/Reading schema,
Web Reader/Reviewer/Vuex, native projects, `.env`, credentials, or non-testing data.

## Verification

1. focused backend tests prove queued interaction and reading explicit-rating
   replay do not duplicate ReviewLog/FSRS, and Finish replay creates one completion
   and at most one passive settlement per card;
2. existing M1/M2/M4 rating, operation, Reading settlement/concurrency and Sense
   FSRS regressions remain green;
3. Mobile tests/build and focused static guards pass;
4. testing-bound real browser proves disconnect queue/reconnect/preflight/commit
   without client-side passive writes, followed by exact cleanup.
