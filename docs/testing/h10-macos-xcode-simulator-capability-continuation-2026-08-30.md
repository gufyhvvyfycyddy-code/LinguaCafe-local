# H-10 macOS / Xcode / iOS Simulator Capability Continuation — 2026-08-30

## Verdict

**Simulator capability cluster Stage Accepted. H-10 remains DEFERRED / Not Complete only for physical-device and Apple distribution capability.**

The 2026-08-29 H-10 probe correctly recorded that the local Windows host had no Xcode, Apple signing or connected macOS peer. On 2026-08-30 a different, bounded capability became available: the actual `origin` repository was freshly verified as public, so LinguaCafe could use a standard GitHub-hosted `macos-26` runner without introducing a paid larger runner or Apple credential path.

This continuation now closes the previously missing native macOS/Xcode compile, iOS Simulator install/launch, rendered login shell, authenticated Simulator credential lifecycle, formal Sense Review Good/Undo, rendered Reader touch/source-binding, real-system-Files `.txt` import, and the authenticated offline/reconnect Simulator content matrix. It does **not** close physical-device behavior, real Apple signing/archive, TestFlight/App Store Connect, or App Review portions of H-10.

## Authority and safety boundary

The work keeps the existing M9/H-10 architecture and release boundary:

- the accepted Reader slice changes only the existing Mobile Reader integration boundary: it exposes the canonical reading-unfamiliar target endpoint to Mobile, reuses one shared Reader manual-Sense creation owner across Web/Mobile, and carries authoritative reading session/source/occurrence identity into Mobile manual Sense creation; no database schema, FSRS scheduler/rating semantics, ReviewLog semantics, offline-sync contract or second Reader truth source was added;
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

## Formal Sense Review rating / Undo continuation

GitHub Actions run `33282205923` completed **SUCCESS** on head `1dd45ebad3e826375062962f5ccd56cc926d1a8f` using the same testing MySQL/native-FSRS/PAB backend and iPhone 17 Pro Simulator.

The run prepared two deterministic Sense Review cards through the existing testing smoke-data owner under `TestingDatabaseLease`, captured card A's authoritative FSRS fingerprint, then used rendered iOS UI/user events to execute:

1. open `复习` and wait for card A;
2. tap `显示答案` and observe the persisted smoke meaning;
3. scroll to and tap the real `良好` control;
4. observe card B as the next card;
5. scroll to and tap `撤回上一次评分`;
6. observe card A restored with `显示答案` again.

The testing database then proved exactly one `sense_review` ReviewLog for card A, rating `good`, with `undone_at` populated; card B had zero ReviewLogs; the card-A operation ledger contained exactly one `sense_review.rating` operation in `undone` status at version 2 with exactly two operation changes; and the post-Undo FSRS fingerprint exactly matched the pre-rating fingerprint. The same run then repeated rendered device revoke/logout and clean testing teardown.

This accepts the Simulator formal **Good → next card → Undo → exact FSRS restoration** path without introducing a second rating or scheduler owner. Physical-device haptics remain a separate device gate.

## Rendered Reader touch / source-binding continuation

GitHub Actions run `33301226295` completed **SUCCESS** on head `15eec00ad9a4f7570b83c150a4afb43f1c159b42` using the same dedicated testing MySQL/native-FSRS/PAB backend and iPhone 17 Pro Simulator. The final run repeated the complete environment, dependency, schema, Mobile, SwiftPM and `build-for-testing` gates, then executed three short native XCUITest sessions with `test-without-building`.

The rendered flow proved:

1. authenticated login persists into the native Mobile shell;
2. portrait Reader opens the task book/chapter and exposes the real `bank` / `account` token controls;
3. tapping `bank`, entering a confirmed Chinese meaning and tapping `确认并创建` creates the reading-origin WordSense through the canonical shared creation owner;
4. reopening the same token shows the existing-Sense controls `认识 / 记得` and `不认识` instead of the new-Sense form;
5. landscape Reader accepts a real long-press/drag from `bank` to `account`, exposes `bank account` in the lookup sheet, and keeps the phrase flow outside the single-token recognition-rating path;
6. MySQL corroboration proves exactly one matching reading-unfamiliar target, one reading-origin WordSense, one Sense ReviewCard, one canonical `reading_occurrence` source binding and one `new_sense` evidence row, while ReviewLog count remains zero for the creation flow;
7. PAB observes the real Mobile marker and WordSense API paths; testing lease/sentinel, MySQL service and Simulator cleanup all return clean.

