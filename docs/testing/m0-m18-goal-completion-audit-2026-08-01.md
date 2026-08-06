# M0–M18 Goal Completion Audit — 2026-08-01

Status: **Local Implementation Closed / Goal Acceptance Deferred — Not Complete (iOS Capability Cluster)**

Goal: complete the authorized cloud-first, Anki-aligned M0–M18 roadmap while
preserving the repository's security, data-authority and formal-rating boundaries.

## Milestone ledger

| Milestone | Audited state | Remaining evidence |
|---|---|---|
| M0 | Completed / Planning Closed | None |
| M1–M8 | Accepted / Closed | None |
| M9 | Implementation Accepted / Acceptance Deferred — Not Complete | Apple toolchain capability cluster below |
| M10–M16 | Accepted / Closed | None |
| M17 | Web slice Accepted / Closed; Android platform evidence is owned by M7 | iOS platform checks travel with M9 |
| M18 | Implementation + Web + Android evidence Accepted / Closed | iOS media checks travel with M9 |

All repository implementation slices authorized by the roadmap are closed. M9 now
contains an official Capacitor iOS project, device-bound Keychain credential storage,
officially documented custom-plugin registration through the app bridge, real
platform identity, bounded UTF-8 `.txt` file import with server-side
idempotency, privacy manifest, scoped local sign-out cleanup and review-ready App
Store materials. M17's Web slice is accepted; its Android Haptics/Notifications
facts are cross-milestone platform evidence owned by the accepted M7 Android
client rather than a second M17 native implementation. The shared M18
implementation and its Web/Android evidence are accepted. Their iOS checks remain
inside the M9 capability cluster.

## Final open capability cluster

The current Windows host has no `xcodebuild`, `xcrun`, Swift toolchain, Apple signing
identity, booted iOS simulator/device, App Store Connect session or TestFlight
delivery surface. Therefore the following checks cannot be fabricated or replaced
with source inspection:

- Xcode dependency resolution and native compile;
- signed install and launch on an iOS simulator or physical device;
- Keychain persistence across restart and removal on sign-out;
- safe-area, notification, haptics, file-picker and `.txt` import interaction;
- online/offline package, queued-rating sync and cached-audio behavior on iOS;
- archive/signing validation and a real TestFlight build;
- App Store Connect privacy metadata, screenshots and review submission outcome.

These are one named iOS capability cluster because macOS/Xcode plus the applicable
Apple account/device access unlocks their real evidence. The cluster does not
invalidate the independently accepted Web, Android or server contracts, but the
goal cannot truthfully be marked Complete until every listed check is closed.

## 2026-08-06 publication revalidation

- Android source/config was published in `f243a9c`; Mobile Vitest passed 29/29,
  production Web assets rebuilt, offline Gradle test/assemble passed, and the
  current debug APK contained the expected package/assets with zero sourcemaps.
  No device was connected, so that exact rebuilt APK was not device-revalidated;
  the accepted 2026-08-01 emulator record remains the native workflow evidence.
- iOS source/config was published in `4be6c39` and passed static project,
  Keychain, privacy, storyboard, asset and SPM checks. The ignored generated iOS
  Web bundle is stale and must be refreshed and reverified by controlled
  `cap sync ios` on the authorized macOS/Xcode environment before native build.

## Verification basis

- M0–M18 roadmap, current handoff and master-plan status audit;
- milestone-specific ADRs, plans and acceptance reports;
- M9 PHP feature tests: 3 tests / 24 assertions;
- mobile Vitest: 4 files / 21 tests;
- mobile TypeScript/Vite build, zero-dependency-vulnerability audit and Capacitor
  iOS sync;
- M9/M7/M17/M18 and goal-mode documentation guards;
- final Android compatibility build after the shared M9 changes;
- official Browser 390 px Web rendering, Console and platform-label verification;
- official Browser and Chrome App Store Connect capability checks, both reaching
  an unauthenticated Apple-account sign-in screen without transmitting data;
- exact-scope whitespace and worktree checks.

No additional repository slice, migration, browser fixture, development-database
write or unresolved authority conflict remains. Resumption requires the named Apple
capability, not another product decision or permission round.
