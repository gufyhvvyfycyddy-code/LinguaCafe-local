# AI Study Card Generation Service Implementation Plan

Date: 2026-07-23

Design: `docs/superpowers/specs/2026-07-23-ai-study-card-service-convergence-design.md`

Status: Accepted for Phase 7E implementation

## Frozen slice

Move the remaining confirmed-card generation orchestration intact from `AiStudyCardPendingItemService` to `AiStudyCardGenerationService`.

The new owner composes the accepted validation, source-binding, lifecycle, and `WordSenseService` collaborators. It retains the same per-item transaction, create/confirm/card sequence, created/skipped/duplicate/failed classification, lifecycle transition, summary, messages, and safety flags.

`AiStudyCardPendingItemService::generateCardsFromConfirmedCandidates()` remains the public compatibility method and becomes a thin delegate. Controller construction and all endpoints/payloads remain unchanged.

## Dependency wiring

The coordinator's existing public constructor continues to accept only `WordSenseService`. It privately constructs:

- one shared pending lifecycle owner;
- candidate package owner;
- candidate validation owner;
- source binding owner;
- generation owner composed from the same lifecycle, validation, source, and WordSense collaborators.

No container binding or public constructor assumption changes.

## Files

Allowed:

- `app/Services/AiStudyCardGenerationService.php`
- `app/Services/AiStudyCardPendingItemService.php`
- `tests/Feature/AiStudyCardGenerationServiceTest.php`
- Phase 7E plan/acceptance and authority documents

Forbidden:

- controller, route, public request/response, frontend, provider, model, migration, package, validation, source, lifecycle, WordSense/ReviewCard identity, ReviewLog, FSRS, or transaction semantic changes;
- new DTOs, interfaces, dependencies, or adjacent cleanup.

## Verification

1. Direct RED/GREEN tests for created, skipped, duplicate/idempotent, lifecycle/source results, exact safety/summary, and coordinator delegation.
2. Complete V1–V6 pending/generation/hardening/lifecycle/provider fail-closed matrix.
3. Protected WordSense, Review FSRS, scheduling, Sense, and source-context suites because the moved owner writes WordSense/ReviewCard/occurrence data.
4. Testing DB health/config, PHP syntax, frontend build, diff/line/owner audit, and scoped five-axis review.
5. Official Browser acceptance of one unchanged user-confirmed generation flow after automation passes, followed by fixture cleanup and protected-write delta verification.

