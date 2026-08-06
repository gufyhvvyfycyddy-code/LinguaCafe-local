# M9 iOS Device and Store Acceptance Playbook

Status: Ready to execute on an authorized macOS/Xcode/Apple environment

This playbook closes the capability-bound checks in
`m9-ios-mvp-release-acceptance-2026-08-01.md`. It does not authorize production
data writes, credential disclosure, paid enrollment, build upload or App Store
submission by itself. Use a dedicated testing server and minimum-role test account;
never paste Apple credentials, session tokens, signing keys or reviewer passwords
into repository files, shell arguments, logs or screenshots.

## 1. Required capability

- macOS with an App Store Connect-supported Xcode release and current iOS SDK;
- an available iOS simulator plus one physical iPhone for haptics and final device
  behavior;
- access to an Apple Developer Program team and App Store Connect role allowed to
  manage this app;
- a testing-bound HTTPS LinguaCafe server whose environment/database sentinel is
  verified before any write;
- a task-specific, minimum-role LinguaCafe testing identity.

Record Xcode/macOS/iOS versions, device model, commit/tree identity, bundle id and
testing sentinel result. Do not record secrets.

## 2. Unsigned compile gate

From `mobile/`:

```bash
npm ci
npm test -- --run
npm run cap:sync:ios
```

### Post-sync Web asset integrity gate

Do not continue merely because Capacitor reports a successful sync. From
`mobile/`, resolve the JS/CSS filenames referenced by both `dist/index.html`
and `ios/App/App/public/index.html`, then require:

1. referenced JS filenames are identical;
2. referenced CSS filenames are identical;
3. `shasum -a 256` values for index, referenced main JS and referenced CSS are
   identical between `dist` and generated iOS public;
4. `find ios/App/App/public -name '*.map'` returns zero files;
5. no bundle referenced by the pre-sync index remains referenced after sync;
6. the generated main JS contains all three current safeguards:
   `正式移动端仅允许 HTTPS`, `服务器分页信息无效`, and `仅用于本地调试`.

Record filenames and SHA-256 values as external evidence without committing the
generated directory. Any mismatch, sourcemap or missing safeguard blocks Xcode
compile, simulator/device testing, archive and release work.

Only after this gate passes:

```bash
cd ios/App
xcodebuild -resolvePackageDependencies -project App.xcodeproj -scheme App
xcrun simctl list devices available
```

Choose one listed simulator UUID and keep it only in the current shell:

```bash
LC_IOS_SIM_UDID='<available-simulator-uuid>'
xcodebuild \
  -project App.xcodeproj \
  -scheme App \
  -configuration Debug \
  -sdk iphonesimulator \
  -destination "platform=iOS Simulator,id=${LC_IOS_SIM_UDID}" \
  CODE_SIGNING_ALLOWED=NO \
  build
```

Pass requires exit code 0 with no missing Swift source, unresolved package,
storyboard class, privacy-manifest or `SecureToken` registration error. Preserve the
full build log as an external acceptance artifact; do not commit DerivedData.

## 3. Simulator functional matrix

Before the first write, prove that the exact server host/port is bound to the
testing environment and dedicated testing database. Use HTTPS; do not weaken App
Transport Security for acceptance convenience.

Run these flows through rendered UI and user events:

1. Fresh install opens login and labels the device as iOS.
2. Login reaches the library; force-quit/relaunch restores the session without
   another password entry.
3. Reader opens an English chapter, preserves safe-area spacing in portrait and
   landscape, supports touch lookup and creates one task-marker sense.
4. Reviewer reveals the answer, submits one rating, shows the next card, then undo
   restores the exact prior state. Confirm one ReviewLog and one operation-ledger
   effect only.
5. Settings exposes the iOS `.txt` picker. Import one valid UTF-8 English file,
   reject invalid extension/encoding/oversize input, and confirm the created book
   belongs only to the current user/language.
