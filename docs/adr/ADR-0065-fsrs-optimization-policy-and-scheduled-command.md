# ADR-0065: FSRS optimization policy and scheduled command

- Status: Candidate contract — pending web-side architecture acceptance; Goal authorization permits continued execution but does not self-accept this ADR.
- Date: 2026-08-23
- Scope: G-06F configurable interval optimization

## Context

The ordinary product already has one manual ReviewLog-to-FSRS optimizer and one successful-optimization timestamp in the active Review Settings Preset. Product Rebaseline §10 additionally requires users to choose manual-only operation or automatic optimization every N days, defaulting the interval to 30 days. Parameter optimization must remain separate from existing-card rescheduling.

Creating a second schedule record, optimizer, queue state or card mutation path would duplicate current authorities. Laravel 11 already discovers commands and `routes/console.php` already owns recurring command registration.

## Decision

1. Review Settings Preset schema V3 adds two normalized FSRS fields:
   - `optimization_mode`: `manual` or `interval`, default `manual`;
   - `optimization_interval_days`: integer 1–365, default 30.
2. V1 and V2 documents remain readable and normalize lazily to V3 defaults. No migration or backfill is required, and upgrade never enables automatic work without a user choice.
3. `parameters_optimized_at` remains the only successful-run timestamp. Interval eligibility is derived from it plus the configured interval. When it is absent, interval mode is due as soon as the existing review-history gate is satisfied.
4. A dedicated authenticated policy update changes only the two policy fields. Manual preview/apply keeps its existing endpoint and optimizer.
5. One daily `fsrs:optimize-due` command scans existing English preset bindings in bounded chunks and calls the same `FsrsOptimizationSettingsService::apply()` path. The schedule uses the existing `withoutOverlapping()` convention.
6. A successful automatic run updates only FSRS parameters, source and successful timestamp. Neither automatic nor manual optimization invokes restore-default or any reschedule preview/confirm service.
7. Failed attempted runs are visible through sanitized command output and application logs. They do not create a second failure ledger or advance the successful timestamp.

## Consequences

- Automatic behavior has one policy truth and one success-time truth.
- Presets shared across language bindings still retain their existing shared-config semantics; the automatic command is restricted to the current English product boundary and rechecks fresh eligibility before every attempt.
- Scheduler cadence is only a trigger. It is not eligibility truth and can safely run daily.
- Existing-card due dates remain unchanged until a user separately enters the established reschedule preview/confirmation flow.

## Verification

- V1/V2-to-V3 normalization, defaults and bounded validation tests.
- Ordinary authenticated policy update, isolation and validation tests.
- Manual mode, not-due interval mode, due successful interval mode, repeated-run suppression and missing-extension/failure observability tests.
- Due-run proof that ReviewCard due fields and ReviewLog counts do not change.
- Schedule registration, protected optimizer/scheduler/Review FSRS regressions, UI guard and Mix build.
