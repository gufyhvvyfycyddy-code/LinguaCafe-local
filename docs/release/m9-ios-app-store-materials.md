# M9 iOS App Store materials and review checklist

Status: release candidate; not submitted

## Listing draft

- App name: `LinguaCafe`
- Subtitle: `Read, look up, review English`
- Version candidate: `1.0`
- Build candidate: `1`
- Primary category: Education
- Secondary category: Reference
- Primary language: publisher-owned App Store Connect value; must be chosen before submission
- Version release setting: publisher-owned App Store Connect value; choose one before
  submission: `Manual`, `Automatic`, or `Automatic, no earlier than`
- Promotional text: `Continue your LinguaCafe English reading and sense-based
  reviews on iPhone and iPad, with short-term offline access and local reminders.`
- Keywords: `English,reading,vocabulary,FSRS,review,dictionary,offline`

Current App Store metadata guardrails, rechecked against Apple's 2026-09-01
requirements:

- app name and subtitle: at most 30 characters each;
- promotional text: at most 170 characters;
- keywords: at most 100 UTF-8 bytes;
- Version Release Settings are selected per app version in App Store Connect and
  control when an approved version becomes public; the repository records the three
  current Apple choices but does not select one for the publisher;
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
the iOS app does not create an account. Login is required because the app's core
features are the user's server-backed library, WordSense/review state and sync;
the app authenticates directly against that selected LinguaCafe server with the
existing email/password account and does not integrate a third-party or social
login service. Permanent server-account deletion remains available from that
server's Web account settings. The review account must be least privilege,
contain only review-safe fixture data and must not expire during App Review.
Credentials must be entered in App Store Connect, never committed here.

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
- run `33366809125` passed a real Xcode 26.6 unsigned Release `.xcarchive` build and verified the archived app bundle identity/release metadata plus the app-level privacy/export declarations;
- run `33383576886` passed the stricter unsigned archive gate that also requires valid `PrivacyInfo.xcprivacy` resources inside the archived `Capacitor.framework` and `Cordova.framework`; the app-level manifest is not treated as a substitute for those SDK manifests;
- run `33386931423` re-ran the Release archive gate and then captured App Store-sized, non-alpha JPEG evidence from the rendered app: 6.9-inch iPhone `1320x2868` and 13-inch iPad `2064x2752`;
- the carrier commits used only to dispatch branch workflows are excluded from Goal history; the accepted privacy net change is integrated in Goal commit `330caf569d63199047d2f0ef54573e7c47c6795e`, and the later archive/layout guards are present in the current Goal tree.

These checks establish the repository-side candidate. The deployment owner still has to answer App Store Connect against the actual production server/partners at submission time.

A 2026-08-31 anonymous HTTPS probe also confirmed that the public GitHub repository can serve the commit-pinned privacy-policy Markdown page without GitHub authentication (`HTTP 200`). That proves a technically public candidate exists; it does **not** select the final App Store Privacy Policy URL. The final URL must remain stable for the publisher's release process, represent the actual publishing entity/data practices, and be linked from the submitted app/store metadata. Enabling GitHub Pages or another public deployment remains an explicit deployment action, not an automatic repository-side step.

## Business-model factual boundary

The current iOS/mobile client contains no StoreKit integration, in-app purchase or subscription UI, payment SDK, checkout flow, or call to action that sends the user to an external purchase mechanism. This is a binary/repository fact, not a claim that the deployment or server is free. Before submission, the publisher must confirm the actual business model against the storefronts where the app will be distributed. If a release sells or unlocks digital content/features, adds an external-purchase call to action, or introduces StoreKit/payment code, re-review App Review Guideline 3.1 and update the metadata/review notes before submission.

## Age-rating and content-rights factual boundary

Repository evidence can pre-answer only capability facts, not the final rating or legal declaration:

