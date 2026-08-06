# M15 Browser Knowledge Hygiene V3 Acceptance

> Status: Accepted / Closed
> Date: 2026-07-29
> Architecture: ADR-0047

## Accepted scope

M15 adds one bounded knowledge-hygiene surface to the existing ReviewCard
Browser:

- server-persisted visible columns and saved query views;
- query-scoped find/replace for four approved WordSense text fields, capped at
  500 cards, with preview fingerprint, apply and conflict-safe undo;
- deterministic same-lemma/same-POS duplicate classes and explicit human
  confirmation;
- merge impact preview, automatic M6 backup, primary schedule preservation,
  source/tag/ReviewLog rebinding and conflict-safe undo;
- safe deletion with a 30-day Recent Deletes view and same-card restoration;
- continued reuse of M10 search and existing Tag, Marker, lifecycle and M11
  scheduling owners.

No endpoint accepts arbitrary fields or direct FSRS values. Cross-user,
cross-language and different-lemma/POS merge attempts are rejected.

## Automated and build evidence

- Focused M15 run: 9 tests passed, 55 assertions.
- Protected M10/M11/ReviewCardManage/ReviewFsrs/FsrsSchedulingService/
  WordSense run: 752 tests passed, 3 skipped, 3,073 assertions.
- M15 frontend contract guard passed.
- `npm run development` compiled successfully; only existing Sass deprecation
  warnings remained.
- PHP syntax, route listing and `git diff --check` passed.

The focused suite covers user scoping, bounded preferences, zero-write preview,
stale-preview rejection, apply/undo, same-card safe-delete restore, duplicate
classification, backup-gated merge, primary-schedule preservation,
different-lemma/POS rejection and merge-undo conflict on later tag changes.

## Real-browser evidence

The official OpenAI Chrome and in-app Browser channels were both genuinely
attempted first and finalized; this runtime blocked loopback navigation with
`ERR_BLOCKED_BY_CLIENT`. The authorized Playwright fallback used one browser,
context and page and closed them after the batch.

Before any page write, `127.0.0.1:8785` returned:

```json
{"environment":"testing","database_is_testing":true,"sentinel_present":true}
```

Through rendered UI and ordinary DOM user events, the browser:

1. created and logged into a task-only normal testing account;
2. saved server-backed column preferences and a named current-query view;
3. previewed one Chinese-definition replacement, applied it, and undid it;
4. scanned an exact duplicate pair and opened the merge impact/automatic-backup
   confirmation dialog without performing an unnecessary merge;
5. moved one card to Recent Deletes and restored that same card;
6. reloaded at 390×844 and confirmed viewport and document widths were both
   exactly 390px with the Knowledge Hygiene surface visible.

All M15 requests returned 200. The only HTTP failures were the two existing
unauthenticated font requests during register/login. The existing local Pusher
fallback emitted connection-refused console messages without affecting the
workflow. The local, gitignored mobile screenshot was written to
`output/m15-acceptance/m15-mobile.png`; the path records the inspection
location and is not versioned repository evidence.

## Cleanup

All task accounts, WordSenses, ReviewCards, ReviewLogs, settings, operations,
the testing sentinel and temporary browser fixture scripts were removed.
Follow-up task-marker counts were zero, port 8785 had no listener, and both
official-browser sessions and the fallback browser were closed. The local
acceptance screenshot remained outside versioned repository evidence.
