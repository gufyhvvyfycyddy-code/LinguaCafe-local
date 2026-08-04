# M6 Resilience, Health, and Isolation Implementation Plan

> Status: M6A–M6D Accepted / Closed; M6 Closed  
> Date: 2026-07-28  
> Architecture: ADR-0036

## Goal and non-goals

Close the M6 roadmap milestone through ordered M6A–M6D slices: safe automatic
backup, previewed recoverable restore, read-only article health, and executable
multi-user/resource isolation evidence.

Do not add cloud quota/billing, multimedia backup, generic storage browsing,
automatic data repair, native mobile UI, deployment, or any real restore of
development/production/user data.

## Slice order

1. **M6A Safe backup inventory**
   - replace shell concatenation and runtime `env()` reads;
   - publish manifest/checksum only after success;
   - list backups and apply post-success retention;
   - move admin creation from GET to POST;
   - configure fail-closed scheduling.
2. **M6B Restore safety**
   - archive validation and preview token;
   - safety snapshot and operation lock;
   - isolated temporary-database validation;
   - active restore coordinator with automatic safety rollback;
   - admin preview/confirm UI.
3. **M6C Article health**
   - stable finding schema and scoped read service;
   - tokenizer, structure, source/occurrence, fallback, pollution checks;
   - admin/user-visible read-only health UI;
   - no repair mutation.
4. **M6D Isolation closeout**
   - controller/job/export/storage/cache/temp-path audit;
   - owner-specific fixes and regression tests;
   - combined security and protected-module acceptance.

## Slice status

- **M6A Safe backup inventory:** `Accepted / Closed`. Evidence:
  `docs/testing/m6a-safe-backup-acceptance-2026-07-28.md`.
- **M6B Restore safety:** `Accepted / Closed`. Evidence:
  `docs/testing/m6b-restore-safety-acceptance-2026-07-28.md`.
- **M6C Article health:** `Accepted / Closed`. Evidence:
  `docs/testing/m6c-article-health-acceptance-2026-07-28.md`.
- **M6D Isolation closeout:** `Accepted / Closed`. Evidence:
  `docs/testing/m6d-isolation-closeout-acceptance-2026-07-28.md`.

M6 verdict: **Accepted / Closed**. All four ordered slices have focused,
protected-regression and real-browser evidence.

## M6A allowed files

M6A may modify only:

- `app/Services/BackupService.php`;
- `app/Services/DatabaseDumpProcess.php`;
- `app/Services/BackupSchedule.php`;
- `app/Exceptions/BackupException.php`;
- `app/Console/Commands/CreateBackup.php`;
- `app/Http/Controllers/BackupController.php`;
- `config/backup.php`;
- `routes/console.php`;
- `routes/web.php`;
- `resources/js/components/Admin/AdminDashboard.vue`;
- new focused backup tests;
- `tests/Unit/DatabaseDumpProcessTest.php`;
- `tests/Unit/BackupServiceTest.php`;
- `tests/Unit/BackupScheduleTest.php`;
- `tests/Feature/BackupManagementTest.php`;
- `tests/Fixtures/fake-mysqldump.cs`（仅编译到 testing 临时目录供真实浏览器验收）；
- `tests/Fixtures/m6a-browser-server.php`（仅 testing 环境真实浏览器验收）；
- ADR/plan/acceptance and documentation routing files.

Each later slice must append its exact files, owner, seams, and validation to
this plan before implementation. Files outside the frozen slice remain
forbidden. Existing unrelated working-tree changes remain user assets.

## M6B frozen architecture and allowed files

### Responsibility and seams

- `BackupService` remains the only published-backup inventory authority. M6B
  may add an ID lookup that returns a validated manifest and contained local
  payload path to restore-domain code; controllers never receive the path.
- `SqlDumpInspector` performs bounded streaming inspection of `.sql` and
  `.sql.gz` payloads. It owns uncompressed-size limits, table inventory,
  required-table validation, and rejection of unsupported/dangerous dump
  statements. It never executes SQL.
- `DatabaseRestoreProcess` is the only MySQL restore-process adapter. It uses
  argument arrays, `MYSQL_PWD`, resource-backed standard input, server-generated
  safe temporary database names, and `finally` cleanup. Isolated validation uses
  a dedicated validation-server identity that must differ from the active
  database identity outside testing. Active restore and rollback both reset the
  selected schema inventory before importing the pinned dump. It exposes these
  operations without logging credentials or SQL.
