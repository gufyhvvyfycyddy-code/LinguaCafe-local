# H-GATE Final Goal Completion Audit — 2026-08-30

## Verdict

**Stage Accept for Windows / Web / Android plus the full required iOS Simulator capability cluster.**

**H-GATE = DEFERRED / Not Complete.**

The audit itself is complete. The full A–H Goal is not complete because the final Definition of Done explicitly requires the complete Apple/iOS capability cluster when iOS is part of the final target. The 2026-08-30/31 continuation recovered real macOS/Xcode/SwiftPM/iOS Simulator capability through a standard GitHub-hosted `macos-26` runner, including rendered authenticated login, Simulator Keychain save/load across relaunch, server token/device ownership and rendered revoke/logout, formal Sense Review Good/Undo, Reader touch/source-binding, real-system-Files `.txt` import, and the full offline article/review/audio → queued Good → relaunch persistence → reconnect exactly-once sync matrix. The Simulator capability cluster is now Stage Accepted; physical-device, signing/archive and TestFlight/App Store/App Review evidence remain unaccepted.

This is a narrowed external capability boundary, not a newly discovered LinguaCafe product-code regression.

Do not emit `LINGUACAFE_A_H_GOAL_COMPLETE` until the remaining iOS capability cluster has been executed and accepted.

## Fresh Git authority

The final runnable Android capability closure was committed and pushed before this audit:

- validation-tool repair: `e14a0e96d5d564e9e86541c757fdc9bb4e26966f`;
- E-06/E-07 Android capability closure: `5448554e10fe6df093ac8fa1dd3832a35e312359`;
- local HEAD, tracking branch and live `origin/goal/linguacafe-a-h-sol-medium-20260809` were aligned at `5448554e10fe6df093ac8fa1dd3832a35e312359` before H-GATE documentation updates;
- live `origin/master` remained `1c9bdcd74fa793356ba3938f21c56405f3261e39`;
- the Goal branch was 197 commits ahead and 0 behind that master at the E-06/E-07 preflight.

No reset, rebase, merge, stash, clean, force push, production deployment or store submission was performed.

## What changed after the accepted H-11 backend/runtime baseline

The accepted H-11 repair baseline is `eaab88a7a7d96d2c1078b4b5243210430a305970`.

Fresh path comparison from that commit through the accepted E-06/E-07 closure found only:

- current-context / Goal documentation;
- H-11 and E-06/E-07 acceptance documentation;
- Android `MainActivity` and manifest Back integration;
- E-06 and master-plan static guards.

There are **no PHP application, route, database or migration changes after H-11**. Therefore the H-11 PHP 8.4 full-suite result still corresponds to the current backend product code:

- Unit + Feature: **4034 tests / 19397 assertions / 14 skipped / 0 failures**.

The Android native change was independently rebuilt and exercised on Android 16 after H-11.

## Milestone inventory

Fresh mechanical inventory of the Goal milestone table before changing H-GATE itself found:

- **71 DONE**;
- **2 DEFERRED**;
- **1 TODO** (`H-GATE`).

The two DEFERRED entries were:

1. `E-08` — iOS current-semantics / native capability cluster;
2. `H-10` — real iOS Xcode/device/signing/TestFlight capability cluster.

They describe the same unresolved Apple execution boundary at two roadmap levels. No Android capability cluster remains: E-06 and E-07 are now DONE with real Android 16 evidence.

Phase summary:

- FND: DONE;
- Phase A: DONE + A-GATE DONE;
- Phase B: DONE + B-GATE DONE;
- Phase C: DONE + C-GATE DONE;
- Phase D: DONE + D-GATE DONE;
- Phase E: E-01…E-07 DONE, E-08 DEFERRED, E-GATE DONE for all runnable/local evidence;
- Phase F: DONE + F-GATE DONE;
- Phase G: DONE + G-GATE DONE;
- Phase H: H-00…H-09 DONE, H-10 DEFERRED, H-11 DONE;
- H-GATE: this audit executed, but the completion gate remains DEFERRED because the final required iOS cluster is not closed.

## Current runnable evidence

### Web / backend

H-11 remains the final full backend/Web regression baseline because no backend application path changed after it. Its accepted evidence includes:

