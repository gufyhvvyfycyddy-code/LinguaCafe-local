# ADR-0032: AI Pending Lifecycle Ownership and Provider Gate

> **Status**: Accepted service convergence / live provider deliberately disabled
> **Date**: 2026-07-17

## Context

`AiStudyCardPendingItemService` mixed package generation, candidate normalization, learning-card generation, source binding, and pending-item state transitions. The V6 provider path already existed behind an explicit browser action and a disabled-by-default backend boundary, but a real provider run still requires product and environment decisions that are not present in the repository.

## Decision

1. `AiStudyCardPendingLifecycleService` is the single owner for dismiss, restore, processed-state transition, and empty lifecycle metadata.
2. `AiStudyCardPendingPackageService` is the single owner for preview/final package construction, candidate normalization, and deduplication.
3. `AiStudyCardGenerationService` is the single owner for confirmed WordSense/sense ReviewCard generation and source-occurrence binding; it calls the lifecycle owner only after a confirmed user-selected item is created or found as a duplicate.
4. `AiStudyCardPendingItemService` remains the compatibility facade and delegates these responsibilities; routes and payloads do not change.
5. Every lifecycle transition remains scoped by authenticated user, active language, and expected current status, and fails closed when the item is outside that scope.
6. V6 request-package generation is read-only. A provider attempt requires the explicit local UI action and goes only through the local backend route.
7. The V6 request package exposes a read-only external-request preflight: provider/model, item count, external fields, timeout, cost ceiling, estimated-cost availability, secret-source category, failure policy, and blocking reasons. It never exposes a secret or secret reference.
8. The user explicitly chose to keep the live provider disabled on 2026-07-17. Provider/model/pricing/timeout/secret decisions and a real external request are deferred until a future explicit authorization; this is a deliberate product decision, not an open acceptance failure.
9. AI recommendations remain unchecked, AI reason is not a Chinese definition, and card creation continues through the existing manual V5 confirmation boundary.

## Evidence

- Architecture guards: `tests/js/AiStudyCardPendingLifecycleArchitectureGuard.test.mjs`, `tests/js/AiStudyCardPendingPackageArchitectureGuard.test.mjs`, and `tests/js/AiStudyCardGenerationArchitectureGuard.test.mjs`.
- Lifecycle behavior: `tests/Feature/AiStudyCardPendingLifecycleTest.php`.
- Full AI/Review regression suites and browser evidence, including the blocked preflight: `docs/testing/ai-study-card-service-convergence-browser-acceptance-2026-07-17.md`.

## Consequences

- Lifecycle, package/candidate preparation, and confirmed-card/source-binding behavior now have focused owners. The compatibility facade is 195 lines and no routes, payloads, or schema were added.
- The provider adapter and transport remain implemented but disabled; code presence is not production authorization.
- Phase 7 is accepted for the authorized disabled-provider scope. A future live-provider task must reopen this ADR with explicit provider/pricing/security decisions and controlled network/cost acceptance.
