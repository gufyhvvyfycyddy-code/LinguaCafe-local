# ADR-0047: M15 Browser Knowledge Hygiene V3

> Status: Accepted / Implemented / Closed
> Date: 2026-07-29

## Context

M10 established the unified WordSense query, tags, Saved Searches and Browser
foundation. M11 established previewed manual scheduling and the operation
ledger. M15 adds high-efficiency knowledge cleanup without exposing arbitrary
fields or allowing irreversible bulk edits.

## Decision

1. A focused `KnowledgeHygieneService` owns preview, apply, audit and undo for
   text replacement, safe deletion and confirmed duplicate merging.
2. One additive operation table stores user/language, operation type/status,
   bounded subject IDs, before/after snapshots, preview fingerprint, metadata
   and undo time. Snapshots never contain credentials or unrelated user data.
3. Find/replace is limited to `sense_zh`, `sense_en`,
   `example_sentence_zh` and `example_sentence_en`. It reuses the M10 query,
   previews exact before/after values and impact count, caps a batch at 500,
   rejects stale preview hashes and is undoable.
4. Browser column visibility/order and named views are bounded preferences in
   the existing per-user Settings store. Only the existing serializer's
   allowlisted columns and M10 filter fields are accepted.
5. Duplicate analysis is deterministic and read-only:
   - exact normalized lemma/POS/definitions -> `exact_duplicate`;
   - same lemma/POS with a missing definition -> `needs_distinction`;
   - high definition-token overlap -> `possible_merge`;
   - otherwise -> `keep_separate`.
   Any AI output remains an optional suggestion and can never apply a merge.
6. A merge always requires a fresh preview, explicit primary/duplicate choice,
   an automatic M6 backup, impact display and explicit confirmation. The
   primary card keeps its schedule. Occurrences, tags and ReviewLogs are
   rebound; the duplicate Sense is rejected and its card removed. The complete
   bounded snapshot supports conflict-checked undo.
7. Existing Browser single/bulk delete paths become safe-delete operations.
   They retain the rejected WordSense, ReviewLogs and occurrences, record the
   deleted card/sense/link snapshot, and appear in a 30-day Recent Deletes
   list. Restore recreates the same card identity and link state only when no
   conflicting card exists.
8. Existing bulk Tag, Marker, lifecycle and M11 safe-scheduling paths remain
   the only owners of those mutations. M15 surfaces them together but does not
   create duplicate mutation logic.

## Safety

- Every query and operation is scoped by authenticated user and selected
  language.
- Apply/undo run in transactions and compare current state with the preview or
  stored after snapshot.
- A failed backup aborts merge before any knowledge mutation.
- No endpoint accepts FSRS fields, raw SQL, arbitrary model names or arbitrary
  column names.
- Restores never overwrite a newer conflicting Sense/card/link state.

## Verification

Tests cover isolation, allowlists, stale previews, zero-write preview,
find/replace undo, safe delete/restore, duplicate classes, backup-gated merge,
merge undo and unchanged ReviewLog cardinality. Existing M10/M11/ReviewCard
management tests remain green. Real-browser acceptance exercises preferences,
preview/apply/undo and Recent Deletes on a server-bound testing database.
