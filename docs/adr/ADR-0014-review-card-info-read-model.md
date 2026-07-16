# ADR-0014: Review Card Info Read Model

**Status**: Accepted
**Date**: 2026-07-13
**Related**: `docs/adr/ADR-0007-review-card-manage-deep-link.md`, `docs/adr/ADR-0009-sense-review-undo-audit-trail.md`, `docs/adr/ADR-0010-review-card-lifecycle-state-machine.md`, `docs/adr/ADR-0011-sense-leech-governance-and-rewrite-package.md`, `docs/adr/ADR-0012-review-card-browser-search.md`, `docs/adr/ADR-0013-review-card-browser-search-execution-pipeline.md`

## Context

The Review Card management page already has a right-hand detail drawer in `resources/js/components/ReviewCards/ReviewCardManage.vue`. The drawer is opened from two entry points:

1. The list row "详情" button → `openDetail(item)` reuses the list-row item directly.
2. The daily-report deep link `?review_card_id=...&from=daily-report` → `loadDeepLinkDetail()` calls `GET /review-cards/manage/{reviewCard}/detail`, then `openDetail(item)`.

### Current multi-request call chain (Before)

Opening the drawer fires **three** parallel sub-requests in addition to the optional detail call:

```
openDetail(item)
  ├── GET /review-cards/manage/{reviewCard}/logs          → ReviewCardManageController::logs
  ├── GET /review-cards/{reviewCard}/lifecycle-events     → ReviewCardLifecycleController::events
  └── GET /reviews/senses/{reviewCard}/leech              → SenseReviewLeechController::show

# Deep-link entry adds one more before openDetail:
loadDeepLinkDetail(reviewCardId)
  └── GET /review-cards/manage/{reviewCard}/detail        → ReviewCardManageController::detail
```

So a deep-link open issues **4** HTTP requests; a list-row open issues **3** HTTP requests. Each sub-request independently re-runs `ReviewCardManageAccessService::findManageableSenseCardOrFail()` (a 2-query access check: `ReviewCard` + `WordSense`).

### Problems identified (Phase A read-only review)

1. **Multiple round-trips per drawer open.** 3–4 HTTP requests for one logical "show me this card's info" action.
2. **Repeated access checks.** Each of the 4 endpoints re-runs the same 2-query access check on the same card.
3. **No single read model.** The frontend stitches together 3–4 independent responses; there is no canonical Card Info payload contract.
4. **Stale-response risk on fast card switching.** Each sub-request is independent; if the user opens card A then quickly card B, late-arriving responses for A can overwrite B's drawer (no request-sequence guard).
5. **No explicit "recent history" framing.** The logs/events sections show the most recent 20 rows but the UI does not state that this is a capped recent view, not the full history.
6. **Lifecycle-events endpoint has no PHP feature test.** The other three endpoints have direct HTTP-level tests; the events endpoint is only exercised through the UI.

## Decision

### 1. Target single-request call chain (After)

```
GET /review-cards/manage/{reviewCard}/detail
  → ReviewCardManageAccessService::findManageableSenseCardOrFail()   (1 access check, reused)
  → ReviewCardInfoQueryService::build($card, $sense, $userId, $language)
      ├── ReviewCardManageItemSerializerService::serializeCard($card, $sense)   (reused, top-level fields)
      ├── ReviewLog query: card + user + language, reviewed_at DESC, limit 20   (1 query)
      ├── ReviewCardStateEvent query: card + user, created_at DESC, limit 20    (1 query)
      └── SenseReviewLeechQueryService::describeForCard($card, now, tz)         (reused, no Policy duplication)
  → response = { ...existing top-level fields, "card_info": { review_logs, lifecycle_events, leech } }
```

Frontend opens the drawer with **one** canonical detail request and renders all sections from `response.data.card_info`. The three old endpoints (`/logs`, `/lifecycle-events`, `/leech`) are **kept** for backward compatibility and are no longer called by the drawer.

### 2. Card Info V1 is read-only

The Card Info panel **only** displays information. Edit / suspend / resume / archive / reset / delete actions remain in their existing locations on the management page (table row actions + bulk action bar). No action buttons are added inside the drawer.

### 3. Response contract (additive)

`GET /review-cards/manage/{reviewCard}/detail` returns:

```json
{
  "...all existing top-level serializeCard() fields...": "unchanged",
  "card_info": {
    "review_logs": {
      "items": [
        {
          "id": int,
          "rating": string,
          "source": string,
          "reviewed_at": ISO8601|null,
          "previous_state": string|null,
          "new_state": string|null,
          "previous_due_at": ISO8601|null,
          "new_due_at": ISO8601|null,
          "previous_stability": number|null,
          "new_stability": number|null,
          "previous_difficulty": number|null,
          "new_difficulty": number|null,
          "undone": bool,
          "undone_at": ISO8601|null,
          "undo_source": string|null
        }
      ],
      "limit": 20
    },
    "lifecycle_events": {
      "items": [
        {
          "id": int,
          "action": string,
          "previous_state": string|null,
          "new_state": string|null,
          "source": string|null,
          "created_at": ISO8601,
          "request_id_prefix": string|null
        }
      ],
      "limit": 20
    },
    "leech": {
      "status": "stable"|"struggling"|"leech",
      "severity": int,
      "reasons": [string],
      "suggestions": [string],
      "blocked_actions": [string]
    } | null
  }
}
```

