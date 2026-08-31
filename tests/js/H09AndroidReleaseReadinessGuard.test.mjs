import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = relative => readFileSync(path.join(root, relative), 'utf8');

function filesUnder(directory) {
  const absolute = path.join(root, directory);
  return readdirSync(absolute, { withFileTypes: true }).flatMap(entry => {
    const relative = path.posix.join(directory.replaceAll('\\', '/'), entry.name);
    return entry.isDirectory() ? filesUnder(relative) : [relative];
  });
}

test('H-09 Android release configuration keeps the current Play boundary', () => {
  const variables = read('mobile/android/variables.gradle');
  const appGradle = read('mobile/android/app/build.gradle');
  const packageJson = JSON.parse(read('mobile/package.json'));
  const manifest = read('mobile/android/app/src/main/AndroidManifest.xml');
  const debugManifest = read('mobile/android/app/src/debug/AndroidManifest.xml');
  const ui = read('mobile/src/ui.ts');
  const privacy = read('mobile/src/privacy.ts');
  const privacyDoc = read('docs/release/mobile-privacy-and-data-deletion.md');

  assert.match(variables, /minSdkVersion\s*=\s*24/);
  assert.match(variables, /compileSdkVersion\s*=\s*36/);
  assert.match(variables, /targetSdkVersion\s*=\s*36/);
  assert.match(appGradle, /applicationId\s+"com\.linguacafe\.mobile"/);
  assert.match(appGradle, /versionCode\s+1/);
  assert.match(appGradle, /versionName\s+"1\.0"/);

  for (const variable of [
    'LINGUACAFE_ANDROID_KEYSTORE_PATH',
    'LINGUACAFE_ANDROID_KEYSTORE_PASSWORD',
    'LINGUACAFE_ANDROID_KEY_ALIAS',
    'LINGUACAFE_ANDROID_KEY_PASSWORD',
  ]) {
    assert.match(appGradle, new RegExp(variable));
  }
  assert.match(appGradle, /verifyReleaseSigning/);
  assert.match(appGradle, /bundleRelease/);
  assert.equal(packageJson.scripts['android:release'], 'npm run cap:sync && cd android && gradlew.bat bundleRelease');

  assert.match(manifest, /android:allowBackup="false"/);
  assert.match(manifest, /android:usesCleartextTraffic="false"/);
  assert.match(debugManifest, /android:usesCleartextTraffic="true"/);
  assert.equal(filesUnder('mobile/android/app/src/main').filter(file => file.endsWith('.so')).length, 0);

  assert.match(ui, /mobilePrivacyPolicyHtml/);
  for (const text of [
    '隐私权政策',
    '不包含广告 SDK',
    'Android Keystore',
    '不出售用户数据',
    '撤销此设备并退出',
  ]) {
    assert.match(privacy, new RegExp(text));
  }
  assert.match(privacyDoc, /2026-08-31 Android\/iOS mobile privacy policy publication source/);
  assert.match(privacyDoc, /not evidence that a public\s+Privacy Policy URL has already been deployed/);
  assert.match(privacyDoc, /must publish this policy at a stable public HTTPS URL/);
  assert.match(privacyDoc, /Privacy requests\s+about the distributed mobile app[\s\S]*store Support URL/);
  assert.match(privacyDoc, /does not directly integrate an advertising network, analytics SDK,[\s\S]*social-login provider/);
  assert.doesNotMatch(privacyDoc, /Version: .*public Android\/iOS mobile privacy policy/);
});
