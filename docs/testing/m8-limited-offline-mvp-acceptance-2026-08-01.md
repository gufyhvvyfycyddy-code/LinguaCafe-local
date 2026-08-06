# M8 Limited Offline MVP — Acceptance Report (2026-08-01)

## Verdict

**Accepted / Closed.** Implementation, rendered-WebView and booted Android 12
emulator evidence are complete. No physical-device-only claim is made.

## Scope and architecture

ADR-0051 and the M8 plan freeze one cloud-authoritative path:

`M3 package -> user/language-scoped IndexedDB -> offline reader/reviewer ->
durable rating action -> M4 /sync/actions -> ReviewCardService -> FSRS`.

The client does not calculate due dates, alter FSRS fields, add a second local
authority or invent another sync endpoint. `applied` and `replayed` actions are
removed, `retryable` actions remain queued, and terminal conflicts become visible
issues instead of silent retry loops.

## Automated evidence

- Mobile Vitest: 4 files, 17 tests passed. Coverage includes user/language scope
  isolation, cache reads/writes, monotonic durable action sequencing, result
  reconciliation, validated cached bootstrap recovery, media cache behavior and
  native-fetch receiver correctness.
- `tsc --noEmit` and Vite production build passed.
- Capacitor Android sync copied the accepted production bundle and detected only
  the official Local Notifications and Preferences plugins.
- The Android debug APK was rebuilt after the M8 changes; build output and artifact
  identity are recorded in the closeout command log.
- The protected Laravel M3/M4/M7, WordSense and FSRS test matrix passed after the
  browser fixture was removed.

## Server-bound browser acceptance

The official OpenAI Chrome connection reused one task-owned page against
`127.0.0.1:8794`. Before every write-capable action, the testing-only sentinel
returned:

```json
{"environment":"testing","database_is_testing":true,"sentinel_present":true}
```

Through rendered UI and user events, the browser:

1. registered and logged in through the normal forms, then loaded the task article,
   chapter tokens and Sense review package online;
2. stopped the bound PHP listener and reopened the cached article directory,
   chapter list, reader tokens and review card;
3. clicked **显示答案** and **良好 Good** while the server was unavailable;
4. observed the card advance locally and Settings report one pending operation;
5. restarted the same testing-bound listener and clicked **立即同步**;
6. observed pending/issues return to `0 / 0` and the database contain exactly one
   ReviewLog, one operation and one queued-metadata record;
7. verified the connection pill changes to **服务器不可达** on a real failed read
   even when `navigator.onLine` remains true, then returns to **在线** after a
   successful server read.

At a 390×844 viewport, document `scrollWidth` equalled `clientWidth` (375 px),
the smallest visible button was 44 px high and the sync button was 48 px high.
The final Chrome warning/error log was empty.

## Android 12 emulator acceptance

The same server-bound testing sentinel was proven from both host and emulator.
Through the installed APK and rendered controls, the device:

1. cached the article list, chapter/tokens, two-card review package and one MP3;
2. stopped the bound server and displayed cached library, chapter and review
   content with `服务器不可达` and the offline package notices;
3. played the cached MP3 offline with one Android audio-focus acquire/release and
   zero native media HTTP requests;
4. queued a `Good` rating, displayed `1 待同步`, force-stopped and restarted the
   app, then recovered the same user/language shell and the same pending action;
5. restarted the testing-bound server and clicked `立即同步`; Settings returned
   to pending/issues `0 / 0`, and the database contained exactly one new
   ReviewLog and one applied operation for that queued card;
6. clicked sync again with an empty queue and observed no duplicate ReviewLog.

The device run found and fixed two native-only defects: native fetch entering
Sanctum CSRF instead of the Bearer path, and offline restart lacking a validated
cached bootstrap scope. Both fixes preserve server authority and existing API
semantics.

## Cleanup

The browser fixture and the later Android user id 544 fixture were both removed by
exact identity. Android follow-up counts for the user, every `user_id=544` row,
markers, Sanctum token and sentinel were zero; media files were absent. Port 8797
closed, the APK was uninstalled, ADB forwarding was removed, MuMu shut down and no
FastCtx background job remained.