- `BackupRestoreService` owns preview-token issuance, durable operation creation,
  operation status, and restore orchestration.
  Preview revalidates containment, manifest compatibility, exact size/checksum,
  dump inventory, and disk headroom, then stores a short-lived token bound to
  the admin user, backup ID, and checksum. Execute consumes that token under a
  restore lock, revalidates the immutable preview facts, and runs isolated
  validation. It then enters application maintenance mode, proves application
  writers have quiesced, holds the backup-operation lock continuously while
  creating a pinned safety snapshot, restoring the active database, checking
  the restored connection, and performing any rollback.
- Preview tokens, operation records, restore/backup locks, maintenance ownership,
  and failure markers use one explicitly configured coordination cache store
  outside the restored database. The single-host default is Laravel's file
  store; multi-host deployments must configure a shared non-database store.
- Confirmation creates an opaque idempotent operation record and dispatches a
  dedicated Redis-queue job. Restore execution is not owned by the confirming
  HTTP connection. The operation record exposes queued/running/succeeded/
  rolled_back/failed_manual_recovery state after client or proxy timeout.
  The restore connection's `retry_after` must exceed the job timeout, and the
  restore/backup operation lock must outlive both, so a live lease is retried
  instead of allowing concurrent execution.
- `BackupController` remains the HTTP adapter and returns the existing stable
  `{error: {code, message}}` shape. Routes remain under the existing
  `auth`/`auth.session`/`admin` group.
- `AdminDashboard.vue` owns selection, preview presentation, exact `RESTORE`
  confirmation, loading/error/success states, and post-success list refresh.
  It never receives a filesystem path and never bypasses the preview token.

### Public HTTP contract

- `POST /backups/{backupId}/restore-preview` has no mutation payload and returns
  `201 {preview: {token, backup_id, checksum, created_at, expires_at,
  size_bytes, uncompressed_size_bytes, table_count, tables, required_tables,
  warnings}}`.
- `POST /backups/{backupId}/restore` requires
  `{preview_token, checksum, confirmation: "RESTORE"}` and returns
  `202 {restore_operation: {operation_id, backup_id, checksum, status,
  created_at}}`.
- `GET /backup-restores/{operationId}` requires the opaque status capability
  returned only by successful administrator confirmation. It reads the
  external operation record without querying the database being restored, so
  status remains observable during maintenance or manual recovery. It never
  exposes paths, credentials, SQL, or process output.
- Preview tokens are opaque, single-use, administrator-bound, backup-bound,
  checksum-bound, and expire after the configured TTL. Missing, expired,
  replayed, cross-admin, or checksum-mismatched tokens fail closed.
- Preview is read-only. Execute is the sole restore write entrance. A changed
  payload or manifest invalidates execution before any active-database write.

### Compatibility and failure boundaries

- Accepted archives must use `linguacafe-backup` format version `1`, MySQL, the
  database scope, a contained immutable payload filename, a matching byte size
  and SHA-256, and all configured required tables.
- The inspector rejects multi-database selection/creation, privilege/account
  mutation, filesystem import/export, cross-database qualified identifiers,
  unsupported executable comments, delimiter/comment confusion, and payloads
  over the configured uncompressed limit. Only an exact allowlist of standard
  mysqldump versioned session `SET` directives is accepted; ordinary table DDL
  and row data remain supported.
- Failure before active restore leaves the active database untouched. Failure
  during active restore triggers one automatic restore from the already
  published safety snapshot while locks and maintenance mode remain active.
  Rollback failure is surfaced distinctly and is never reported as success.
- The target and safety payloads are copied into operation-private, read-only
  pins while their hashes are computed. The exact pin is rehashed immediately
  before each import; source retention or replacement cannot change an
  operation already in progress.
- If the application was already in maintenance mode, execution fails closed
  rather than claiming ownership of that state.
- Maintenance mode is acquired before the safety snapshot. Normal Laravel HTTP
  and non-force queue workers stop taking work; the coordinator additionally
  activates an external write fence before maintenance, rejects new HTTP writes
  and console entry, rechecks the fence immediately before every non-read-only
  Laravel database query on existing and future connections, and proves a
  stable zero-writer window including active InnoDB transactions before
  snapshotting. The fence remains active through restore and rollback.
  Scheduler/CLI restore runbooks must honor the same external operation lock.
