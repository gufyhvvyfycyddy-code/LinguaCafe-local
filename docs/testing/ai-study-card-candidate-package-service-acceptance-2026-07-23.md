# AI Study Card Candidate Package Service Acceptance

Date: 2026-07-23

Status: **Accepted / Production Closed**

Implementation: `8f2b078` (`refactor: extract ai candidate packages`)

## Closed responsibility

`AiStudyCardCandidatePackageService` now owns the existing V3 preview package and V4 final-candidates package construction, including package-local normalization and deduplication.

`AiStudyCardPendingItemService` retains the two public compatibility methods as thin delegates. It no longer contains either package schema literal or the package-specific dedupe helper. The coordinator fell from 784 to 487 lines; the new owner is 255 lines.

No controller, route, payload, model, migration, frontend, provider, generation, lifecycle, source-binding, WordSense, ReviewCard, ReviewLog, or FSRS behavior changed.

## Verification

- RED: the new direct test failed only because `AiStudyCardCandidatePackageService` did not yet exist.
- GREEN: `AiStudyCardCandidatePackageServiceTest` passed 3 tests / 24 assertions.
- Protected matrix passed 195 tests / 1,519 assertions:
  - testing-database health and bootstrap guards;
  - candidate-package direct tests;
  - the complete pending-item V1–V5 facade suite;
  - pending lifecycle;
  - all V6 fail-closed/provider-boundary suites.
- `php -l` passed for both changed services.
- `npm run development` compiled successfully in 6.54 seconds.
- `git diff --check` passed for the exact implementation scope.

The scoped five-axis review found no required or critical issue:

- correctness: existing package shapes, limits, isolation, normalization, dedupe summary, messages, and public facade remain covered;
- readability: package construction has one named owner and the coordinator exposes only delegation;
- architecture: this removes one responsibility from the hotspot without changing seams;
- security: parameterized Eloquent queries retain user/language/pending isolation and no secret or external flow was added;
- performance: the existing bounded limits of 100 pending items and 200 AI recommendations remain unchanged, with no additional query or loop.

## Browser decision

No new browser pass was required. This slice changes no visible or HTTP behavior, the unchanged V3/V4 facade suite passed, and Phase 7A already accepted the visible pending workflow in the official Browser. This follows the accepted Phase 7B plan rather than substituting source inspection for a changed UI.

## Next slice

Phase 7C moves generation-time candidate/package validation and deduplication only. Source binding, WordSense/ReviewCard creation, lifecycle, provider behavior, public payloads, and FSRS remain outside that slice.
