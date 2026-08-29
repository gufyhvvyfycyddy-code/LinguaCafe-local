# H-10 macOS / Xcode / iOS Simulator Capability Continuation — 2026-08-30

## Verdict

**Partial capability recovery accepted. H-10 remains DEFERRED / Not Complete.**

The 2026-08-29 H-10 probe correctly recorded that the local Windows host had no Xcode, Apple signing or connected macOS peer. On 2026-08-30 a different, bounded capability became available: the actual `origin` repository was freshly verified as public, so LinguaCafe could use a standard GitHub-hosted `macos-26` runner without introducing a paid larger runner or Apple credential path.

This continuation now closes the previously missing native macOS/Xcode compile, iOS Simulator install/launch, rendered login shell, and authenticated Simulator credential lifecycle evidence. It does **not** close the remaining Reader/Review/import/offline Simulator matrix, physical-device behavior, Apple signing/archive, TestFlight/App Store Connect, or App Review portions of H-10.

## Authority and safety boundary

The work keeps the existing M9/H-10 architecture and release boundary:

- no product API, database, FSRS, Reader, Review or synchronization semantics changed;
- no Apple password, certificate, private key, provisioning profile, App Store Connect credential or signing secret was requested or read;
- no `.env` file was read or modified;
- no archive, IPA, upload, TestFlight submission or App Store submission was created;
- the permanent compile/render probe keeps `CODE_SIGNING_ALLOWED=NO` and remains a no-secret, no-upload capability check;
- the later authenticated acceptance used a separate testing-only run with a dedicated MySQL testing database, `TestingDatabaseLease`, same-process PAB sentinel and runtime-only test identity;
- no production database, Apple password, certificate, private key, provisioning profile, App Store Connect credential or distribution signing secret was used;
- the authenticated Simulator build used only Xcode's testing-time simulated application identifier at link/sign-to-run-locally time; that value is not a production Team ID and is not committed into the Xcode project.

The permanent tooling is intentionally small:

- `.github/workflows/ios-xcode-capability-probe.yml` — manual/reusable macOS capability lane;
- `mobile/scripts/ios-generated-web-integrity.mjs` — one repeatable owner for post-sync Web asset integrity;
- `npm run ios:generated-web-integrity` — the local/CI entry;
- `tests/js/M9IosMvpGuard.test.mjs` — keeps the lane manual/read-only/unsigned and prevents it from drifting into signing or distribution.

## Why a GitHub-hosted macOS runner became acceptable

Fresh repository inspection confirmed that `gufyhvvyfycyddy-code/LinguaCafe-local` is currently **PUBLIC**. GitHub's current billing documentation states that standard GitHub-hosted runners are free for public repositories; larger runners remain billable. The probe therefore uses only the standard `macos-26` label.

GitHub's current macOS runner image documents Node.js 24 and Xcode 26.6 (`17F113`). The workflow pins the Xcode toolchain for the job with:

`DEVELOPER_DIR=/Applications/Xcode_26.6.app/Contents/Developer`

This avoids mutating runner-global Xcode selection. The new probe uses `actions/checkout@v6`, whose current release line uses Node.js 24; this also removes the Node-20 action deprecation warning observed in an earlier probe run.

No automatic `push`, `pull_request` or `schedule` trigger was added. The reusable workflow remains callable only from an explicitly invoked workflow path.

## Repeated generated-Web integrity owner

The old playbook described six manual post-sync checks. They are now executable through:

`npm run ios:generated-web-integrity`

The script fails closed unless all of the following are true:

1. `dist/index.html` and generated iOS `public/index.html` reference the same main JS;
2. both reference the same CSS;
3. the complete `dist/assets/` and generated iOS `assets/` file sets are identical;
4. every corresponding generated asset has the same SHA-256;
5. index/main JS/CSS hashes match exactly;
6. generated iOS public contains zero `.map` files;
7. generated main JS contains `正式移动端仅允许 HTTPS`, `服务器分页信息无效`, and `仅用于本地调试`.

