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
privilege, contain only review-safe fixture data and must not expire during App
Review. Credentials must be entered in App Store Connect, never committed here.

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
- Contact info / email: the mobile sign-in request sends the account email to the
  selected server; it is linked to the account and used for authentication/app functionality.
- Device ID: the random app-installation UUID plus device/platform/app-version metadata
  are retained by the selected server, linked to the account, for app functionality/security.
- User content: imported learning text and user-created meanings are collected, linked
  to the account, for app functionality.
- Product interaction/review activity: reading progress, review ratings/timing and
  queued actions are collected, linked to the account, for app functionality/scheduling.
- Search history: dictionary lookup terms can be retained in the standard Apache
  combined access log; disclose as linked, not tracking, for app functionality/security.
- Other diagnostic data: ordinary request/client-IP access-log metadata can be retained
  by the standard deployment; disclose as linked, not tracking, for app functionality/security.
- Name and User ID are not separate device-origin collected data types in the current
  mobile flow: bootstrap returns the existing server profile to the app, but the app
  does not upload those profile fields. Custom-server log retention can vary.

The current `PrivacyInfo.xcprivacy` / App Store privacy-answer candidate therefore uses
this exact collected-data set: Email Address, Device ID, Other User Content, Product
Interaction, Search History, and Other Diagnostic Data. All six are declared as linked
to the user, not used for tracking, and used only for App Functionality. Any future
change to authentication fields, dictionary transport, access-log retention, analytics,
or diagnostics requires this classification to be reviewed again.

The `PrivacyInfo.xcprivacy` required-reason entry is
`NSPrivacyAccessedAPICategoryUserDefaults / CA92.1`, matching the official
Capacitor Preferences guidance. It declares no tracking domains.

## Export-compliance repository classification

The current iOS bundle sets `ITSAppUsesNonExemptEncryption = NO`. The repository-side
review found no proprietary or third-party encryption implementation in the iOS app:
LinguaCafe uses Apple Keychain through `Security.framework`, system HTTPS/WebView
networking, Web Crypto SHA-256 for integrity checks, and Capacitor's Apple
`CommonCrypto` SHA-256 helper for its app UUID. Under Apple's current guidance these
are Apple operating-system encryption/security facilities or otherwise exempt
cryptographic uses, so no App Store Connect encryption-document upload is expected for this dependency
set. Re-run this determination before release if any native/network/security dependency
changes; the deployment owner remains responsible for the final export-compliance
answers and any jurisdiction-specific reporting requirement.

## Repository-side privacy validation evidence

The privacy/export-compliance candidate is now executable evidence rather than source-only prose:

- run `33359184066` on Xcode 26.6 passed the compiled `App.app/Info.plist` check for `ITSAppUsesNonExemptEncryption = NO` and the serial iPhone + 13-inch-iPad rendered smoke;
- run `33361617463` passed the first compiled-bundle six-category Privacy Manifest check plus the same rendered device smoke and cleanup;
- final run `33362427450` passed the stricter compiled-bundle check for the exact six collected-data types, linked/non-tracking/App-Functionality semantics, `UserDefaults / CA92.1`, empty tracking domains and `ITSAppUsesNonExemptEncryption = NO`, then passed iPhone + 13-inch-iPad rendered smoke and shutdown;
- the carrier commits used only to dispatch branch workflows are excluded from Goal history; the accepted privacy net change is integrated in Goal commit `330caf569d63199047d2f0ef54573e7c47c6795e`.

These checks establish the repository-side candidate. The deployment owner still has to answer App Store Connect against the actual production server/partners at submission time.

## Age-rating and content-rights factual boundary

Repository evidence can pre-answer only capability facts, not the final rating or legal declaration:

- the current mobile client has no advertising SDK, chat/messaging or social feed, gambling/loot-box flow, unrestricted Web browser, camera/microphone/contact/location/advertising-ID access, or public user-to-user content publishing surface;
- LinguaCafe displays English learning material supplied by the server selected for the release. Therefore content-frequency questions such as profanity, violence, medical or sexual content must be answered from the actual release server/content inventory; do not infer `None` solely from client source;
- H-08 already accepted the supported public package/content-rights boundary, including SPDX/REUSE-style notices, bundled third-party license texts and exclusion of unresolved-provenance flag PNGs from supported Docker/`git archive` releases. That evidence supports the packaged-app rights review, but the App Store Content Rights declaration must additionally account for material served by the actual release server and the territories where the app is distributed;
- App Store Connect must calculate the final age rating from its current questionnaire. The repository does not hard-code or claim a numeric/label age rating.

## Required external values and evidence before submission

- public privacy-policy HTTPS URL plus the final in-app link to that exact policy;
- public support HTTPS URL with real deployment-owner contact information;
- publisher-owned App Store app-record values that cannot be inferred from source: SKU, copyright owner/year, availability/territories and any region-specific declarations that apply;
- App Review contact name/email/international-format phone plus a non-expiring least-privilege review account and reachable review server;
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
