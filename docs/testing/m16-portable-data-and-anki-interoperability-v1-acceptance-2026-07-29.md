# M16 Portable Data and Anki Interoperability V1 Acceptance

> Status: Accepted / Closed
> Date: 2026-07-29
> Architecture: ADR-0048

## Accepted scope

M16 delivers one fixed and bounded WordSense portability contract:

- a `LinguaCafe WordSense v1` `.apkg` with one Sense Card per WordSense;
- default New-card export and an explicit, limited scheduling-state mapping;
- frozen JSON and CSV content envelopes with controlled preview/apply;
- a five-entry `.lcpkg` containing content, article structure, safe settings,
  history and a checksum manifest;
- create/update/skip/conflict classification, preview fingerprinting, an M6
  recovery point and a transaction before any accepted import write;
- a responsive Portable Data panel in the existing ReviewCard manager.

The implementation does not provide arbitrary Anki note/template conversion.
Ordinary Anki/JSON/CSV import cannot create ReviewLog rows. Only a validated
full LinguaCafe package can restore its declared scheduling and history.

## Automated and build evidence

- Final focused M16 plus database-dump run: 23 tests passed, 99 assertions.
- Final protected M6/M10/ReviewCardManage/ReviewFsrs/FSRS/WordSense/M16 run:
  670 tests passed, 2 skipped, 2,717 assertions.
- The M16 frontend guard passed.
- `npm run development` compiled successfully.
- PHP syntax, all six portable routes and scoped `git diff --check` passed.

The focused suite covers fixed-template parsing, default and explicit Anki
queues, absent revlog, content schedule omission, JSON/CSV preview and apply,
cross-origin numeric-ID safety, forged-origin rejection, database-drift
rejection, full-package checksums, schedule/article/settings restoration,
unsafe ZIP names and invalid JSON.

The final five-axis review additionally locked the exact Anki template/Deck and
rejected extra collection data, included article/settings state in the preview
fingerprint, mapped cross-instance history through the classified Sense target,
validated manifest counts/field sizes against storage limits, and proved those
paths with regression tests. Manifest history counts are derived from the
exported Sense-bound history rather than unrelated legacy Word-card logs.
The final structural pass moved classification, identity mapping, schedule
validation and lock-bound target fingerprinting into a dedicated import-planning
service. Candidate senses, cards and tags are batch-loaded; a 25-item regression
guard proves preview query growth remains bounded.

During real-browser apply, the recovery-point gate exposed a Windows Web SAPI
process-environment defect: Symfony correctly kept the password out of the
command, but filtered Windows runtime variables required by the MariaDB child
process. `DatabaseDumpProcess` now explicitly inherits only `SystemRoot`,
`WINDIR` and `ComSpec` on Windows in addition to `MYSQL_PWD`. The focused unit
test and the final protected matrix cover the change; the same rendered-page
import then completed with HTTP 200 and a real backup ID.

## Real-browser evidence

The official OpenAI in-app Browser and Chrome channels were genuinely attempted
first and finalized; both blocked loopback navigation with
`ERR_BLOCKED_BY_CLIENT`. The authorized Playwright fallback reused one system
Chrome browser, one context and one page, then closed all three.

Before any page write, `127.0.0.1:8786` returned:

```json
{"environment":"testing","database_is_testing":true,"sentinel_present":true}
```

Through rendered UI and ordinary DOM/user events, the browser:

1. logged into a task-only normal testing account;
2. downloaded the default `.apkg`; inspection found the fixed model, no media,
   `type=0`, `queue=0`, `reps=0`;
3. selected scheduling explicitly and downloaded a second `.apkg`; inspection
   found `type=2`, `queue=2`, `reps=2`, `ivl=3`;
4. uploaded JSON, observed `create 1 / update 0 / skip 0 / conflict 0`, confirmed
   the preview and applied it after a recovery point;
5. observed the imported WordSense and tag in the table; testing DB evidence
   showed two task senses, two cards and zero ReviewLog rows;
6. uploaded an invalid JSON file and observed the expected HTTP 422 plus the
   visible fixed-format validation message;
7. downloaded the full `.lcpkg`; it had exactly five entries and all four
   manifest-declared payload size/SHA-256 checks passed;
8. reloaded at 390×844 and confirmed viewport/document/body widths of 390px,
   no horizontal overflow, and visible export/import controls.

After the final review changed the visible scheduling label and extended the
explicit checkbox to JSON/CSV, a fresh testing-bound page on port 8787 clicked
that checkbox and downloaded JSON through the UI. The request contained
`include_scheduling=1`; the downloaded envelope reported
`include_scheduling=true` and its only item had `fsrs_state=review`. The final
390px screenshot was recaptured from that build.

The local, gitignored narrow-screen screenshot was written to
`output/m16-acceptance/m16-mobile.png`; the path records the inspection
location and is not versioned repository evidence. Expected local development
evidence was
limited to unauthenticated font responses, the disabled local Pusher fallback,
the deliberate invalid-file 422 and the two pre-fix backup failures described
above. The final valid preview, backup-gated apply and full export returned 200.

## Cleanup

The task account, both task WordSenses and ReviewCards, tags, settings, any task
history, the testing sentinel and the new recovery point were removed. Follow-up
counts were all zero, ports 8786 and 8787 had no listener, automation-owned
Chrome process count returned to zero, temporary fixture/import/package files
and logs were removed. The local acceptance screenshot remained outside
versioned repository evidence.