The local Windows preflight on the current source reported:

- index SHA-256: `2aa656e3718faabc05b972d44b315c3b6ba6b159c7ef8c40427460611f28803c`;
- main JS: `assets/index-CGTGpXs0.js`;
- main JS SHA-256: `7dcec2f97408575b30674d0632fde03989160b2ed51409aa81a77c5bcede1953`;
- CSS: `assets/index-CLhVdIPh.css`;
- CSS SHA-256: `2afed444c3e988451accf4bc55443dc023c3eb315e41524afd64ddae24e03552`;
- asset count: 5;
- sourcemap count: 0;
- all three safeguards present.

The same script also passed on the real GitHub macOS runner after `cap sync ios`.

## First real macOS/Xcode run

GitHub Actions run:

`33264818165`

URL:

`https://github.com/gufyhvvyfycyddy-code/LinguaCafe-local/actions/runs/33264818165`

Head:

`8e142eac252feb1dac910fd0c9b592047d64bebe`

Result: **SUCCESS**.

Observed facts from the runner log:

- macOS 26.5.2;
- Xcode 26.6;
- Xcode build `17F113`;
- Mobile Vitest: 5 files / 42 tests PASS;
- generated-Web integrity: asset count 5, sourcemap count 0, safeguards PASS;
- M9 iOS source/release guard PASS;
- SwiftPM package resolution PASS;
- `xcrun simctl list devices available` returned current iPhone simulators including iPhone 17 Pro;
- unsigned iOS Simulator build compiled both arm64 and x86_64 simulator targets;
- bundle identifier remained `com.linguacafe.mobile`;
- final Xcode result: `** BUILD SUCCEEDED **`.

This is real macOS/Xcode evidence. Windows source inspection is no longer being used as a substitute for native compile or SwiftPM resolution.

## Second real macOS/Xcode + simulator run

GitHub Actions run:

`33264963006`

URL:

`https://github.com/gufyhvvyfycyddy-code/LinguaCafe-local/actions/runs/33264963006`

Head:

`709c5b6ff0fd65c2c961e98608fc2136cd511721`

Result: **SUCCESS**.

The first ten gates repeated successfully, including Xcode compile. The added simulator gate then completed:

1. selected an available iPhone 17 Pro simulator;
2. `simctl boot` + `bootstatus -b` reached terminal `Finished` state;
3. installed the built `App.app`;
4. `simctl get_app_container` returned a real installed application container;
5. `simctl launch ... com.linguacafe.mobile` returned process id `9028`;
6. the process remained launchable for the bounded smoke interval;
7. `simctl terminate` succeeded;
8. `simctl shutdown` succeeded.

The relevant installed bundle container was under the simulator's normal CoreSimulator application container, and no runner artifact was uploaded.

This closes the basic native simulator **boot → install → launch → terminate → shutdown** capability. It does not prove login, Reader, Review, offline sync or Keychain behavior because those require a testing-bound LinguaCafe server and rendered UI/user-event acceptance.

## Third stability run

GitHub Actions run:

`33265308947`

URL:

`https://github.com/gufyhvvyfycyddy-code/LinguaCafe-local/actions/runs/33265308947`

Head:

`20b42148aa488aca9b2e8e7722bf20a29ccf55eb`

Result: **SUCCESS**.

This run used `actions/checkout@v6`, repeated all compile/integrity gates, then repeated the simulator boot/install/launch/terminate/shutdown smoke. The original `schedule-next-attempt` job was **skipped**, so the carrier did not run OpenCode, post issue/PR comments, or start an auto-fix cycle.

The run again passed Mobile 42/42, Capacitor iOS sync, generated-Web integrity, the M9 source/release guard, SwiftPM resolution and the unsigned iOS Simulator build before launching the app. The checkout-v4 Node-20 deprecation warning seen on the earlier runner was no longer present on the new probe path.

## Rendered iOS login-shell smoke

