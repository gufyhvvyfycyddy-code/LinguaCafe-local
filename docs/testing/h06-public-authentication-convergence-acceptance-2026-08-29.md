# H-06 Public Authentication Convergence Acceptance — 2026-08-29

## Verdict

**Accepted / DONE.**

H-06 closes the current Web public-authentication surface around the existing email/password identity. The implementation keeps one active login request-policy owner, adds bounded failed-login controls, keeps generic public failure behavior, prevents authenticated identity switching through the login endpoint, requires the current password for password changes, removes two proven-dead Breeze-era duplicate owners, and leaves password reset/email verification unexposed until LinguaCafe has an accepted outbound-mail recovery path.

No SMS, CAPTCHA, Apple login, WeChat login, social-login framework, second identity store, or new authentication dependency was introduced.

## Exact implementation evidence

H-06 product/test commit:

`04e301f` — `feat: close H-06 public authentication`

The accepted authentication path is:

- `POST /login` → `UserController::authenticateUser()` → `App\Http\Requests\Auth\LoginRequest`;
- the login endpoint is guarded by Laravel's existing `guest` middleware;
- successful authentication regenerates the current session and continues using `Auth::logoutOtherDevices()`;
- incorrect credentials use one public `401 INVALID_CREDENTIALS` envelope for an existing email with a wrong password and for an unknown email;
- failed login attempts hit two Laravel `RateLimiter` keys: one per normalized account identifier and one per request IP;
- the account bucket blocks the sixth failed attempt after five failures; the IP bucket blocks after twenty-five failures in the same window;
- successful login clears only the account bucket, preserving the source IP's recent failure history;
- rate-limit failure uses `429 LOGIN_RATE_LIMITED` and a generic retry-later UI message;
- `UpdatePasswordRequest` requires `current_password:web` before accepting a new password;
- the Web password-change dialog visibly asks for the current password and uses the existing safe validation-error text path;
- the unused `AuthenticateUserRequest` and unused Breeze `RegisteredUserController` were removed after fresh caller checks found no application caller;
- current account creation remains owned by the existing `UserController::createUser()` flow.

Public password-reset and email-verification routes remain intentionally unexposed. LinguaCafe currently has no accepted outbound-mail/recovery deployment path, so H-06 does not expose a half-working recovery surface.

## Mature external guidance used

The H-06 boundary was checked against current Laravel authentication/session/rate-limiting behavior and OWASP Authentication Cheat Sheet guidance.

The resulting product choices are deliberately small:

- authentication failure text does not disclose whether the submitted account exists;
- rate limiting is enforced server-side through Laravel's existing limiter;
- the session identifier is regenerated after successful login;
- an authenticated user cannot reuse the public login endpoint to switch identity;
- a sensitive credential change requires the user's current credential;
- password-manager autocomplete hints are present for login and account-creation fields.

The final independent Hy3 review found no Critical blocker. It identified one deployment prerequisite that belongs to H-07: if production is placed behind Nginx, a CDN, or a load balancer, Laravel trusted-proxy configuration must be verified so `$request->ip()` resolves the real client through an explicitly trusted proxy chain. H-07 owns that deployment check before the IP limiter is relied on publicly.

## Automated regression

Fresh post-browser verification on the exact H-06 product diff:

- `tests/Feature/Auth` + `tests/Feature/CreateLocalUserCommandTest.php`: **25 passed / 141 assertions**;
- `H06PublicAuthContract.test.mjs` + `AuthLoginErrorPolicy.test.mjs` + `UserFormErrorPolicy.test.mjs`: **21 passed / 0 failed**;
- `npm run development`: **PASS**;
- `git diff --check`: **PASS**.

Runtime regression coverage includes:

- valid Web login succeeds with the existing response contract;
- invalid password returns the generic 401 envelope;
- unknown email and wrong password have the same public failure;
- the sixth failed account attempt is rate limited;
- twenty-five failures from one IP across rotating email addresses cause the next source-IP attempt to be rate limited;
- successful login clears the account limiter without erasing the IP failure history;
- an already authenticated user cannot switch to another identity by posting another user's credentials to `/login`;
- logout remains functional;
- guest password change is rejected;
- missing or wrong current password cannot change the stored password;
- correct current password changes the password and keeps the current authenticated session usable;
- currently unsupported password-reset and email-verification public routes remain closed;
- setup, normal registration configuration, administrator creation, and local CLI user creation continue to work under their existing policies.

