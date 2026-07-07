# AI Study Card V6 Provider Security Plan

> **Status**: Implemented as a pre-real-provider security gate.
> **Date**: 2026-07-07.
> **Depends on**: `docs/adr/ADR-0004-ai-study-card-v6-real-ai-boundary.md`, `docs/plans/ai-study-card-v6-preflight-plan.md`.

---

## 1. Purpose

This document freezes the provider configuration and security boundary that must exist before LinguaCafe is allowed to integrate a real AI provider for AI Study Card V6.

This task does **not** implement a real provider. It does **not** add API keys. It does **not** add routes or UI. It only establishes disabled-by-default configuration, policy inspection, and tests.

---

## 2. Implemented files

| File | Purpose |
|---|---|
| `config/ai_study_card_v6.php` | Disabled-by-default V6 provider/security config. Contains no secret value and no live provider endpoint. |
| `app/Services/AiStudyCardV6ProviderSecurityPolicyService.php` | Read-only policy service exposing config snapshot, safety flags, and real-provider precondition checks. |
| `tests/Feature/AiStudyCardV6ProviderSecurityConfigTest.php` | Guard tests locking default disabled config, fail-closed policies, logging policy, data policy, network validation, and absence of secret material. |

---

## 3. Current default configuration

The default provider state is:

- `provider.name = disabled`
- `provider.external_requests_enabled = false`
- `provider.allowed_adapter = disabled`
- `provider.secret_source = not_configured`
- `provider.secret_reference = null`

This means the project still cannot call a real AI provider.

---

## 4. Request policy

The request policy defaults to:

- explicit user action required
- background provider requests forbidden
- page-load provider requests forbidden
- token-click provider requests forbidden
- max items per request = 50
- timeout = 0 seconds, meaning real provider timeout is not configured yet
- max retries = 0
- quota failures fail closed
- malformed provider output fails closed
- network failures fail closed

No future implementation may enable external provider calls without first setting a real timeout, a real failure policy, and explicit browser Network validation.

---

## 5. Logging policy

The logging policy defaults to:

- do not log raw prompt
- do not log raw provider response
- do not log source text
- do not log secret reference
- do not log provider headers
- redact provider metadata

Future real-provider implementation must preserve these rules unless a new ADR explicitly changes them.

---

## 6. Data policy

The data policy defaults to:

- provider may not create `WordSense`
- provider may not create `ReviewCard`
- provider may not create `ReviewLog`
- provider may not change FSRS
- provider may not create legacy word cards
- user confirmation is required
- AI recommendations default unchecked
- AI reason is not `sense_zh`

Real AI recommendations remain suggestions only. User-confirmed V5 card generation remains the only card creation path.

---

## 7. Network validation policy

Before a real provider is allowed, the project must pass browser Network validation:

- real browser interaction is required
- page load must not trigger provider calls
- token click must not trigger provider calls
- provider call must be triggered only by explicit user action
- local requests may go to `localhost` / `127.0.0.1`
- external AI domains are forbidden until the provider is explicitly enabled

The current forbidden-domain list includes:

- `api.openai.com`
- `api.deepseek.com`
- `api.anthropic.com`
- `generativelanguage.googleapis.com`
- `api.x.ai`

---

## 8. Secret policy

This task intentionally does not define an API key variable name and does not include any secret reference.

A future real-provider task must decide the secret storage mechanism separately. It must not commit secret values, must not expose them to Vue/JavaScript, must not write them to database rows, and must not place them in logs or response payloads.

The current config and policy files are guarded against:

- common provider key variable names
- `env(...)` calls
- token-like `sk-` values
- bearer-token strings
- live provider endpoint paths

---

## 9. Real-provider preconditions

`AiStudyCardV6ProviderSecurityPolicyService::assertRealProviderPreconditions()` currently returns not-ok with these expected errors:

- `external_requests_disabled`
- `provider_name_disabled`
- `secret_source_not_configured`
- `timeout_not_configured`

A future task must not remove these errors silently. It must explicitly update this plan, update tests, and provide real browser Network evidence.

---

## 10. Still not implemented

The following remain not implemented:

- real AI provider
- API key configuration
- external provider HTTP calls
- provider route
- provider UI action
- automatic AI recommendation
- automatic AI explanation
- automatic card creation
- ReviewLog writes
- FSRS changes
- legacy word card creation

---

## 11. Next allowed step

The next allowed step is not a real provider call.

Recommended next step:

**V6-4 real-provider implementation plan / ADR update**

That step should choose exactly one provider candidate, define secret storage, define timeout and quota handling, define redacted logging, define the dedicated backend route, and write the MCP Chrome Network smoke script before any live provider adapter is implemented.
