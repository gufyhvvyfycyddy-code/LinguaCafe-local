# ADR-0046: M14 Statistics and Card Info V3

> Status: Accepted / Implemented / Closed
> Date: 2026-07-29

## Context

The current home statistics service exposes a few legacy reading counters.
M14 requires Anki-aligned review statistics, unified query scope, richer Card
Info, responsive summaries and CSV/PDF export. ReviewLog and ReviewCard remain
the scheduling/history authorities; pages must not calculate competing metric
definitions.

The current Anki manual is the behavioral reference for Future Due, Review
Time, FSRS distributions, answer buttons and True Retention. LinguaCafe maps
deck scope to its authenticated user, selected language and M10 unified
WordSense query.

## Decision

1. `StatisticsService` is the single read-only metric-definition service.
   Web payloads and exports consume the same normalized report.
2. Scope is always authenticated user + selected language + confirmed
   WordSense Sense cards. An optional M10 filter/search payload is parsed once
   and applied through `ReviewCardManageQueryService`; no export-specific query
   grammar is introduced.
3. Period is one of 7, 30, 90 or 365 days. Current-card distributions use the
   current scoped cards; history metrics use formal, non-undone ReviewLogs in
   the period.
4. Future Due counts active enabled cards due on future calendar days and
   excludes the current overdue backlog, matching Anki's forecast meaning.
5. Review Time is the sum and average of non-null `review_duration_ms`.
   Durations are capped at 60 seconds per rating for analytics so abandoned
   screens cannot dominate the result.
6. True Retention counts the first formal review for each card on each local
   calendar day. Again is fail; Hard/Good/Easy are pass. The payload states the
   numerator, denominator and resulting rate. A separate review-state value is
   provided; no unsupported “mature” claim is made when prior interval evidence
   is unavailable.
7. Current retrievability is computed from elapsed days and stored stability
   with the same deterministic forgetting-curve formula already used by the
   workload planner. Missing FSRS values are excluded and coverage is reported.
8. Hardest lists are bounded, deterministic and safe: most Again ratings,
   highest current difficulty and lowest current stability.
9. Reading conversion is a transparent funnel: read-word events, encountered
   words, confirmed WordSenses and active Sense cards. Source contribution is
   based on scoped bound occurrences and never infers causality.
10. CSV and PDF are renderings of the same report and metric definitions.
    PDF generation uses a small project-owned PDF writer with a fixed ASCII
    report layout; it adds no dependency and is visually rendered in acceptance.
11. Card Info V3 adds current FSRS descriptors, current retrievability,
    accumulated formal-rating counts/time and interval history to the existing
    additive `card_info` payload. Existing audit logs and manual operations are
    unchanged.
12. The responsive web page is also the mobile summary surface. It uses native
    HTML/SVG/CSS only, adds no chart dependency and preserves readable tables
    when charts have no data.

## Safety and compatibility

- No statistics or export endpoint writes ReviewCard, ReviewLog, WordSense,
  lifecycle or FSRS state.
- Undone logs never enter product analytics but remain visible in Card Info's
  audit history.
- Export filenames and content disposition are fixed by the server.
- Every row and subquery preserves user/language/card scope.
- Legacy `/statistics/get` remains available and returns an additive V3 report.

## Verification

Focused service/feature tests cover isolation, unified scope, undone exclusion,
duration cap, True Retention, Future Due, empty data, CSV/PDF signatures and
zero-write behavior. Existing Card Info and M10 query tests remain green.
Frontend guard/build and a server-bound real-browser run verify filters,
charts, summaries and both downloads. The produced PDF is rendered to PNG and
visually inspected.
