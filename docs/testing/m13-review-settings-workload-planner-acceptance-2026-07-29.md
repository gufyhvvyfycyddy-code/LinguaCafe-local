# M13 Review Settings and Workload Planner V2 Acceptance

> Status: Accepted / Closed
> Date: 2026-07-29
> Architecture: ADR-0045

## Accepted behavior

M13 closes the bounded Review Settings V2 slice:

- preset schema V2 keeps V1 normalization compatibility;
- learning and relearning steps, interval bounds and future-only Easy Days are
  owned by the existing FSRS scheduling service;
- `fsrs_step_index` is additive and legacy eight-field undo snapshots remain
  valid;
- automatic advance is opt-in, requires a visible timer and never chooses a
  rating;
- workload projections cover 30, 90 and 365 days without writing cards or
  history;
- optimization diagnostics report insufficient history and suspicious rating
  use;
- old-card rescheduling remains behind the existing preview, backup, risk
  confirmation and undo flow.

## Automated evidence

- Focused M13, preset and optimization run: 104 tests passed, 472 assertions.
- Protected FSRS, WordSense and operation-ledger run: 412 tests passed, 1961
  assertions; one unrelated tokenizer test was skipped.
- Final scheduler/formal-rating regression: 81 tests passed, 451 assertions.
- M13 UI guard passed.
- `npm run development` completed successfully; only existing Sass deprecation
  warnings remained.
- `git diff --check` reported no whitespace errors.

## Server-bound real-browser evidence

The official Browser and Chrome channels had already been genuinely attempted
for this localhost batch and could not provide a stable writable localhost
session. The accepted fallback used one Playwright-controlled Chromium page and
closed its page, context and browser afterward.

Before any write, the same `127.0.0.1:8783` server returned:

```json
{"environment":"testing","database_is_testing":true,"sentinel_present":true}
```

With a task-only administrator created through the rendered setup form, the
390×844 browser session:

1. opened `/admin/reviews` with no horizontal overflow;
2. saved learning steps `5, 20`, blank relearning steps, maximum interval 365,
   minimum relearning interval 2, question timer 12 seconds and answer timer 8
   seconds;
3. enabled automatic advance and audio autoplay through visible controls;
4. reloaded and verified every value persisted;
5. generated the empty-state workload plan;
6. opened Advanced Tools and verified reschedule preview and undo controls.

All `/settings/fsrs/*` requests returned 200. The only failed requests were the
existing unauthenticated font lookup on setup/login; the local Pusher fallback
also remained unavailable as expected. The first diagnostic run exposed two
`/settings/global/get` failures because the isolated testing database lacked
the legacy `reviewIntervals` fixture. Adding that testing-only legacy fixture
removed both failures, proving they were not caused by the M13 endpoints.

The local, gitignored screenshot was written to
`output/m13-acceptance/m13-review-settings-workload-planner.png`; the path
records the inspection location and is not versioned repository evidence.

## Cleanup

The task administrator, preset bindings, presets, testing-only
`reviewIntervals` fixture and acceptance sentinel were removed. Follow-up
queries returned zero for every task marker, and the testing server was stopped.