The same final head also replaces a fragile Mobile 404 check that compared exception prose with the stable `ReadingChapterTextService::ERROR_CHAPTER_NOT_FOUND` code. That change does not add a second Reader truth source; it only keeps the public Mobile error mapping stable if human-readable exception wording changes later.

This accepts the Simulator Reader **portrait token → canonical new Sense/source binding → existing-Sense recognition controls** and **landscape phrase gesture** paths. Physical-device notch/Dynamic-Island/home-indicator safe-area behavior remains a separate device gate.

## Real iOS Files `.txt` import continuation

GitHub Actions run `33308079898` completed **SUCCESS** on head `f89351c740957b0275696456bd6c8ea0597a0203`; the stable four-file result was then re-extracted from fresh live Goal and fast-forward pushed as `6028453a899bdaf03f743d9c8b918ea4e4cbd236` without the temporary dispatch carrier.

The same iPhone 17 Pro Simulator and testing MySQL/native-FSRS/PAB stack proved the real system document-picker path rather than substituting an API upload. XCUITest opened `我的`, scrolled the actual WKWebView until `选择文本文件` was hittable, entered the system Files picker, navigated through Browse to **On My iPhone**, and selected files through the Files accessibility hierarchy. The final iOS 26 hierarchy exposed file cells by identifiers such as `basename, txt`; the unsupported PDF cell was present but disabled by the system picker.

The rendered flow proved all four required cases:

1. an unsupported `.pdf` fixture was visible but disabled by the system picker and could not be selected as import input;
2. a `.txt` containing invalid UTF-8 bytes was selected through Files and rejected by the app with `文本文件必须使用 UTF-8 编码`;
3. a 200001-byte `.txt` was selected through Files and rejected by the existing 200 KB product boundary;
4. a valid UTF-8 English `.txt` was selected through Files, imported through the normal `MobileTextImportController → MobileIdempotencyService → ImportService` path, and appeared as the new material in the rendered library.

MySQL/PAB corroboration proved exactly one matching imported Book, one `导入文本` Chapter, one `library.text_import` mobile client action and one `/api/v1/mobile/imports/text` request. The workflow then removed only its exact marker files, shut down the Simulator and MySQL service, and verified `TestingDatabaseLease active=false / stale_metadata=false` with zero remaining testing sentinel rows.

The stable product delta is deliberately narrow: the existing iOS import fields now carry explicit accessibility labels and fatal UTF-8 decoding maps to one deterministic user-facing error. No new endpoint, schema, upload path, dependency or import authority was added.

This accepts the Simulator **real Files picker → reject wrong type/encoding/size → import valid UTF-8 English `.txt` → server/MySQL idempotency evidence** path.

## Offline / reconnect Simulator matrix closure — 2026-08-31

The remaining authenticated Simulator content matrix is now closed. Focused run `33346226130` first completed the offline-only lane. Final full GitHub Actions run `33350591521` then completed **SUCCESS** on head `6209dbc5b9d584c698eee6ba4b2e5743a01d9736`, re-running login, portrait Reader source binding, landscape phrase gesture, real system Files import and the entire offline/reconnect sequence on the same iPhone 17 Pro Simulator and testing MySQL/native-FSRS/PAB stack.

The final full run proved through real XCUITest sessions:

1. `testOfflineWarmCaches` — article/review data and the actual media asset were warmed and the cached word audio played;
2. `testOfflineCachedContentAndQueuesGood` — after the app-facing relay was shut down, cached content remained usable and one rendered `Good` was queued locally;
3. `testOfflinePendingSurvivesRelaunch` — terminating and relaunching the app preserved exactly one pending offline action;
4. `testOfflineReconnectAutomaticallySyncs` — restoring the relay automatically synchronized that action and returned the local queue to zero;
5. `testOfflineReconnectEmptyQueueRemainsStable` — another relaunch/reconnect with an empty queue did not create a duplicate rating or a second sync application.

Server-side corroboration then proved `fsrs_reps=1`, exactly one non-undone `good` Sense ReviewLog, exactly one completed `sense_review.rating` mobile client action, exactly one applied rating operation with one `apply` operation change, and exactly one media asset request. PAB recorded one sync request before the offline phase and exactly one additional sync request after reconnect (`SYNC_REQUESTS_BEFORE_OFFLINE=1`, `EXPECTED_SYNC_REQUESTS=2`), so reconnect was exactly-once rather than repeated polling or duplicate submission.

