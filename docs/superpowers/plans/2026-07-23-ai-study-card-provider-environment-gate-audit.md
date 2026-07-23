# AI Study Card Provider Environment Gate Audit Plan

Date: 2026-07-23

Status: **Accepted / Environment Gate Closed (default-off)**

Authority: `ADR-0004`, `ADR-0005`, `ADR-0028`, and `docs/superpowers/specs/2026-07-23-ai-study-card-service-convergence-design.md`

## Goal

Close the roadmap's provider Environment Gate as an evidence-backed **default-off / fail-closed implementation gate**. This is not authorization to enable a provider, choose or change a model, supply a secret, edit `.env`, incur cost, or send a real external request.

## Architecture review

The existing production seam is already separated:

- authenticated local HTTP entry: `AiStudyCardV6RecommendationController::providerPreview`;
- policy gate: `AiStudyCardV6ProviderSecurityPolicyService`;
- orchestration and schema validation: `AiStudyCardV6ProviderPreviewService` and `AiStudyCardV6RecommendationService`;
- provider adapter: `AiStudyCardV6ProviderInterface`;
- backend transport: `AiStudyCardV6ProviderTransportInterface`;
- explicit desktop trigger: `AiStudyCardV6RequestPackagePanel.vue`;
- card creation: unchanged V4/V5 manual-confirmation path.

The default container binding remains the disabled adapter unless environment configuration explicitly enables the OpenAI-compatible adapter. The current audit must prove that default state, authentication, explicit action, schema validation, fail-closed errors, absence of learning writes, frontend/backend separation, and no secret exposure.

The audit found one hardening opportunity inside the existing policy seam: `snapshot()` currently returns the complete provider config array, including runtime secret-bearing fields, even though there is no production caller. The method must become safe-by-construction before this gate closes. It may expose only non-sensitive provider state plus a boolean `secret_configured`; it must never return `api_key` or `secret_reference`.

The accepted ADR-0005 describes the implementation as future work, while the current repository and later accepted execution evidence contain the default-off transport and explicit UI trigger. A new ADR is required to supersede only that stale implementation-status description while preserving ADR-0004/0005 activation, external-send, secret, model, cost, and browser Network constraints.

## Allowed files

- `app/Services/AiStudyCardV6ProviderSecurityPolicyService.php`
- `tests/Feature/AiStudyCardV6ProviderSecurityConfigTest.php`
- `docs/adr/ADR-0030-ai-study-card-v6-default-off-provider-gate.md`
- this plan, the provider-gate acceptance report, documentation index, roadmap, master plan, and current handoff

## Forbidden

- `.env`, secrets, credentials, provider/model/base URL/timeout values, costs, or runtime activation;
- real HTTP/provider requests;
- controller, route, frontend, adapter, transport, prompt, response schema, V4/V5, WordSense, ReviewCard, ReviewLog, FSRS, model, migration, or database changes;
- weakening default-off policy or existing tests;
- claiming that a closed default-off gate authorizes future external sends.

## Verification

1. Add a direct test proving `snapshot()` excludes `api_key` and `secret_reference` while reporting only `secret_configured`.
2. Run the complete `AiStudyCardV6` suite against the isolated testing database.
3. Run PHP syntax, secret/provider frontend scan, exact-scope diff check, authority-conflict search, and five-axis review.
4. Reuse the already accepted real-browser Network evidence for the unchanged explicit UI trigger, and the Phase 7E official Browser evidence that the default UI shows provider disabled and performs no provider call during the complete V4/V5 flow.
5. Record that no live provider call was made during this audit.

## Closure

`bd447d1` made policy inspection safe-by-construction. The direct policy suite passed 9 tests / 79 assertions and the full V6 matrix passed 80 tests / 806 assertions. ADR-0030 reconciles the stale implementation-status documents while retaining every activation and safety constraint. No live external request was made.
