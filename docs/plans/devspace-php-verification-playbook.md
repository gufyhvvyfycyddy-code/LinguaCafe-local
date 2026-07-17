# DevSpace PHP / PHPUnit Verification Playbook

> **Status**: Current verification playbook
> **Last updated**: 2026-07-17
> **Scope**: PHP, Artisan, and PHPUnit verification when streamed output is large, truncated, unavailable, or replaced by a 502/tool transport failure
> **Authority boundary**: This playbook defines how to recover trustworthy test evidence. It does not authorize product changes, database resets, commits, pushes, or a next phase.

## 1. Trigger

Use this playbook when any of the following is true:

- a PHP/PHPUnit command is expected to produce large output;
- streamed output is truncated or the tool returns 502 before a trustworthy result is visible;
- a full suite is too large for one reliable tool response;
- a prior report claims pass without a recoverable exit code and result summary.

Do not use a transport failure as evidence that tests passed or failed.

## 2. Required Sequence

1. Run the narrowest relevant test or command first.
2. For a large suite, use a trustworthy job/log facility or capture output outside the repository while preserving the real process exit code.
3. Record the exact command, exit code, passed/failed/skipped counts, and any failed test names.
4. If one full run is unreliable, split by relevant suite or test group without changing test behavior.
5. Run the smallest regression set that protects the modified contract, then the broader suite required by the task.
6. If no method yields both a trustworthy exit code and a trustworthy result summary, report `Incomplete` and identify the unverified command. Do not convert missing evidence into pass.

## 3. Evidence Contract

A PHP verification result is acceptable only when the report includes:

- the exact command;
- a real exit code or equivalent tool completion status;
- a result summary with pass/fail/skip counts when the runner provides them;
- the failed test and error summary for any non-zero result;
- which runs were focused, split, or full-suite;
- whether output was captured outside the repository or provided by the tool/job system.

Partial output without a completion result is diagnostic evidence only.

## 4. Prohibited Recovery Shortcuts

- Do not rerun with weaker assertions, excluded failing tests, or modified configuration merely to obtain green output.
- Do not edit or delete tests because the transport is unreliable.
- Do not claim a full-suite pass from focused tests.
- Do not infer pass from silence, timeout, truncation, 502, or a still-running process.
- Do not place temporary logs, credentials, or generated artifacts in tracked repository paths.
- Do not run destructive database commands, notification scripts, DCP, commit, or push unless separately authorized by the current task.

## 5. Failure And Exception Owner

- A real non-zero test result is a failure, not `Incomplete`.
- Missing or untrustworthy completion evidence is `Incomplete`.
- Only the current user/task contract may reduce the required verification scope. A local tool preference, old task report, or legacy appendix cannot waive it.

## 6. Final Report Line

Use one explicit conclusion:

- `PHP verification: PASS — <commands and counts>`
- `PHP verification: FAIL — <failed command and failure summary>`
- `PHP verification: INCOMPLETE — <unverified command and evidence gap>`
