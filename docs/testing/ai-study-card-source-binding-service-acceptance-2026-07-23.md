# AI Study Card Source Binding Service Acceptance

Date: 2026-07-23

Status: **Accepted / Production Closed**

Implementation: `8f2a70c` (`refactor: extract ai source binding`)

## Closed responsibility

`AiStudyCardSourceBindingService` now owns confirmed-candidate occurrence binding, explicit/synthetic sentence ids, occurrence identity/update payloads, missing-source decisions, and source-binding status strings.

The coordinator still owns the per-item transaction and calls the new source owner after WordSense/ReviewCard creation inside that transaction. Missing source data still permits sense/card creation without an occurrence. The coordinator fell from 334 to 243 lines; the new owner is 101 lines.

No candidate validation, WordSense/ReviewCard creation, lifecycle, controller, route, public payload, frontend, provider, model, migration, ReviewLog, or FSRS semantic changed.

## Verification

- RED: 4 direct tests failed only because `AiStudyCardSourceBindingService` did not yet exist.
- GREEN: direct suite passed 4 tests / 26 assertions.
- Protected matrix passed 203 tests / 1,571 assertions, including the complete V5 source-binding/hardening/lifecycle facade and all V6 fail-closed suites.
- Explicit sentence id, exact synthetic id, three missing-source reasons, occurrence payload, idempotent update, status strings, and coordinator delegation are directly locked.
- `php -l` passed for both changed services.
- `npm run development` compiled successfully in 6.57 seconds.
- Exact-scope `git diff --check`, source-owner search, and line audit passed.

The scoped five-axis review found no critical or required issue:

- correctness: every prior occurrence branch and result field remains covered;
- readability: source binding is a small named operation inside the unchanged transaction;
- architecture: only the source-binding seam moved;
- security: user/language/chapter isolation still occurs before the service call, and occurrence ownership derives from the validated sense;
- performance: the same single idempotent `updateOrCreate` remains, with no added query loop.

## Browser decision

No new browser pass was required because no visible or HTTP behavior changed and the complete V5 facade suite remained green.

## Next slice

Phase 7E moves confirmed card-generation orchestration to `AiStudyCardGenerationService`, leaving `AiStudyCardPendingItemService` as the stable public compatibility coordinator.
