# AI Study Card Candidate Validation Service Acceptance

Date: 2026-07-23

Status: **Accepted / Production Closed**

Implementation: `c76c0fe` (`refactor: extract ai candidate validation`)

## Closed responsibility

`AiStudyCardCandidateValidationService` now owns generation-time batch limits, candidate normalization, V4 package reverse validation, pending-item and chapter isolation queries, package-key comparison, and skipped-result construction.

`AiStudyCardPendingItemService::generateCardsFromConfirmedCandidates()` retains the public boundary and delegates preparation and per-candidate validation. Its transaction, WordSense/ReviewCard creation, occurrence binding, lifecycle transition, exception result, summary, message, and safety flags are unchanged. The coordinator fell from 487 to 334 lines; the new owner is 219 lines.

No controller, route, public payload, frontend, provider, model, migration, package-construction, source-binding, lifecycle, ReviewLog, FSRS, or transaction semantic changed.

## Verification

- RED: 4 direct tests failed only because the validation service did not yet exist.
- GREEN: `AiStudyCardCandidateValidationServiceTest` passed 4 tests / 26 assertions.
- Protected matrix passed 199 tests / 1,545 assertions:
  - testing-database health/config;
  - package and validation direct-owner suites;
  - complete pending-item V1–V5 facade and hardening suite;
  - pending lifecycle;
  - all V6 fail-closed/provider-boundary suites.
- `php -l` passed for both changed services.
- `npm run development` compiled successfully in 6.57 seconds.
- Exact-scope `git diff --check`, source-owner search, and line audit passed.

The scoped five-axis review found no critical or required issue:

- correctness: validation order, normalization, rejection reasons, 50-item limit, and result payloads remain covered;
- readability: validation now has one owner while the coordinator reads as orchestration;
- architecture: one seam moved; transaction, source binding, lifecycle, and creation stayed in place;
- security: current user/language/pending and chapter isolation remain parameterized owner queries;
- performance: the same two bounded preparation queries and per-candidate validation loops remain.

## Browser decision

No new browser pass was required because the public HTTP/UI behavior did not change and the complete V5 facade suite passed. Phase 7A remains the official Browser acceptance for the unchanged visible workflow.

## Next slice

Phase 7D moves source-occurrence binding and source-binding status reporting only. Candidate validation, card creation, lifecycle, provider behavior, public payloads, and FSRS remain outside that slice.