6. Download one article/review package and one audio asset, force-quit, disable the
   server/network, relaunch, read/play from cache, queue one rating, restore the
   network and confirm exactly-once sync.
7. Sign out, force-quit and relaunch. Login must be required; the prior
   user/language offline scope and cached media must not be rendered.

Capture DOM/user-visible state, Console, Network and testing-database before/after
facts. Clean every marker through the approved testing harness or normal product
entry; never clean by dropping/truncating tables.

## 4. Keychain evidence

- Confirm `SecureToken` calls succeed on login, load after force-quit, and clear on
  sign-out; a missing plugin or bridge error fails M9.
- Search the simulator app data container and Web storage for a unique non-secret
  token fingerprint supplied by the testing harness. The fingerprint/token must not
  appear in Preferences, IndexedDB, Local Storage, Session Storage, logs or files.
- Confirm the Security API item uses the app bundle-derived service, fixed
  `mobile-session-v1` account and
  `kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly` accessibility.
- Never print or screenshot the bearer token itself. Record only boolean checks and
  Security API status codes.

## 5. Physical-device matrix

Repeat login/relaunch, reader, formal rating/undo, offline restart/sync, cached audio,
file picker and sign-out on a signed physical iPhone. Additionally verify:

- haptic feedback occurs only for the frozen review actions and never selects a
  rating;
- local-notification permission denial is fail-soft;
- an allowed reminder fires at the expected local time and opens the app safely;
- audio playback acquires/releases system audio focus and remains usable after
  interruption;
- safe areas remain correct around notch/Dynamic Island and home indicator.

No simulator observation substitutes for these physical haptic/device checks.

## 6. Archive and TestFlight

In Xcode, assign the deployment owner's team, verify the final bundle id, version,
build number, app icon, launch screen and supported destinations, then create an
archive. Use Organizer **Validate App** before any upload. Preserve validation output
and the archive outside the repository.

After the deployment owner authorizes upload:

1. upload the validated archive to the matching App Store Connect app record;
2. confirm processing succeeds and record the build/version identity;
3. complete export-compliance answers factually;
4. add an internal TestFlight group and the frozen testing notes;
5. install through TestFlight on the physical device and repeat the critical matrix;
6. record sessions/crashes and resolve every release-blocking issue.

Apple documents that distribution requires a matching bundle id/team, TestFlight
builds require provisioning identifiers, and uploaded builds must finish Apple-side
processing before they appear. Do not call an upload successful before these states
are visible in App Store Connect.

## 7. Store metadata and review

Use `docs/release/m9-ios-app-store-materials.md` and
`docs/release/mobile-privacy-and-data-deletion.md` as drafts. Replace every placeholder
with an externally hosted, publicly reachable value owned by the deployment owner.
Verify screenshots on the submitted build and answer privacy questions from observed
data flow rather than marketing intent.

Submission is a separate external action. Only after actual App Store Connect review
shows approval may M9 be changed from `Not Complete` to `Accepted / Closed`.

## 8. Completion record

Update the M9 acceptance report with:

- unsigned build command/log identity;
- simulator and physical-device matrices with versions and observable outcomes;
- testing sentinel and cleanup evidence;
- Keychain boolean/status evidence without token material;
- archive validation, TestFlight build and device results;
- public privacy/support URLs and App Store Connect privacy-answer review;
- actual review state.

Then rerun the M0–M18 completion audit. No source inspection, Android evidence or
draft store document may be promoted to iOS device/store evidence.

## Official references

- <https://developer.apple.com/documentation/xcode/preparing-your-app-for-distribution>
- <https://developer.apple.com/documentation/xcode/distributing-your-app-for-beta-testing-and-releases>
- <https://developer.apple.com/help/app-store-connect/manage-builds/upload-builds/>
- <https://developer.apple.com/help/app-store-connect/test-a-beta-version/testflight-overview/>
- <https://developer.apple.com/documentation/security/keychain-services>
