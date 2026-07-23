# AI Study Card Source Binding Service Implementation Plan

Date: 2026-07-23

Design: `docs/superpowers/specs/2026-07-23-ai-study-card-service-convergence-design.md`

Status: Accepted for Phase 7D implementation

## Frozen slice

Move only V5 confirmed-candidate source occurrence binding, synthetic sentence-id construction, and source-binding status reporting to `AiStudyCardSourceBindingService`.

The coordinator keeps the existing per-item database transaction. It creates/finds the WordSense and ReviewCard, then calls the source-binding owner inside that same transaction. Missing source data continues to create the sense/card without an occurrence.

## Stable internal contract

`bind(WordSense $sense, ?ReviewCard $card, array $candidate): array` returns the existing:

- `occurrence_created`;
- `occurrence_id`;
- `occurrence_reason`;
- `effective_sentence_id`;
- `source_binding_status`.

Explicit and synthetic sentence ids, occurrence identity/update fields, evidence/raw payload, source/type/status, and all human-readable status strings remain exact.

## Files

Allowed:

- `app/Services/AiStudyCardSourceBindingService.php`
- `app/Services/AiStudyCardPendingItemService.php`
- `tests/Feature/AiStudyCardSourceBindingServiceTest.php`
- Phase 7D plan/acceptance and authority documents

Forbidden:

- moving or changing the transaction boundary, WordSense/ReviewCard creation, candidate validation, package/lifecycle behavior, controller/routes/payloads, frontend, provider, models/migrations, ReviewLog, or FSRS;
- changing occurrence source identity, binding semantics, or public result fields;
- adding DTOs, interfaces, dependencies, or adjacent cleanup.

## Verification

1. Direct RED/GREEN tests for explicit id, synthetic id, insufficient source cases, idempotent update, result/status shape, and coordinator ownership.
2. Complete V5 occurrence/hardening/lifecycle suite plus V3/V4 and V6 fail-closed suites.
3. Testing DB health/config, PHP syntax, frontend build, diff/line/owner audit, and scoped five-axis review.
4. No new browser pass is required if the public/UI flow is unchanged and the complete source-binding facade tests remain green.