The final build still reports existing Bootstrap/Sass deprecation warnings and Node's existing module-type warning. They did not produce build or test failures and were not introduced by H-06.

## Real browser acceptance

A real PAB browser server was started on a dedicated port under the existing machine-global TestingDatabaseLease. Before any authentication write, the real sentinel endpoint returned:

- `environment=testing`;
- `database_is_testing=true`;
- `sentinel_present=true`.

The testing database initially contained no user. Following the project browser-acceptance rule, the normal `/setup` page was used to create the task-provided local testing administrator identity. No development or production identity was created or changed.

The real Web flow then proved:

1. a nonexistent email with an incorrect password showed the generic Chinese error `邮箱或密码不正确。`;
2. five real `POST /login` requests returned HTTP 401 and the next request returned HTTP 429;
3. the page rendered `登录尝试次数过多，请稍后再试。` after the 429 response;
4. the normal local testing account could still log in successfully after failures were made against a different account identifier;
5. `/user-settings` rendered a password-change dialog with current password, new password, and confirmation fields;
6. an incorrect current password produced `当前密码不正确。` and did not change the password;
7. the correct current password successfully changed the password and the current authenticated page remained usable;
8. through the same real password-change dialog, the testing password was restored to the task-provided original value;
9. the browser logged out and returned to `/login`;
10. the original restored testing password was then used in a fresh real login, which succeeded and reached the authenticated home page;
11. a final real logout returned the browser to `/login`.

The repository document intentionally does not record the concrete testing password.

## Cleanup proof

The first PAB browser process was force-stopped after browser work. That exposed a real testing-harness cleanup residue:

- the server itself was no longer listening;
- TestingDatabaseLease reported `active=false` but `stale_metadata=true`;
- the interrupted process had not emitted a normal old-sentinel cleanup event.

H-06 did not ignore or manually delete this state. The existing PAB recovery owner was run once in testing mode with an immediate-success child. It reported:

- `stale_sentinel_cleanup removed=1`;
- its new sentinel was created;
- child exit code `0`;
- `sentinel_cleanup status=ok` for its own sentinel.

After that recovery cycle, the existing lease status command reported:

- `active=false`;
- `stale_metadata=false`;
- no live lease metadata.

Port 8818 had no listener, and the H-06 isolated browser page was closed. This proves server, lease metadata, and acceptance sentinel cleanup before milestone closure.

## Tooling incidents handled during H-06

Two development-tool incidents were handled without weakening product verification:

- the local Windows MCP gateway briefly became unreachable across DevSpace/Chrome/Codex paths and later recovered; Tailscale status, Serve mapping, and the main local MCP listeners were then rechecked;
- Reasonix remained down independently because its intended local 8792 gateway was not listening even though the existing scheduled guardian task was enabled. The existing `ReasonixMcpGatewayStarter` task was started without changing configuration; 8792 resumed listening, the repository's identity probe passed, `/healthz` returned `status=ok`, and the top-level Reasonix MCP status call worked again.

A separate DevSpace `git_commit` helper issue was also observed: after `git_stage_exact` twice reported the same exact 16-file staged set, `git_commit` incorrectly claimed no exact-stage receipt was available. The Git index itself was verified by `git diff --cached --check`, `--stat`, and exact name-status. The already verified index was committed with normal non-force Git. This tool bug remains operational tooling debt and does not change the H-06 product diff.

No `.env` read or modification, no `AGENTS.md` modification, no `.omo` action, no destructive database reset/wipe, no notification script, no DCP, and no force push occurred.

## H-07 handoff boundary

H-06 is closed. H-07 owns public deployment/runtime and current cost decisions.

H-07 must carry forward these facts:

- H-03 measured the canonical Reading / lookup / Sense Review application paths as fast; the large mixed p95 came from fresh Apache prefork cold-burst admission, so deployment topology should be evaluated before changing business queries or learning logic;
- if a reverse proxy/CDN/load balancer is used, trusted-proxy handling must be verified before the H-06 IP limiter is considered production-correct;
- as of 2026-08-29, the repository is on Laravel 11 while Laravel 11's official security-fix window has ended; public deployment therefore requires a supported-framework/runtime gate rather than freezing the current PHP 8.2/Laravel 11 combination;
- current provider prices must be re-read from current sources, since 2026 infrastructure prices have changed materially.
