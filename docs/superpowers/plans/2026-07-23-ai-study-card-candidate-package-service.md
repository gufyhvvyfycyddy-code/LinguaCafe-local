# AI Study Card Candidate Package Service Implementation Plan

Date: 2026-07-23

Design: `docs/superpowers/specs/2026-07-23-ai-study-card-service-convergence-design.md`

Status: Accepted for Phase 7B implementation

## Frozen slice

Move only V3 preview-package and V4 final-candidates-package construction to `AiStudyCardCandidatePackageService`, including package-specific AI recommendation normalization and deduplication.

The coordinator keeps both public methods and delegates them unchanged. Generation-time candidate/package validation, WordSense/ReviewCard creation, source binding, and lifecycle remain outside this slice.

## Files

Allowed:

- `app/Services/AiStudyCardCandidatePackageService.php`
- `app/Services/AiStudyCardPendingItemService.php`
- `tests/Feature/AiStudyCardCandidatePackageServiceTest.php`
- Phase 7B plan/acceptance and authority documents

Forbidden:

- controller, route, payload, model, migration, frontend, provider, generation, lifecycle, WordSense, ReviewCard, ReviewLog, FSRS, or source-binding changes.

## Verification

1. Direct RED/GREEN tests for preview shape/isolation and final package normalization/dedupe.
2. Source guard proving both coordinator methods delegate and retain no V3/V4 package literals.
3. Existing PendingItem V3/V4/V5 tests plus V6 fail-closed matrix.
4. Syntax, build, diff, line/owner, and scoped five-axis review.

No new browser pass is required if this slice changes no visible/API behavior and the unchanged full V3/V4 facade tests pass; Phase 7A already accepted the visible pending workflow.
