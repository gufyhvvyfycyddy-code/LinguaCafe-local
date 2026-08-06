# ADR-0044 — M7 Android Connected MVP

## Status

Accepted under the current roadmap goal authorization (2026-07-29).

## Context

M1–M5 already provide device-bound Sanctum authentication, article/review
packages, idempotent formal rating, operation-ledger undo and touch-ready Web
interaction contracts. M7 needs an installable Android client without creating
a second server authority or prematurely implementing M8 offline sync.

The repository currently has no Android project, Java/Android SDK wiring or
native credential storage. Reusing the full Laravel/Vue Web application inside
a remote WebView would not exercise the Mobile API and would couple the native
shell to Web session cookies.

## Decision

Create an additive `mobile/` application built with the official Capacitor
Core, Android, Preferences and Local Notifications packages. Its UI is a small
TypeScript/Vite application and consumes only `/api/v1/mobile`.

Responsibilities:

- `mobile/src/api`: envelope-aware Mobile API client; never computes FSRS.
- Official Capacitor HTTP patches native `fetch`/XHR so the Android shell uses
  the Mobile API's device-bound Bearer contract rather than being mistaken for
  a browser-cookie/CSRF session; the Web build keeps the normal Web fetch path.
- `mobile/src/session`: base URL/device/session orchestration.
- Android `SecureToken` Capacitor plugin: encrypt the one-time Sanctum token
  with Android Keystore AES/GCM and store only ciphertext in private
  SharedPreferences.
- Capacitor Preferences: non-secret server URL, device UUID and reminder
  preferences.
- Local Notifications: one user-configured local review reminder.
- Mobile screens: login, article list/chapter reader/local dictionary lookup,
  deliberate manual-sense creation, review/reveal/rating, latest-operation
  undo, and a compact daily summary.

The server adds only three Mobile API seams:

1. read-only local-dictionary lookup;
2. deliberate confirmed WordSense creation through the existing
   `WordSenseService::createManualSense` owner;
3. read-only daily summary through existing review query services.

Article and review data continue to come from the accepted M3 packages.
Ratings and undo continue to use the accepted M1/M2 endpoints.

## Data and compatibility

- Laravel/MySQL remains authoritative.
- The app is connected-only in M7. It may keep current screen data in memory,
  but does not persist article/review packages or queue ratings.
- Every mutation uses the existing device-bound bearer token and server-side
  user/language isolation.
- The app generates one UUID `client_action_id` per deliberate rating and does
  not generate a second ID when retrying the same in-flight action.
- Existing Web routes, Vuex state, Web scoring and payloads remain unchanged.
- Native transport does not add cookies, weaken Web CSRF middleware or change
  Mobile API envelopes, status codes, authentication or isolation semantics.
- HTTP is disabled in release. A debug-only Android manifest may allow local
  emulator/testing hosts.

## Consequences

This produces a real Android client and keeps M8's local database, queued
actions and background sync out of scope. The small native secure-token plugin
avoids a third-party credential dependency while preserving official Capacitor
priority.

## Verification

- TypeScript unit/contract tests and production Web build;
- Mobile API feature tests for lookup/create/summary isolation and zero-write
  lookup/summary behavior;
- protected Review FSRS, scheduler and WordSense regressions;
- official Capacitor sync plus Gradle debug build;
- Android emulator or real-device browser/UI acceptance against a
  server-bound testing environment;
- token ciphertext/no-plaintext inspection, local reminder scheduling,
  login/reader/lookup/create/review/undo/summary and cleanup evidence.
