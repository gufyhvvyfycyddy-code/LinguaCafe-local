# ADR-0029: Card Marker and Custom Study 1B

## Status

Accepted / Production Closed on 2026-07-18. The automated, isolated testing-database, and authenticated-browser evidence is recorded in `docs/testing/card-marker-custom-study-1b-browser-acceptance-2026-07-18.md`.

## Context

LinguaCafe needs a lightweight card-level attention marker and a preview-only way to study all marked cards. Anki's official flags provide the product reference: one card has at most one flag, `0` means none, and `1..7` are stable colors. This state is separate from note/content tags, lifecycle, leech classification, and scheduling.

The existing seams already own the required responsibilities:

- `ReviewCardManageController`, `ReviewCardManageAccessService`, and `ReviewCardManageMutationService` own manageable sense-card mutations.
- `ReviewCardManageItemSerializerService` owns Browser and Card Info response fields.
- Custom Study already validates criteria, selects eligible confirmed sense cards, applies canonical ordering, issues rotating encrypted tokens, and answers in preview mode without formal rating.

Creating another controller, service family, repository, token version, or study-session protocol would duplicate those seams.

## Decision

### Persistence and identity

Add `review_cards.marker` as a non-null unsigned tiny integer with default `0`. The durable values are `0..7`: none, red, orange, green, blue, pink, turquoise, and purple.

The database must enforce `CHECK (marker BETWEEN 0 AND 7)` with the stable constraint name `review_cards_marker_range_check`. Add one composite index over `(user_id, language_id, target_type, marker)`. Do not add a low-selectivity single-column Marker index.

Marker belongs to the existing ReviewCard identity `(user_id, language_id, target_type, target_id)`. It is not a WordSense tag. It survives rating, reset, due-now, bury, suspend, archive, and restore. Deleting the ReviewCard deletes its Marker; a recreated card starts at `0`.

### HTTP interfaces

The single-card interface is:

```text
PATCH /review-cards/manage/{reviewCard}/marker
request  {"marker": 4}
response {"review_card_id": 123, "marker": 4}
```

The bulk Browser interface is:

```text
POST /review-cards/manage/bulk-marker
request  {"ids": [123, 456], "marker": 2}
response {"affected": 2, "skipped": 0, "marker": 2}
```

Boundary validation accepts only integer Marker values `0..7`. Bulk IDs must be distinct positive integers, non-empty, and limited to 100. Single-card access reuses `ReviewCardManageAccessService` and returns `404` for unknown, cross-user, cross-language, legacy, non-sense, or non-confirmed targets. Bulk access revalidates every ID, counts inaccessible IDs as skipped, and does not disclose their identities. Bulk writes are transactional.

Both operations are idempotent and last-write-wins. They assign only `review_cards.marker`; generic mass assignment is forbidden. Existing list and detail serializers add one numeric `marker` field without removing or changing existing fields.

### Frontend ownership

One shared presentation module owns the stable numeric-to-label/icon/color mapping. One `ReviewCardMarkerPicker.vue` owns both Marker write requests and local loading/error behavior.

`ReviewCardTableSurface.vue` and `ReviewCardInfoDrawer.vue` embed the picker but issue no Marker write requests. `ReviewCardManage.vue` reconciles emitted canonical server results and coordinates navigation; it issues no Marker write request.

Browser and Card Info both expose `学习已标记卡片` and navigate to `/custom-study?mode=marked`. They never pass current-card, selected-card, or arbitrary ID snapshots.

### Custom Study 1B

Add a fifth parameterless criteria mode `marked`. Its candidate query reuses the canonical confirmed-sense query and `scopeSenseReviewEligible()`, then adds `review_cards.marker > 0`. It therefore retains user/language/target/status/lifecycle/FSRS-enabled isolation.

Marked sessions use the existing canonical Review Queue order. Marker color does not affect priority. Route query `mode=marked` only preselects the criterion; the user still presses the existing start button.

The encrypted token shape and version remain unchanged. Existing four-mode tokens remain valid. Open, resume, reveal, and preview answer behavior remain read-only with respect to learning data.

### Protected write boundary

Marker APIs and marked preview sessions must not create ReviewLog rows, call FSRS scheduling, alter due dates or FSRS fields, alter lifecycle, change WordSense or source context, or enter the formal rating path. Formal rating remains:

```text
ReviewController::rateReviewCard / SenseReviewController::rate
  -> ReviewCardService::recordReview
  -> FsrsSchedulingService::schedule
```

## Rejected alternatives

- A free-text WordSense tag: wrong identity and larger search/product contract.
- Reusing lifecycle or leech fields: conflates independent user intent and protected behavior.
- Multiple booleans or a marker history table: unnecessary for one-card/one-marker semantics.
- A new Marker controller/service/repository: duplicates the established ReviewCard management seam.
- Passing Browser IDs into Custom Study: creates stale snapshots and a second session contract.
- Marker-color ordering: Anki flags express attention, not scheduling priority.
- Token-version bump: the serialized shape does not change.

## Rollout and rollback

Implementation is delivered in four slices: persistence/API, Browser/Card Info UI, marked Custom Study, and closure. The migration may execute only in isolated testing MySQL during implementation. Development or production migration execution requires a separate explicit operational action.

Rollback drops the named check, composite index, and Marker column. It does not touch ReviewLog, FSRS, lifecycle, WordSense, or the existing Custom Study token structure. Removing application support must first stop emitting `marked` criteria; existing four modes remain compatible.

## Verification

- Migration tests: default/backfill, `0..7` constraint, composite index, reversible down/up, no unrelated delta.
- API tests: validation, idempotence, isolation, confirmed-sense requirement, bulk skipped count, and protected-column/ReviewLog preservation.
- Serializer tests: additive numeric field in list and detail.
- Custom Study tests: criteria, query eligibility, canonical ordering, token compatibility, open/resume/answer, security, query budget, and no-write acceptance.
- Node guards: the picker is the sole Marker HTTP mutation owner; navigation passes only `mode=marked`.
- Protected regressions: `ReviewFsrsTest`, `FsrsSchedulingServiceTest`, and WordSense-focused tests.
- Frontend build plus authenticated Chrome acceptance at `1920x1080` and `900x900`, including database delta proof.

## Source contract

The full approved product and implementation boundary is recorded in `docs/superpowers/specs/2026-07-18-card-marker-custom-study-1b-design.md`. This ADR owns the durable architectural decision; the spec and implementation plan own delivery detail.
