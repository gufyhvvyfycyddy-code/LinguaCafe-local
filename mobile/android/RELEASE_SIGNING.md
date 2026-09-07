# Android release signing

LinguaCafe release bundles must be signed with a dedicated upload key before they are sent to Google Play.

The repository does not store the keystore, alias, or passwords. Configure the release build with these environment variables (or equivalent Gradle project properties with the same names):

- `LINGUACAFE_ANDROID_KEYSTORE_PATH`
- `LINGUACAFE_ANDROID_STORE_PASSWORD`
- `LINGUACAFE_ANDROID_KEY_ALIAS`
- `LINGUACAFE_ANDROID_KEY_PASSWORD`

Build from `mobile/` with:

`npm run android:release`

The Gradle release gate intentionally rejects `bundleRelease` and `assembleRelease` when any signing value is missing. Debug builds remain unsigned by this release configuration and continue to use the normal debug workflow.

For Google Play, use a dedicated upload key and Play App Signing. Keep the upload key outside the repository and maintain secure backups. Never commit the keystore or passwords.

After building, verify the AAB signer certificate before upload. Play Console testing, app-signing enrollment, and production rollout evidence remain separate release gates.
