# ADR-0004: AI Study Card V6 Real AI Boundary

## Status

Accepted as a **pre-implementation architecture gate**. This ADR does not implement V6, does not authorize API keys, and does not authorize automatic AI calls.

## Date

2026-07-07

## Context

AI Study Card V1-V5 are now implemented as a local, user-confirmed workflow:

1. The user marks a word or phrase as pending.
2. LinguaCafe builds a local preview package.
3. The user may paste AI recommendations manually.
4. LinguaCafe dedupes and builds a final candidates package.
5. The user manually confirms Chinese meanings.
6. LinguaCafe creates confirmed `WordSense` records and `ReviewCard(target_type=sense)` cards.

V6 is the first stage that may involve real AI provider calls. That is a high-risk boundary because it can accidentally introduce:

- API keys or secrets in code, docs, logs, frontend, or database rows.
- Automatic recommendations without explicit user action.
- AI-generated meanings that bypass user confirmation.
- New `WordSense` or `ReviewCard` creation before the user confirms the content.
- ReviewLog / FSRS writes from a reading or suggestion surface.
- A provider-specific implementation scattered across Vue components, Controllers, and Services.

The project has just established `docs/architecture/sense-http-controller-boundaries.md`: new sense / review-adjacent features must have a clear Controller, Service, payload, and test boundary before implementation. V6 must follow the same rule.

## Decision

V6 is split into two layers:

### V6 Architecture Layer

This ADR and `docs/plans/ai-study-card-v6-preflight-plan.md` define the boundary.

The architecture layer is allowed to add:

- Documentation.
- Guard tests.
- Future interface names and route names in docs.
- A disabled-by-default plan for future implementation.

It is not allowed to add real provider calls or new product behavior.

### V6 Implementation Layer

Any implementation round after this ADR must open a specific task and must keep the provider boundary isolated.

The intended future ownership is:

| Area | Intended home |
|---|---|
| V6 HTTP entrypoint | Dedicated `AiStudyCardV6RecommendationController` or equivalent, not `SenseOccurrenceController` and not the V1-V5 generate endpoint |
| Provider orchestration | Dedicated `AiStudyCardV6RecommendationService` |
| Provider adapter | Dedicated provider interface / adapter layer, backend only |
| Request package schema | `ai-study-card-v6-request-package-v1` |
| AI response schema | `ai-study-card-v6-recommendation-package-v1` |
| User confirmation | Existing V4 / V5 package and generate-cards confirmation path |

## Product Rules

1. V6 means **AI recommends candidates**, not AI directly creates study cards.
2. The user must explicitly click a V6 action before any provider request is made.
3. No provider call may happen on page load, token click, preview open, list refresh, or background timer.
4. AI recommendations are untrusted suggestions.
5. AI recommendations must default to unchecked.
6. AI reason text must not automatically become `sense_zh`.
7. The user must type or confirm the final Chinese meaning before card creation.
8. V6 must feed into the existing final candidates / generate-cards path instead of creating a parallel creation path.
9. V6 must not implement reading-inline review scoring.
10. V6 must not write `ReviewLog` or alter FSRS scheduling.

## Security Rules

1. Provider calls must be backend-only.
2. API keys must never appear in Vue, JavaScript services, routes, docs examples, tests, logs, database rows, or response payloads.
3. No key name or secret value should be committed. If a future implementation needs config, it must use a separate configuration plan and must not modify `.env` in a task.
4. Prompt text and provider responses must be treated as user data and must not be logged verbatim.
5. Any future logging must be redacted and must avoid source text dumps.
6. Provider timeout, failure, malformed JSON, and quota failure must fail closed.
7. Provider failure must not create, update, archive, delete, or rate anything.
8. Provider output must be schema-validated before it reaches the V4/V5 confirmation UI.

## Data / FSRS Rules

1. V6 provider calls must not write `ReviewLog`.
2. V6 provider calls must not change `review_cards.fsrs_*`, due dates, reps, lapses, stability, difficulty, or enabled state.
3. V6 provider calls must not create `ReviewCard`.
4. V6 provider calls must not create `WordSense` directly.
5. Card creation remains owned by the existing user-confirmed V5 `generate-cards` path.
6. Created cards must remain `target_type=sense`; no legacy word cards.
7. V6 must not alter delete / archive / restore semantics.

## Route Rules

Future V6 routes must use a dedicated namespace such as:

- `POST /ai-study-card/v6/recommendations/preview`
- `POST /ai-study-card/v6/recommendations/parse`

The exact route names are not implemented by this ADR. Any final route must be reviewed in the implementation task.

Forbidden route shortcuts:

- Do not add provider calls to `POST /ai-study-card/pending-items/preview-package`.
- Do not add provider calls to `POST /ai-study-card/pending-items/final-candidates-package`.
- Do not add provider calls to `POST /ai-study-card/generate-cards`.
- Do not add provider calls to `GET /senses/inline-preview`.
- Do not add provider calls to reading inline confirmation endpoints.

## Controller / Service Placement

V6 must not be added to these Controllers:

- `SenseOccurrenceController`
- `ManualWordSenseController`
- `SenseOccurrenceActionController`
- `SenseOccurrenceBulkActionController`
- `ReadingInlineSenseConfirmationController`
- `SenseSourceContextController`

V6 belongs in a dedicated AI Study Card controller family. Existing V1-V5 controllers may remain unchanged unless an implementation task explicitly proves the change is a thin delegation and has guard tests.

## Validation Requirements For A Future V6 Implementation

A future V6 implementation must include:

1. Route tests for the dedicated V6 route.
2. Tests proving provider calls require explicit user action.
3. Tests proving malformed provider output fails closed.
4. Tests proving provider failure does not write `WordSense`, `ReviewCard`, `ReviewLog`, or FSRS fields.
5. Tests proving AI suggestions default unchecked.
6. Tests proving AI reason is not copied into `sense_zh` automatically.
7. Tests proving all generated cards still go through V5 user confirmation.
8. Tests proving no provider strings or API key material appear in frontend files.
9. MCP Chrome real-page validation for any UI that triggers the provider request.

## Consequences

- V6 can move forward, but only through a dedicated boundary.
- V1-V5 remain stable local workflows.
- The project avoids hiding real AI calls inside existing preview, package, or card-generation endpoints.
- The next implementation round can focus on a small provider stub or request-package boundary without touching FSRS, ReviewLog, or legacy word cards.