GitHub Actions run `33268124499` completed **SUCCESS** on head `9fb344d6184df17205f65816fdd62efc097156be` after two bounded selector/tooling experiments established the actual WKWebView accessibility hierarchy. The final smoke used a checksum-pinned Maestro 2.7.0 CLI downloaded from its official GitHub release; no Maestro Cloud/API key was used.

On a real iPhone 17 Pro Simulator, the rendered accessibility tree exposed and the black-box flow successfully asserted:

- `LinguaCafe` and `IOS · CONNECTED MVP`;
- `继续学习`;
- server, email and password fields;
- `安全登录`;
- the user-facing statement `设备令牌由系统 Keychain 保护；应用不会保存密码。`;
- a real tap on the server URL field;
- real text input of the local testing URL;
- the entered URL remaining visible;
- the expected local-development HTTP safety warning becoming visible.

The earlier failed experiments were not accepted as product failures: one assumed the eyebrow text was a single accessibility node, while WKWebView correctly exposed it as separate nodes; another called a redundant `hideKeyboard` action after the input event even though Maestro reported no keyboard to hide. The final flow removed those test-tool assumptions and passed without changing product UI code.

## Overlapped simulator-boot stability run

GitHub Actions run `33268819125` completed **SUCCESS** on head `e9893e7e10aaa76b95a11cd7b9b0f99c0387fec7` with the same rendered smoke. The simulator cold boot now starts immediately after Xcode selection while dependency installation, Mobile 42/42, Capacitor sync, integrity checks, SwiftPM, unsigned build and Maestro setup continue. After those gates completed, the remaining `bootstatus`/launch step took only 42 seconds on that runner instead of holding the whole workflow idle for the full simulator migration period.

This optimization adds no cache, service, extra runner, signing path or second product runtime; it only overlaps an already required simulator boot with existing build work.

## Authenticated iOS Simulator continuation

GitHub Actions run `33279140695` completed **SUCCESS** on head `507eb50e72d431d389945f3b0cc9299c98da90a8`.

This run reused the real LinguaCafe testing runtime instead of a mock or SQLite substitute:

- Homebrew PHP 8.4 and MySQL 8.4;
- verified Composer install and the repository's pinned native FSRS extension;
- normal pending migrations on a dedicated testing database;
- `TestingDatabaseHealth` plus `TestingDatabaseLease`;
- same-process PAB testing sentinel before the first UI write;
- a runtime-only testing identity whose password was masked and never committed or reported.

The initial unsigned Simulator build exposed a real Keychain acceptance problem: authentication returned a token, but `SecureTokenPlugin.save()` could not use the app's default Keychain group without an application identifier. The accepted test build does **not** weaken the plugin or move the bearer token into Preferences. Instead, Xcode generates a Simulator-only `App.app-Simulated.xcent` at link time with `application-identifier` and `keychain-access-groups` equal to `FAKETEAMID.com.linguacafe.mobile`, then uses the normal local Simulator signing path. A separate fast signing probe proved that this linked entitlement, `codesign --verify`, Simulator install and launch are mutually consistent. Post-build ad-hoc re-signing was rejected because SpringBoard refused that mismatched app and is not used.

The rendered authenticated run then proved, through real iPhone 17 Pro Simulator UI/user events plus server-side truth:

1. enter the testing server, email and password through the rendered iOS UI and tap `安全登录`;
2. reach the authenticated shell (`首页 / 阅读 / 复习 / 生词 / 我的`);
3. terminate/relaunch and return to the authenticated shell without another password entry;
4. PAB recorded the real `/api/v1/mobile/auth/tokens` and subsequent `/api/v1/mobile/bootstrap` requests;
5. the server owned exactly one active iOS `mobile_device` with a non-null `personal_access_token_id`, and one matching personal access token;
6. the ordinary iOS Preferences plist contained no `linguacafe-session-token` Web-session key;
7. open `我的`, scroll to and tap `撤销此设备并退出` through rendered UI;
8. the server device became revoked with `personal_access_token_id = null`, the personal access token count returned to zero, and the rendered app returned to `安全登录`;
9. relaunch again still required login and showed the Keychain/password handling copy;
10. PAB, lease/sentinel, testing database service and Simulator cleanup all completed successfully.

