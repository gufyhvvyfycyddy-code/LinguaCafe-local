# ADR-0054 — M9 iOS MVP and release readiness

Status: Accepted under current goal authorization

Date: 2026-08-01

## Context

M9 is the final named milestone. The shared Capacitor client already owns the
connected reader/reviewer, limited offline queue, media cache, reminders,
haptics and safe-area CSS. iOS must reuse those contracts without creating an
iOS-only scheduling or data authority.

The remaining platform-specific seams are the Xcode project, Keychain-backed
session storage, document selection, iOS privacy metadata, release material and
device/signing evidence.

## Decision

1. Add the official Capacitor iOS platform at the same `8.4.2` version as Core,
   Android and CLI. Haptics, Local Notifications, Preferences and Capacitor HTTP
   remain the official shared plugins.
2. Capacitor does not provide an official secure-credential storage plugin. The
   minimal custom `SecureToken` bridge is therefore the bounded fallback. Its
   JavaScript contract is shared. Android implements it with
   Android Keystore; iOS implements it with Keychain using a
   `kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly` generic-password item.
   The iOS app subclasses `CAPBridgeViewController` and registers the local
   plugin instance from `capacitorDidLoad()`, as required by the official
   Capacitor custom-native-code contract. Web continues using session storage.
   Passwords are never persisted.
3. Login device identity is derived from the actual Capacitor platform; the UI
   must not describe an iOS build as Android.
4. M9 file import is deliberately narrow: an authenticated iOS user may select
   one UTF-8 English `.txt` document through the standard system document
   picker and submit it to an additive mobile API. The API reuses the existing
   `ImportService`, fixed English chunk defaults and the current user's selected
   language. It creates no local authoritative library and introduces no new
   tokenizer or import algorithm.
5. The mobile import write uses `client_action_id` and the existing
   `MobileIdempotencyService`; replay returns the first response and a reused key
   with different content is rejected. The UI never automatically retries an
   ambiguous upload.
6. The iOS privacy manifest declares only APIs actually used by the generated
   app and plugins. Store copy, privacy answers, support and account-deletion
   instructions are repository release artifacts, not proof of App Store
   submission.
7. Signing, an iOS simulator/device run, notification delivery, Keychain
   inspection and App Store review are one iOS capability cluster. Source and
   cross-platform verification may proceed independently, but M9 is not Closed
   until each named platform check has real evidence.

## Boundaries

- No iOS-specific backend schema, FSRS path, ReviewLog path or offline database.
- No arbitrary ebook/subtitle/archive import in the mobile MVP.
- No background upload or automatic retry of document imports.
- No Apple credential creation, paid enrollment, signing, upload or submission
  without the separately required external authority and capability.
- Existing Android and Web contracts remain compatible.

## Verification

- Mobile Vitest, TypeScript/Vite production build and dependency audit.
- Targeted mobile import Feature tests for auth/device, English isolation,
  validation, idempotent replay and conflicting reuse.
- Static iOS source guard for platform version, custom ViewController/plugin
  registration, Keychain
  accessibility, privacy manifest, safe-area/document-picker UI and release
  artifacts.
- `npx cap sync ios` and an unsigned Xcode build when macOS/Xcode is available.
- Real iOS simulator/device acceptance for login, reader, lookup, review,
  offline restart/sync, document import, haptics, notification, media playback,
  safe areas and Keychain at-rest evidence.