- Maintenance mode exempts only the capability-protected restore-status route;
  all domain routes remain unavailable while restore owns maintenance.
- Maintenance mode is released only after restore or rollback succeeds and a
  database reconnect/health check passes. Rollback or health-check failure sets
  `failed_manual_recovery` in the external operation record and deliberately
  leaves maintenance mode active.
- Feature tests replace the restore process and maintenance-mode contract with
  fakes. Optional process integration may use only a task-owned testing
  database. No M6B acceptance step executes restore against development,
  staging, production, or user data.

### Exact allowed files

M6B may modify only:

- `app/Services/BackupService.php`;
- new `app/Services/SqlDumpInspector.php`;
- new `app/Services/DatabaseRestoreProcess.php`;
- new `app/Services/BackupRestoreService.php`;
- new `app/Services/RestoreWriteFence.php`;
- new `app/Http/Middleware/RejectWritesDuringRestore.php`;
- new `app/Jobs/ExecuteBackupRestore.php`;
- `app/Providers/AppServiceProvider.php`;
- `bootstrap/app.php`;
- `app/Exceptions/BackupException.php`;
- `app/Http/Controllers/BackupController.php`;
- `config/backup.php`;
- `config/queue.php`;
- `routes/web.php`;
- `resources/js/components/Admin/AdminDashboard.vue`;
- `tests/Unit/BackupServiceTest.php`;
- new `tests/Unit/SqlDumpInspectorTest.php`;
- new `tests/Unit/DatabaseRestoreProcessTest.php`;
- new `tests/Unit/BackupRestoreServiceTest.php`;
- `tests/Feature/BackupManagementTest.php`;
- new `tests/Feature/BackupRestoreManagementTest.php`;
- new `tests/Feature/ExecuteBackupRestoreTest.php`;
- M6B test fixtures and acceptance evidence under `tests/Fixtures/` and
  `docs/testing/`;
- this plan, ADR-0036, current handoff, roadmap, documentation index, and
  documentation guard tests when required to keep authority synchronized.

### M6B minimum validation

- Preview accepts one valid bounded dump and rejects tampering, size/checksum
  drift, traversal, incompatible format/driver/scope, missing required tables,
  dangerous statements, malformed gzip, insufficient disk, and zip-bomb-sized
  expansion.
- Tokens prove expiry, single use, admin binding, backup/checksum binding, and
  no restore-process call on rejection.
- Coordination tests prove the selected cache store is non-database, operation
  creation is idempotent, Redis dispatch is required, status is
  administrator-bound, and replay returns the same operation rather than
  starting a second restore.
- Process tests prove argument boundaries, secret redaction, streamed input,
  safe temporary database identifiers, cleanup on every exit, inventory
  comparison, and timeout/failure mapping.
- Coordinator tests prove external restore lock → isolated validation →
  maintenance/quiescence → continuous backup lock → pinned snapshot → active
  restore ordering, immutable revalidation, reconnect/health check, automatic
  rollback, and maintenance retention on rollback/health-check failure.
- Feature tests prove administrator-only access, validation/error schemas, and
  no second restore write entrance.
- `npm run development`, protected import/tokenizer/Mobile/FSRS/WordSense
  regressions, documentation guards, and a real-browser preview/confirm-state
  acceptance pass. The real-browser batch must not execute a development-data
  restore.

## M6C frozen architecture and allowed files

### Goal and explicit non-goals

M6C adds one bounded, read-only article-health report for the authenticated
user's selected English learning language and one user-visible page. It does
not repair, reprocess, delete, relink, import, create learning data, call source
context recovery, or change Reader/import/tokenizer semantics.

### Responsibility and seams

- New `ArticleHealthService` is the sole report owner. Its public method is
  `report(int $userId, string $language): array`.
- Every domain query starts with both `user_id` and `language`. Infrastructure
  readiness is explicitly classified and contains no other user's identifiers
  or content.
- New `ArticleHealthController` is a thin authenticated GET adapter. It derives
  both scope values from the authenticated user and returns
  `{article_health: report}`.
- `ArticleHealth.vue` owns loading, retry, error, healthy/empty, integration,
  summary and finding presentation. It issues only GET and exposes no repair
  control.
- `Layout.vue` and `resources/js/app.js` expose `/article-health` to ordinary and
  administrator users without changing existing Reader or admin routes.

### Stable report contract

The report contains:

