# AI Study Card V6 Preflight Plan

> **Status**: Frozen architecture preflight. V6-1 provider-disabled request-package preview implemented.
> **Date**: 2026-07-07.
> **Depends on**: `docs/adr/ADR-0004-ai-study-card-v6-real-ai-boundary.md` and `docs/architecture/sense-http-controller-boundaries.md`.

---

## 1. Goal

Prepare the architecture for AI Study Card V6 without adding real AI calls yet.

V6 is the future stage where LinguaCafe may call a real AI provider to recommend study-card candidates. This plan only freezes the boundary so the implementation does not become another large mixed component or service.

This plan does **not** implement:

- Real AI provider calls.
- API key configuration.
- Automatic AI recommendations.
- AI-generated `WordSense` creation.
- AI-generated `ReviewCard` creation.
- Reading-inline review scoring.
- FSRS mutation.
- ReviewLog writing.

---

## 2. Current V1-V5 baseline

The existing local workflow is:

1. User marks a word/phrase as pending.
2. User opens the pending list.
3. LinguaCafe builds a local preview package.
4. User manually pastes AI recommendations.
5. LinguaCafe parses and dedupes the pasted JSON.
6. User selects recommendations; AI recommendations default unchecked.
7. User confirms final candidate package.
8. User types/confirms Chinese meanings.
9. LinguaCafe creates confirmed `WordSense` records and `ReviewCard(target_type=sense)` cards through `/ai-study-card/generate-cards`.

V6 must reuse the user-confirmed V4/V5 confirmation path. It must not create a second card-generation path.

---

## 3. Product decision

V6 should mean:

**AI helps recommend candidate words / phrases and draft explanations. The user still decides what becomes a study card.**

V6 should not mean:

- AI automatically creates cards.
- AI automatically fills the final Chinese meaning.
- AI rates a card.
- AI changes FSRS.
- AI writes reading-inline review logs.

---

## 4. Proposed future architecture

| Layer | Future component | Responsibility |
|---|---|---|
| HTTP | `AiStudyCardV6RecommendationController` | Accept a request package and return a provider recommendation package. No card creation. |
| Application service | `AiStudyCardV6RecommendationService` | Build prompt payload, call provider adapter, validate response schema, return safe package. |
| Provider boundary | `AiStudyCardAiProviderInterface` + concrete adapter | Backend-only external provider call. No Vue or route-level provider code. |
| Schema validator | `AiStudyCardV6RecommendationSchemaService` | Validate `ai-study-card-v6-recommendation-package-v1`. |
| Existing user confirmation | V4/V5 final-candidates + generate-cards path | Still owns user selection and card creation. |
| Frontend | `AiStudyCardDesktopWorkflow` or a smaller V6 child component | User-triggered V6 request button. Must show safety copy and default unchecked suggestions. |

---

## 5. Candidate schemas

### Request package

`schema_version = ai-study-card-v6-request-package-v1`

Minimum fields:

- `chapter_id`
- `language`
- `selected_pending_item_ids[]`
- `selected_items[]`
- `context_policy`
- `safety_flags`

Required safety flags:

- `user_triggered_request = true`
- `no_card_creation = true`
- `no_review_log_created = true`
- `no_fsrs_changed = true`
- `no_word_sense_created = true`
- `no_review_card_created = true`

### Recommendation package

`schema_version = ai-study-card-v6-recommendation-package-v1`

Minimum fields:

- `recommended_items[]`
  - `word`
  - `lemma`
  - `surface`
  - `sentence_text`
  - `reason`
  - `confidence`
  - `source = ai_provider_v6`
- `dropped_items[]`
- `provider_metadata_redacted`
- `safety_flags`

Required safety flags:

- `ai_generated_suggestions_only = true`
- `user_confirmation_required = true`
- `default_unchecked = true`
- `no_card_creation = true`
- `no_review_log_created = true`
- `no_fsrs_changed = true`

---

## 6. Future minimum implementation sequence

### V6-1: Provider-disabled request-package preview — implemented 2026-07-07

