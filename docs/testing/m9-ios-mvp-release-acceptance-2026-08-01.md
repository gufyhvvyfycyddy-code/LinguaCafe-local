# M9 iOS MVP and release readiness acceptance — 2026-08-01

Status: `Implementation Accepted / Acceptance Deferred — Not Complete (iOS Capability Cluster)`

## Implemented scope

- Added the official `@capacitor/ios@8.4.2` platform, aligned with Capacitor
  Core/Android/CLI, and synced the official Haptics, Local Notifications and
  Preferences plugins through Swift Package Manager.
- Added the minimal custom `SecureToken` bridge because Capacitor has no
  official secure credential plugin. It stores only the device token in a
  generic-password Keychain item with
  `kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly`; passwords are never
  persisted. The storyboard now uses the app's `MyViewController`, whose
  `capacitorDidLoad()` registers `SecureTokenPlugin`; the Swift file is included
  in the Xcode Sources phase.
- Fixed the shared login request and labels so iOS sends `platform=ios` and
  `LinguaCafe iOS` instead of the previous hard-coded Android identity.
- Added the iOS system file-picker flow for one UTF-8 `.txt` document up to
  200 KB. It hashes the selected bytes, retains one action id for an ambiguous
  manual retry, and calls a new device-authenticated, English-only endpoint.
- The endpoint reuses `ImportService` and `MobileIdempotencyService`; exact
  replays return the first operation and changed payload reuse returns 409.
- Added safe-area reuse, optional local notification/haptic support, privacy
  manifest data/reason declarations, App Store listing/review drafts, privacy
  notice and data-deletion guidance.
- Sign-out now waits for the active offline mutation chain, removes the current
  user/language offline scope, clears the media cache, revokes the device when
  reachable and clears the native token. A cache cleanup failure is surfaced
  while credential clearing remains fail-closed.

## Executable evidence on this host

- `M9MobileTextImportTest`: 3 tests / 24 assertions passed, including auth/device,
  English isolation, exact replay, conflict, format/size and whitespace validation.
- Mobile Vitest: 4 files / 21 tests passed.
- TypeScript and Vite production build passed.
- `npm audit --audit-level=low`: zero vulnerabilities.
- `cap sync ios` passed and detected only the three official shared plugins.
- M9 static source/release guard passed, including the custom ViewController,
  storyboard module, plugin registration and Xcode Sources membership.
- Windows XML parsing accepted the storyboard, `Info.plist` and privacy manifest;
  a PBX reference audit confirmed every app Swift source exists and belongs to
  the Sources phase.
- Capacitor Doctor confirmed aligned installed Core/CLI/Android/iOS `8.4.2`
  dependencies and reported the remaining native failure precisely as
  `Xcode is not installed`.
- Android compatibility `assembleDebug` passed after adding the iOS platform
  and shared UI/API changes.
- Official OpenAI Browser rendered the local client at 390×844. It showed
  `MOBILE · CONNECTED MVP`, session-only Web credential wording, exact
  `viewportWidth=scrollWidth=390`, and no Console warnings/errors. The dedicated
  test page was closed; there were no pre-existing user tabs.

## 2026-08-06 repository publication revalidation

The 22 formal iOS source/config candidates were published in commit `4be6c39`.
A fresh static audit confirmed the Xcode project references, iOS 15 deployment
target, `com.linguacafe.mobile` bundle id, Keychain bridge, storyboard class,
privacy manifest, assets and Swift Package declarations. Mobile Vitest again
passed 29/29 and the TypeScript/Vite build passed.

The ignored generated directory `mobile/ios/App/App/public/**` is not part of
that commit and is currently stale: it still references `index-C9ukJ5MA.js`,
contains four sourcemaps and lacks the current HTTPS, pagination and local-debug
safeguard strings. Before any iOS compile, simulator/device run, archive or
release attempt, an authorized macOS/Xcode environment must run controlled
`cap sync ios` and prove that the generated index/JS/CSS match the current
`mobile/dist`, contain zero sourcemaps and include the current safeguards. Static
source readiness does not promote the iOS capability cluster to complete.

## Capability-bound evidence still required

The current host is Windows/MSYS and has no `xcodebuild`, `xcrun` or Swift
toolchain. Source generation and `cap sync ios` cannot substitute for:

1. unsigned iOS simulator compilation in Xcode;
2. signed archive validation with the deployment owner's Apple team;
3. booted iOS simulator/device login, reader, lookup, create-sense, review,
   undo, reminder, haptic, safe-area and file-picker/import flows;
4. Keychain at-rest inspection showing no plaintext token;
5. offline article/review/audio after force-quit and exactly-once restore/sync;
6. public privacy/support URLs, reviewer fixture account, screenshots and App
   Store Connect privacy answers;
7. TestFlight/device acceptance and actual App Store review result.

The official OpenAI Browser and Chrome channels were both navigated to App Store
Connect. Each exposed the Apple-account sign-in screen rather than an authenticated
App Store Connect session. No credential, cookie, local storage, password or account
identifier was inspected; no form was filled and no upload/submission was attempted.

These checks form the M9 iOS signing/device/store capability cluster. They are
not marked Accepted, Closed or Complete, and Android/Web evidence is not reused
as iOS evidence. The exact resumption commands, device matrix, evidence and cleanup
requirements are frozen in `m9-ios-device-and-store-acceptance-playbook.md`.

## Cleanup

- The official Browser test page was finalized and the temporary Vite server
  was stopped.
- The App Store Connect capability-check tabs in both official browser channels
  were finalized; pre-existing user Chrome tabs were not claimed or closed.
- No Apple credentials, production account, real user data, payment, signing,
  upload or store submission was used.
- No testing fixture was created by the read-only Browser check.
