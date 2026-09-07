import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname.replace(/^\/(.:)/, '$1')), '../..');
const gradle = fs.readFileSync(path.join(root, 'mobile/android/app/build.gradle'), 'utf8');
const mobilePackage = JSON.parse(fs.readFileSync(path.join(root, 'mobile/package.json'), 'utf8'));
const docs = fs.readFileSync(path.join(root, 'mobile/android/RELEASE_SIGNING.md'), 'utf8');

for (const name of [
    'LINGUACAFE_ANDROID_KEYSTORE_PATH',
    'LINGUACAFE_ANDROID_STORE_PASSWORD',
    'LINGUACAFE_ANDROID_KEY_ALIAS',
    'LINGUACAFE_ANDROID_KEY_PASSWORD',
]) {
    assert.ok(gradle.includes(name), `${name} must be consumed by the Android release build`);
    assert.ok(docs.includes(name), `${name} must be documented without a value`);
}

assert.match(gradle, /signingConfigs\s*\{/);
assert.match(gradle, /signingConfig signingConfigs\.release/);
assert.match(gradle, /graph\.hasTask\(':app:bundleRelease'\)/);
assert.match(gradle, /graph\.hasTask\(':app:assembleRelease'\)/);
assert.match(gradle, /Release signing is not configured/);
assert.equal(mobilePackage.scripts['android:release'], 'npm run cap:sync && cd android && gradlew.bat bundleRelease');

assert.doesNotMatch(gradle, /storePassword\s+['"][^'"]+['"]/);
assert.doesNotMatch(gradle, /keyPassword\s+['"][^'"]+['"]/);
assert.doesNotMatch(docs, /password\s*=\s*\S+/i);

console.log('Android release signing guard passed.');