- `review_logs.items` field shape is **byte-identical** to the existing `/logs` endpoint contract (ADR-0009 audit trail: all sources + undone rows retained).
- `lifecycle_events.items` field shape is **byte-identical** to the existing `/lifecycle-events` endpoint contract.
- `leech` descriptor shape is **byte-identical** to the existing `/leech` endpoint's `leech` field (ADR-0011).
- `limit` is always `20` and is returned so the frontend can label the section as "最近 20 条" (recent 20).
- `leech` may be `null` only if `SenseReviewLeechQueryService::describeForCard()` returns null (it currently always returns a descriptor; the null slot is reserved for future defensive cases).

### 4. Backward compatibility

- **Old top-level fields**: all 30 fields from `serializeCard()` are preserved unchanged. No field is renamed, removed, or retyped.
- **Old endpoints**: `GET /review-cards/manage/{reviewCard}/logs`, `GET /review-cards/{reviewCard}/lifecycle-events`, `GET /reviews/senses/{reviewCard}/leech` are **not deleted, not modified, not deprecated**. Their contracts are frozen. They remain available for any caller that prefers the granular endpoints. The drawer simply stops calling them.
- **Deep-link contract (ADR-0007)**: `?review_card_id=...&from=daily-report` parsing and the "返回学习报告" button are preserved.
- **List-row open path**: the list row's `openDetail(item)` is migrated to also call the canonical detail endpoint (one request) instead of using the list-row item directly. This guarantees the drawer always shows the freshest card state, not the possibly-stale list-row snapshot.

### 5. user / language / sense-only isolation

`ReviewCardInfoQueryService` does **not** re-implement access control. It receives `[$card, $sense]` already validated by `ReviewCardManageAccessService::findManageableSenseCardOrFail()`, which enforces:

- `review_cards.user_id = $userId` AND `review_cards.language_id = $language` AND `target_type = 'sense'`
- `word_senses.user_id = $userId` AND `word_senses.language_id = $language` AND `status = 'confirmed'`
- Legacy word cards → 404. Rejected / deleted senses → 404. Archived cards (fsrs_enabled=false) **allowed**.

The ReviewLog and ReviewCardStateEvent queries inside `ReviewCardInfoQueryService` additionally scope by `user_id` (defensive — a card already belongs to exactly one user, but the explicit filter prevents any future cross-user leak if a card were ever shared).

### 6. ReviewLog data source and limit

- Source: `ReviewLog` rows where `review_card_id = $card->id` AND `user_id = $userId` AND `language_id = $language`.
- **No source filter** — all sources (`sense_review`, `reset`, `import`, etc.) and all undone rows are retained. This matches the existing `/logs` endpoint audit-trail contract (ADR-0009).
- Sort: `reviewed_at DESC`.
- Limit: `20` (same as `/logs`).
- The frontend history section must label this as "最近记录" (recent records), not "全部历史" (full history).

### 7. Lifecycle event data source and limit

- Source: `ReviewCardStateEvent` rows where `review_card_id = $card->id` AND `user_id = $userId`.
- Sort: `created_at DESC`.
- Limit: `20` (same as `/lifecycle-events`).
- Item shape: `{id, action, previous_state, new_state, source, created_at, request_id_prefix}` — identical to the existing endpoint.

### 8. Leech data source

- `ReviewCardInfoQueryService` delegates to `SenseReviewLeechQueryService::describeForCard($card, now, tz)`.
- `describeForCard` internally calls `SenseReviewLearningFeedbackService::buildForCard()` + `ReviewCardLifecyclePolicy::describe()` + `SenseReviewLeechPolicy::classify()`.
- **No Leech Policy duplication.** The Policy class is the single source of truth.
- **No AI provider call.** The Policy is a pure function.

### 9. Query budget

Per `detail` request (after access check):

| Query | Count | Notes |
|---|---|---|
| Access: `ReviewCard` + `WordSense` | 2 | reused from `findManageableSenseCardOrFail` |
| `serializeCard()` source-chapter lookup | 0–1 | cached/optional, same as list-row serialization |
| `ReviewLog` query | 1 | `WHERE card + user + language ORDER BY reviewed_at DESC LIMIT 20` |
| `ReviewCardStateEvent` query | 1 | `WHERE card + user ORDER BY created_at DESC LIMIT 20` |
| Leech feedback (`buildForCard`) | 1 | single `ReviewLog` query inside `SenseReviewLearningFeedbackService` |
| Leech lifecycle describe | 0 | reads `$card` columns already loaded |
| Leech policy classify | 0 | pure function |

