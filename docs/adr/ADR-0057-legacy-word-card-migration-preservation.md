# ADR-0057 — Legacy Word Card Migration Preservation and Recovery

Status: Accepted under current goal authorization

Date: 2026-08-12

Milestones: D-03 design/recovery infrastructure; D-04 controlled testing execution

## Context

Phase D must move safely identifiable legacy `ReviewCard.target_type=word` learning state into the WordSense review system without inventing historical facts. A legacy `ReviewLog` identifies its original ReviewCard but does not store a WordSense identity. Some logs even have `source=sense_review` because the generic legacy rating endpoint supplied that source for word cards. Retargeting the old card or moving its logs would therefore make present-day mapping evidence look like historical Sense review evidence.

D-02 provides the only mapping authority: a stable persisted-ID-only report classified as `unique_mapping`, `needs_user_confirmation`, or `unmapped_read_only`. Existing backup, card-snapshot, operation, and knowledge-hygiene infrastructure were evaluated before defining a migration owner.

## Decision

### 1. Identity preservation

- A legacy word card is never changed from `target_type=word` to `target_type=sense` and its `target_id` is never rewritten.
- Its ReviewLogs, lifecycle events, reschedule items, operations, reading-session rows, and occurrence rows are never moved, relabeled, copied to a Sense card, or deleted by migration.
- After a successful migration the legacy card remains under its original ID and target, archived and disabled as read-only historical evidence.
- `ReviewLog.source`, lemma, POS, translation, surface text, or approximate equality never establish historical WordSense identity.

### 2. Mapping authority and eligibility

`LegacyWordCardMigrationClassifier` remains the sole mapping authority. The migration service consumes the exact classifier schema and a SHA-256 fingerprint of the canonical report; it does not reproduce candidate inference.

Only a fresh, revalidated `unique_mapping` item may change data. The selected WordSense must still be confirmed and match the card's user and language, the legacy card must still have its original identity and before-state, and there may be at most one same-scope Sense ReviewCard for the selected WordSense. Stale, ambiguous, unmapped, cross-scope, or multiply-carded input causes a hard refusal or remains untouched; there is no partial skip-and-continue apply.

### 3. Sense-card scheduling state

- If the selected WordSense has no ReviewCard, create exactly one Sense card from the legacy card's explicit current FSRS, lifecycle, enabled, and marker state. This transfers current scheduling state only; it creates no ReviewLog and makes no claim about which Sense was reviewed historically.
- If the selected WordSense already has one ReviewCard, that card is already the scheduling authority and remains byte-for-byte unchanged. The two schedules are not merged and the existing Sense card is not overwritten.
- Migration does not call `FsrsSchedulingService`, rate a card, synthesize a rating, or write analytics.

### 4. Dedicated trace ledger

Migration uses dedicated run and item records. The mobile `operations` ledger, knowledge-hygiene operations, and reschedule snapshots have different public semantics and must not become a second migration truth source.

The run records its UUID, state, filters, classifier schema, canonical report and plan fingerprints, required backup ID and hashes, counts, and timestamps. Each item records the run, legacy card/EncounteredWord/selected WordSense/Sense card numeric identities, whether the Sense card was created or reused, the complete canonical classifier row before and after apply with fingerprints, and exact card before/after snapshots and fingerprints.

Historical numeric IDs in this ledger deliberately have no cascading or nulling foreign keys: the ledger must remain inspectable if a later defect deletes a referenced row. Only two lifecycle states exist: `applied` and `rolled_back`.

### 5. Backup and transaction boundary

D-04 apply must run under the existing machine-global testing database lease and the existing `BackupService::withExclusiveOperation()` boundary. It creates and inspects a full MySQL backup immediately before applying the plan, records its manifest and payload hashes, and protects that backup through apply and verification. The existing BackupService remains the sole full-backup owner; no second dump, restore, lock, or retention system is added.

All item locks, revalidation, Sense-card creation, legacy-card archival, and ledger writes occur in one database transaction. Backup failure, stale input, a write failure, or verification failure leaves all business tables and the migration ledger unchanged. Full backup restore is manual disaster recovery, not the ordinary per-run rollback path.

### 6. Exact rollback

Rollback locks the applied run, its items, and every affected row. Every current row must match the recorded after-fingerprint before any write occurs.

- A migration-created Sense card may be deleted only when it still exactly matches the recorded after-state and has acquired no ReviewLog or other dependent row.
- A reused Sense card is not restored because apply never modified it.
- The legacy card is restored exactly from its before-snapshot.
- Historical dependency rows remain unchanged throughout.
- Any drift aborts the entire rollback; no item is skipped and no partial recovery is committed.

Successful rollback retains the ledger and changes only its state to `rolled_back`. Repeating an already applied identical request or an already completed rollback is idempotent; a different plan for a protected legacy card is rejected.

## D-03 and D-04 boundary

D-03 adds the accepted contract, dedicated dormant ledger, recovery owner, and testing-database apply/rollback proof. It adds no command, route, controller, UI, automatic job, or production-reachable migration entry.

D-04 owns the controlled execution command and all live-writer fences. Before exposing apply it must fence legacy rating, initialization/fix commands, negative-stage/CSV recreation, chapter-finish stage writes, hard deletion, and language-data deletion so migrated history cannot be changed, recreated, or erased. D-04 must also prove the backup is retained through its verification window and that formal queues contain only Sense cards.

## Verification

Focused testing-database tests must prove:

- create-versus-reuse Sense-card paths and exact user/language isolation;
- ambiguous/unmapped/stale input makes no writes;
- backup or transactional failure makes no writes;
- ReviewLogs and every D-02 dependency table remain byte-equivalent;
- run/item hashes and immutable identities are complete;
- exact rollback restores every changed card and removes only an unchanged, dependency-free created Sense card;
- post-migration drift rejects rollback with zero partial writes;
- repeat apply/rollback is idempotent.

The D-02 classifier, Review FSRS, WordSense, backup, testing-database health, syntax, diff, and lease-cleanup checks remain regression gates.

## Consequences

Historical learning remains honest and inspectable. A newly created Sense card may carry the legacy card's current scheduling snapshot without inheriting historical Sense ratings. Existing Sense scheduling always wins over legacy scheduling. Ambiguous and unmapped cards remain legacy/read-only until explicit user confirmation or a later separately accepted decision.
