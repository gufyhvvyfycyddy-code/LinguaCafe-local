# M7 Android Connected MVP — Implementation Plan

## Status

Accepted / Closed — implementation and the 2026-08-01 Android 12 emulator scope are complete; the exact 2026-08-06 rebuilt debug APK device revalidation is deferred

## Goal and non-goals

Deliver an installable, connected-only Android MVP using the accepted Mobile
API: login/device token, online reading and local dictionary lookup, deliberate
simple WordSense creation, formal Sense Review with undo, a compact daily
summary and a local reminder.

M7 does not persist downloadable packages, queue offline writes, perform
background sync, implement iOS, change FSRS, create a second server authority,
embed the authenticated Web site, or publish to an application store. A debug
APK build is not release APK/AAB, signing, Play Console or store evidence.

## Architecture gate

Risk: high. This slice adds a native client, device credential storage, three
Mobile API seams and formal rating/undo UI.

Data flow:

`Android UI -> typed MobileApiClient -> /api/v1/mobile -> existing server
owner -> MobileApiResponse`.

Formal scoring remains:

`MobileSenseReviewController -> MobileIdempotencyService ->
ReviewCardService::recordReviewWithLog -> FsrsSchedulingService::schedule`.

Manual sense creation remains:

`MobileWordSenseController -> WordSenseService::createManualSense`.

## Allowed files

- additive `mobile/**`;
- additive Mobile lookup/sense/summary controllers and focused serializer if
  required;
- `routes/api.php`;
- `MobileBootstrapController.php` capability flags only;
- focused M7 PHP/TypeScript/guard tests;
- ADR-0044, this plan, Mobile API contract, M7 acceptance and current
  roadmap/index/handoff documents;
- package lockfiles created by official package managers.

## Forbidden files

- existing Web controllers, Web routes, Vuex or Reader/Reviewer state;
- ReviewCard, ReviewLog, FSRS scheduling algorithm or scoring services;
- migrations/database schema;
- M8 offline queue/cache/database/background-sync implementation;
- iOS project, signing, store publication, `.env`, credentials or non-testing
  data.

## API additions

- `GET /api/v1/mobile/dictionary/lookup?term=...`: normalized term and bounded
  definitions from enabled local dictionaries only; no external provider and
  no writes.
- `POST /api/v1/mobile/word-senses`: validated lemma/surface/POS/Chinese
  meaning plus optional chapter/sentence context; delegates to the existing
  service and returns the existing serialized WordSense.
- `GET /api/v1/mobile/summary`: reviewed/introduced today, due-now and
  active-card counts scoped to current user/language.

All use the common envelope, active-device middleware and stable Mobile error
handling.

## Minimal verification

1. TypeScript API/session/state tests and M7 source guard;
2. focused Mobile API Feature tests;
3. production mobile Web build and official Capacitor sync;
4. Gradle debug APK build;
5. protected ReviewFsrs, FsrsSchedulingService and WordSense tests;
6. server-bound testing Android emulator/device acceptance for login, article
   reader, lookup, create, reveal/rate, undo, summary and reminder;
7. verify Android private storage contains ciphertext but not the plaintext
   token;
8. verify no queued/offline rating path exists and clean all testing fixtures;
9. scoped `git diff --check` and fresh adversarial review.

Implementation, official Chrome responsive acceptance, API/client regression,
Capacitor sync, the debug APK build and the booted Android 12 emulator workflow
were completed for the 2026-08-01 accepted artifact scope. The final device
report records login, reading, lookup, creation, rating/undo, summary, reminder,
native transport and Keystore-at-rest evidence.

Repository publication revalidation on 2026-08-06 confirmed the 56 tracked
Android source/config files in `f243a9c`, Mobile Vitest 29/29, production build,
offline Gradle test/assemble, current package/assets, zero sourcemaps and the
current HTTPS/pagination/local-debug safeguards. No device or emulator was
connected for that exact rebuilt APK, so its installation, Instrumentation,
login, native UI and logcat matrix remain deferred. Historical device evidence
must not be relabeled as latest-bundle device evidence.

Evidence: `docs/testing/m7-android-connected-mvp-acceptance-2026-08-01.md`.
