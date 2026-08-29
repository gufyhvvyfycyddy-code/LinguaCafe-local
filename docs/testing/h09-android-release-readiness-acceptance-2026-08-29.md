# H-09 Android Release Readiness Acceptance — 2026-08-29

## Verdict

**Accepted / DONE.**

The accepted H-09 product commit is:

`e39154275154ce6f313d63ea0826947891f05bde` — `feat: close H-09 Android release preparation`

H-09 prepares and verifies the current Android client for a future Google Play release. It does not create or configure a real Play Console application, upload a bundle, publish a privacy-policy website, register the final upload certificate, or submit to any Play track.

## Git / authority preflight

Fresh Git preflight on 2026-08-29 found:

- product baseline before H-09: `760e08bf74d437cb62529babb69a20e845d24143`;
- current Goal remote before H-09: the same `760e08bf...`;
- `origin/master`: `1c9bdcd74fa793356ba3938f21c56405f3261e39`;
- `origin/master` is an ancestor of the Goal branch, with zero master-only commits and 190 Goal-only commits at preflight;
- no reset, merge, rebase, force push, or replacement of the Goal branch was required.

The local worktree branch name remains historical/stale, but the pushed authority is `origin/goal/linguacafe-a-h-sol-medium-20260809`.

## Current Google Play / Android requirements checked

Official material was rechecked on 2026-08-29 before closure:

- from 2026-08-31, new Android mobile apps and app updates submitted to Google Play must target Android 16 / API level 36 or higher;
- new Google Play apps use Android App Bundle as the publication format;
- with Play App Signing, the developer keeps a separate upload key and Google Play keeps the app-signing key used for delivered APKs;
- the upload key must be RSA 2048 bits or stronger;
- apps targeting Android 15/API 35 or higher must support 16 KB memory pages on 64-bit devices, and from 2027-02-01 unsupported updates cannot be released;
- every Play app must provide a privacy-policy link in Play Console plus a privacy-policy link or text inside the app;
- the public privacy URL must be active, public, non-geofenced, non-editable, and not a PDF;
- if the app itself allows account creation, Play requires an in-app deletion path plus an external deletion resource.

Reference pages:

- https://support.google.com/googleplay/android-developer/answer/11926878
- https://developer.android.com/google/play/requirements/target-sdk
- https://developer.android.com/guide/app-bundle
- https://support.google.com/googleplay/android-developer/answer/9842756
- https://developer.android.com/studio/publish/app-signing
- https://developer.android.com/guide/practices/page-sizes
- https://support.google.com/googleplay/android-developer/answer/10144311
- https://support.google.com/googleplay/android-developer/answer/13327111

## Release configuration

The accepted Android identity/build boundary is:

- application id: `com.linguacafe.mobile`;
- version code: `1`;
- version name: `1.0`;
- min SDK: 24;
- compile SDK: 36;
- target SDK: 36;
- Capacitor Android: 8.4.2;
- Android Gradle Plugin: 8.13.0;
- Gradle: 8.14.3;
- local release-preparation JDK: Microsoft OpenJDK 21 LTS.

`mobile/package.json` now exposes the release path:

`npm run android:release`

which performs Capacitor sync and then `bundleRelease`.

The release signing owner is `mobile/android/app/build.gradle`. Release signing is supplied only through these external environment values:

- `LINGUACAFE_ANDROID_KEYSTORE_PATH`;
- `LINGUACAFE_ANDROID_KEYSTORE_PASSWORD`;
- `LINGUACAFE_ANDROID_KEY_ALIAS`;
- `LINGUACAFE_ANDROID_KEY_PASSWORD`.

No keystore, private key, signing password, or certificate is stored in Git.

The Gradle configuration fails closed:

- no signing configuration -> `verifyReleaseSigning` fails;
- partial signing configuration -> configuration fails with the H-09 partial-configuration error;
- missing keystore file -> release verification fails;
- `bundleRelease` and `assembleRelease` both depend on the signing verification gate.

A focused no-signing run finished with exit code 1 and the expected message:

`Android release signing is not configured. Set all four LINGUACAFE_ANDROID_KEYSTORE_* variables before building a release artifact.`

