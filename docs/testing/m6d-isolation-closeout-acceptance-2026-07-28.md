# M6D Isolation Closeout Acceptance — 2026-07-28

Status: **Accepted / Closed**
Roadmap: `docs/plans/cloud-first-mobile-product-and-technical-milestones-2026-07-28.md`
Architecture: `docs/adr/ADR-0036-m6-resilience-health-and-isolation-boundaries.md`
Implementation plan: `docs/plans/m6-resilience-health-isolation-implementation-plan.md`

## 1. Accepted scope

M6D closes the audited public-file and user-content isolation seams:

- `SafeFilePathService` resolves only an existing, non-symlink direct child of
  an approved root and rejects dot names, NUL, slash/backslash traversal,
  missing files and canonical escape;
- manual, font, Kanji and user-owned book-image responses use that resolver;
- Book, Chapter and Vocabulary single-resource reads and writes bind both the
  authenticated user and selected language;
- vocabulary example and source bridges validate the target or chapter before
  creating any learning record;
- `ProcessChapter` validates `(chapter_id, user_id, language)` before any
  tokenizer, vocabulary, status or broadcast work;
- existing export queries remain user/language scoped, while administrator-wide
  backup/cache capabilities and server-generated user/random temporary names
  retain their reviewed boundaries.

No route or payload shape, migration, Reader/import flow, FSRS, ReviewLog,
Mobile, operation-ledger, backup or restore semantics changed.

## 2. Focused and protected verification

The final M6A–M6D focused closeout set passed **85 tests / 317 assertions**,
including `M6IsolationAuditTest` at **11 tests / 56 assertions**. It proves:

- cross-user and cross-language Book, Chapter and Vocabulary requests cannot
  read, mutate, enqueue or create bridge records;
- a mismatched queued chapter cannot call processing services or change data;
- valid direct-child files work while traversal, encoded backslashes, NUL,
  missing files and symlinks fail closed;
- example targets and vocabulary exports cannot cross ownership boundaries.

The final protected matrix also passed:

- `ReviewFsrsTest`: **63 passed / 375 assertions**;
- `FsrsSchedulingServiceTest`: **9 passed / 46 assertions**;
- WordSense filter: **203 passed / 1 skipped / 873 assertions**;
- Mobile foundation/ledger, vocabulary search/enrollment and affected units:
  **57 passed / 383 assertions**;
- tokenizer, Reader, source-context, write-boundary and ReviewCard management
  filter: **419 passed / 2 skipped / 1,647 assertions**;
- final affected Book/Chapter/Vocabulary subset:
  **42 passed / 187 assertions**.

PHP syntax checks passed for all changed PHP files. The M6C frontend build
already passed and M6D changed no frontend source.

## 3. Full-suite diagnostic

`php artisan test` reached the late suite and then exhausted its fixed 128 MB
child-process memory limit. Running PHPUnit directly with a 512 MB limit
completed **3,397 tests / 14,015 assertions**, with 13 failures, 64
deprecations and 14 skips.

Eleven failures were shared-state/order-sensitive count assertions; sampled
failures passed independently, and every M6D-related protected module passed in
isolation as listed above. Two failures also reproduce independently and are
pre-existing documentation-guard mismatches outside M6D:

- the documentation index does not list an old AI V6 preflight plan;
- ADR-0005 lacks one exact English phrase expected by its guard.

M6D does not edit those unrelated AI documents or weaken their tests. These
diagnostics are recorded as repository debt, not hidden or misclassified as
M6D behavior.

## 4. Real-browser and cleanup evidence

Channel: official OpenAI Browser plugin, one automation-owned page against a
task-owned testing listener at `127.0.0.1:8092`.

The rendered-page batch:

1. registered and logged in a testing-only user through visible UI controls;
2. clicked `用户手册` and observed rendered manual content;
3. navigated to an encoded backslash path and observed rendered `404 NOT FOUND`;
4. returned to the manual and clicked `FAQ`, observing the rendered FAQ page.

The browser client blocked direct top-level navigation to the raw `text/plain`
file response, while the product UI's DOM-driven request rendered the same
valid FAQ resource. No API write substituted for a UI action.

Closeout closed the only automation-owned page, verified no official-browser
tabs remained, stopped the exact port-8092 listener and deleted only the exact
testing user and its task-owned goal/settings rows. The testing database was
then verified empty across users, content, learning, review, Mobile and
operation-ledger tables.

## 5. Quality review

- **Correctness:** ownership and queued-language predicates are applied before
  reads, writes or service calls; valid same-scope behavior remains covered.
- **Security:** canonical file containment is centralized and rejects both
  traversal encodings and symlinks; content access is user/language scoped.
- **Compatibility:** route and response shapes remain unchanged, and affected
  legacy/module tests pass.
- **Side effects:** rejected requests and jobs prove zero observable mutation or
  processing; cleanup touched only task-owned testing fixtures.
- **Architecture:** controllers remain adapters, service owners retain their
  domains, and no new cross-module write entrance was introduced.

Verdict: **Approve M6D. M6A–M6D and M6 are Accepted / Closed.**
