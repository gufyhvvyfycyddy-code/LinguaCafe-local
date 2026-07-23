# AI Study Card Candidate Validation Service Implementation Plan

Date: 2026-07-23

Design: `docs/superpowers/specs/2026-07-23-ai-study-card-service-convergence-design.md`

Status: Accepted for Phase 7C implementation

## Frozen slice

Move only generation-time candidate/package normalization, reverse validation, isolation queries, and skipped-result construction to `AiStudyCardCandidateValidationService`.

The new owner prepares the V4 package membership context and validates one confirmed V5 candidate at a time. `AiStudyCardPendingItemService::generateCardsFromConfirmedCandidates()` keeps the public method, result arrays, transaction, WordSense/ReviewCard creation, source occurrence binding, lifecycle transition, exception handling, summary, messages, and safety flags.

This is internal responsibility convergence. It does not change the controller, route, request fields, response fields, model, database, or user flow.

## Stable internal contract

- `prepare(User $user, array $confirmedItems, array $finalCandidatesPackage): array`
  - preserves the 50-item rejection;
  - builds package membership keys;
  - loads only current-user/current-language/pending items and current-user/current-language chapters;
  - returns an internal validation context without writes.
- `validate(array $confirmedItem, array $context): array`
  - normalizes the existing candidate fields;
  - returns either the normalized candidate or the exact existing skipped-result payload/reason.

The compatibility coordinator constructs this collaborator privately so its existing public constructor remains unchanged.

## Files

Allowed:

- `app/Services/AiStudyCardCandidateValidationService.php`
- `app/Services/AiStudyCardPendingItemService.php`
- `tests/Feature/AiStudyCardCandidateValidationServiceTest.php`
- Phase 7C plan/acceptance and authority documents

Forbidden:

- controller, route, public payload, frontend, provider, model, migration, package-construction, source-binding, lifecycle, WordSense, ReviewCard, ReviewLog, FSRS, or transaction semantic changes;
- moving card creation or occurrence writes in this slice;
- adding DTOs, interfaces, dependencies, or a second architectural seam.

## Verification

1. Direct RED/GREEN tests for batch limit, normalization, package membership, user/language/pending/chapter isolation, skipped reasons, and coordinator delegation.
2. Existing V5 generation/hardening/lifecycle tests plus V3/V4 and V6 fail-closed suites.
3. Testing DB health/config, PHP syntax, frontend build, diff/line/owner audit, and scoped five-axis review.
4. No new browser pass is required if public/UI behavior is unchanged and the complete V5 facade suite remains green; Phase 7A already accepted the visible workflow.