A focused partial-configuration run also finished nonzero and emitted:

`Android release signing is partially configured. Set all LINGUACAFE_ANDROID_KEYSTORE_* variables or none of them.`

Gradle also printed a secondary configuration diagnostic after that intentional early failure; the project source still had `compileSdk = rootProject.ext.compileSdkVersion` and the successful signed build proved the compile-SDK configuration itself was healthy.

## Signed local release artifact proof

H-09 generated an external, one-time RSA-2048 test upload key outside the repository only for local release verification. It is not the future production upload key and was removed after verification.

With that temporary test upload key:

- `bundleRelease`: PASS;
- `assembleRelease`: PASS;
- Gradle executed `validateSigningRelease`, `verifyReleaseSigning`, `signReleaseBundle`, and release packaging tasks;
- temporary keystore search inside the repository after verification: zero files.

Release artifacts:

- AAB SHA-256: `160aef7f7b80b47c996d414774862aa37c6d97ff0c3b602630098cab53006fe1`;
- APK SHA-256: `5f97651c6f96dfbe60c3b2331949eec3c3fb7ea89fe8eff536cb62aa0130caf2`.

`jarsigner -verify` for the AAB returned exit code 0. Android `apksigner` for the release APK reported:

- verifies: yes;
- APK Signature Scheme v2: true;
- signer count: 1;
- signer certificate SHA-256: `eafe68ef99883b4fe5d10a1cbd737e81813ca1bac8ce34c5822dd3dcf872ef07`;
- signer DN identifies the H-09 temporary test certificate.

The certificate is evidence of local signing only. It is not registered with Google Play and must not be reused for the real store release.

## 16 KB page-size evidence

The current Android source and current Capacitor Android dependencies contain no `.so` native library relevant to the application bundle.

The final H-09 AAB was also inspected directly:

- native `.so` entries in AAB: **0**.

Therefore the current artifact has no native ELF page-alignment repair to perform. Future dependency changes must repeat the final AAB inspection; the source-level guard is intentionally lightweight and does not replace artifact inspection.

## Privacy and Play Data Safety preparation

H-09 adds an in-app privacy disclosure used on both the login and account/settings surfaces. The visible disclosure covers:

- LinguaCafe identity;
- account name/email;
- random installation/device identifier;
- learning materials, meanings, ratings and progress;
- offline queue and cached media;
- server security/diagnostic logs;
- native token protection through Android Keystore / Apple Keychain;
- no advertising SDK;
- no cross-app/cross-site tracking;
- no sale of user data;
- device revocation and local cleanup;
- server-account deletion through the selected server's Web account settings;
- backup-retention responsibility.

The fuller source remains:

`docs/release/mobile-privacy-and-data-deletion.md`

The Play working matrix is:

`docs/release/h09-android-google-play-materials.md`

It is deliberately a working engineering matrix. Final Data Safety declarations still require reconciliation against the actual public deployment and its enabled external processors immediately before submission.

The current Android client does not provide or link to account creation. Mobile logout/device revocation remains separate from destructive server-account deletion. If future Android work adds or links to account creation, the account-deletion product requirement must be reopened before Play submission.

## Android 16 real-device smoke

H-09 restored a real Android device path using the existing `LinguaCafeM7` AVD with the Android 36 Google APIs x86_64 system image.

Observed emulator facts:

- Android Emulator 36.6.11.0;
- Android API/system image: 36;
- WHPX hypervisor path operational;
- emulator boot completed successfully;
- ADB device: `emulator-5554`.

The exact debug APK installed for the testing-only HTTP/PAB device flow had SHA-256:

`9f1f3d7a0bd375fa7332eac7379b0b6a99c5e01c3e53b22e95765f1858016d66`

Release artifacts were kept separate because the production manifest correctly blocks cleartext HTTP. Local testing HTTP is allowed only through the debug manifest; H-09 did not weaken the release network policy to make device testing easier.

## Testing-only server proof

The Android device smoke used the existing PAB + TestingDatabaseLease path on port 8876.

Before authentication writes, the exact server returned:

