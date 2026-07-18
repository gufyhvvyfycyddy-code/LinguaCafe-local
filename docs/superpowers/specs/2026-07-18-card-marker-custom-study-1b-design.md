# Card Marker and Custom Study 1B Design

## Status

Approved by the user in Codex task `019f73c2-ea29-7aa3-93ec-1e90bc920c4e` on 2026-07-18.

For ordinary unresolved product choices inside this persistent goal, use Anki's official design first. When Anki has no directly applicable behavior, use the smallest correct recommended design. Irreversible data, security, secret, paid-provider, and real external-transmission decisions remain mandatory stops.

## Goal

Complete roadmap Phase 4 by adding a card-level Marker to sense ReviewCards and using marked cards as a Custom Study 1B preview criterion.

Browser and Card Info provide Marker management and an entry to Custom Study. Both entries open the current user's current-language set of all marked, still-eligible sense cards; they do not pass current-card or selected-card ID snapshots.

## Official Anki mapping

Anki flags are card-level, a card has at most one flag, `0` means no flag, and `1` through `7` represent red, orange, green, blue, pink, turquoise, and purple. Flags can be searched and used to form filtered study sets.

Sources:

- <https://docs.ankiweb.net/editing.html#using-flags>
- <https://docs.ankiweb.net/searching.html#flags>
- <https://docs.ankiweb.net/browsing.html>

LinguaCafe calls the feature Card Marker. It preserves Anki's card-level single-value semantics but does not add flag renaming, deck creation, or a general card-search language in this milestone.

## Explicit exclusions

- No WordSense Tag or free-text marker.
- No marker history table, audit log, model, repository, or new dependency.
- No marker effect on lifecycle, leech classification, FSRS, ReviewLog, rating, queue position, or due dates.
- No Custom Study rescheduling or formal-rating mode.
- No current-card, selected-card, or arbitrary card-ID Custom Study session contract.
- No marker renaming, user-defined colors, deck/subdeck, Saved Search integration, or mobile-specific UI.
- No Reader or Reviewer marker UI in Phase 4.

## Domain contract

### Marker values

| Value | Meaning | Stable label |
|---:|---|---|
| 0 | No marker | 无标记 |
| 1 | Red | 红色 |
| 2 | Orange | 橙色 |
| 3 | Green | 绿色 |
| 4 | Blue | 蓝色 |
| 5 | Pink | 粉色 |
| 6 | Turquoise | 青色 |
| 7 | Purple | 紫色 |

Color is presentation; the numeric value and accessible label are the durable contract. A future palette adjustment must not change the numeric meaning.

Marker belongs to ReviewCard, not WordSense. The existing unique card identity remains `(user_id, language_id, target_type, target_id)`. Legacy word and phrase cards are not manageable through this feature.

### Persistence

Add `review_cards.marker` as a non-null unsigned tiny integer with default `0` and named database constraint `review_cards_marker_range_check` enforcing `CHECK (marker BETWEEN 0 AND 7)`. Existing rows backfill through the default without changing any other column.

Add a composite lookup index matching the marked-study query prefix: `(user_id, language_id, target_type, marker)`. Do not add a single-column marker index because eight values have low selectivity.

Marker survives normal rating, reset, due-now, bury, suspend, archive, and restore. Deleting a ReviewCard deletes its Marker with the row. If a card is later recreated, it starts at `0`; deleted-card Marker state is not resurrected.

## Backend interfaces

### Single-card mutation

`PATCH /review-cards/manage/{reviewCard}/marker`

Request:

```json
{ "marker": 4 }
```

Success response:

```json
{ "review_card_id": 123, "marker": 4 }
```

Contract:

- `marker` is a required integer from `0` through `7`.
- `0` is the only clear operation.
- Repeating the same value is idempotent and returns `200`.
- Invalid or missing Marker returns `422` with field `marker`.
- Unknown, cross-user, cross-language, legacy, non-sense, or non-confirmed targets return `404` through `ReviewCardManageAccessService`.
- The write assigns only `ReviewCard.marker`; it cannot mass-assign or mutate any other field.
- Last write wins. Marker does not justify a new version column or optimistic-lock protocol.

### Bulk Browser mutation

`POST /review-cards/manage/bulk-marker`

Request:

```json
{ "ids": [123, 456], "marker": 2 }
```

Success response:

```json
{ "affected": 2, "skipped": 0, "marker": 2 }
```

Contract:

- `ids` is a required non-empty list of distinct positive integers with a maximum of `100`, matching the Browser's maximum visible page selection.
- `marker` uses the single-card validation contract.
- Each ID is revalidated for current user, language, sense target, and confirmed WordSense.
- Invalid or inaccessible IDs are counted as skipped; no cross-user or cross-language fact is disclosed.
- The operation is transactional and writes only `review_cards.marker`.

### Ownership

Reuse `ReviewCardManageController`, `ReviewCardManageAccessService`, and `ReviewCardManageMutationService`. Marker is a small ReviewCard management mutation and does not justify a new Controller or Service.

