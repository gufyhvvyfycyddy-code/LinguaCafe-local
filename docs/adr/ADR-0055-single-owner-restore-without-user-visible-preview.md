# ADR-0055 — Single-Owner Restore Without User-Visible Preview (Equal Privilege, Responsive Web)

Status: Accepted / Implemented / Production Closed<br>
Date: 2026-08-05<br>
Closed: 2026-08-06 governance reconciliation<br>
Program: `linguacafe-recovery-publication-2026-08` (CFH-02B-M6B)
Supersedes in part: ADR-0036 §M6B (preview-token and admin-only contract)

## 1. Context

M6B (restore safety) was originally implemented and accepted as an
admin-only, two-phase flow: `POST /backups/{id}/restore-preview` issued an
opaque preview token bound to the administrator, and `POST /backups/{id}/restore`
required `{preview_token, checksum, confirmation}`.

Product decisions frozen by the web-side supervisor (2026-08-05, CFH-02B-M6B)
changed the public contract:

1. LinguaCafe does not distinguish administrators from ordinary users for
   backup/restore capability. Every authenticated user has the same
   backup and restore ability.
2. Unauthenticated users still cannot access backup or restore.
3. There is no user-visible restore preview. Users do not read database
   tables, checksums, manifests, SQL, warnings, or other technical check
   results.
4. After choosing a backup the user directly opens a confirmation dialog,
   must type exactly `RESTORE` (case-sensitive, no extra whitespace), and
   then must click the confirm button once more.
5. Both desktop web and phone web (responsive browser pages, not native
   apps) must fully support the flow.
6. Removing the user-visible preview does not remove the server-side
   safety checks.

## 2. Decision

### Equal privilege

- The backup/restore API group is protected only by `auth` and
  `auth.session`. No `admin` middleware and no `is_admin` check applies to
  `/backups`, `POST /backups`, `POST /backups/{backupId}/restore`, or
  `GET /backup-restores/{operationId}`.
- The existing `AdminDashboard.vue` file name and the `/admin/{page?}`
  page path are kept, but they are not a permission boundary. The page
  route is reachable by every authenticated user; non-admin users see only
  the backup tab.

### No user-visible preview

- `POST /backups/{backupId}/restore-preview` is removed from the route
  table, the controller, the service, the UI, and the tests.
- `preview_token`, `checksum`, `tables`, `warnings`, and preview
  `expires_at` are no longer part of the client contract. The client
  submits only `{"confirmation": "RESTORE"}`.
- The confirmation dialog shows only: selected backup name or creation
  time, the risk notice, the `RESTORE` input field, a cancel button, and
  the confirm button. It never shows tables, checksums, manifests, file
  paths, SQL, preview tokens, or technical warnings.

### Server-side preflight preserved

- On `POST /backups/{backupId}/restore` the server itself reads and
  revalidates the target backup before any executable operation record is
  created: manifest format/version/driver/scope compatibility, payload size
  and SHA-256, required tables, dangerous SQL rejection, decompression
  limit, disk headroom, and path containment.
- If the preflight fails, no operation record is created and the active
  database is not touched.
- The full internal safety pipeline is unchanged: operation-private
  immutable pin, idempotent unguessable operation record, dispatched
  restore job, job-side pin revalidation, `RestoreWriteFence`,
  maintenance mode ownership, zero-writer quiescence, safety snapshot,
  isolated validation, active restore, reconnect health check, automatic
  safety rollback on failure, `failed_manual_recovery` when rollback
  fails, and secret redaction.
- `GET /backup-restores/{operationId}` stays readable during maintenance
  mode, requires login, does not check admin role, and never exposes
  paths, passwords, SQL, commands, cookies, tokens, or process output.

### Confirmation, idempotency, concurrency

- `confirmation` must equal `RESTORE` exactly (case-sensitive, no
  surrounding whitespace). Any other value is rejected with
  `BACKUP_RESTORE_CONFIRMATION_INVALID` (422).
- Repeated confirmation of the same backup by the same user returns the
  existing operation (idempotent); confirmation by a different user for
  the same backup returns a stable conflict (409). Double-clicks and HTTP
  timeouts therefore produce at most one operation.
- The restore/backup locks cover the full execution period; an active
  lease prevents a second restore from starting.
- Operation state lives in a non-database coordination store, so status
  polling does not depend on the database being restored.

### Responsive web

- Desktop target viewport about 1440×900 and phone target viewport about
  390×844. Phone flow must have no horizontal overflow, a fully visible
  dialog, a touch-friendly confirm button, focusable input, visible
  loading/error/success feedback, and the ability to recover operation
  state after a page refresh (localStorage-persisted operation id).
- This stage does not implement Android/iOS native pages.

## 3. Compatibility boundaries

- `SqlDumpInspector`, `DatabaseRestoreProcess`, `RestoreWriteFence`,
  `RejectWritesDuringRestore`, `ExecuteBackupRestore`, maintenance
  ownership, safety snapshot, rollback, coordination store, and required
  tables are preserved unchanged.
- This ADR changes only the M6B public restore contract. It did not itself
  authorize M6C or M6D; their final status is governed by the M6 implementation
  plan and their own acceptance reports.
- The `restore_preview_ttl_seconds` configuration key is no longer read by
  the service; it is left in place for backward compatibility and removed
  together with any later config cleanup slice.

## 4. Acceptance and closure evidence

CFH-02B-M6B closed after all of the following were verified:

- unauthenticated requests to backup/restore routes are rejected;
- authenticated non-admin users can list/create backups and confirm a
  restore;
- the restore-preview route is absent and `preview_token` appears nowhere
  in the client contract;
- confirmation strictness (missing, lowercase, extra-space, correct) is
  covered by tests;
- double-submit idempotency and cross-user conflict are covered by tests;
- server-side checksum revalidation and payload tampering rejection are
  covered by tests;
- the full desktop and phone web flows pass real-browser (MCP Chrome)
  acceptance with Console/Network evidence and no credential leaks;
- relevant regressions (Review FSRS, FSRS scheduling, ReviewCard manage,
  TextReader smoke) pass.

## 5. Consequences

The restore contract is single-owner and preview-free while keeping all
server-side safety. Old admin/preview tests were rewritten, not merely appended
to. ADR-0036 §M6B remains as history with a supersession note; this ADR is the
current authority for the public restore contract. The executable and browser
evidence is recorded in
`docs/testing/cfh-02b-m6b-responsive-restore-acceptance-2026-08-05.md` and its
machine-readable MCP evidence JSON.
