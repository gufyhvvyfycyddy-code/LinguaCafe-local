# AI Study Card Generation Service — Browser Acceptance

Date: 2026-07-23

Status: **Accepted / Production Closed**

Implementation: `7cf7de9` (`refactor: extract ai card generation`)

## Closed responsibility

`AiStudyCardGenerationService` now owns confirmed-candidate generation orchestration: validation preparation, per-item transaction, WordSense confirm/create, sense ReviewCard creation, source binding, pending lifecycle transition, created/skipped/duplicate/failed classification, summary, messages, and safety flags.

`AiStudyCardPendingItemService::generateCardsFromConfirmedCandidates()` remains the stable public compatibility method and is now a single delegation. Its public constructor, controllers, routes, request/response payloads, frontend workflow, provider boundary, models, ReviewLog, FSRS, and transaction semantics are unchanged.

The coordinator fell from 1,065 lines before Phase 7 to 61 lines. Lifecycle, preview/final packages, validation, source binding, and generation now each have one named owner.

The required verification matrix exposed four stale source-owner assertions and a pre-Phase-7E WordSense fallback reflection helper that still pointed at the former Phase 6M owner. Those harness references were aligned with the already accepted owners; no production behavior changed.

## Automated verification

- Direct generation suite: 4 tests / 36 assertions.
- Extracted-owner and fallback alignment suite: 18 tests / 118 assertions on the final rerun.
- Full required Phase 7E protected matrix: 1,093 tests / 6,124 assertions in 46.50 seconds.
- The matrix covered testing DB health/config, the complete AI Study Card V1–V6/fail-closed chain, WordSense, Sense Review, Review FSRS, scheduling, token payload, and source context.
- `npm run development` compiled successfully in 6.21 seconds.
- PHP syntax, exact-scope `git diff --check`, owner searches, and line audit passed.

The scoped five-axis review found no critical or required issue:

- correctness: the prior generation body moved intact and direct tests lock created, skipped, duplicate/idempotent, source, lifecycle, summary, and safety results;
- readability: the compatibility coordinator is now a small facade and generation has a named owner;
- architecture: all five accepted Phase 7 responsibilities have owners without a new public constructor or container binding;
- security: user/language/chapter and final-package validation still precede writes, provider behavior remains disabled/fail-closed, and no secret or external request was introduced;
- performance: the same per-item transaction and query pattern remain, with no new loop or external call.

## Official Browser acceptance

The official OpenAI Browser plugin completed one real, authenticated, user-confirmed flow against `linguacafe_fsrs_test`:

1. Opened the isolated English chapter and clicked `landscape`.
2. Opened “待 AI 解释列表” and the one pending item.
3. Built the preview package and final candidates package without calling a provider.
4. Opened “生成学习卡”, entered the required Chinese meaning and optional English explanation, and explicitly confirmed one card.
5. The UI reported one created, zero skipped, zero duplicate, and zero failed; it displayed “来源已绑定（合成 sentence_id）” and moved the item to “已处理 (1)”.

The protected-write delta after the browser action was:

- one confirmed WordSense;
- one `target_type=sense` ReviewCard in `fsrs_state=new`, `fsrs_reps=0`;
- one bound WordSenseOccurrence with synthetic sentence id `ai-study-card:308:0:0:landscape`;
- zero ReviewLog rows;
- pending status `processed`.

The Browser-owned tab was closed and the tab list returned to the empty pre-test baseline. The local testing server was stopped. The isolated fixture user and every row scoped to that user were deleted; final user, pending, sense, card, chapter, and book counts were all zero.

The initial login attempt also proved an environment detail: `APP_ENV=testing` selects the testing DB, while the inherited testing session driver is `array`. Browser acceptance therefore used the same testing DB with `SESSION_DRIVER=file`; no repository configuration was changed.

## Phase result

Phase 7 AI Study Card service convergence is **Accepted / Production Closed**. The separate real-provider Environment Gate remains fail-closed and requires its own audit; this closure grants no provider, secret, model, cost, or external-send authorization.