Add Marker to both list and detail serializers as an additive field. Existing response fields and endpoints remain unchanged.

## Frontend interfaces

Create one shared Marker presentation module for the stable `0..7` value, label, icon, and Vuetify color mapping.

Create one `ReviewCardMarkerPicker.vue` mutation owner. It receives the canonical card ID/current Marker or a selected-ID list, performs the single or bulk request, exposes loading/error state, and emits the canonical mutation result. It is the only frontend module that writes Marker.

`ReviewCardTableSurface.vue`:

- displays the accessible Marker indicator for every row;
- embeds the picker for the current row;
- exposes a bulk Marker action for existing selected IDs;
- keeps its existing rule against direct write HTTP.

`ReviewCardInfoDrawer.vue` embeds the same picker and remains free of direct write HTTP. Its existing detail request remains read-only.

`ReviewCardManage.vue` only reconciles emitted Marker results into current list/detail state and coordinates navigation. It does not issue Marker HTTP requests.

Both Browser and Card Info expose “学习已标记卡片”. The action navigates to `/custom-study?mode=marked`; it never passes IDs.

## Custom Study 1B

Add the fifth criteria mode `marked` with no parameters.

The marked candidate query reuses the canonical confirmed-sense base query plus `scopeSenseReviewEligible()` and adds `review_cards.marker > 0`. Therefore suspended, archived, not-yet-expired buried, disabled, non-confirmed, legacy, cross-user, and cross-language cards are excluded even if they have a Marker.

`CustomStudySessionOrder` uses the existing canonical Review Queue order as the marked-mode order. No new randomization or Marker-color priority is introduced.

`CustomStudy.vue` adds “已标记的词义” and initializes `mode` from a validated route query. Unknown route values fall back to the existing default. Direct Browser/Card Info entry preselects `marked`; the user still explicitly presses “开始预览学习”.

The existing rotating encrypted token, eligibility recheck, card limit, preview answer behavior, sessionStorage handling, and shared `SenseStudyCard.vue` remain unchanged. Preview answers never alter Marker.

## Error and compatibility rules

- Marker API errors remain local to the picker and preserve the last canonical value.
- After a successful mutation, the server response is authoritative; stale local values are replaced.
- Closing/reopening Card Info reloads Marker from the detail endpoint.
- Existing Browser search grammar is unchanged. Marker filtering in Custom Study does not add `flag:` or `marker:` Browser search syntax.
- Existing Custom Study four-mode payloads remain valid; `marked` is additive.
- Existing session tokens remain valid because their four existing mode values stay accepted and the serialized token shape does not change. Adding `marked` does not bump the token version.

## Protected behaviors

- Formal rating writes remain `ReviewController::rateReviewCard` / `SenseReviewController::rate` → `ReviewCardService::recordReview` → `FsrsSchedulingService::schedule`.
- Marker endpoints and preview sessions create no ReviewLog and call no FSRS scheduling.
- Lifecycle commands never clear or reinterpret Marker.
- Marker writes never change WordSense, EncounteredWord, occurrence/source context, or card identity.
- Strict user/language/sense/confirmed isolation applies to single, bulk, list, detail, and marked-study paths.
- Custom Study continues to use eligible-only sense cards and never changes the normal queue.

## Delivery slices

### Phase 4A — Marker foundation

Migration, model constants/cast, access-safe single and bulk mutation, additive serialization, and backend tests.

### Phase 4B — Browser and Card Info

Shared presentation/picker, row/detail/bulk UI, state reconciliation, entry navigation, Node guards, build, and two-viewport browser acceptance.

### Phase 4C — Custom Study 1B

Marked criteria/query/order/open-session path, route-query preselection, no-write/security/query-budget regression, and preview browser acceptance.

### Phase 4D — Production closure

Combined protected-module regression, database delta proof, authenticated Browser → Custom Study and Card Info → Custom Study flows, document status update, and completion audit.

## Verification

- Testing DB health check before Feature tests.
- Migration test proves default/backfill, `0..7` constraint, composite index, rollback, and no unrelated data delta.
- Single/bulk endpoint tests cover validation, idempotence, strict isolation, confirmed-sense requirement, skipped counts, and preservation of FSRS/ReviewLog/lifecycle/WordSense.
- Serializer/detail tests prove additive canonical Marker fields.
- Criteria, marked query, query service, session order, open/resume/answer, security, query-budget, and no-write tests cover the fifth mode.
- Node guards prove Table/Info contain no direct Marker write HTTP outside the picker and Browser/Card Info navigation passes `mode=marked` without IDs.
- Run `ReviewFsrsTest`, `FsrsSchedulingServiceTest`, and WordSense-focused regression.
- Run `npm run development`.
- Run `git diff --check` and exact-path scope review.
- Authenticated Chrome acceptance at `1920x1080` and `900x900`: set/clear all values, bulk set, refresh persistence, Browser entry, Card Info entry, empty marked state, eligible exclusion, preview answer loop, Console/Network inspection, and ReviewLog/FSRS/lifecycle database delta of zero.