- PHP 8.4 Unit + Feature 4034 / 19397, 0 failures;
- production Docker native FSRS real build/load/schedule;
- real Web Reader → manual Sense → Finish → Sense Review → WordSense;
- exact 430px four-primary-navigation smoke;
- release-rights and runtime guards;
- final testing server / lease / account cleanup.

### Android

The later E-06/E-07 capability recovery added the missing current Android-native evidence:

- Android 16 / API 36 current APK build and install;
- Reader long-press phrase selection;
- lookup Bottom Sheet and IME reachability;
- real WordSense creation;
- cached short-term Reviewer, reveal and four ratings;
- haptic `impact(MEDIUM)` invocation;
- article package download and real offline Reader content after app restart;
- offline Good queued while the server was unreachable;
- reconnect / automatic sync;
- server readback of `sense_review.rating` applied with `reps=1`;
- device revoke / logout / restart boundary;
- Predictive Back contract: WebView history Back stays in LinguaCafe, while root Back returns control to the Android launcher.

Current Android/client verification:

- Mobile Vitest: **42 / 42 PASS**;
- TypeScript + Vite production build: PASS;
- Android debug assemble: **BUILD SUCCESSFUL**;
- final JS guard suite on the accepted Android callback / plan state: **477 / 477 PASS**.

## Fresh H-GATE checks

The final audit reran the checks that can materially change at this stage:

- M9 iOS source/release guard: PASS;
- H-08 public release-rights guard: PASS, **2071 tracked paths checked**;
- workspace inventory at accepted E-06/E-07 HEAD: **0 modified, 0 deleted, 0 untracked, 0 dangerous untracked**;
- no backend/product PHP diff after H-11;
- final Android task cleanup: APK uninstalled, emulator stopped, ADB device list empty;
- final PAB recovery: stale sentinel cleanup removed exactly one expected stale sentinel and ended with `TestingDatabaseLease active=false / stale_metadata=false`;
- full JS guard suite after the H-GATE status, authority-router and integrity-guard updates: **477 / 477 PASS / 0 failures**.

After the H-GATE row update, the mechanical milestone inventory is **71 DONE / 3 DEFERRED / 0 TODO**. The three DEFERRED rows are `E-08`, `H-10` and `H-GATE`; all three point to the same remaining Apple/iOS execution capability boundary rather than three independent product defects.

## Apple capability continuation after the original H-GATE probe

The original H-GATE probe correctly found that the local host remains Windows/x86_64, with no local `xcodebuild`, `xcrun`, `codesign` or `simctl`, and no online macOS Tailscale peer. That local-host fact remains true.

After that audit, the actual `origin` repository was freshly confirmed public and a bounded standard GitHub-hosted `macos-26` lane was added. The first three real runs `33264818165`, `33264963006` and `33265308947` established Xcode/SwiftPM/unsigned build and Simulator boot/install/launch. Later runs `33268124499` and `33268819125` both succeeded with a rendered iOS login-shell smoke; the latter also overlapped Simulator cold boot with build work without changing the result. Run `33279140695` then completed the authenticated lifecycle against a same-runner testing MySQL/native-FSRS/PAB backend, run `33282205923` completed rendered Sense Review Good/Undo with exact ReviewLog/operation/FSRS restoration evidence, run `33301226295` completed rendered Reader token/Sense/source-binding plus landscape phrase-gesture evidence, and run `33308079898` completed real iOS Files `.txt` import including disabled unsupported extension, invalid UTF-8, oversize rejection, successful valid import and exactly-one MySQL/PAB action/request evidence. The stable import result was re-extracted from fresh Goal and pushed as `6028453a899bdaf03f743d9c8b918ea4e4cbd236` without the temporary dispatch carrier.

The continuation now proves:

- macOS 26.5.2 + Xcode 26.6 (`17F113`);
- Mobile 42/42 and Capacitor iOS sync on macOS;
- generated-Web integrity after sync;
- SwiftPM resolution under the actual Xcode toolchain;
- unsigned iOS Simulator compile with `CODE_SIGNING_ALLOWED=NO`;
- an available iPhone 17 Pro Simulator can boot;
- the built `App.app` installs and `com.linguacafe.mobile` launches without immediate process-start failure;
- simulator terminate/shutdown cleanup succeeds;
- WKWebView exposes the login shell through the iOS Accessibility hierarchy;
- black-box assertions see `LinguaCafe`, `IOS · CONNECTED MVP`, login fields/actions and the Keychain/password-handling copy;
- a real tap/input on the server field updates the rendered UI and exposes the expected local-HTTP safety warning;
- real rendered login reaches the authenticated shell and survives process relaunch without another password;
- PAB receives `/api/v1/mobile/auth/tokens` followed by authenticated `/api/v1/mobile/bootstrap`;
- the server owns exactly one active iOS device/token relationship after login;
- the ordinary Preferences plist contains no `linguacafe-session-token` Web token key;
- rendered `撤销此设备并退出` revokes the device, deletes the personal access token, clears the Simulator Keychain credential and returns to login after relaunch;
- rendered Sense Review performs answer reveal → Good → next card → Undo and restores the original card;
- testing DB proves exactly one undone `sense_review` Good ReviewLog, one version-2 undone rating operation with two operation changes, zero rating on the next card, and exact pre/post-Undo FSRS fingerprint equality;
- rendered Reader in portrait opens a real `bank` token, creates a canonical reading-origin Sense/source binding, then reopens it through the existing-Sense path with `认识 / 记得` and `不认识` controls;
- rendered Reader in landscape long-press-drags `bank` to `account` and resolves the real `bank account` phrase flow;
- PAB records the canonical Reader mobile endpoints, MySQL corroborates the resulting source binding, and TestingDatabaseLease/sentinel, MySQL service and Simulator cleanup all return clean;
- the real system Files picker exposes only eligible `.txt` input as selectable, rejects invalid UTF-8 and oversize `.txt` through rendered UI, imports one valid UTF-8 English file, and leaves exactly one `library.text_import` action plus one import endpoint request.

Final full run `33350591521` has since closed the authenticated Simulator content matrix: cached article/review/audio survived the offline phase, one rendered Good queued locally and survived relaunch, reconnect synchronized exactly once, the empty queue remained stable, MySQL/PAB corroboration passed, and strict lease/sentinel cleanup passed. Release-capability run `33355499203` then revalidated Xcode 26.6 with `UIRequiredDeviceCapabilities = arm64`, passed the rendered login-shell flow serially on iPhone 17 Pro and `iPad Pro 13-inch (M5)`, and produced a `2064x2752` 13-inch iPad screenshot. Repository-side export-compliance run `33359184066` proved `ITSAppUsesNonExemptEncryption = NO` in the compiled bundle. Privacy runs `33361617463` and final `33362427450` then proved the revised collected-data declaration plus `UserDefaults / CA92.1`, non-tracking semantics and empty tracking domains in the built `App.app`; final run `33362427450` also passed the serial iPhone/iPad rendered smoke and cleanup.

The following completion-required evidence still does not exist and must not be inferred from Simulator evidence:

- signed physical-iPhone installation plus physical-device Keychain confirmation and physical haptics/notification/audio/safe-area behavior;
- Apple team/provisioning and signed archive validation;
- App Store Connect processing, TestFlight install and App Review evidence.

Full continuation evidence: `docs/testing/h10-macos-xcode-simulator-capability-continuation-2026-08-30.md`.

The authenticated login/Keychain/logout, formal Sense Review Good/Undo, Reader touch/source-binding, real Files `.txt` import and offline/reconnect lanes are now real and server-bound. This 2026-08-31 continuation supplies the previously missing iOS Simulator content evidence for the current H-GATE audit without rewriting H-11's historical 2026-08-29 report.

## Definition of Done audit

The Goal plan's final Definition of Done requires all twelve conditions.

Current result:

1. all completion-required milestones DONE — **FAIL only for E-08 / H-10 Apple capability**;
2. all Phase Gates DONE — **FAIL because H-GATE cannot pass while final required iOS capability remains deferred**;
3. rating / Finish / offline sync / migration / restore integrity evidence — PASS;
4. Web real-browser evidence — PASS;
5. Android real device/emulator evidence — PASS;
6. required iOS real macOS/Xcode/device/TestFlight evidence — **PARTIAL: macOS/Xcode/SwiftPM/full Simulator content matrix PASS, including authenticated login, Simulator Keychain save/load/revoke lifecycle, formal Sense Review Good/Undo, Reader touch/source-binding, real Files `.txt` import, offline/reconnect exactly-once sync, corrected `arm64` release capability, serial iPhone + 13-inch iPad rendered smoke, compiled-bundle export-compliance declaration and compiled-bundle Privacy Manifest/required-reason checks; physical device, real Apple signing/archive, TestFlight/App Store processing, public Privacy/Support URLs and publisher-owned final store answers remain FAIL / unavailable**;
7. testing DB / lease / sentinel / server clean — PASS;
8. no unexplained skipped / incomplete / false-green — PASS for runnable work; H-11's 14 skips are recorded capability/test metadata rather than hidden failures, and the stale MasterPlan false-negative guard was repaired explicitly;
9. no unknown blocker — PASS; the remaining blocker is known and named;
10. final-required deferred capability clusters cleared — **FAIL: iOS remains**;
11. Goal branch normally pushed / audit reproducible — PASS through the accepted code baseline; this H-GATE audit/status update is committed and normally pushed before the final external conclusion;
12. no unauthorized production / irreversible external action — PASS.