The same final full run also re-passed the complete system Files matrix. A prior iOS 26 Simulator run had intermittently synthesized a tap on a Files cell without the system picker completing the selection, leaving the Web file input at `no file selected`. The bounded UI-test guard now retries once only when the same Files cell still exists and remains hittable after the app has not received the selected filename. The successful final run did **not** emit `FILES_PICKER_SELECTION_RETRY`, so its Files proof completed on the normal first-tap path; the retry remains only as a narrow harness protection for the observed Simulator defect and does not hide an app-side document callback failure.

The strengthened cleanup gate also passed: the testing database lease ended with `active=false / stale_metadata=false`, the testing acceptance sentinel count returned to zero, exact Files/audio fixtures were removed, PAB/relay processes and the Simulator were stopped, and MySQL 8.4 shut down cleanly. Cleanup failure can no longer be masked by the best-effort teardown commands.

The run head contained a temporary branch-only workflow-dispatch carrier used only because the Reader acceptance workflow is not yet registered on the default branch. The carrier was removed immediately after dispatch in follow-up commit `1bf2016873492c0b641dbab0891fcce0465ec6e3`; the stable candidate lineage therefore restores the production `auto-fix-scheduler.yml` and keeps the successful run's product/test content unchanged apart from later documentation/guard closeout.

After the Simulator and release-capability evidence converged, the final H-10 tree was re-applied to a clean worktree based on fresh Goal `4bcd32a6cb7478e7d475a27df791b78ab7956607`, verified there, and squash-integrated as `c2ef3da94a9b9437a7d796e625ab963266e92e6f` (`feat: integrate accepted H10 iOS capability`). That integration carries only the final 23-file net change plus the CRLF-portable Auto-Fix contract guard; the temporary dispatch/restore carrier commits do not enter Goal history.

This accepts the Simulator **cached article/review/audio → offline restart → queued Good → relaunch persistence → reconnect exactly-once sync → stable empty queue** path.

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
- rendered device revoke/logout, server token deletion, Keychain clear, and relaunch-to-login boundary;
- rendered Sense Review answer reveal, formal Good rating, next-card transition and canonical Undo;
- ReviewLog / operation-ledger exactly-once facts plus exact post-Undo FSRS fingerprint restoration;
- rendered portrait Reader token interaction and canonical reading-origin new-Sense/source binding;
- existing-Sense `认识 / 记得` and `不认识` controls after the canonical source-bound creation;
- rendered landscape long-press/drag phrase selection for `bank account` without entering the single-token recognition path;
- rendered system Files picker `.txt` import, including disabled unsupported extension, invalid UTF-8 rejection, oversize rejection, successful valid English import and exactly-one server action/request evidence;
- authenticated Simulator offline content and media cache use across relay shutdown, one queued Good surviving app relaunch, automatic reconnect exactly-once sync, and stable zero-pending state after the queue is empty;
- current 64-bit release capability declaration (`UIRequiredDeviceCapabilities = arm64`) under Xcode 26.6;
- the same generated app rendering the login shell on both iPhone 17 Pro and `iPad Pro 13-inch (M5)` Simulator after serializing the two Simulator sessions on the standard hosted Mac;
- 13-inch iPad screenshot output at `2064x2752`, matching the current App Store Connect 13-inch portrait screenshot requirement;
- repository-side App Privacy / export-compliance preparation: Xcode 26.6 run `33361617463` first proved the revised six-category collected-data manifest inside the built app, and final run `33362427450` then passed the compiled-bundle checks for the exact six data categories, linked/non-tracking/App-Functionality semantics, `NSPrivacyAccessedAPICategoryUserDefaults / CA92.1`, empty tracking domains and `ITSAppUsesNonExemptEncryption = NO`, followed by the serial iPhone + 13-inch-iPad rendered smoke and cleanup;
- repository-side Release archive and screenshot preparation: run `33366809125` produced a real unsigned Release `.xcarchive`; run `33383576886` verified archived App identity/version/privacy plus Capacitor/Cordova SDK privacy manifests; run `33386931423` repeated the archive lane and produced non-alpha JPEG screenshots at 6.9-inch iPhone `1320x2868` and 13-inch iPad `2064x2752`.

## What remains unavailable / unaccepted

H-10 remains DEFERRED because the following still lack the required evidence:

1. physical-iPhone installation with real Apple signing;
2. physical haptics, notification behavior, audio focus/interruption and real notch/Dynamic-Island/home-indicator behavior;
3. physical-device Keychain lifecycle confirmation under the real team/provisioning identity;
4. Apple team/provisioning/bundle registration;
5. Apple-team signed Release archive plus Organizer `Validate App`; the unsigned archive structure is already proven;
6. App Store Connect upload/processing and final marketing screenshot selection/upload;
7. TestFlight install and critical-flow rerun on a physical iPhone;
8. final public Privacy Policy / Support URLs plus deployment-owner confirmation of the App Store Connect privacy questionnaire and other publisher-owned store metadata;
9. App Review result.

