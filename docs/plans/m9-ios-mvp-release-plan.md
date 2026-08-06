# M9 — iOS MVP and release readiness plan

Status: Accepted under current goal authorization

## Goal and non-goals

Deliver an iOS project for the existing shared mobile client, close the iOS
security and document-import seams, and prepare reviewable privacy/store
materials. Do not change FSRS, review lifecycle, Android/Web behavior, full web
library management, or server authority.

## Responsibility and seams

- Shared client: platform-neutral labels, secure-token routing, one explicit
  `.txt` picker/import flow, and the existing reader/reviewer/offline/media UI.
- iOS shell: Capacitor bridge, a custom `CAPBridgeViewController` that registers
  the Keychain `SecureToken` plugin, notification permissions, privacy manifest
  and safe-area-compatible WebView.
- Server: one authenticated/device-bound/idempotent text-import endpoint that
  delegates to existing `ImportService` with English-only fixed defaults.
- Release: factual privacy, support, deletion and App Store checklist documents.

Data flow:

`UIDocumentPicker/WKWebView file input → UTF-8 size/type validation → mobile API
with Bearer token + device id + client_action_id → MobileIdempotencyService →
ImportService → existing Book/Chapter/ProcessChapter flow`.

Compatibility boundary: all existing mobile endpoints and payloads are
unchanged; the new import endpoint is additive. Web import remains unchanged.

## Allowlist

- `mobile/package.json`, `mobile/package-lock.json`, `mobile/capacitor.config.ts`
- `mobile/src/api.ts`, `mobile/src/api.test.ts`, `mobile/src/storage.ts`,
  `mobile/src/storage.test.ts`, `mobile/src/ui.ts`, `mobile/src/styles.css`
- generated `mobile/ios/**`
- `routes/api.php`
- one M9 mobile import Controller and its targeted Feature test
- `docs/plans/mobile-api-v1-contract.md` for the additive import contract
- M9 ADR, plan, acceptance/release documents, documentation index/current state,
  roadmap/handoff/master-plan status, and focused JS guards

All other backend models, migrations, services, reader Web components, FSRS and
review code are forbidden. If existing `ImportService` cannot safely provide
the frozen seam, pause only the import slice and keep platform/release work
moving.

## Verification and acceptance

1. Mobile unit tests and audit pass.
2. Mobile import Feature tests prove authentication/device binding, validation,
   user/language isolation, exactly-once replay and conflict rejection.
3. Production Web build and iOS Capacitor sync pass where the host supports it.
4. Static guard proves Keychain accessibility, iOS plugin registration, privacy
   manifest, safe-area CSS, document input and release artifacts.
5. Real iOS simulator/device evidence covers the named flows in ADR-0054.
6. Signing/archive/store submission is reported only from actual Apple tooling;
   documents or static inspection cannot substitute for it.
