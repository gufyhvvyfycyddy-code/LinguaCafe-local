# M7 Android Connected MVP — Interim Acceptance

## Status

`Acceptance Deferred — Not Complete`

The connected Android implementation and all evidence available without a
booted Android device are complete. The remaining acceptance item is the
server-bound Android emulator/device workflow and Keystore-at-rest inspection.
Under ADR-0052, M8 implementation may continue against the executable M3/M4
contracts; M7 and M8 device observations remain in the same Android-device
evidence cluster and neither milestone is closed by this report.

## Completed evidence

- `M7MobileConnectedApiTest`: 6 tests / 39 assertions passed, including
  authentication, user/language isolation, local-only dictionary lookup,
  manual WordSense creation, server-owned field rejection, cross-user chapter
  rejection, summary scoping and zero-write read paths.
- Mobile API unit tests: 8 passed, including fixed rating action IDs, article
  envelope projection, bounded rating-only undo selection, network failures,
  safe server errors and non-JSON response handling.
- `M7AndroidConnectedMvpGuard.test.mjs` passed.
- TypeScript typecheck and Vite production build passed.
- Official Capacitor Android sync passed with the official Core, Android,
  Preferences and Local Notifications packages.
- Gradle `assembleDebug` passed. The generated debug APK is 4,204,854 bytes
  with SHA-256
  `d491bfe09da14a89142cd0c9c4b3209d14a1c0bb80baee7ee7140d222e284824`.
- Protected Review FSRS, FSRS scheduling and WordSense suites passed during
  this slice.
- Scoped `git diff --check` passed; the only message was Git's existing
  LF-to-CRLF notice for `routes/api.php`.

## Official Chrome UI evidence

The official OpenAI Browser connector was attempted first but its isolated
localhost policy replaced the target with an error page. The official OpenAI
Chrome connector then rendered the real Vite app.

At an explicit 390 × 844 viewport:

- document width was exactly 390 with no horizontal overflow;
- each login input was 50 px high;
- the primary login button was 54 px high;
- the page exposed the expected server, email and password controls;
- filling all three fields and clicking `安全登录` exercised the real error
  path and displayed `无法连接服务器，请检查地址和网络`;
- Console contained no warning or error.

The automation-owned pages and temporary Vite process were closed. The
pre-existing user pages were left unchanged.

## Deferred Android device evidence

The official Android SDK, API 36 images, platform tools and emulator are
installed. `adb devices -l` reports no connected physical device.

The x86_64 AVD cannot use acceleration because the Android Emulator
Hypervisor Driver service is not installed. Installing or enabling the
required Windows hypervisor component requires a UAC-elevated system action,
which the available Computer Use policy prohibits automating.

Two non-elevated official Emulator fallbacks were attempted:

1. `-accel off -gpu swiftshader_indirect`;
2. `-accel off -gpu off` (Lavapipe fallback).

Both initialized QEMU and the rendering backend, then exited with Windows
access-violation code `0xC0000005` before registering with ADB. The installed
ARM64 API 36 image is not executable on this x86_64 host.

Therefore the following remain open:

- install and launch the debug APK on a booted Android emulator/device;
- server-bound testing login, reader, lookup, WordSense create, rating, undo,
  summary and reminder workflow;
- confirm Android private storage contains ciphertext/IV and not the plaintext
  device token;
- clean the task-only testing fixtures and verify zero residual rows.

This interim evidence releases no device acceptance claim. It does not prohibit
independently tested downstream implementation under ADR-0052.
