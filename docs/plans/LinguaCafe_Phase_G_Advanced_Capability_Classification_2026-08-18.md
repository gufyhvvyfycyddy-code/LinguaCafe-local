# LinguaCafe Phase G Advanced Capability Classification — 2026-08-18

Status: G-04 final classification

Committed product evidence baseline: `77dd62f08624984ff64385cee9f606de6719da0c`

G-04 classification HEAD: `abf745873c7d159642678d0ed06d24ce6e77e172`

`77dd62f... -> abf7458...` changes only the Phase G Goal ledger document, so the G-03 same-code browser/regression evidence remains applicable.

## Purpose and taxonomy

G-04 classifies the 11 advanced capability families after G-02 hid ordinary entries and G-03 proved the main Reader / Review / Vocabulary / Material / Mobile flows remain independent.

Only these labels are used:

- `KEEP_ADVANCED`: current advanced user value, contextual caller, shared/mobile caller, or safety/recovery use remains.
- `COMPAT_READ_ONLY`: the active product surface has moved on, but history, old links/contracts, persisted data readability, migration, audit, or compatibility still requires retention.
- `DELETE_CANDIDATE`: allowed only when current callers, routes, shared owners, compatibility, safety/recovery/migration duties, accepted retention contracts, and current product value are all absent.

A family may split between its current surface, legacy compatibility slice, lower owner, and data contract. Hidden ordinary navigation never makes a shared lower owner deletable.

## 11-family final matrix