Because conditions 1, 2, 6 and 10 are not satisfied, the full Goal cannot be marked complete.

## Code review conclusion

The current Goal branch includes the Stage Accepted H-10 iOS net change and its repository-side release-preparation follow-ups; the latest privacy-manifest squash integration is Goal commit `330caf569d63199047d2f0ef54573e7c47c6795e`. Five-axis review found no Critical or Required issue in the integrated H-10/release-preparation delta:

- correctness: final iPhone full run `33350591521` passed all nine native XCUITest sessions with MySQL/PAB exactly-once corroboration and strict cleanup; release-capability run `33355499203` passed the corrected `arm64` unsigned build plus serial iPhone and 13-inch-iPad rendered smoke; export-compliance run `33359184066` and privacy runs `33361617463` / `33362427450` then passed the compiled-bundle release declarations, with `33362427450` also completing iPhone+iPad rendered smoke and cleanup;
- readability: the Mobile media-cache/playback changes remain local to the existing cache/player path, and the acceptance workflows expose explicit focus/device steps rather than a second runtime owner;
- architecture: Mobile continues to use the existing Media API, offline repository and FSRS owners; the new smoke-data command is testing-only, and the temporary workflow-dispatch carrier history was excluded from the squash integration;
- security: no Apple credential, signing key, public write API or production database path was added; the Auto-Fix workflow repair only supplies required GitHub token scope to the already-existing automation path;
- performance: the media migration only recreates disposable cache state, iOS data-URL conversion is scoped to the current audio item, and iPhone/iPad Simulator sessions are serialized to avoid hosted-Mac resource contention.

The integration also exposed one Windows portability defect in `AutoFixWorkflowContract.test.mjs`: its scheduler-input regex accepted LF only. The guard now accepts `\r?\n`, preserving the exact `issue_number`/`attempt` contract while passing a fresh CRLF checkout. Fresh integration verification passed Mobile 43/43 + production build, all 478 JS contract tests, workflow/YAML guards and PHP support-file syntax checks.

The earlier `MasterPlanIntegrityContract` repair remains a necessary project-validation-tool correction; it updates current facts without weakening or skipping the guard.

## Merge / release readiness

The current Goal branch is **merge-ready for the audited Windows / Web / Android work and the Stage Accepted iOS Simulator/repository-side release-preparation code**, subject to the project's normal merge process.

It is **not a complete cross-platform A–H Goal release** and is **not App Store / TestFlight ready**. No production deploy, Play submission or Apple store action is authorized by this audit.

## Reopen condition

H-GATE remains open only on the physical-device and Apple distribution portion of the capability cluster; the public macOS runner has now honestly closed the required Simulator content matrix.

The minimum remaining work is:

1. repeat the critical matrix on a signed physical iPhone, including physical Keychain confirmation and haptics/notification/audio/safe-area behavior;
2. perform Apple team/provisioning and signed archive validation;
3. obtain TestFlight/App Store Connect processing and physical install evidence if the final Goal still requires store readiness;
4. publish the final Privacy Policy / Support URLs and have the deployment owner confirm the prepared privacy, age-rating, content-rights and other publisher-owned App Store Connect answers against the actual release server/content;
5. obtain the real App Review result;
6. rerun H-GATE and mark DONE only if the deferred cluster is fully cleared.

Until then, the accurate project status is:

**Windows/Web/Android program plus the full required iOS Simulator cluster: Stage Accepted. Full cross-platform A–H Goal: DEFERRED / Not Complete only due to physical-device and Apple distribution capability.**
