# H-10 iOS Capability Probe — 2026-08-29

## Verdict

**DEFERRED / capability unavailable. H-10 is not Accepted, DONE, or Complete.**

The current LinguaCafe iOS implementation remains source-level / Windows-static ready under the existing M9 evidence. This H-10 probe confirms that the current execution environment cannot produce the capability-bound evidence required for Xcode build, iOS simulator/device behavior, Keychain inspection, signed archive, TestFlight, or App Store Connect release work.

H-10 therefore stops at a factual capability boundary. Windows source checks, Android evidence, Web evidence, and generated-file inspection are not promoted to real iOS evidence.

## Baseline

H-10 started from the pushed Goal state:

`a5a8684173dde9ce8908d18bf79382374eacf92a` — `docs: close H-09 and open H-10`

The H-10 production-tool guard repair was then published as:

`5f60bc9557dcdbbc79fe79928581a19af51052c5` — `test: repair H-10 iOS readiness guard`

The previously published iOS implementation source remains the M9 capability cluster described by:

- `docs/adr/ADR-0054-m9-ios-mvp-and-release-readiness.md`;
- `docs/plans/m9-ios-mvp-release-plan.md`;
- `docs/testing/m9-ios-mvp-release-acceptance-2026-08-01.md`;
- `docs/testing/m9-ios-device-and-store-acceptance-playbook.md`;
- `docs/release/m9-ios-app-store-materials.md`.

## Current Apple requirements rechecked

Official Apple material was rechecked on 2026-08-29.

Apple's current SDK minimum requirement says that, since 2026-04-28, apps uploaded to App Store Connect must be built with **Xcode 26 or later** using an **iOS 26 SDK** for iOS submissions.

App Store Connect also states that:

- an app record must exist before a build is uploaded;
- upload requires an authorized App Store Connect role;
- builds may be uploaded through supported Apple delivery paths such as Xcode, Transporter, or the App Store Connect API;
- the uploaded bundle ID/version/build identity is used to associate the build with the App Store Connect record;
- Apple-side processing must finish before an uploaded build appears for use;
- TestFlight requires an uploaded eligible build and provisioning identifiers;
- TestFlight/device distribution remains an Apple-account/device capability, not a source-code property.

Official references:

- https://developer.apple.com/news/upcoming-requirements/
- https://developer.apple.com/help/app-store-connect/manage-builds/upload-builds/
- https://developer.apple.com/help/app-store-connect/test-a-beta-version/testflight-overview/
- https://developer.apple.com/help/app-store-connect/get-started/app-store-connect-workflow/

## Fresh local capability probe

The current host was probed directly rather than reusing the 2026-08-01 M9 statement.

Host facts:

- OS: Windows 10.0.26200.9168 / MSYS shell;
- `xcodebuild`: absent;
- `xcrun`: absent;
- `swift`: absent;
- `codesign`: absent;
- Apple `security` CLI: absent;
- `altool`: absent;
- Transporter CLI/app path: absent.

No Apple/App Store signing or upload environment-variable names were present in the current process environment. Values were not searched for, printed, inferred, or requested.

Repository signing-artifact inventory:

- `.p12`: 0;
- `.mobileprovision`: 0;
- `.xcarchive`: 0.

Tailscale capability probe:

- current node: online Windows host;
- online peers: 0;
- online macOS peers: 0.

Repository CI capability probe found no project workflow that currently supplies a macOS/Xcode execution lane for this iOS capability cluster.

These facts are sufficient to conclude that this session has no authorized path to a real Xcode/iOS/App Store Connect acceptance run.

## Windows-static revalidation completed

The absence of Xcode does not prevent rechecking the portable/shared portion of the implementation.

From `mobile/`:

- mobile Vitest: **5 files / 42 tests PASS**;
- TypeScript/Vite build: PASS;
- `cap sync ios`: completed its portable Web-copy/plugin-generation portion;
- Capacitor Doctor reported installed Core/CLI/Android/iOS all aligned at **8.4.2**;
- Doctor's native iOS result remained exactly: **`Xcode is not installed`**.

Capacitor Doctor also reported a newer 8.5.0 release exists. H-10 did not upgrade dependencies merely because a newer release is available. The current 8.4.2 set is internally aligned, H-10 has no Xcode capability to validate an iOS upgrade, and dependency upgrade is outside this capability probe.

## Current generated-Web integrity proof

The old 2026-08-06 M9 report recorded that ignored `mobile/ios/App/App/public/**` was stale at that historical checkpoint. H-10 refreshed the portable generated Web assets and checked them immediately.

Current generated references:

- main JS: `index-CGTGpXs0.js` in both `mobile/dist` and iOS generated public;
- CSS: `index-CLhVdIPh.css` in both locations.

SHA-256 comparisons:

- `index.html`: `2aa656e3718faabc05b972d44b315c3b6ba6b159c7ef8c40427460611f28803c` — identical;
- main JS: `7dcec2f97408575b30674d0632fde03989160b2ed51409aa81a77c5bcede1953` — identical;
- CSS: `2afed444c3e988451accf4bc55443dc023c3eb315e41524afd64ddae24e03552` — identical.

Additional integrity checks:

- generated iOS sourcemaps: **0**;
- `正式移动端仅允许 HTTPS`: present in generated main JS;
- `服务器分页信息无效`: present;
- `仅用于本地调试`: present.

This closes the old generated-Web staleness observation for the **current local generated copy**. It does not satisfy the playbook's required authorized-macOS post-sync gate before a real Xcode compile; that gate must be repeated on the Mac that performs the native build.

The ignored generated public directory remains outside the release source commit as designed.

## Production-tool bug: stale M9 source guard

