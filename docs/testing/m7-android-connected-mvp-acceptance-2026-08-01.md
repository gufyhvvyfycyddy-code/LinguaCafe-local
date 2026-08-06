# M7 Android Connected MVP — Final Acceptance (2026-08-01)

## Verdict

**Accepted / Closed.** The earlier 2026-07-29 report remains the historical
record of the unavailable official Emulator path; this report closes the same
Android-device evidence cluster on a booted MuMu Android 12 emulator.

## Server and artifact identity

- Device: Android 12, model `PHY110`, ADB `127.0.0.1:16384`.
- The task PHP listener was bound to `0.0.0.0:8797`. Both host and device
  requests returned `environment=testing`, `database_is_testing=true` and
  `sentinel_present=true` before write-capable UI actions.
- Emulator-tested debug APK: 4,286,653 bytes; SHA-256
  `406f3069e396ba547ac811e6b08e86d0020daec45dfce82e46db29b43d43d423`.
- After the review fixes (Vite/Vitest security updates plus fail-safe cached
  bootstrap cleanup), the same source rebuilt to 4,286,741 bytes; SHA-256
  `b4255e08125af58c027737592fb9ace29e2a5fbc0c7b51eec32136c235609da1`.
- After M9 added platform-neutral labels and local sign-out data cleanup, the
  current shared source passed Android `assembleDebug` again: 4,289,660 bytes;
  SHA-256 `77d3fbda56f313cf731fa3173c833f219a7b2d6800cc9376e4cf5c2498b2303c`.
- Capacitor sync detected only official Haptics, Local Notifications and
  Preferences plugins. Official Capacitor HTTP supplies the native Bearer
  transport; Web builds keep normal fetch behavior and Web CSRF is unchanged.

## Rendered Android workflow

Real rendered controls and trusted CDP/ADB input completed:

1. server URL/email/password login and device-token issuance;
2. article list, chapter list and three-token reader;
3. local-only dictionary lookup with a bounded empty result;
4. deliberate WordSense creation through the rendered form;
5. answer reveal, `Good` rating, latest-rating undo and queue restoration;
6. daily summary and an 18:00 local reminder saved from Settings.

The first native login exposed a real `419 CSRF`: WebView fetch was being
classified as a browser-cookie request. Enabling the official bundled
Capacitor HTTP transport fixed the native request without changing Mobile API
envelopes, authentication, isolation or server middleware. Device acceptance
also exposed an empty undo payload; the client now sends the accepted M2
`client_action_id + expected_version` contract and the rendered undo succeeds.

## Native security and OS evidence

- Android private `linguacafe_secure_session.xml` contained exactly two keys:
  a 67-byte decoded AES-GCM ciphertext and a 12-byte IV. No plaintext Sanctum
  token marker was present.
- The Local Notifications plugin scheduled an Android `RTC_WAKEUP` for 18:00
  through `TimedNotificationPublisher`; package alarm/notification records were
  present.
- The official Haptics plugin received one rendered-rating `impact` call with
  style `MEDIUM`. MuMu exposes no vibrator service, so no physical-vibration
  claim is made; the native plugin invocation and non-blocking fallback are
  verified.

## Automated evidence

- Mobile Vitest: 4 files, 17 tests passed.
- M7 Android source guard passed.
- TypeScript/Vite production build, Capacitor sync and Gradle
  `assembleDebug` passed.
- Mobile `npm audit` reports zero vulnerabilities after the non-major Vite and
  Vitest security updates.
- Existing protected Mobile API, WordSense and FSRS matrices had already passed
  for the implementation slice; this native closeout changed no backend code.

## 2026-08-06 repository publication revalidation

The formal Android source/config tree was published in commit `f243a9c`. From
that committed source, the current Windows host independently re-ran:

- Mobile Vitest: 4 files / 29 tests passed;
- TypeScript/Vite production build;
- offline Gradle `:app:testDebugUnitTest :app:assembleDebug` with all 133 tasks
  re-executed;
- package and generated-asset checks for application id
  `com.linguacafe.mobile`, zero sourcemaps and the current HTTPS/pagination/local
  debug safeguards.

The resulting debug APK had SHA-256
`94c1f68e4ce00dd8c311e1eb16dd95c4b45ee79bba3287478dbd1af6423d1f85`.
No Android device or emulator was connected on 2026-08-06, so that exact rebuilt
artifact was not reinstalled or re-run through the native UI matrix. This does
not erase the accepted 2026-08-01 Android 12 emulator evidence above, but the
new APK must not be described as device-revalidated, release-signed or
store-ready.

## Cleanup

The exact testing user id 544 and its books, chapters, WordSenses,
occurrences, ReviewCards, ReviewLogs, operations/changes, client actions,
devices, Sanctum token, settings presets, media reference/asset and sentinel
were deleted. The task media directory and source MP3 were removed. Follow-up
counts for the user, markers, token, sentinel and every table row with
`user_id=544` were zero. Port 8797 closed, the APK was uninstalled, ADB forwards
were removed, MuMu shut down and no FastCtx background job remained.
