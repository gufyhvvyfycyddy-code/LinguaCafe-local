# AI Study Card Pending Lifecycle Service Implementation Plan

Date: 2026-07-23

Design: `docs/superpowers/specs/2026-07-23-ai-study-card-service-convergence-design.md`

## Frozen slice

Move only pending-item lifecycle behavior to `AiStudyCardPendingLifecycleService`:

- create or reuse pending;
- list/filter pending, dismissed, processed, or all;
- dismiss and restore;
- normalize the lifecycle key;
- mark a current pending item processed;
- return empty lifecycle metadata for non-pending sources.

`AiStudyCardPendingItemService` keeps the same public facade methods and generation response assembly.

## Files

Allowed:

- `app/Services/AiStudyCardPendingLifecycleService.php`
- `app/Services/AiStudyCardPendingItemService.php`
- `tests/Feature/AiStudyCardPendingLifecycleServiceTest.php`
- design/plan/acceptance and authority documents

Forbidden:

- controller, route, model, migration, frontend, provider, package, validation, card-generation, source-binding, WordSense, ReviewCard, ReviewLog, or FSRS changes.

## Steps

1. Add direct lifecycle characterization and facade/source guard tests.
2. Run RED because the lifecycle service does not exist.
3. Copy the exact lifecycle queries/messages/status transitions into the new owner.
4. Delegate the four public facade methods and the two generation lifecycle helpers.
5. Remove only the moved implementation bodies/imports.
6. Run focused and protected regression suites, build, diff checks, and owner review.

## Success

- Lifecycle behavior has one owner.
- User/language/chapter isolation, unique-conflict recovery, idempotence, processed metadata, and failure fallback remain exact.
- Existing controller and generation responses are unchanged.

## Failure

Any changed query scope, status transition, deletion fallback, message/status code, response field, transaction/generation behavior, public method, or protected learning-data behavior.