| # | Family | User-facing surface | Lower owner / data | Current callers / consumers | Compatibility / safety responsibility | Ordinary-user visibility | G-05 allowed next action | G-05 prohibited deletion boundary | Confidence |
|---|---|---|---|---|---|---|---|---|---|
| 1 | Browser / Card Info | `KEEP_ADVANCED` | `KEEP_ADVANCED` | Sense Review, Study Overview deep links, FSRS tools, Mobile search/sync, Portable Data, Statistics, Knowledge Hygiene, Special Study | Card detail and operation/lifecycle history remain current; query/mutation/serializer owners are shared | Keep out of ordinary primary navigation; retain advanced/contextual access | No deletion; later UI simplification only with fresh caller proof | Do not delete `/review-cards/manage`, Card Info detail contract, query/mutation/serializer owners | High |
| 2 | Custom Study / Special Study | Current `/custom-study` M12 Special Study = `KEEP_ADVANCED`; legacy Custom Study 1A preview-token slice = `COMPAT_READ_ONLY` | Current Special Study owners = `KEEP_ADVANCED`; legacy preview-token compatibility owners retained | Current Special Study sessions/filters/formal answers; Browser still emits marked-study deep link | ADR-0040 requires existing `/custom-study/*` preview-token endpoints to remain compatible | Current Special Study stays advanced; do not direct ordinary users to legacy 1A | Preserve both current M12 capability and legacy compatibility; contract repair may be handled later | Do not delete current page/services or legacy 1A compatibility stack while ADR/test contract remains | High; marked-link mismatch carried forward |
| 3 | Saved Search | Active Browser CRUD/persistence = `KEEP_ADVANCED`; saved-search schema V1 reading = `COMPAT_READ_ONLY` | Service/model/query reuse = `KEEP_ADVANCED` | Study Overview consumes `saved_search_id` and reconstructed Browser filter state | ADR-0038 requires V1 rows to remain readable and upgrade only when edited | Advanced Browser workflow | Preserve active CRUD; preserve V1 reads until stored-data/contract closure | Do not delete routes/service/model/table or V1 readability | High |
| 4 | Tag / Marker | `KEEP_ADVANCED` | `KEEP_ADVANCED` | Sense Review, Browser table/Card Info, Special Study filters; Portable Data also carries tags | Persisted tag/marker learning metadata must remain readable and scoped | Contextual/advanced only | No deletion; keep contextual controls and filters | Do not delete APIs, fields/relations, Portable Data tag contract, or filter behavior | High |
| 5 | Manual Scheduling / Review Control | `KEEP_ADVANCED` | `KEEP_ADVANCED` | Browser and Sense Review mount scheduling controls; Web and Mobile share `ReviewCardManualOperationService` | Operation ledger, preview/apply, undo/redo and audit history are current safety behavior | Contextual advanced control | No deletion | Do not delete shared manual-operation service, ledger/history, undo/redo, or Mobile contract | High |
| 6 | FSRS technical settings / metrics | Current settings, tools and status/metric panels = `KEEP_ADVANCED` | Scheduling, stats, preview, workload and reschedule owners = `KEEP_ADVANCED` | Formal rating, interval preview, Study Overview, workload simulation, optimization/reschedule tools; status stats feed current goal/workload UI | Scheduling state, ReviewLog/snapshots and persisted settings remain current | Keep technical detail in admin/advanced settings; it need not appear in ordinary learning flow | Presentation may be simplified later, but no G-05 deletion is currently authorized | Never delete `FsrsSchedulingService` or current settings/stats contracts; do not treat technical presentation as legacy without fresh proof | High |
| 7 | Study Overview / complex statistics | `KEEP_ADVANCED` | `KEEP_ADVANCED` | `/study-overview`, Home Statistics, Saved Search scope, Browser query, ReviewLog/FSRS metrics, CSV/PDF export | Read-only analytics definitions are current product behavior | High-level summary may remain visible; complex controls/exports belong in advanced contexts | No deletion under orphan rule | Do not delete Overview/Statistics routes, services, filters, export definitions or shared metric semantics | High |
| 8 | Portable Data / `.apkg` | `KEEP_ADVANCED` | `KEEP_ADVANCED` | `.apkg`/JSON/CSV/`.lcpkg` export and controlled import; Browser query/serializer; Backup recovery point | File-format interoperability, migration, user/language scope, preview/apply and rollback duties remain current | Advanced, low-frequency data tool | No deletion | Do not delete formats, parsers/plans, preview/apply, serializer/query reuse, or Backup safety integration | High |
| 9 | Backup / Restore | Page and current recovery operations = `KEEP_ADVANCED`; inert `backup.restore_preview_ttl_seconds` config placeholder = `COMPAT_READ_ONLY` | `BackupService`, `BackupRestoreService`, schedule/command and restore safety pipeline = `KEEP_ADVANCED` | Scheduled backup, manual restore, Portable Data, Knowledge Hygiene, legacy migration recovery | Restore fences/locks/snapshot/rollback are current; ADR-0055 explicitly retains the old TTL key for backward compatibility until a later config cleanup | Advanced/manual recovery access | No current G-05 deletion candidate; later authorized config cleanup may supersede the compatibility placeholder | Do not delete page, services, scheduler/command, write fence, locks, snapshots, rollback, or the TTL compatibility placeholder under the current G-04 contract | High |
| 10 | Article Health | `KEEP_ADVANCED` | Controller/service/data contract = `KEEP_ADVANCED` | Current `/article-health` page calls the scoped read-only report | Reads current content/source/tokenizer integrity data and preserves no-write diagnostics | Advanced diagnostic page | No deletion | Do not delete route/page/controller/service or scoped diagnostic schema while current product value remains | High |
| 11 | Knowledge Hygiene | `KEEP_ADVANCED` | `KEEP_ADVANCED` | Browser panel and controller; service reuses Browser query, WordSense and Backup safety owners | Preview/apply, backup-gated merge, safe delete, Recent Deletes and conflict-checked undo protect shared learning data | Advanced maintenance surface | No deletion | Do not delete service, operation/history state, backup gate, safe-delete/merge/undo contracts or shared data rebinding | High |

## Reconciled classification conflicts

Two predecessor differences required final adjudication:

1. `backup.restore_preview_ttl_seconds`: one report proposed `DELETE_CANDIDATE`. Final classification is `COMPAT_READ_ONLY`. Current runtime code no longer reads the key, but ADR-0055 explicitly says it is left in place for backward compatibility and removed with a later config-cleanup slice. The strict orphan test therefore fails both the compatibility-dependency and accepted-contract-retention conditions.
2. FSRS status / engineering-style metrics: one report proposed `COMPAT_READ_ONLY` for the display layer. Final classification is `KEEP_ADVANCED`. `FsrsStatusPanel.vue` is still a current mounted advanced/admin surface, and its loaded stats are also passed into the current goal/workload UI. No current evidence makes that surface historical-compatibility-only.

These resolutions leave no classification blocker.

## `DELETE_CANDIDATE` inventory

**0 items.**