The successful rerun also resolved two test-tool false negatives without product changes: the authenticated shell is the stable login success owner rather than the transient `在线` pill, and Maestro's existing bounded `scrollUntilVisible` can reach the bottom-of-settings revoke control on the real WKWebView page.

This evidence accepts Simulator Keychain **save → load after relaunch → clear on rendered logout** without exposing the bearer token value. Physical-iPhone Keychain behavior remains a separate device gate.

## What is now genuinely closed

The H-10 capability list can now mark these items as proven on real macOS/Xcode infrastructure:

- Xcode 26.6 native compilation against the installed current iOS Simulator SDK;
- SwiftPM resolution under the actual Xcode toolchain;
- availability and successful boot of an iOS Simulator;
- installation of the current LinguaCafe simulator app;
- launch of `com.linguacafe.mobile` without immediate process-start failure;
- bounded terminate/shutdown cleanup;
- current generated Web asset integrity on macOS after Capacitor sync;
- rendered iOS login shell and WKWebView accessibility exposure;
- real black-box tap/input on the server field;
- visible Keychain/password-handling copy and local-HTTP safety warning;
- same-runner testing PHP/MySQL/native-FSRS/PAB backend capability;
- rendered authenticated login through the real Mobile API;
- Simulator Keychain token save and relaunch load, corroborated by `/bootstrap` and server token/device ownership;
- absence of the Web session-token key from ordinary iOS Preferences;
- rendered device revoke/logout, server token deletion, Keychain clear, and relaunch-to-login boundary.

## What remains unavailable / unaccepted

H-10 remains DEFERRED because the following still lack the required evidence:

1. the remaining authenticated Simulator content matrix: Reader touch/safe-area, formal Review/undo, `.txt` import, article/review/audio package offline restart, queued rating and exactly-once reconnect sync;
2. physical-iPhone installation with real Apple signing;
3. physical haptics, notification behavior, audio focus/interruption and real notch/Dynamic-Island/home-indicator behavior;
4. physical-device Keychain lifecycle confirmation under the real team/provisioning identity;
5. Apple team/provisioning/bundle registration;
6. signed archive and Organizer validation;
7. App Store Connect upload/processing;
8. TestFlight install and critical-flow rerun on a physical iPhone;
9. final public privacy/support URLs and App Store Connect privacy answers;
10. App Review result.

A cloud simulator is useful for compile/runtime capability but cannot substitute for physical-device and Apple-account distribution evidence.

## Current efficient Simulator boundary

The same-runner testing backend is now proven useful and production-aligned: it reuses the real Laravel/MySQL/native-FSRS/PAB owners and therefore avoids a mock server, SQLite substitute, public tunnel or second scheduler. The scoped iOS local-network declaration permits the explicit loopback testing path without enabling arbitrary HTTP loads.

The remaining Simulator work should continue on that single testing stack for Reader/Review/import/offline/sync evidence. Physical-device haptics, notifications, audio interruption, real safe areas and all Apple distribution actions stay outside this lane and must not be inferred from Simulator results.

## Official environment references

- GitHub Actions billing: https://docs.github.com/en/billing/concepts/product-billing/github-actions
- GitHub-hosted runners reference: https://docs.github.com/en/actions/reference/runners/github-hosted-runners
- GitHub macOS 26 runner image / Xcode inventory: https://github.com/actions/runner-images/blob/main/images/macos/xcode-27-Readme.md
- `actions/checkout` v6 / Node 24 release line: https://github.com/actions/checkout

## Current H-10 conclusion

**Native macOS/Xcode/SwiftPM + rendered shell + authenticated Simulator Keychain/session lifecycle: Accepted.**

**Full H-10 / E-08 / H-GATE: still DEFERRED / Not Complete**, now narrowed to the remaining authenticated Simulator content matrix plus physical-device behavior, real Apple signing/archive, TestFlight and App Store capability described above.