- `generated_at`, `scope.language`, and status `healthy|warning|critical`;
- `summary.total|critical|warning|info`;
- `checks`, keyed by check name, with
  `available|unavailable|not_configured`;
- ordered `findings`, each with stable `code`, `severity`, `category`,
  `entity_type`, nullable `entity_id`, `count`, user-facing `message`, and
  bounded non-secret `metadata`;
- `scan` metadata with the configured bound and whether any scoped scan was
  truncated.

Initial stable codes cover:

- `ARTICLE_TOKENIZER_UNAVAILABLE` and `ARTICLE_TOKENIZER_NOT_CONFIGURED`;
- `ARTICLE_BOOK_EMPTY`, `ARTICLE_CHAPTER_EMPTY`,
  `ARTICLE_TEXT_BLOCK_EMPTY`, `ARTICLE_TEXT_BLOCK_INVALID`,
  `ARTICLE_TOKENIZATION_PENDING`, and `ARTICLE_TOKENIZATION_FAILED`;
- `ARTICLE_CHAPTER_POSITION_DUPLICATE` when a supported position column exists;
- `ARTICLE_OCCURRENCE_CHAPTER_INVALID`,
  `ARTICLE_OCCURRENCE_SENSE_INVALID`,
  `ARTICLE_OCCURRENCE_CARD_INVALID`, and
  `ARTICLE_SENSE_SOURCE_CHAPTER_INVALID`;
- `ARTICLE_SOURCE_FALLBACK_EXCESSIVE`;
- `ARTICLE_VOCABULARY_POLLUTION`;
- `ARTICLE_HEALTH_SCAN_TRUNCATED`.

The current `chapters` schema has no position/order column. The
`chapter_positions` check therefore reports `not_configured`; M6C must not
invent ordering from IDs/names or add a migration. If a future supported
`position` column exists, duplicate `(book_id, position)` values are reported
within the same scoped user/language only.

Tokenizer configuration moves behind `config/article_health.php`. A blank URL
is `not_configured`; timeout, connection, non-2xx or invalid health payload is
`unavailable`, never an HTTP 500. Chapter payload decoding is bounded by the
scoped scan limit. Source checks use direct read-only queries and never call
the existing source-context recovery seam because that seam may write location
fields.

### Exact allowed files

M6C may modify only:

- new `app/Services/ArticleHealthService.php`;
- new `app/Http/Controllers/ArticleHealthController.php`;
- new `config/article_health.php`;
- `routes/web.php`;
- new `resources/js/components/Health/ArticleHealth.vue`;
- `resources/js/app.js`;
- `resources/js/components/Layout.vue`;
- new `tests/Feature/ArticleHealthTest.php`;
- M6C browser fixture/evidence under `tests/Fixtures/` and `docs/testing/` when
  required;
- this plan, ADR-0036, roadmap, current handoff, documentation index and
  directly related documentation guards.

Forbidden files include migrations, models, import/tokenizer/Reader/source
context services, WordSense/ReviewCard/ReviewLog logic, stores, queue jobs,
backup/restore implementation, and unrelated UI.

### M6C minimum validation

- Healthy, empty, corrupt/pending/failed, invalid-reference, excessive-fallback,
  pollution, optional-integration and scan-bound fixtures return the stable
  schema without writes.
- Cross-user and cross-language fixtures never affect counts, finding IDs,
  samples or check state.
- Authentication is required; no POST/PATCH/PUT/DELETE health route exists.
- Before/after counts and field snapshots prove no WordSense, occurrence,
  ReviewCard, ReviewLog, FSRS, operation-ledger, book, chapter or vocabulary
  mutation.
- Relevant import/tokenizer/Reader/source-context/WordSense/FSRS regressions,
  `npm run development`, route inspection and a real-browser loading/healthy/
  finding/error-state pass complete before M6C closes.

## M6D frozen architecture and allowed files

### Goal, audit result and explicit non-goals

M6D closes the user-content isolation boundary with executable guards. The
read-only audit covers every public file response, user-content controller,
queue job, export, storage/temp path and cache/lock in `app/`.

The audit found three corrective seams:

1. manual, font and Kanji file responses compose route input into filesystem
   paths without one canonical containment owner;
2. legacy Book, Chapter and Vocabulary endpoints consistently check `user_id`
   but several single-resource reads/writes omit the authenticated selected
   language;
3. `ProcessChapter` serializes user, chapter and language but validates only
   user/chapter ownership before processing.

