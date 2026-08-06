# M12 Special Study Sessions Acceptance — 2026-07-29

## Result

M12A–M12D are **Accepted / Closed**.

The accepted implementation adds server-authoritative saved Special Study
sessions without moving cards or introducing a second scheduler. Preview
answers remain session-local; formal and early-review answers use the existing
ReviewCard → FSRS → ReviewLog boundary and the shared operation ledger.

## Automated evidence

- Focused and protected PHP matrix:
  `1312 passed (4264 assertions)`.
- The matrix covered M12, all legacy Custom Study tests, M2/M11 operation
  behavior, daily progress, Study Overview, Browser search, leech behavior,
  Review FSRS, scheduling and WordSense.
- Custom Study page/session guards passed.
- The executable legacy coordinator suite passed all 9 behaviors.
- Backend vertical-slice guard passed 38 checks.
- Architecture documentation guard passed 84 checks.
- All M12 PHP files passed `php -l`.
- `php artisan route:list --path=special-study` exposed the expected eight
  authenticated routes.
- `npm run development` compiled successfully. Existing Sass deprecation
  warnings remain unrelated.

## Server-bound real-browser evidence

The official OpenAI Chrome plugin was used against
`http://127.0.0.1:8766`. Before any page write, the bound server returned:

```json
{"environment":"testing","database_is_testing":true,"sentinel_present":true}
```

The server used `APP_ENV=testing`, the dedicated testing database, the
testing-only sentinel and `SESSION_DRIVER=file`. File sessions were necessary
because the PHPUnit-oriented `array` session driver cannot preserve a CSRF
session across real HTTP requests.

Through normal rendered UI and user events, the acceptance run:

- created and logged into one task-only first-install account;
- applied a today-only review increase of 2 and later cleared it;
- built a one-card filtered preview session using FSRS state `Review`;
- saved, renamed, completed, rebuilt and ended that preview session;
- revealed the real Sense card and rated it `Good`;
- built a formal filtered session for the same card;
- displayed the formal queue-impact warning, revealed the answer and rated it
  `Good`;
- displayed the separate early-review warning and its seven-day control;
- rebuilt and ended the formal saved session without submitting a second
  rating.

The preview checkpoint contained one completed session action and exactly zero
ReviewLog and operation rows. The formal checkpoint contained:

- one non-undone ReviewLog with `source=special_study`, `rating=good`;
- one applied operation with `source_channel=web`,
  `operation_type=sense_review.rating`, session scope and the same ReviewLog;
- one card transition from `fsrs_reps=2` to `fsrs_reps=3`;
- one completed formal action linked to that operation.

The final adversarial review added an executable race regression: an
early-review card that becomes due now or leaves the frozen future window
before the answer transaction is skipped with zero ReviewLog/operation writes.

No warning or error was logged by the browser after authentication. The two
earlier 401 console entries occurred on the unauthenticated setup/login pages
before the successful login.

The local, gitignored visual evidence was written to
`output/m12-acceptance/m12-special-study.png`; the path records the inspection
location and is not versioned repository evidence.

## Cleanup

The task account, marker WordSenses and occurrences, ReviewCard, ReviewLog,
operations, Special Study sessions/actions and today-limit override were
removed from the testing database. The final cleanup query returned zero for
every task-owned category. The testing sentinel was removed, its endpoint
returned 503 afterward, the exact server process tree was stopped, and port
8766 had no remaining listener. Pre-existing browser tabs were left untouched.

No development or production database was migrated or written.