**Total: ~5–6 DB queries per detail request**, regardless of ReviewLog count or lifecycle-event count. No N+1, no per-log query, no per-event query.

### 10. Loading / empty / error states

Frontend `ReviewCardManage.vue` must implement per-section states driven by the single `card_info` response:

- **Loading**: while the canonical detail request is in flight, the drawer shows a loading state for all three sections. The drawer opens immediately with a skeleton/loading indicator; the content fills in when the response arrives.
- **Empty**: if `review_logs.items` is `[]`, the history section shows "暂无复习记录。"; if `lifecycle_events.items` is `[]`, "暂无生命周期记录。"; if `leech` is `null` or `status === 'stable'` with no reasons, the diagnostic section shows "暂无遗忘诊断数据。".
- **Error**: if the canonical detail request fails (404 / 500 / network), the drawer shows a single error state with the message; the three sub-sections do not independently error out (because there are no sub-requests anymore).

### 11. Fast card switching — stale response guard

When the user opens card A then quickly opens card B before A's response arrives, the drawer must end up showing B's data, not A's. The implementation uses a **monotonic request sequence number**:

- `data()` adds `detailRequestSeq: 0`.
- `openDetail()` increments `detailRequestSeq` and captures `const seq = this.detailRequestSeq` before the axios call.
- The `.then()` handler checks `if (seq !== this.detailRequestSeq) return;` before mutating `detailTarget` / `cardInfo`.
- Closing the drawer increments `detailRequestSeq` to invalidate any in-flight response.

This is the project's existing lightweight pattern (no AbortController dependency added). An `AbortController` alternative is acceptable if the project already uses it elsewhere; the ADR does not mandate a specific mechanism, only the invariant.

### 12. Scope exclusion

This ADR does **not** introduce:

- Queue Order logic.
- Custom Study sessions.
- Saved Search.
- today-only limits.
- Study Overview or Stats changes.
- Any new search syntax (ADR-0012 V1 frozen).
- Any new migration.
- Any FSRS algorithm / parameter / scheduling change.
- Any ReviewLog schema or historical log change.
- Any lifecycle state machine change (ADR-0010).
- Any Leech Policy change (ADR-0011).
- Any external AI provider call.
- Any edit / suspend / resume / archive / reset / delete action inside the Card Info drawer.

## Rollback

Revert the refactor commit. The old `/logs`, `/lifecycle-events`, `/leech` endpoints remain functional, so reverting the frontend changes restores the 3-request behavior. The `card_info` field is additive — reverting the backend change removes `card_info` but does not break any old top-level field consumer. No migration, no schema change, no FSRS / lifecycle / ReviewLog change — rollback is a pure code revert.

## Prohibited scope

- No new migration.
- No FSRS algorithm / parameter / scheduling change.
- No ReviewLog schema or historical log change.
- No lifecycle state machine change (ADR-0010).
- No Leech Policy change (ADR-0011).
- No new search syntax (ADR-0012 V1 frozen, ADR-0013 pipeline frozen).
- No external AI provider call.
- No second detail page or second detail drawer.
- No deletion of the existing deep-link contract (ADR-0007).
- No duplication of lifecycle state machine or Leech Policy in Controller or Vue.
- No per-log / per-event DB query.
- No ReviewLog write.
- No legacy word card creation.
- No edit / suspend / resume / archive / reset / delete action inside the Card Info drawer.

## Phase 3A frontend extraction evidence (2026-07-16)

The accepted read model is now consumed through one dedicated presentation boundary: `ReviewCardInfoDrawer.vue`. The child owns the canonical detail GET, request lifecycle, monotonic sequence guard, close/switch cleanup, overview/history/diagnosis tabs and Card Info-only formatting. Its public contract is limited to `value`, `reviewCardId` and `deepLinkSource` props plus `input`, `close`, `open-source` and `return-to-report` events.

`ReviewCardManage.vue` remains the coordinator for deep-link parsing, source-dialog navigation, report return, list refresh and every write workflow. It has no parallel detail GET or Card Info response state. The parent changed from 3,411 to 2,792 lines, direct `axios.` references changed from 24 to 22, and its 12 existing `v-dialog` blocks remain unchanged. The canonical Card Info detail request now has one frontend owner.

No endpoint, payload, access rule, database schema, ReviewLog behavior, lifecycle/FSRS behavior or write semantics changed. Automated guards, grouped related Feature tests, the Unit suite and the frontend build passed. The implementation report records both viewports, all three tabs, source navigation, close/reopen, deep-link report return and undone audit presentation without application console errors. Web-side re-verification on 2026-07-16 could not complete authenticated Network acceptance because `Chrome_DevTools_1` repeatedly returned 502 and the remaining local browser session was unauthenticated. Direct CDP confirmed the browser endpoint was alive but is not accepted as a substitute for an authenticated real-page trace. Phase 3A therefore remains Incomplete, and Phase 3B is not authorized by this evidence.
