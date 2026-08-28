# H-05 Isolation / Privacy Boundary Acceptance — 2026-08-28

## Verdict

**Accepted / DONE.**

H-05 closes the current user/language isolation, self-service account deletion, synchronized-device revocation, and privacy disclosure milestone. The implementation reuses the existing Web authentication, `UserService`, Sanctum/mobile device ownership, storage disk, restore write fence, and testing-browser harness. It does not introduce a second account system, alternate identity store, SMS authentication, or a new deletion worker.

## Exact implementation evidence

Supporting testing-browser tooling fix:

`013c8afc38140f92d6a518e68d8eba57d64936cb` — `fix: persist testing browser sessions`

H-05 product implementation:

`62fcc2432ad707a27aeee420c7bbb4470d6d8563` — `feat: close H-05 privacy boundaries`

The testing-browser fix is testing-only. PHPUnit uses `SESSION_DRIVER=array`, which cannot preserve a real browser login across requests. The existing PAB server now overrides only supported testing `artisan serve` browser children to `SESSION_DRIVER=file`; the machine-global testing DB lease and testing-only sentinel remain the authoritative database/process binding.

H-05 product changes:

- `DELETE /users/account` lives inside the existing `auth + auth.session + web` middleware group.
- The server derives the account from `Auth::user()`; the caller cannot submit a target user id.
- Permanent account deletion requires the exact confirmation text `delete my account` and the current password.
- The final administrator account cannot self-delete. Administrator rows are locked in the deletion transaction so concurrent administrator deletions cannot both pass the invariant.
- Account deletion removes active user-owned application rows, Sanctum tokens, registered mobile devices, password-reset state, session rows when a database-backed session table exists, and the user's active uploaded-media path.
- User media is moved into a private deletion quarantine before database deletion. A failed database transaction restores quarantined files; successful deletion purges the private quarantine after commit.
- English study-data deletion remains narrower than account deletion. It deletes only the current user's English-scoped learning rows/media while preserving the account, mobile authorization, other users, other languages, shared media files, and user-level settings/presets that are not language-scoped.
- Schema-backed contract tests enumerate every current `user_id + language/language_id` table and every non-cascading `user_id` table, so adding a new unowned table causes H-05 tests to fail instead of silently leaving a privacy gap.
- The Web account page exposes a distinct permanent deletion control and explains that active server data is deleted while existing recovery backups are not rewritten by the self-service action. Backup retention remains an operator policy.
- Mobile device revoke remains a separate action: it revokes the server device/token when reachable and still clears local credentials, offline state, and cached media if the server is unavailable. Current Android/iOS clients remain existing-account login only.

## Mature external guidance used

The implementation was checked against current OWASP authentication/authorization guidance and Laravel's existing authentication/session model rather than adding a custom identity framework:

- authorization decisions are enforced server-side from the authenticated principal;
- destructive account actions require re-authentication material;
- authentication failures should avoid exposing sensitive internal details;
- session/token invalidation is part of destructive identity actions;
- public authentication rate limiting and generic failure behavior are left for H-06, where login/public-auth UX is the actual owner.

H-05 therefore does not add unrelated MFA, CAPTCHA, SMS, social login, or password-reset machinery.

## Automated regression

Fresh post-fix verification on the exact H-05 working tree:

- `PabR3BrowserAcceptanceHarnessTest`: **22 passed / 126 assertions**, plus one existing environment warning from its real-port probe; no test failure.
- `H05AccountDeletionPrivacyTest`: **10 passed / 93 assertions**.
- `MobileApiFoundationTest`: **17 passed / 152 assertions**.
- `H05AccountDeletionUiContract.test.mjs`: **5 passed**.
- `npm run development`: **PASS**.
- `git diff --check`: **PASS**.

An earlier expanded user-scope regression on the same H-05 product diff also passed **280 tests / 1741 assertions**; two existing tokenizer external-service examples were skipped rather than reported as passed.

The focused contracts prove at least these failure paths:

- guest account deletion is rejected;
- incorrect confirmation text is rejected;
- incorrect current password is rejected;
- final administrator deletion is rejected without losing media;
- restore write fence blocks account deletion before data changes;
- account-media quarantine failure happens before database deletion;
- language-media quarantine failure restores already-moved files;
- English data deletion cannot damage another user or another language;
- device revocation cannot target another user's device;
- a revoked device token cannot continue calling the mobile API.

## Real browser acceptance

A dedicated testing Laravel server was bound to the testing DB through the existing PAB lease + exact sentinel before any destructive browser action.

The first browser attempt exposed a testing-tool defect: `APP_ENV=testing` inherited `SESSION_DRIVER=array`, so the GET and subsequent login/registration POST did not share a session and returned CSRF 419. This was fixed in the PAB testing-browser owner (`013c8af`) instead of weakening CSRF or changing product authentication.

After the tool fix, the real browser flow succeeded:

1. the testing-only sentinel endpoint confirmed `environment=testing`, `database_is_testing=true`, and `sentinel_present=true` on the exact server/port;
2. normal Web login succeeded and authenticated requests returned 200;
3. `/user-settings` rendered the permanent account deletion section, deletion scope, backup-retention explanation, exact confirmation field, current-password field, and disabled destructive button;
4. entering the exact confirmation text alone kept the destructive button disabled;
5. entering the current password enabled the destructive button;
6. clicking the real button sent `DELETE /users/account`, returned HTTP **200**, invalidated the current Web session, and redirected to `/login`;
7. the following authenticated resource request returned **401**, proving the deleted session no longer retained access.

The browser console contained only the known local Pusher/WebSocket connection-refused noise when the optional local Pusher server was not running, plus the expected post-deletion unauthenticated request. There was no account-deletion JavaScript error or failed destructive request.

## Cleanup

Final cleanup proved:

- H-05 testing browser server stopped;
- testing port had no listener after shutdown (remaining entries were `TIME_WAIT` only);
- TestingDatabaseLease `active=false`, `stale_metadata=false`;
- task-specific testing identity was deleted by the real account-deletion flow;
- no destructive reset/wipe command;
- no development or production database write;
- no `.env` read or modification;
- no notification action;
- no DCP;
- no force push.

## Follow-up boundary

H-05 is closed. H-06 owns public login/registration authentication convergence. Current web research for H-06 supports keeping the existing email/password path as the default, then checking generic authentication errors, rate limiting, session regeneration, registration exposure, password-reset behavior, and public-page UX before considering optional Apple/WeChat. SMS is not introduced without a current product need and cost decision.
