# AI Study Card V6 Real Provider Implementation Plan

> **Status**: Frozen plan. V6-5 backend provider-preview skeleton implemented disabled/fail-closed.
> **Date**: 2026-07-07.
> **Depends on**: `ADR-0004`, `ADR-0005`, `ai-study-card-v6-preflight-plan.md`, and `ai-study-card-v6-provider-security-plan.md`.

---

## 1. Goal

Define the exact implementation path for a future real-provider V6 task without adding the provider yet.

This plan exists so the future implementation does not smuggle a provider call into the existing V1-V5 workflow, request-package endpoint, or card-generation endpoint.

---

## 2. Current state

Already implemented:

- V6-1 provider-disabled request package endpoint.
- V6-1 desktop UI for generating/copying a request package.
- V6-2 provider interface, disabled adapter, schema validator, recommendation service.
- V6-3 disabled-by-default provider/security config and policy service.

Implemented as disabled/fail-closed skeleton:

- provider-preview backend route skeleton
- provider-preview service boundary

Still not implemented:

- real provider adapter
- provider UI trigger
- secret storage
- external requests
- real provider response parsing from HTTP
- browser Network smoke for a live provider flow

---

## 3. Provider candidate

First live provider implementation must use exactly one backend-only OpenAI-compatible chat-completions adapter configured for one user-approved provider.

This is a provider-shape decision, not a secret or endpoint implementation. The exact provider name, base URL, model, and secret reference must be approved in a later task before code is allowed to call it.

Do not implement multiple providers at once.

---

## 4. Future implementation sequence

### Step A — provider selection approval

Before code changes:

1. User confirms the provider.
2. User confirms the model.
3. User confirms where the secret value will live.
4. User confirms whether `.env` may be edited manually outside the task.
5. Browser Network smoke script is prepared before UI integration.

### Step B — backend route skeleton, still disabled — implemented 2026-07-07

Added exactly one route:

`POST /ai-study-card/v6/recommendations/provider-preview`

Controller:

`AiStudyCardV6RecommendationController::providerPreview`

Service:

`AiStudyCardV6ProviderPreviewService::preview`

It rejects while real-provider preconditions are not met. Current default response is fail-closed `503` with `security_policy_blocked=true` and `no_provider_called=true`.

No UI changes yet.

### Step C — live adapter implementation, no UI

Add a live adapter behind `AiStudyCardV6ProviderInterface`.

It may be tested with fake HTTP responses. No real external requests in automated tests.

### Step D — real provider local manual test

Only after user supplies runtime configuration outside committed code.

The manual test must be backend-only first, then UI.

### Step E — UI trigger + browser Network validation

Add a user-triggered button and stop for WorkBuddy / MCP Chrome browser validation.

---

## 5. Required backend route behavior

The future provider-preview route must:

- require authentication
- accept `ai-study-card-v6-request-package-v1`
- reject if schema is invalid
- reject if security policy preconditions fail
- call only `AiStudyCardV6RecommendationService`
- return a validated `ai-study-card-v6-recommendation-package-v1`
- return fail-closed JSON on provider failure
- never create learning data

The route must not be added to existing V1-V5 endpoints.

---

## 6. Required provider adapter behavior

A live adapter must:

- be backend-only
- read only approved provider config
- use a nonzero timeout
- use zero background retries by default
- parse provider JSON strictly
- pass parsed output to `AiStudyCardV6RecommendationSchemaService`
- fail closed on timeout
- fail closed on quota failure
- fail closed on malformed JSON
- fail closed on provider refusal
- fail closed on network failure
- never log raw prompt or raw response
- never expose secret values
- never write learning data

---

## 7. Required UI behavior

The future UI must show explicit copy before calling the provider:

- external provider may be called
- suggestions are not study cards
- suggestions default unchecked
- AI reason is not final meaning
- user confirmation is required before generation

The UI must not trigger the provider on:

- page load
- token click
- pending list open
- preview dialog open
- selecting or deselecting items
- copying request package

---

## 8. Required browser validation

WorkBuddy / MCP Chrome must validate:

1. Login with local test account.
2. Open a readable chapter.
3. Click a word.
4. Add or reuse pending item.
5. Open pending list.
6. Open preview dialog.
7. Confirm no provider request before clicking the provider button.
8. Click the provider button.
9. Confirm browser Network calls only the local backend provider-preview route.
10. Confirm browser Network does not expose any secret.
11. Confirm the frontend does not call provider domain directly.
12. Confirm recommendations appear unchecked.
13. Confirm no card is created until V5 confirmation.
14. Confirm provider failure shows fail-closed UI and creates no learning data.

---

## 9. Stop condition

Implementation must stop before Accept when UI trigger or live provider behavior is added and real browser Network validation has not yet been completed.

Code review or API tests cannot replace browser validation for this stage.

---

## 10. Acceptance for this plan-only task

This plan and route-skeleton stage is complete when:

- ADR-0005 exists.
- This plan exists.
- Documentation index references both.
- A guard test proves ADR/plan exist and continue to forbid live provider shortcuts.
- Provider-preview route exists but stays disabled/fail-closed under current security policy.
- No UI starts provider calls.
- No config or service starts external provider calls.
- No secret reference or secret value is added.
