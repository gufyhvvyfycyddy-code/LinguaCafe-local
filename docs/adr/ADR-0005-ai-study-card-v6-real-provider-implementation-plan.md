# ADR-0005: AI Study Card V6 Real Provider Implementation Plan

## Status

Accepted as a **planning and approval gate**. This ADR does not implement a live provider, does not add a provider route, does not add a UI trigger, does not add a secret, and does not authorize external requests.

## Date

2026-07-07

## Context

AI Study Card V6 has already established three preconditions:

1. `ADR-0004-ai-study-card-v6-real-ai-boundary.md` freezes the rule that AI may recommend candidates but may not create study cards.
2. `ai-study-card-v6-preflight-plan.md` defines V6 request/recommendation packages and staged rollout.
3. `ai-study-card-v6-provider-security-plan.md` implements disabled-by-default provider config, provider security policy inspection, and fail-closed tests.

The next risky step would be a real provider adapter. That step must not be started by casually adding a URL, a secret variable, a route, or a button. This ADR defines the future implementation plan and approval checklist.

## Decision

The first real-provider implementation, if approved later, must be a single backend-only adapter behind the existing V6 provider interface.

The provider candidate for the first live adapter is:

**A single OpenAI-compatible chat-completions provider adapter, configured for one user-approved provider only.**

This project must not implement multiple live providers in the same task. If the chosen provider changes, update this ADR first.

## Non-goals

This ADR does not permit:

- live external provider calls
- API keys or secret values
- `.env` changes
- frontend provider calls
- page-load provider calls
- token-click provider calls
- automatic card creation
- automatic `sense_zh` creation
- `ReviewLog` writes
- FSRS changes
- legacy word cards
- background jobs
- mobile / BottomSheet V6

## Future route boundary

A future live-provider task may add exactly one backend route:

`POST /ai-study-card/v6/recommendations/provider-preview`

This route must:

1. Require authentication.
2. Accept only a validated `ai-study-card-v6-request-package-v1` package.
3. Reject requests unless the provider security policy reports real-provider preconditions are met.
4. Call only `AiStudyCardV6RecommendationService`.
5. Return only `ai-study-card-v6-recommendation-package-v1` or a fail-closed error package.
6. Never create `WordSense`, `ReviewCard`, `ReviewLog`, FSRS changes, or legacy word cards.
7. Never be called by page load or token click.

Forbidden route shortcuts:

- Do not put live provider calls into `/ai-study-card/v6/recommendations/request-package`.
- Do not put live provider calls into `/ai-study-card/pending-items/preview-package`.
- Do not put live provider calls into `/ai-study-card/pending-items/final-candidates-package`.
- Do not put live provider calls into `/ai-study-card/generate-cards`.
- Do not put live provider calls into `/senses/inline-preview`.

## Future adapter boundary

A future live adapter must implement `AiStudyCardV6ProviderInterface` and must be backend-only.

The adapter must:

- read provider settings only through an approved config/policy path
- use a hard timeout
- use no background retry by default
- fail closed on timeout, quota failure, network failure, malformed JSON, schema mismatch, and provider refusal
- return raw provider output only to the schema validator, never directly to a Controller or Vue component
- never log raw prompts, raw responses, source text, request headers, authorization headers, or secret references
- never mutate learning data

## Secret storage decision

Secret storage is not implemented by this ADR.

Before a live adapter is implemented, a separate approved task must decide and document exactly where the secret reference lives and how the value is supplied at runtime.

Minimum requirements:

1. No secret value in Git.
2. No secret value in Vue or JavaScript bundles.
3. No secret value in database rows.
4. No secret value in logs.
5. No secret value in response payloads.
6. No secret value in browser Network payloads.
7. No secret value in screenshots.
8. No task may read or modify `.env` unless the user explicitly approves that exact action.

## Prompt / payload boundary

The live adapter must build a small provider prompt from `ai-study-card-v6-request-package-v1`.

The prompt must instruct the provider to return only:

`ai-study-card-v6-recommendation-package-v1`

The prompt must not ask the provider to create cards, rate reviews, alter FSRS, or write final meanings.

The provider output must be treated as untrusted until `AiStudyCardV6RecommendationSchemaService` validates it.

## UI boundary

The first live-provider UI task may add a dedicated user-triggered button such as:

`调用 AI 推荐候选词（需要确认）`

The UI must also show:

- this may call an external provider
- suggestions are not study cards
- suggestions default unchecked
- AI reason is reference text, not final meaning
- final card creation still requires user confirmation

The UI must not call the live provider on page load, token click, opening the pending list, or opening the preview dialog.

## Browser Network validation requirement

A future live-provider UI task must pass real browser Network validation before Accept.

The validation must prove:

1. Page load triggers no provider request.
2. Token click triggers no provider request.
3. Opening the pending list triggers no provider request.
4. Opening the preview dialog triggers no provider request.
5. Only the explicit live-provider button triggers the provider-preview route.
6. Browser Network does not expose secret values.
7. Browser Network does not call provider domains directly from frontend code.
8. The only browser-visible request is to the local backend route.
9. Provider failure shows a fail-closed UI message and creates no learning data.
10. Successful provider recommendation still defaults unchecked and requires V5 confirmation.

## Test requirements for the future live-provider task

A future live-provider task must include tests proving:

1. The live provider route requires auth.
2. The route rejects if security preconditions are not met.
3. The route rejects malformed request packages.
4. The route fails closed on disabled provider.
5. The route fails closed on timeout/network/quota/malformed JSON/provider exception.
6. The route does not create `WordSense`.
7. The route does not create `ReviewCard`.
8. The route does not create `ReviewLog`.
9. The route does not change FSRS fields.
10. The route does not create legacy word cards.
11. Valid provider output still returns unchecked suggestions only.
12. AI reason is not copied into `sense_zh`.
13. V5 generation remains the only card creation path.
14. No provider key material appears in frontend files.
15. No provider key material appears in response payloads.

## Consequences

- The project can now prepare a live-provider implementation task without ambiguity.
- The implementation still cannot start until provider choice, secret storage, timeout, failure behavior, and browser Network validation are explicitly approved.
- V5 card generation remains the only card creation path.
- V1-V5 remain the only card creation path.
- V6 remains recommendation-only until the user confirms final candidates.