- current binary/source candidates for Apple's in-app controls and capability questions are: Parental Controls: `No`; Age Assurance: `No`; Unrestricted Web Access: `No`; User-Generated Content: `No`; Messaging and Chat: `No`; Advertising: `No`;
- the `User-Generated Content: No` candidate follows Apple's broad-distribution definition: LinguaCafe can import private per-account learning text and meanings, but the mobile product does not broadly distribute that material to other users. `Unrestricted Web Access: No` likewise reflects that entering a LinguaCafe server URL is not a general-purpose browser surface;
- the current mobile client has no advertising SDK, chat/messaging or social feed, gambling/loot-box flow, unrestricted Web browser, camera/microphone/contact/location/advertising-ID access, or public user-to-user content publishing surface;
- Social Media capability: `No`. Apple defines this capability around redistributing, amplifying, or interacting with user-generated content through a social feed or similar discovery surface. LinguaCafe's private per-account learning text and meanings are not redistributed through such a surface. As of September 2026, this response is required when submitting new apps or updates. Re-review this answer before submission if the mobile product adds a social feed/discovery surface, reposting, likes, comments, reactions, or another public UGC amplification path;
- LinguaCafe displays English learning material supplied by the server selected for the release. Therefore content-frequency questions such as profanity, violence, medical or sexual content must be answered from the actual release server/content inventory; do not infer `None` solely from client source;
- the Regulated Medical Device declaration is conditional for EU/EEA, UK, or U.S. availability: Apple requires it if the primary or secondary category becomes Health & Fitness or Medical, or if the age-rating answer for Medical or Treatment Information is `frequent`. The current category candidate is Education / Reference, so the category trigger is absent; the content-frequency trigger still depends on the actual release server inventory. The publisher must confirm the final regulated-medical-device declaration in App Store Connect at submission time if either trigger applies; do not manufacture that declaration from source alone;
- H-08 already accepted the supported public package/content-rights boundary, including SPDX/REUSE-style notices, bundled third-party license texts and exclusion of unresolved-provenance flag PNGs from supported Docker/`git archive` releases. That evidence supports the packaged-app rights review, but the App Store Content Rights declaration must additionally account for material served by the actual release server and the territories where the app is distributed;
- App Store Connect must calculate the final age rating from its current questionnaire. The repository does not hard-code or claim a numeric/label age rating.

## Required external values and evidence before submission

- public privacy-policy HTTPS URL plus the final in-app link to that exact policy;
- public support HTTPS URL with real deployment-owner contact information;
- publisher-owned App Store app-record values that cannot be inferred from source: Primary Language, SKU, copyright owner/year, price/tax category, availability/territories, Digital Services Act trader status, and any region-specific declarations that apply (including conditional South Korea, China mainland, or Vietnam fields);
- final age-rating questionnaire confirmation against the actual release server/content inventory, including the September 2026 Social Media capability response; the repository candidate is `No` unless the release product adds a social-media surface described above;
- conditional Regulated Medical Device declaration if the final categories or Medical or Treatment Information frequency trigger Apple's EU/EEA, UK, or U.S. requirement;
- publisher-owned Version Release Setting for this app version: `Manual`,
  `Automatic`, or `Automatic, no earlier than`;
- App Review contact name/email/international-format phone plus a non-expiring least-privilege review account and reachable review server;
- confirmation that the submitted iOS build still has no account-creation flow;
  if one is added, in-app account-deletion initiation is also required;
- Apple Developer team/bundle ownership and signing profiles;
- real Apple-team signing, a signed Release archive plus Organizer `Validate App`, and a signed TestFlight build; the repository-side unsigned archive structure is already proven by runs `33366809125`, `33383576886`, and `33386931423`;
- final localized App Store screenshot selection/upload. The repository-side capture gate already proves accepted 6.9-inch iPhone and 13-inch iPad pixel classes, JPEG format and no alpha, but it does not choose marketing screenshots or upload them;
- real iOS acceptance report for Keychain, file picker/import, notifications,
  haptics, safe areas, offline restart/sync and audio;
- reviewer fixture server/account and deletion/cleanup record;
- App Store Connect privacy questionnaire confirmation and review result.

None of these external checks is inferred from source code, Android evidence or
this document.