- Local endpoint: `POST /ai-study-card/v6/recommendations/request-package`.
- Controller: `AiStudyCardV6RecommendationController::requestPackage`.
- Service: `AiStudyCardV6RequestPackageService::buildRequestPackage`.
- Desktop UI: `AiStudyCardV6RequestPackagePanel.vue`, mounted inside `AiStudyCardPreviewDialog.vue`.
- Frontend API wrapper: `buildV6RequestPackage()` in `AiStudyCardPendingWorkflowService.js`.
- Builds `ai-study-card-v6-request-package-v1`.
- Shows clear provider-disabled safety copy.
- Provides copy-to-clipboard for the generated request package.
- Does not call a provider.
- Proves the request package and safety flags.
- Can be used for prompt copy / manual provider testing.
- Covered by `AiStudyCardV6RequestPackageTest`, `AiStudyCardV6PreflightArchitectureGuardTest`, and `AiStudyCardV6RequestPackageUiGuardTest`.

### V6-2: Provider adapter stub, disabled by default — implemented 2026-07-07

- Added `AiStudyCardV6ProviderInterface`.
- Added production default `AiStudyCardV6DisabledProviderAdapter`.
- Added `AiStudyCardV6ProviderDisabledException`.
- Added `AiStudyCardV6RecommendationSchemaService`.
- Added `AiStudyCardV6RecommendationService`.
- Bound `AiStudyCardV6ProviderInterface` to the disabled adapter in `AppServiceProvider`.
- Fake provider exists only inside tests, not as a production provider.
- No real API key.
- No real network call.
- No new route.
- No UI change.
- Malformed provider output fails closed.
- Provider exception fails closed.
- Disabled provider fails before any provider result is trusted.
- Covered by `AiStudyCardV6ProviderAdapterTest`.

### V6-3: Real provider integration, explicit user action only

- Requires a new implementation task.
- Requires config/key handling plan.
- Requires MCP Chrome real validation.
- Requires no secrets in code, docs examples, logs, DB, frontend, or responses.

### V6-4: UX integration

- Add a user-triggered V6 button to the desktop workflow.
- Suggestions default unchecked.
- AI reason remains reference text, not final `sense_zh`.
- Final card creation still goes through V5 confirmation.

---

## 7. Forbidden shortcuts

Do not:

1. Put provider code in Vue components.
2. Put provider code in `AiStudyCardPendingItemService` unless it is a thin delegation to the V6 service.
3. Put provider code in `SenseOccurrenceController` or any sense occurrence controller family.
4. Put provider code in `/ai-study-card/generate-cards`.
5. Trigger provider calls on page load or token click.
6. Store API keys in DB or commit them to docs/tests.
7. Log raw prompts, raw source text, raw provider responses, or keys.
8. Auto-create `WordSense` or `ReviewCard` from provider output.
9. Write ReviewLog or mutate FSRS.
10. Add mobile / BottomSheet V6 until desktop V6 is stable and explicitly approved.

---

## 8. Acceptance for this preflight task

This preflight task is complete when:

1. ADR-0004 exists and freezes the V6 boundary.
2. This plan exists and lists staged implementation steps.
3. Documentation index links ADR-0004 and this plan.
4. Current handoff records that V6 is still not implemented.
5. A guard test proves:
   - ADR and plan exist.
   - V6 is documented as pre-implementation only.
   - Current V1-V5 code surface still has no real provider endpoint strings.
   - Current routes do not expose V6 provider routes yet.
   - Current frontend still treats AI recommendations as user-confirmed, not auto-generated cards.

---

## 9. Current status after preflight

After this preflight, V6-1, V6-2, and the V6-3 provider security gate, the project is allowed to plan a real-provider ADR update. It is still not allowed to implement a live provider until that future ADR/config task is approved and browser Network validation is prepared.

V6-3 provider configuration/security gate is documented in `docs/plans/ai-study-card-v6-provider-security-plan.md` and implemented through:

- `config/ai_study_card_v6.php`
- `AiStudyCardV6ProviderSecurityPolicyService`
- `AiStudyCardV6ProviderSecurityConfigTest`

It is still not allowed to:

- Add real provider calls.
- Add API keys.
- Auto-generate cards.
- Auto-fill final meanings.
- Change FSRS.
- Write ReviewLog.
- Add reading-inline scoring.