The H-10 static gate found that `tests/js/M9IosMvpGuard.test.mjs` had been broken since its 2026-08-06 publication.

The guard still required old wording:

- `server-data deletion`;
- `does not offer account creation`;
- `future mobile release adds account creation`.

The authoritative privacy document had already used the current semantics before H-09:

- heading `Server data deletion`;
- current Android/iOS clients `do not offer or link to account creation`;
- future mobile release `adds or links to mobile account creation`.

Git history confirmed the test expectation was stale; the privacy document was not regressed to make the test green.

The guard was repaired to assert the current authoritative wording.

After repair:

`node tests/js/M9IosMvpGuard.test.mjs`

reported:

`M9 iOS MVP source and release guard passed.`

## Production-tool bug: Windows Capacitor SwiftPM path drift

The Windows H-10 `cap sync ios` run exposed a second tooling hazard.

Capacitor rewrote the tracked `mobile/ios/App/CapApp-SPM/Package.swift` plugin paths from the canonical portable form:

`../../../node_modules/@capacitor/...`

to Windows host separators:

`..\..\..\node_modules\@capacitor\...`

The generated diff affected only the three local plugin paths for Haptics, Local Notifications, and Preferences.

H-10 did not commit this machine-specific generated drift. The exact generated diff was inspected, then only `Package.swift` was restored to the committed canonical version.

The M9 iOS source guard now additionally requires:

- the three Capacitor local plugin paths to retain canonical relative forward-slash form;
- no `\node_modules\` Windows path form in the tracked SwiftPM package.

This is deliberately a small repository guard rather than a new wrapper, patched Capacitor installation, second iOS generator, or Windows-specific normalization service. Real iOS native build work already requires macOS/Xcode, so maintaining a second authoritative Windows-generated SwiftPM form would add an unsupported path without closing H-10.

## Capability-bound evidence still unavailable

The following H-10 evidence cannot be produced in the current environment and remains explicitly unverified:

1. Xcode 26+ native compilation against iOS 26 SDK;
2. package resolution under the actual Xcode/SwiftPM toolchain;
3. booted iOS simulator install and UI flow;
4. signed physical-iPhone installation;
5. real safe-area/notch/Dynamic Island behavior;
6. physical haptic behavior;
7. native local-notification behavior on iOS;
8. native `.txt` document picker/import behavior;
9. force-quit offline article/review/audio and exactly-once recovery on iOS;
10. Keychain at-rest boolean/status inspection proving no plaintext bearer token in ordinary storage;
11. signed archive creation and Xcode Organizer validation;
12. real Apple team/provisioning/bundle registration;
13. App Store Connect upload and Apple-side build processing;
14. TestFlight installation on a physical iPhone;
15. final public privacy/support URLs and real App Store Connect privacy answers;
16. App Review result.

No Android, Chrome, Windows, source, generated-file, or static evidence is used as a substitute for any item above.

## Why H-10 does not add a cloud-mac workaround now

A remote macOS CI runner could eventually help automate unsigned compile and archive checks, but it would not by itself satisfy the current full capability cluster:

- Apple team and signing authorization are still required for distribution;
- a physical iPhone is still required for final haptics/device behavior;
- TestFlight/App Store Connect still requires the deployment owner's Apple-side app/account capabilities;
- the current session has no authorized Apple credentials or app record.

Creating a paid macOS CI/service merely to produce partial evidence would therefore add cost and another production path without making H-10 complete.

The efficient resumption path is the already frozen playbook: when an authorized Mac with Xcode 26+ and the deployment owner's Apple capabilities becomes available, use `docs/testing/m9-ios-device-and-store-acceptance-playbook.md` from the current Goal commit and repeat the post-sync integrity gate on that Mac before any native compile.

## Security / cleanup

H-10 did not:

- read or modify `.env`;
- request or inspect Apple passwords, cookies, certificates, private keys, or account identifiers;
- create a `.p12`, provisioning profile, archive, IPA, or App Store Connect upload;
- weaken App Transport Security;
- use Android/Web evidence as iOS evidence;
- reset or wipe any database;
- perform a store submission.

The Windows-generated SwiftPM backslash diff was removed precisely. The ignored generated iOS Web copy remains current local evidence and is not added to Git.

## 2026-08-30 capability continuation

This 2026-08-29 report remains the historical record of the Windows-only probe. On 2026-08-30, a standard GitHub-hosted `macos-26` runner became a verified, authorized read-only execution lane for the public `origin` repository. The continuation evidence is recorded in `docs/testing/h10-macos-xcode-simulator-capability-continuation-2026-08-30.md`.

That continuation now provides real Xcode 26.6 compile, SwiftPM resolution, iOS Simulator boot/install/launch/terminate/shutdown evidence. It does not provide authenticated simulator main-flow, Keychain-at-rest, signed physical-device, archive, TestFlight, App Store Connect, or App Review evidence.

## H-10 state and H-11 boundary

H-10 remains **DEFERRED / Not Complete** until the remaining authenticated simulator / physical-device / Apple signing and distribution capability is executed and accepted.

The Goal roadmap permits the next final cross-platform regression to use **available iOS** only. H-11 may therefore proceed with full Web + Android regression while recording iOS as unavailable/deferred; doing so must not cause H-GATE to claim the iOS capability cluster is complete.

If a **server-bound rendered iOS functional lane** becomes available before final Goal closure, H-10 must resume from the frozen playbook and H-11 must include that newly runnable iOS main-flow surface before H-GATE. The 2026-08-30 compile/basic-launch lane has already been consumed by H-10, but by itself does not make login/Reader/Review/offline/account-boundary UI runnable and therefore does not manufacture H-11 functional evidence.
