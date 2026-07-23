# ADR-0030: AI Study Card V6 Default-Off Provider Gate

## Status

Accepted.

## Date

2026-07-23

## Supersedes

This ADR supersedes only the stale implementation-status statements in ADR-0005 and the 2026-07-07 provider plans that describe the adapter, HTTP transport, route, and explicit UI trigger as future or unimplemented.

ADR-0004 and ADR-0005 remain authoritative for product safety, backend-only transport, explicit user action, secrets, schema validation, user confirmation, ReviewLog/FSRS isolation, and browser Network requirements.

## Context

The repository now contains more than the original planning skeleton:

- an authenticated local `provider-preview` route;
- a disabled-by-default provider binding;
- an environment-configurable OpenAI-compatible backend adapter and HTTP transport;
- prompt and response schema boundaries;
- an explicit desktop trigger that calls only the local backend;
- a recommendation import path that remains default-unchecked and feeds the existing V4/V5 manual-confirmation flow.

Later execution evidence also records real-browser Network acceptance of that explicit local-backend flow. The accepted Phase 7 service-convergence design permits the provider Environment Gate to close when the existing implementation is proven default-off and fail-closed, without making a new external request.

The older ADR and plans were not updated when those later stages landed, so their implementation-status text conflicts with the current repository. The safety constraints themselves do not conflict and must remain.

## Decision

The provider Environment Gate is closed as a **default-off / fail-closed implementation gate**.

This means:

1. The route, adapter, transport, prompt/response validation, and explicit UI trigger are accepted as existing architecture.
2. Production defaults bind `AiStudyCardV6DisabledProviderAdapter`.
3. External transport is unreachable unless runtime configuration explicitly enables external requests, selects the allowed adapter, supplies all required provider settings, and passes policy preconditions.
4. Page load, token click, pending-list open, preview-dialog open, request-package generation, and V4/V5 confirmation do not call a provider.
5. Provider output remains an untrusted, default-unchecked recommendation package.
6. Card creation remains the existing V5 path after the user supplies or confirms the final Chinese meaning.
7. Provider preview may not create WordSense, ReviewCard, ReviewLog, legacy word cards, or change FSRS.
8. Policy inspection must be safe-by-construction: `snapshot()` may report only non-sensitive state and configuration booleans. It must never return a secret value or secret reference.

## What this does not authorize

Closing the implementation gate does not authorize:

- enabling external requests in any environment;
- choosing or changing a provider, base URL, model, timeout, or cost cap;
- supplying, reading, editing, moving, or logging a secret;
- modifying `.env`;
- a live provider/network test;
- automatic chapter analysis, automatic meanings, automatic card creation, background calls, retries, or mobile V6;
- bypassing V4/V5 selection and confirmation.

Those remain environment-specific operational decisions and require explicit approval of the exact provider, model, secret mechanism, timeout/failure behavior, cost limit, and Network test scope.

## Verification

The closure requires:

- the complete `AiStudyCardV6` test suite;
- default disabled binding and failed preconditions;
- authentication and malformed-package rejection;
- fail-closed provider, quota, network, and malformed-output behavior;
- no learning writes and unchanged FSRS/ReviewLog boundaries;
- frontend scan proving no provider domain or secret reference;
- a regression proving policy snapshots redact `api_key` and `secret_reference`;
- accepted browser Network evidence for the unchanged explicit local-backend trigger;
- an explicit record that the closure audit made no live external request.

## Consequences

- The authorized Anki-aligned roadmap sequence has no remaining implementation milestone.
- Runtime provider activation remains optional and environment-gated, not an unfinished product milestone.
- Deferred alternate-machine validation remains external verification, not repository work.
- Future provider changes must cite ADR-0004, ADR-0005, and this ADR.