Review-card exports and vocabulary CSV queries already bind both authenticated
user and language and stay in memory. Backup/restore files, cache keys and
locks are intentionally administrator-wide capabilities. Import temp names are
server-generated with user ID plus cryptographic randomness; dictionary/font/
Kanji assets are explicit administrator-wide resources. These seams require
evidence, not new abstractions.

M6D does not redesign authorization, change route or payload shapes, rewrite
Reader/import/export behavior, add a migration, make admin assets user-owned,
or alter FSRS, WordSense, ReviewCard, ReviewLog, Mobile or operation-ledger
semantics.

### Owners and data flow

- A small `SafeFilePathService` is the only owner for resolving an existing
  direct child of an approved root. It rejects empty/dot names, NUL bytes,
  forward/backward separators, missing files, symlinks or canonical paths
  outside that root before any `response()->file`.
- `BookService`, `ChapterService` and `VocabularyQueryService` remain the query
  owners. Their HTTP adapters pass authenticated `user_id` and
  `selected_language`; mutation owners apply the same language predicate before
  any write.
- `ProcessChapter` proves `(chapter_id, user_id, language)` before calling
  tokenizer/vocabulary services, on both normal and failure paths.
- Existing scoped export queries, generated temp paths and explicit global
  backup/cache resources remain unchanged and are locked by regression tests.

### Exact allowed files

M6D may modify only:

- new `app/Services/SafeFilePathService.php`;
- `app/Http/Controllers/HomeController.php`;
- `app/Http/Controllers/FontTypeController.php`;
- `app/Http/Controllers/ImageController.php`;
- `app/Services/ImageService.php`;
- `app/Http/Controllers/BookController.php`;
- `app/Services/BookService.php`;
- `app/Http/Controllers/ChapterController.php`;
- `app/Services/ChapterService.php`;
- `app/Http/Controllers/VocabularyController.php`;
- `app/Services/VocabularyService.php`;
- `app/Services/VocabularyQueryService.php`;
- `app/Jobs/ProcessChapter.php`;
- new `tests/Feature/M6IsolationAuditTest.php`;
- directly affected existing tests only when a required method signature
  changes;
- M6D browser fixtures/evidence under `tests/Fixtures/` and `docs/testing/`;
- this plan, roadmap, current handoff/context, documentation index and directly
  related documentation guards.

Forbidden files include routes, middleware, models, migrations, Reader/import
implementation, review/FSRS/sense/mobile/operation-ledger logic, backup/restore
implementation and unrelated UI.

### M6D minimum validation

- Cross-user and cross-language Book, Chapter and Vocabulary resource reads and
  writes are rejected without observable mutation.
- A mismatched ProcessChapter payload cannot tokenize, index, change chapter
  status or broadcast another language's chapter.
- Traversal using slash, backslash, encoded separators, dot names, symlinks and
  missing paths cannot escape approved manual/font/image roots; valid existing
  files still render.
- Vocabulary and review-card exports exclude another user/language; backup
  status capabilities reveal nothing without the exact token; task-generated
  temp paths cannot delete a sibling path.
- Relevant legacy article/import/Reader/export, Mobile, WordSense and FSRS
  regressions pass. The valid public-file paths receive a real-browser smoke;
  rejected file reads are proven by HTTP tests and Network evidence.

## M6A data flow

`scheduler/admin POST → BackupController/command adapter → BackupService →
Process runner → temporary dump/archive → checksum + manifest → atomic publish
→ post-success retention → stable response`

Failure before publication removes only task-owned temporary files and leaves
all previously successful backups untouched.

## M6A minimum tests

- Process receives argument boundaries and no credential appears in command,
  response, exception, or log text.
- Failed dump/finalization publishes no backup and deletes no prior backup.
- Successful backup produces matching manifest, size, SHA-256, and stable ID.
- Retention keeps the newest successful archive and rejects an invalid maximum.
- List ignores temporary, malformed, traversing, and non-backup files.
- Admin authorization and POST/GET method contracts hold.
- Disabled/invalid schedule fails closed.
- Existing import, tokenizer, Mobile M1/M2, FSRS, and frontend build regressions
  remain green.

## Stop conditions

Implementation may continue autonomously inside the frozen slice. Stop only for
an authority conflict, scope expansion outside M6, real non-testing restore or
migration execution, secret access, deployment, or an irreversible third-party
action. A fake runner, task-owned temporary archive, and dedicated testing
database are authorized verification resources.
