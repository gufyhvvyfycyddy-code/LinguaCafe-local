# AI Study Card Provider Environment Gate Acceptance

Date: 2026-07-23

Status: **Accepted / Environment Gate Closed (default-off)**

Hardening: `bd447d1` (`fix: redact provider security snapshot`)

Decision: `ADR-0030`

## Result

The existing V6 provider-preview architecture satisfies the roadmap gate in its default-off, fail-closed state. No provider, model, base URL, timeout, secret, cost setting, `.env`, route, UI, schema, adapter, transport, card-generation, ReviewLog, FSRS, model, migration, or database behavior was enabled or changed by this audit.

`AiStudyCardV6ProviderSecurityPolicyService::snapshot()` was hardened so it reports only non-sensitive provider state and configuration booleans. It no longer returns `api_key`, `secret_reference`, a raw base URL, or any secret-bearing value. The repository had no production caller, and the new regression proves a configured test secret and reference cannot escape through the snapshot.

## Evidence

- Complete V6 suite: **80 tests / 806 assertions**, all passing in 4.95 seconds.
- Direct policy suite: **9 tests / 79 assertions**, all passing.
- Default container binding remains `AiStudyCardV6DisabledProviderAdapter`.
- Default preconditions report not-ok for disabled external requests, disabled provider/adapter, missing key/base URL, and zero timeout.
- Provider-preview requires authentication, rejects malformed packages before policy evaluation, and returns fail-closed `503` while disabled.
- Fake-transport tests cover configured adapter selection, local backend route, quota, malformed response, missing key, schema validation, default-unchecked recommendations, and no learning writes.
- Frontend scan found no provider-domain, secret-reference, or bearer-token material.
- PHP syntax and exact-scope `git diff --check` passed.

The scoped five-axis review found no critical or required issue:

- correctness: snapshot behavior is explicit and regression-locked; all V6 behavior tests remain green;
- readability: sensitive values are eliminated at the policy boundary rather than relying on every future caller;
- architecture: no new seam was added and the dedicated controller/service/adapter/transport separation remains;
- security: secrets and references cannot escape through policy inspection, default binding is disabled, and all provider failures remain closed;
- performance: the change is in-memory array shaping with no query or network effect.

## Browser and network evidence

The unchanged explicit provider-preview UI already has accepted real-browser Network evidence recorded by the current handoff and master plan: the browser called only the local backend route, exposed no secret, returned default-unchecked recommendations, and made no learning-data write.

The Phase 7E official Browser pass independently reconfirmed the default state during a full V4/V5 flow: the UI showed `provider disabled`, no provider action occurred, card creation still required a second manual confirmation, and zero ReviewLog rows were written.

No live external provider request was made during this audit.

## Closure semantics

“Environment Gate Closed” means the repository implementation is safely default-off and has a complete activation boundary. It does not mean a runtime provider is enabled. Any future activation still requires explicit approval of the exact provider, model, secret mechanism, timeout/failure behavior, cost cap, and browser Network test scope.

The authorized Anki-aligned product and architecture milestone sequence is complete. Runtime provider activation and alternate-machine reproduction are external/environmental choices, not unfinished repository milestones.
