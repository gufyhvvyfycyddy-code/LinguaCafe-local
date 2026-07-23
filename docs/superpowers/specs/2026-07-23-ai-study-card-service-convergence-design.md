# AI Study Card Service Convergence Design

Date: 2026-07-23

Status: Accepted for Phase 7 staged implementation under goal-mode preauthorization

## Goal

Reduce the 1,065-line `AiStudyCardPendingItemService` into a compatibility coordinator with one established owner for each roadmap responsibility, while preserving every V1–V6 endpoint, payload, message, authorization/isolation rule, transaction, pending-item lifecycle, source binding, card-generation result, and safety flag.

Real external provider activation is not part of service convergence. Existing provider-preview transport and explicit UI trigger remain unchanged and fail closed according to ADR-0004/0005.

## Current owners and remaining concentration

Already separate:

- V6 prompt, schema, request package, response parsing, security policy, provider adapter, transport, and preview orchestration.
- HTTP validation/serialization in `AiStudyCardPendingItemController`.
- WordSense/ReviewCard creation primitives in `WordSenseService`.

Still concentrated in `AiStudyCardPendingItemService`:

1. Pending lifecycle: create/reuse/list/dismiss/restore/mark processed.
2. Preview and final package construction.
3. Candidate normalization, validation, and deduplication.
4. Confirmed card-generation orchestration.
5. Source occurrence binding and status reporting.

## Ordered slices

Each slice moves one responsibility only and keeps the public coordinator methods:

1. `AiStudyCardPendingLifecycleService`
2. `AiStudyCardCandidatePackageService`
3. `AiStudyCardCandidateValidationService`
4. `AiStudyCardSourceBindingService`
5. `AiStudyCardGenerationService`

The order keeps low-coupling lifecycle/package rules first and leaves the transaction-heavy generation seam until its validation and source collaborators exist.

## Stable public boundary

- Controller constructor and routes do not change.
- `AiStudyCardPendingItemService` keeps all public method names and return arrays.
- Frontend endpoints, request fields, response fields/messages, and V1–V6 user flow remain exact.
- User/language/chapter/pending-item isolation remains in the extracted owner responsible for that query.
- `generateCardsFromConfirmedCandidates()` remains the only public generation facade.

## Scope and forbidden changes

Allowed:

- new focused services under `app/Services`;
- thin delegation inside `AiStudyCardPendingItemService`;
- direct characterization tests plus existing V1–V6 suites;
- Phase 7 design/plan/acceptance and authority updates.

Forbidden:

- route/controller/request/payload changes;
- schema, migration, model fillable/status, WordSense binding, ReviewCard identity/lifecycle, ReviewLog, or FSRS changes;
- auto-selecting AI recommendations or copying reason into `sense_zh`;
- provider, model, key, timeout, cost, outbound host, or default-enabled changes;
- new packages, repositories, DTOs, generic interfaces, or adjacent frontend refactors.

## Architecture gate

This is a high-risk AI/WordSense/ReviewCard area. Goal-mode authorization applies only slice by slice after this review freezes one seam. No slice changes public/data semantics or crosses a second seam, so no new ADR is required.

Fresh-context adversarial findings:

- Moving all five responsibilities at once would make regressions hard to localize: rejected.
- Replacing the coordinator/controller contract would change public seams: rejected.
- Injecting all collaborators through the existing public constructor would change test/container assumptions: rejected; collaborators are private implementation details during convergence.
- Moving `WordSenseService` primitives or formal rating/FSRS code into AI services would violate canonical ownership: rejected.
- Treating existing V6 provider-preview completion as permission for new provider/model/key changes would bypass the environment gate: rejected.

## Verification

For every slice:

- direct RED/GREEN tests for the new owner;
- unchanged focused legacy/facade suite;
- V5 lifecycle/generation and V6 provider safety regression;
- testing DB health/config;
- no protected write beyond the exact behavior already exercised by the generation tests;
- frontend build and official Browser acceptance when a visible workflow boundary is touched;
- source guard, diff check, line/owner audit, and scoped five-axis review.

Phase 7 service convergence closes only when all five responsibilities have owners and the coordinator retains no moved implementation body. The provider environment gate is audited separately and may close as “existing fail-closed implementation satisfies the gate” without any real external request.