No family, surface, lower owner, data contract, config leaf, or compatibility slice currently satisfies the full strict orphan standard.

Accordingly, this G-04 result does not authorize any production deletion in G-05 unless a later authorized reclassification first establishes a new confirmed `DELETE_CANDIDATE`.

## `KEEP_ADVANCED` inventory

All 11 families retain at least one active `KEEP_ADVANCED` surface or lower owner:

- Browser / Card Info;
- current Custom Study / Special Study;
- active Saved Search;
- Tag / Marker;
- Manual Scheduling / Review Control;
- FSRS scheduling, settings, tools and current metrics/status;
- Study Overview / complex statistics;
- Portable Data / `.apkg` interoperability;
- Backup / Restore and its safety pipeline;
- Article Health;
- Knowledge Hygiene.

## `COMPAT_READ_ONLY` inventory

Three narrow compatibility slices remain:

1. Legacy Custom Study 1A preview-token route/component/API compatibility required by ADR-0040 and current compatibility regression.
2. Saved Search schema V1 readability required by ADR-0038; V1 rows upgrade only when edited.
3. Inert `backup.restore_preview_ttl_seconds` configuration placeholder retained by ADR-0055 for backward compatibility until a later authorized config-cleanup contract supersedes that retention.

`COMPAT_READ_ONLY` describes why these slices must remain available. It does not imply every underlying HTTP route is literally GET-only.

## Protected shared owners and data contracts

G-05 must preserve these current shared boundaries unless a later classification proves every caller/contract has disappeared:

- `ReviewCardManageQueryService`, `ReviewCardManageMutationService`, `ReviewCardManageItemSerializerService`, Card Info detail read model;
- Special Study candidate/session/formal-answer owners and canonical `ReviewCardService` / FSRS rating path;
- Tag/Marker persisted data, filters and Portable Data compatibility;
- `ReviewCardManualOperationService`, operation ledger and undo/redo/audit history;
- `FsrsSchedulingService`, active FSRS settings/stats/preview/workload/reschedule contracts;
- `ReviewCardSavedSearchService`, schema V1 readability and Study Overview scope reconstruction;
- `StatisticsService` and `StudyOverviewQueryService` metric definitions;
- Portable Data format/plan/query/serializer contracts and recovery-point integration;
- `BackupService`, `BackupRestoreService`, scheduled backup, restore fencing/locks/snapshot/rollback;
- `ArticleHealthService` scoped read-only diagnostic contract;
- `KnowledgeHygieneService`, backup gate, safe-delete/merge/rebind/undo contracts.

## Custom Study marked-link carry-forward

The current committed Browser still sends:

`/custom-study?mode=marked`

The current M12 Custom Study page still has no matching `mode=marked` query consumer.

This is a live caller/consumer contract mismatch. It does not make Browser, Marker, Custom Study, or Special Study deletable. G-04 records it only; this milestone does not repair it.

## G-05 execution boundary

G-05 may delete only a confirmed `DELETE_CANDIDATE` from this document. The current inventory is empty, so G-04 authorizes no production deletion.

G-05 must not infer deletion eligibility from hidden ordinary navigation, low-frequency use, engineering-looking UI, a short direct caller chain, or the marked-study mismatch. Any future proposed deletion needs a fresh caller/route/shared-owner/compatibility/safety/data/ADR scan and a new authorized classification if it is not already listed here.

## Evidence anchors

Primary evidence used for final reconciliation:

- `REPORT-04-G03-FINAL-CLOSURE-R3-2026-08-17.md`;
- `REPORT-03-G03-RECOVERY-INTEGRITY-AUDIT-R3-2026-08-17.md`;
- `REPORT-03-G03-MOBILE-ADVANCED-CONSUMERS-REGRESSION-R1-2026-08-17.md`;
- G-04 canonical reports 01 / 02 / 03;
- committed source at `abf7458...` / product baseline `77dd62f...`;
- ADR-0038 Saved Search compatibility;
- ADR-0040 Special Study / legacy Custom Study compatibility;
- ADR-0055 current restore contract and TTL compatibility note.

Classification unresolved count: **0**.

Known non-blocking contract mismatch count: **1** (`Browser -> /custom-study?mode=marked`).

## G-04 safety statement

G-04 deleted no production feature, route, service, data contract, test, or configuration. It performed classification/documentation only and did not enter G-05.