A cloud simulator is useful for compile/runtime capability but cannot substitute for physical-device and Apple-account distribution evidence.

## Current efficient Simulator boundary

The same-runner testing backend is now proven useful and production-aligned: it reuses the real Laravel/MySQL/native-FSRS/PAB owners and therefore avoids a mock server, SQLite substitute, public tunnel or second scheduler. The scoped iOS local-network declaration permits the explicit loopback testing path without enabling arbitrary HTTP loads.

The Simulator lane is now stage-accepted on that single testing stack: formal Sense Review Good/Undo is accepted by run `33282205923`, rendered Reader touch/source binding by run `33301226295`, real-system-Files `.txt` import by run `33308079898`, and the final full offline/reconnect matrix by run `33350591521`. Release-capability run `33355499203` additionally revalidated the Xcode 26.6 unsigned build with the corrected `arm64` requirement and passed the same rendered login-shell flow serially on iPhone 17 Pro and `iPad Pro 13-inch (M5)`. Repository-side export compliance then passed run `33359184066`; privacy-manifest runs `33361617463` and `33362427450` proved the collected-data and required-reason declarations in the compiled app bundle. Runs `33366809125`, `33383576886`, and `33386931423` then closed the repository-side unsigned Release archive, archived SDK privacy-manifest, and App Store screenshot technical-size/format gates. Do not create more Simulator/archive-structure work unless a regression or a new product requirement appears. Physical-device behavior, Apple-team signed archive/Organizer validation, TestFlight/App Store processing and publisher-owned store actions stay outside this lane and must not be inferred from unsigned archive or Simulator results.

## Windows iOS sync portability hardening — 2026-09-01

A fresh clean-worktree Windows verification reproduced a repository tooling defect in the Capacitor 8.4.2 sync path: `npm run cap:sync:ios` rewrote the tracked `CapApp-SPM/Package.swift` local plugin paths from portable `../../../node_modules/...` paths to Windows backslash paths. This was host-generated SwiftPM configuration drift, not a product/runtime failure.

The canonical `cap:sync:ios` entry now runs `mobile/scripts/normalize-ios-spm-paths.mjs` immediately after `cap sync ios`. The normalizer only accepts the existing `../../../node_modules/...` local SwiftPM dependency shape, converts Windows separators to `/`, is idempotent on already-portable macOS output, leaves remote package declarations untouched, and fails closed for absolute, escaping or unsupported local package declarations. `M9IosMvpGuard.test.mjs` permanently covers those contracts.

The repaired Windows flow was executed end-to-end: Capacitor regenerated the iOS package file, the normalizer repaired the host-specific paths, generated-Web integrity remained green with zero sourcemaps, and the final tracked `Package.swift` had zero semantic diff from Goal. This hardening prevents Windows development from contaminating the later macOS/Xcode SwiftPM path. It does **not** add physical-device, Apple-team signing, signed archive, TestFlight, App Store Connect or App Review evidence, so H-10/E-08/H-GATE remain DEFERRED exactly as above.

## Official environment references

- GitHub Actions billing: https://docs.github.com/en/billing/concepts/product-billing/github-actions
- GitHub-hosted runners reference: https://docs.github.com/en/actions/reference/runners/github-hosted-runners
- GitHub macOS 26 runner image / Xcode inventory: https://github.com/actions/runner-images/blob/main/images/macos/xcode-27-Readme.md
- `actions/checkout` v6 / Node 24 release line: https://github.com/actions/checkout

## Current H-10 conclusion

**Native macOS/Xcode/SwiftPM + corrected `arm64` release capability + rendered iPhone/13-inch-iPad shell + authenticated Simulator Keychain/session lifecycle + formal Sense Review Good/Undo + rendered Reader touch/source binding + real Files `.txt` import + offline/reconnect content matrix + repository-side export-compliance/Privacy Manifest checks + unsigned Release archive/SDK privacy manifests + App Store screenshot technical classes: Stage Accepted.**

**Full H-10 / E-08 / H-GATE: still DEFERRED / Not Complete**, now narrowed to physical-device behavior, Apple-team signing plus signed archive/Organizer `Validate App`, TestFlight/App Store processing, final marketing screenshot selection/upload, public Privacy/Support URLs, publisher-owned store answers and App Review capability described above.
