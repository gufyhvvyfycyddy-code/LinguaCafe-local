# H-09 Android / Google Play release-preparation materials

Verified: 2026-08-29

This document prepares the current LinguaCafe Android client for a future Google Play release. It is not evidence of a Play Console submission and must not be used to claim that the app is already published or store-approved.

## 1. Current package identity

- Application ID: `com.linguacafe.mobile`
- Version code: `1`
- Version name: `1.0`
- Minimum Android API: 24
- Compile SDK: 36
- Target SDK: 36
- Capacitor Android: 8.4.2
- Android Gradle Plugin: 8.13.0
- Gradle: 8.14.3
- Local release-preparation JDK: Microsoft OpenJDK 21 LTS

Google Play's current target-API policy says that new mobile apps and app updates submitted from 2026-08-31 must target Android 16 / API level 36. The current LinguaCafe project already meets that source/build requirement.

Current source:

- https://support.google.com/googleplay/android-developer/answer/11926878
- https://developer.android.com/google/play/requirements/target-sdk

## 2. Android App Bundle and signing

Google Play requires new apps to use Android App Bundles. The release command is:

`cd mobile && npm run android:release`

The Gradle release path is fail-closed. It requires all four values to be provided outside the repository:

- `LINGUACAFE_ANDROID_KEYSTORE_PATH`
- `LINGUACAFE_ANDROID_KEYSTORE_PASSWORD`
- `LINGUACAFE_ANDROID_KEY_ALIAS`
- `LINGUACAFE_ANDROID_KEY_PASSWORD`

The keystore path must point to an existing file. No keystore, password, alias, certificate, or private key belongs in Git.

For a real Play release, create a dedicated long-lived **upload key**, protect it outside the repository, and register its certificate with Play App Signing. Do not reuse H-09's temporary test key. Google currently requires the upload key used for an app bundle to be RSA 2048 bits or stronger; Google Play keeps the separate app-signing key used for final APK delivery.

Current source:

- https://support.google.com/googleplay/android-developer/answer/9842756
- https://support.google.com/googleplay/android-developer/answer/9844279

Every future update must increment `versionCode`; an update must keep the same package identity and accepted signing relationship.

## 3. 16 KB page-size readiness

The current source tree contains no first-party Android `.so` library. The H-09 release AAB must also be inspected after every dependency change. If no `lib/**/*.so` entry exists, there is no native ELF alignment to repair in that artifact.

Google's current Android guidance makes 16 KB page-size support a Google Play release requirement for affected Android 15+ / 64-bit native-code apps, with the current enforcement date documented as 2027-02-01. H-09 records the check now so a later native dependency cannot silently create a future release blocker.

Current source:

- https://developer.android.com/guide/practices/page-sizes

## 4. Privacy policy requirement

Google Play currently requires every app to provide a privacy policy in Play Console and a privacy-policy link or text inside the app. The store URL must be public, active, non-editable, not a PDF, not geographically blocked, and must identify the same app/developer entity as the store listing.

The current in-app privacy text lives in `mobile/src/privacy.ts`. The fuller source text is `docs/release/mobile-privacy-and-data-deletion.md`.

Before actual submission, the deployment/store owner must still provide two external facts that cannot be invented in source control:

1. the final public HTTPS privacy-policy URL;
2. the real developer/privacy contact mechanism matching the Google Play listing entity.

Current source:

- https://support.google.com/googleplay/android-developer/answer/17517561
- https://support.google.com/googleplay/android-developer/answer/9859455

## 5. Data Safety working matrix

This is an engineering fact matrix for the Play Console questionnaire. It must be reconciled with the **actual public server deployment and enabled external processors immediately before submission**. Do not paste it blindly into Play Console if the deployment has changed.

### App-side facts

The current Android client:

- has no advertising SDK;
- does not request camera, microphone, contacts, location, advertising ID, or photo-library access;
- connects to a user-selected LinguaCafe server;
- uses account email/name for the authenticated learning account;
- uses a random installation/device ID and app version for device/session ownership;
- reads learning materials, WordSense data, review state and reading progress from the selected server;
- sends deliberate ratings, reading interactions, WordSense changes and queued offline actions to that server;
- stores offline article/review packages, pending operations and cached media on device;
- protects the native session token with Android Keystore; the password is not stored;
- schedules an optional local review reminder.

### Likely Play categories that require final confirmation

The final Data Safety form should explicitly review at least:

- Personal info: name and email address;
- Device or other IDs: random installation/device identifier;
- App activity: learning/review interactions and progress;
- User-generated content / other user content: imported learning materials and user-authored meanings where the selected server exposes them to the mobile workflow;
- Files/media only to the extent the released Android workflow actually uploads or processes them;
- Diagnostics/security logs only to the extent the public server deployment collects them.

These data are linked to the signed-in account when necessary for learning/sync isolation. They are used for app functionality, account/device security, synchronization, backup/recovery semantics and ordinary service diagnostics. The current mobile app does not sell the data or use it for third-party advertising.

### External processors

The mobile client talks to the user-selected LinguaCafe server. A public deployment may separately enable AI, translation, dictionary, storage, email, logging, hosting or other providers. Before Play submission, the store owner must inventory the **actual deployed processors** and decide which transfers count as collection/sharing under Google Play's definitions. H-09 does not claim that a configurable self-hosted deployment has one universal processor list.

Current source:

- https://support.google.com/googleplay/android-developer/answer/10787469
- https://support.google.com/googleplay/android-developer/answer/10144311

## 6. Account deletion

The Android client does not create accounts and does not link to account creation. It signs in to an existing LinguaCafe server account.

Google Play's in-app account-deletion requirement applies when an app allows users to create an account from within the app. If mobile account creation is ever added, the Android client must also add a readily discoverable in-app account-deletion initiation path and a corresponding external web resource.

The current server already provides password-confirmed permanent account deletion from its Web account settings. Mobile sign-out remains deliberately narrower: it revokes the device and clears local credentials/cache rather than silently deleting the server account.

Current source:

- https://support.google.com/googleplay/android-developer/answer/13327111
- https://support.google.com/googleplay/android-developer/answer/10144311

## 7. Current merged Android permissions

The current release manifest merges these ordinary permissions/capabilities:

- `android.permission.INTERNET`
- `android.permission.VIBRATE`
- `android.permission.RECEIVE_BOOT_COMPLETED`
- `android.permission.WAKE_LOCK`
- `android.permission.POST_NOTIFICATIONS`

`POST_NOTIFICATIONS` comes from the official Capacitor Local Notifications plugin and the app uses its runtime permission API. The release manifest keeps `android:usesCleartextTraffic="false"` and `android:allowBackup="false"`. Debug-only local acceptance may use the separate debug manifest cleartext override.

## 8. Store-console work intentionally not performed by H-09

H-09 does not automatically:

- create or log into a Google Play Console developer account;
- accept Play App Signing terms;
- register a real production upload-key certificate;
- upload an AAB;
- publish a privacy URL on an external domain;
- fill Data Safety, content-rating, audience, ads or other Play Console declarations;
- create screenshots/store graphics from an unverified build;
- submit to internal, closed, open, production or any other Play track.

Those are external release actions. H-09 prepares and verifies the local build/signing/package/privacy/device path so the final operator can perform those steps deliberately.
