# ADR-0036 — M6 Resilience, Health, and Isolation Boundaries

Status: Accepted for implementation  
Date: 2026-07-28  
Roadmap: `docs/plans/cloud-first-mobile-product-and-technical-milestones-2026-07-28.md`

## 1. Context

M6 combines three high-risk responsibilities that must close before external
test users enter:

1. backup and recovery;
2. article/import health;
3. multi-user and operational-resource isolation.

The current repository has a minimal `BackupService`, an admin GET mutation,
and a scheduled command. The service concatenates database settings into a
shell command, reads `env()` at runtime, writes to a hard-coded container path,
deletes old files before proving a new backup, and has no manifest, preview,
restore, checksum, or rollback contract. These behaviors cannot be extended as
the M6 foundation.

Tokenizer diagnostics and many user/language guards already exist and should
be reused. M6 is not authorization for a general repository rewrite.

## 2. Decision

M6 is implemented and accepted as four ordered slices. Each slice has one
owner, independent tests, and a bounded file set.

### M6A — Safe backup inventory and automatic creation

- `BackupService` becomes the only backup-domain owner.
- Database credentials come from Laravel configuration, never direct runtime
  `env()` reads.
- External commands use Symfony Process argument arrays. Credentials are
  passed through the child environment, never concatenated into a command or
  returned in logs.
- A backup is first written under a task-unique temporary name. It becomes
  visible only after the dump, archive finalization, checksum, and manifest all
  succeed.
- Retention runs only after successful publication and never deletes the
  newest successful backup.
- Each archive has immutable metadata: backup ID, format/schema version,
  created time, database driver/name fingerprint, application version,
  included scopes, byte size, SHA-256, and status.
- Admin APIs use POST for creation and GET for listing. Existing admin
  authorization remains required.
- The scheduler reads `config('backup.schedule')`; disabled/invalid schedules
  fail closed without breaking application boot.

### M6B — Restore preview, safety snapshot, and recovery

- Restore is admin-only and always two-phase: preview then explicit execute.
- Preview verifies archive containment, checksum, format/schema compatibility,
  required entries, table inventory, and available disk space. It performs no
  domain write.
- Execute requires the preview token and exact backup checksum. A changed file
  invalidates the preview.
- Execute first publishes a separate safety snapshot. If this cannot complete,
  restore does not start.
- The restore runner validates the target in an isolated temporary database
  before touching the active database.
- Active restore runs under maintenance/operation lock. On any failure it
  automatically restores the safety snapshot before releasing the lock and
  reports whether rollback itself succeeded.
- A restore never runs from tests against the development database. Feature
  tests use a fake process/restore runner; integration validation may use only
  task-owned testing databases.
- Executing a real development, staging, production, or user-data restore
  remains a reserved ADR-0031 stop line and is not implied by implementing the
  feature.

### M6C — Article and import health

- A read-only `ArticleHealthService` owns health findings and stable codes.
- Initial checks cover tokenizer/readiness, empty books/chapters/text blocks,
  duplicate chapter positions, invalid source/occurrence references,
  excessive source-context fallback, and URL/email/path-like vocabulary
  pollution.
- Findings are user/language scoped unless explicitly classified as
  administrator-wide infrastructure readiness.
- Health checks never repair data. Every future repair must have a separate
  preview, backup requirement, ownership check, and test.
- Optional integrations report `available`, `unavailable`, or `not_configured`;
  absence is not an HTTP 500.

### M6D — Isolation audit and executable guards

- Audit public controllers, console jobs, queues, exports, storage paths,
  cache keys, locks, and temporary files that touch user content.
- User-scoped resources include user ID and, where applicable, language ID in
  both authorization queries and operational keys/paths.
- Admin-wide resources are explicit and must not be exposed through ordinary
  user endpoints.
- Findings are either fixed in a small owner-specific slice or recorded with a
  reproducing test and severity. M6 cannot close with an unresolved
  cross-user read/write, unscoped user export, path traversal, secret exposure,
  or restore integrity finding.

## 3. Compatibility boundaries

- Existing article import, Reader, ECDICT, Word/Sense FSRS, ReviewLog, Mobile
  API, and operation-ledger contracts remain unchanged.
- M6 does not add cloud-storage quotas, multimedia backup, arbitrary user
  filesystem browsing, or background restore without confirmation.
- Existing `/backups/create` GET is retained only until the admin UI is moved
  in the same M6A slice, then removed rather than kept as a second mutation
  path.
- Backup/health code must not create WordSense, ReviewCard, ReviewLog, or
  operation-ledger entries.

## 4. Acceptance

M6 closes only when:

- safe create/list/retention and failure cleanup pass with fake process tests;
- preview rejects tampered, incompatible, traversing, or incomplete archives;
- restore execution proves safety-snapshot-first, isolated validation,
  rollback-on-failure, and lock behavior with fakes/testing databases;
- article health normal and rejection fixtures pass;
- the ownership/resource audit has executable guards for every corrected
  critical seam;
- relevant backend, import, Reader, tokenizer, Mobile, FSRS, and frontend build
  regressions pass;
- the visible admin backup/restore and health workflows have real-browser
  evidence.

## 5. Consequences

M6 becomes a sequence of reviewable resilience slices instead of one broad
rewrite. Real restore execution remains deliberately separated from feature
implementation and automated testing, preserving the roadmap's no-user-data
mutation boundary.
