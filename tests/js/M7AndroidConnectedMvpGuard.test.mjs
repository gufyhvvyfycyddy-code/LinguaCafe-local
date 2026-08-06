import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = relative => readFileSync(new URL(`../../${relative}`, import.meta.url), 'utf8');

const packageJson = JSON.parse(read('mobile/package.json'));
const api = read('mobile/src/api.ts');
const storage = read('mobile/src/storage.ts');
const ui = read('mobile/src/ui.ts');
const manifest = read('mobile/android/app/src/main/AndroidManifest.xml');
const debugManifest = read('mobile/android/app/src/debug/AndroidManifest.xml');
const secureToken = read(
  'mobile/android/app/src/main/java/com/linguacafe/mobile/SecureTokenPlugin.java',
);
const mainActivity = read(
  'mobile/android/app/src/main/java/com/linguacafe/mobile/MainActivity.java',
);
const routes = read('routes/api.php');
const bootstrap = read('app/Http/Controllers/Mobile/MobileBootstrapController.php');
const adr = read('docs/adr/ADR-0044-m7-android-connected-mvp.md');
const plan = read('docs/plans/m7-android-connected-mvp-plan.md');

for (const dependency of [
  '@capacitor/core',
  '@capacitor/android',
  '@capacitor/preferences',
  '@capacitor/local-notifications',
  '@capacitor/haptics',
]) {
  assert.ok(packageJson.dependencies[dependency], `${dependency} must use the official package`);
}

assert.match(api, /\/api\/v1\/mobile/);
assert.match(api, /client_action_id: actionId/);
assert.match(api, /\/article-packages/);
assert.match(api, /\/review-packages\/short-term/);
assert.match(api, /\/operations\/\$\{operationId\}\/undo/);
assert.doesNotMatch(api, /fsrs_(stability|difficulty)\s*=/);
assert.doesNotMatch(`${api}\n${storage}\n${ui}`, /indexedDB|sqlite|queueMicrotask\([^)]*rating/i);

assert.match(storage, /registerPlugin<SecureTokenPlugin>\('SecureToken'\)/);
assert.match(storage, /LocalNotifications\.schedule/);
assert.match(ui, /Haptics\.impact\(\{ style: ImpactStyle\.Medium \}\)/);
assert.match(secureToken, /AndroidKeyStore/);
assert.match(secureToken, /AES\/GCM\/NoPadding/);
assert.match(secureToken, /MODE_PRIVATE/);
assert.match(mainActivity, /registerPlugin\(SecureTokenPlugin\.class\)/);
assert.match(manifest, /android:allowBackup="false"/);
assert.match(manifest, /android:usesCleartextTraffic="false"/);
assert.match(debugManifest, /android:usesCleartextTraffic="true"/);

for (const route of ['dictionary/lookup', 'word-senses', 'summary']) {
  assert.match(routes, new RegExp(route));
}
for (const capability of [
  'connected_reader',
  'local_dictionary_lookup',
  'manual_word_sense_creation',
  'daily_summary',
]) {
  assert.match(bootstrap, new RegExp(capability));
}

for (const text of ['安全登录', '创建学习词义', '显示答案', '撤回上一次评分', '本地复习提醒']) {
  assert.match(ui, new RegExp(text));
}
assert.match(ui, /设备令牌仍安全保存在本机/);
assert.match(ui, /startError\.status === 401/);
assert.match(adr, /connected-only/i);
assert.match(plan, /M8 offline queue/i);

console.log('M7 Android connected MVP guard passed.');