- `environment=testing`;
- `database_is_testing=true`;
- `sentinel_present=true`.

The isolated testing database initially had no users. The normal `/setup` Web page created the task-provided local testing administrator. The concrete password is intentionally absent from repository documentation.

ADB reverse connected device port 8876 to the testing-only host server.

## Real Android authentication and navigation

The Android WebView exposed a real accessibility/UI tree and the real login surface. Device interaction completed:

1. server URL was set to the testing-only local address;
2. the testing account email/password were entered in visible Android controls;
3. the visible `安全登录` button was used;
4. the PAB log received a real `POST /api/v1/mobile/auth/tokens`;
5. the device then issued real `GET /api/v1/mobile/bootstrap` and `GET /api/v1/mobile/summary` calls;
6. the Android UI reached the online home screen;
7. visible bottom navigation contained 阅读 / 复习 / 生词 / 我的.

No API-only authentication shortcut was used as a substitute for the Android login flow.

## Real Android privacy and notifications

The account/settings surface visibly showed:

- the connected testing account;
- local review reminder;
- offline-sync status;
- connected server address;
- `隐私权政策与数据说明`;
- `撤销此设备并退出`.

Opening the privacy disclosure in the Android WebView exposed the expected privacy text, including no advertising SDK, server/on-device data, Android Keystore, transfer/sharing, retention, and deletion language.

Saving a review reminder caused Android 16 to display the real operating-system notification permission dialog:

`Allow LinguaCafe to send you notifications?`

After permission was granted:

- `android.permission.POST_NOTIFICATIONS`: granted=true;
- Android `AlarmManager` contained a LinguaCafe local-notification alarm using the Capacitor Local Notifications publisher.

This is real platform evidence rather than a source-code inference that notifications should work.

## Offline / reconnect evidence

After a successful login, H-09 removed the ADB reverse connection and restarted the app.

The current APK visibly entered its server-unreachable state and showed:

- `服务器不可达`;
- `无法读取今日进度`;
- `无法连接服务器，请检查地址和网络`;
- bottom navigation remained available.

After ADB reverse was restored and the app restarted again, the Android client returned to the online home screen without asking the user to re-enter the password. This proves the current native device credential path survived the process restart and was reused when connectivity returned.

## Device revocation / logout evidence

From the visible Android settings UI, H-09 used `撤销此设备并退出`.

The PAB server received the real request:

`DELETE /api/v1/mobile/devices/{device_uuid}`

The Android UI returned to the login surface. After another app restart, it remained on the login surface rather than silently restoring the previous authenticated session.

This proves the current APK's revocation path invalidated the local session path used for restart recovery.

## Keystore evidence boundary

The production source continues to protect the native Android session token with `SecureTokenPlugin` using Android Keystore AES/GCM.

During H-09, an attempt to directly inspect the app's private credential-storage file was blocked by the local tool safety layer. H-09 did not bypass that boundary through another private-file read mechanism.

Therefore this exact APK has:

- source-level Android Keystore implementation evidence;
- successful restart/recovery behavior while authenticated;
- successful device-revocation behavior;
- failed post-revocation session restoration;
- historical Android runtime evidence from the earlier M7 cycle;
- **no new H-09 file-level plaintext-vs-ciphertext dump of the private credential store**.

That missing private-file dump is recorded explicitly and is not represented as passed.

## Automated verification

Final H-09 focused checks after the last source change:

- `node --test tests/js/H09AndroidReleaseReadinessGuard.test.mjs`: **1/1 PASS**;
- mobile Vitest suite: **5 files / 42 tests PASS**;
- `npm run build` in `mobile`: PASS;
- TypeScript `tsc --noEmit`: PASS as part of mobile build;
- Vite production build: PASS;
- `git diff --check`: PASS before product commit;
- release signing missing-config behavioral test: expected nonzero / PASS;
- release signing partial-config behavioral test: expected nonzero / PASS;
- signed release AAB: PASS;
- signed release APK: PASS;
- AAB native `.so` count: 0.

