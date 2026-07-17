# ADR-0029: Review Card Marker and Custom Study 1B

> **Status**: Accepted for implementation
> **Date**: 2026-07-17
> **Scope**: Phase 4 Card Marker + marked-card Custom Study 1B

## Context

Anki exposes one finite flag on a card and supports `flag:` Browser searches. LinguaCafe needs the same attention marker without adding decks, generic notes, free-text tags, or a second review state model. The existing Custom Study 1A session is preview-only, user/language scoped, sense-only, token-backed, and does not write ReviewLog or FSRS.

## Decision

1. `ReviewCard.marker` is an unsigned integer in `0..7`; `0` means unmarked and `1..7` are finite colors.
2. Marker belongs to the sense ReviewCard. It is not WordSense content and is independent of lifecycle, FSRS, Leech, WordSense status, and future WordSense tags.
3. The schema change is additive: a non-null `marker` column defaults to `0` and is indexed. Rollback drops only that column.
4. Single mutation contract: `PATCH /review-cards/{reviewCard}/marker` with `{ marker: 0..7 }` returns `{ review_card_id, marker }`.
5. Bulk mutation contract: `PATCH /review-cards/manage/markers` with `{ ids: 1..50 distinct positive integers, marker: 0..7 }` returns `{ marker, applied_ids, failed_ids }`. Only current-user/current-language confirmed sense cards are applied.
6. Marker writes are last-write-wins and reversible. They do not use lifecycle versioning because they cannot lose scheduling/history state. They create no ReviewLog and no ReviewCardStateEvent.
7. Existing Browser payloads and Sense Review card payloads gain one additive `marker` field. Card Info exposes the same value.
8. Browser search adds one exact finite token, `flag:0` through `flag:7`. Saved Search requires no new persistence because it already stores the raw query.
9. Custom Study adds one `marked` criterion meaning `marker > 0`. It reuses the normal confirmed-sense eligibility query, current queue ordering fallback, existing encrypted preview session, and all 1A no-write guarantees.
10. Browser and Sense Review reuse the same finite picker presentation and marker API client. Browser bulk changes use the existing table selection owner.

## Compatibility and safety

- Existing rows read as unmarked after migration.
- Existing API consumers may ignore the additive field.
- Legacy word cards are not marker mutation targets and receive no new UI.
- No lifecycle, FSRS, ReviewLog, Leech Policy, queue ownership, or normal due membership changes.
- No free-text marker names, marker history table, deck model, or WordSense tag is added.
- Delete remains outside this ADR and remains Not Authorized.

## Acceptance

- Unit/Feature tests cover range validation, single/bulk writes, user/language/sense isolation, exact `flag:` filtering, serializer/Card Info/Sense Review payloads, marked Custom Study eligibility, and zero learning-data writes.
- Browser acceptance covers single and bulk marker changes, clearing, search, Saved Search compatibility, Card Info visibility, Sense Review visibility/change, marked Custom Study preview, two viewports, console, and database before/after evidence.
