# M9 iOS App Store materials and review checklist

Status: release candidate; not submitted

## Listing draft

- App name: `LinguaCafe`
- Subtitle: `Read, look up, review English`
- Primary category: Education
- Secondary category: Reference
- Promotional text: `Continue your LinguaCafe English reading and sense-based
  reviews on iPhone, with short-term offline access and local reminders.`
- Keywords: `English,reading,vocabulary,FSRS,review,dictionary,offline`

Current App Store metadata guardrails, rechecked against Apple's 2026-08-31
requirements:

- app name and subtitle: at most 30 characters each;
- promotional text: at most 170 characters;
- keywords: at most 100 UTF-8 bytes;
- Support URL: must resolve to a page with real contact information;
- Privacy Policy URL: required for iOS, with an easy-to-find in-app link to the
  published policy before submission;
- the current Xcode target supports both iPhone and iPad
  (`TARGETED_DEVICE_FAMILY = "1,2"`), so store assets need the required iPhone
  screenshots and required 13-inch iPad screenshots unless that product scope is
  explicitly changed before release;
- GitHub Actions run `33355499203` on Xcode 26.6 revalidated the unsigned app with
  `UIRequiredDeviceCapabilities = arm64`, rendered the same login-shell flow on
  iPhone 17 Pro and `iPad Pro 13-inch (M5)` Simulator, and produced a
  `2064x2752` iPad screenshot. This proves repository-side universal rendering,
  not final App Store screenshot selection or physical-device readiness.

Description:

> Connect to your LinguaCafe server and keep learning wherever you are. Read
> downloaded English articles, look up words with your server's local
> dictionary, create precise word meanings, and review sense cards with FSRS.
> Short-term offline packages keep key reading, reviews, and pronunciation
> audio available during a temporary connection loss; queued ratings sync
> safely when the server returns. Optional local reminders and accessible,
> safe-area-aware controls support a focused daily routine.

## Review notes draft

LinguaCafe requires a reachable compatible server and an existing test account;
the iOS app does not create an account. The review account must be least
privilege, contain only review-safe fixture data and remain valid for the review
window. Credentials must be entered in App Store Connect, never committed here.

Suggested review path:

1. Enter server HTTPS URL and review credentials.
2. Open an article, open a chapter, tap a word and inspect the local dictionary.
3. Open Review, reveal the answer, rate once, then use Undo.
4. In Settings, enable a local reminder and select a small UTF-8 `.txt` file.
5. Put the device offline, reopen a downloaded article/review/audio item, queue
   one rating, restore connectivity and sync.
6. Use “撤销此设备并退出” to remove local account data and revoke the device.

## App privacy answers from the implemented client

- Tracking: No.
- Third-party advertising: No.
- Contact info / email: collected by the selected server, linked to user, app
  functionality and account authentication.
- User ID/device ID: collected, linked to user, app functionality/security.
- User content: collected, linked to user, app functionality.
- Product interaction/review activity: collected, linked to user, app
  functionality and scheduling.
- Diagnostics: deployment-dependent ordinary server logs; disclose if the
  production operator retains them.

The `PrivacyInfo.xcprivacy` required-reason entry is
`NSPrivacyAccessedAPICategoryUserDefaults / CA92.1`, matching the official
Capacitor Preferences guidance. It declares no tracking domains.

## Required external values and evidence before submission

- public privacy-policy HTTPS URL plus the final in-app link to that exact policy;
- public support HTTPS URL with real deployment-owner contact information;
- confirmation that the submitted iOS build still has no account-creation flow;
  if one is added, in-app account-deletion initiation is also required;
- Apple Developer team/bundle ownership and signing profiles;
- Xcode archive validation and signed TestFlight build;
- required iPhone screenshots and, while iPad remains a supported destination,
  required 13-inch iPad screenshots for localized metadata;
- real iOS acceptance report for Keychain, file picker/import, notifications,
  haptics, safe areas, offline restart/sync and audio;
- reviewer fixture server/account and deletion/cleanup record;
- App Store Connect privacy questionnaire confirmation and review result.

None of these external checks is inferred from source code, Android evidence or
this document.