The permanent H-09 guard now freezes min/compile/target SDK values, package/version identity, external signing-variable names, fail-closed signing task presence, release script, release cleartext/backup policy, debug-only cleartext override, current no-native-source boundary, and in-app privacy copy presence.

## Independent review

A final read-only OpenCode `opencode/hy3-free` review inspected the H-09 diff and reported:

- Critical: none;
- Required: none.

Its Optional observations were checked independently:

- source `.so` scanning is intentionally not treated as a substitute for final AAB inspection; the final AAB was inspected and had zero `.so` entries;
- signing behavior was tested directly, not only through source matching;
- `versionCode=1` is correct for this first release-preparation state and must be incremented for a later actual update;
- merged permissions/Data Safety remain a release-time reconciliation responsibility;
- the standalone Node guard is a focused milestone guard and was explicitly executed in the final gate.

No additional abstraction or CI subsystem was added for those optional observations.

## Production-tool incidents and repairs

H-09 repaired or validated only tooling required for the current Android release task.

### Mobile dependency path

A prior cross-drive/symlinked mobile dependency path caused Capacitor-generated Gradle files to contain machine-specific absolute paths. H-09 restored the normal project-local dependency layout.

Final facts:

- `mobile/node_modules` is a real directory in the current worktree;
- generated Capacitor settings use relative `../node_modules/@capacitor/...` paths;
- no machine-specific absolute Capacitor dependency path is required by the committed project.

### Android emulator

The previously unavailable hardware-acceleration path is currently healthy through WHPX. H-09 could boot Android 16 and complete the real device smoke, so no emulator product/tool patch was needed.

During emulator shutdown the Android tooling briefly restarted its ADB daemon and reported the old device offline while the emulator was exiting. After the daemon settled, `adb devices` was empty. The shutdown warning did not leave a running emulator or product defect.

### PAB / TestingDatabaseLease cleanup

One forced PAB job termination left `stale_metadata=true` and an old acceptance sentinel. H-09 did not manually delete lease state. It ran the existing recovery cycle, which:

- acquired the lease;
- detected and removed one stale sentinel;
- created its own validation sentinel;
- cleaned its own sentinel;
- ended with `active=false` and `stale_metadata=false`.

The recovery mechanism therefore worked as designed; no new testing-harness implementation was justified.

### Capacitor generated line-ending noise

`cap sync android` left two generated Gradle files with line-ending-only working-tree changes. H-09 first verified that `git diff --ignore-space-at-eol` contained no semantic change, then restored only those two generated files to HEAD. No unrelated file was restored or cleaned.

## Final cleanup

After Android acceptance:

- device logout/revocation completed;
- testing mobile devices: 0;
- testing Sanctum/mobile tokens: 0;
- testing users: 0;
- user-owned residue across the UserService account ownership map: 0;
- debug APK was uninstalled;
- emulator was shut down;
- final ADB device list: empty;
- port 8876: no listener;
- TestingDatabaseLease: `active=false`, `stale_metadata=false`;
- temporary H-09 keystore in repository: none;
- product worktree after commit/push: clean.

No development or production database was reset, wiped, restored, or substituted for the testing database.

## Store actions still external

H-09 deliberately leaves these actions for a future explicit store-release operation:

- create/verify the real Google Play developer/store entity;
- create and securely retain the long-lived production upload key;
- register its certificate through Play App Signing;
- publish the final public HTTPS privacy-policy URL;
- provide the real privacy/developer contact mechanism;
- reconcile the current deployed external processors against Data Safety;
- complete Data Safety, ads, audience, content rating and other Play Console declarations;
- increment `versionCode` for future updates;
- upload an AAB;
- run Play pre-launch/internal testing if a Play account is available;
- submit to any Play release track.

None of these external actions is implied by H-09 DONE.

## H-10 handoff boundary

H-09 is closed. H-10 owns the iOS Xcode/signing/device/TestFlight capability cluster.

H-10 must begin with a fresh capability probe. If the available environment has no authorized macOS/Xcode/Apple signing path, the missing capability remains explicitly DEFERRED; Windows/static source review must not be presented as a real Xcode build, simulator/device install, Keychain test, TestFlight upload, or App Store evidence.
