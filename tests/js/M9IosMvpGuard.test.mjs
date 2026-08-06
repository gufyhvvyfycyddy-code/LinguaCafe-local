import assert from 'node:assert/strict';
import fs from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (...parts) => fs.readFileSync(join(root, ...parts), 'utf8');

const pkg = JSON.parse(read('mobile', 'package.json'));
const storage = read('mobile', 'src', 'storage.ts');
const api = read('mobile', 'src', 'api.ts');
const ui = read('mobile', 'src', 'ui.ts');
const styles = read('mobile', 'src', 'styles.css');
const swift = read('mobile', 'ios', 'App', 'App', 'SecureTokenPlugin.swift');
const viewController = read('mobile', 'ios', 'App', 'App', 'MyViewController.swift');
const storyboard = read('mobile', 'ios', 'App', 'App', 'Base.lproj', 'Main.storyboard');
const privacy = read('mobile', 'ios', 'App', 'App', 'PrivacyInfo.xcprivacy');
const project = read('mobile', 'ios', 'App', 'App.xcodeproj', 'project.pbxproj');
const iosPackage = read('mobile', 'ios', 'App', 'CapApp-SPM', 'Package.swift');
const route = read('routes', 'api.php');
const controller = read('app', 'Http', 'Controllers', 'Mobile', 'MobileTextImportController.php');
const apiContract = read('docs', 'plans', 'mobile-api-v1-contract.md');
const plan = read('docs', 'plans', 'm9-ios-mvp-release-plan.md');
const adr = read('docs', 'adr', 'ADR-0054-m9-ios-mvp-and-release-readiness.md');
const devicePlaybook = read('docs', 'testing', 'm9-ios-device-and-store-acceptance-playbook.md');
const storeMaterials = read('docs', 'release', 'm9-ios-app-store-materials.md');
const privacyNotice = read('docs', 'release', 'mobile-privacy-and-data-deletion.md');

assert.equal(pkg.dependencies['@capacitor/core'], '8.4.2');
assert.equal(pkg.dependencies['@capacitor/ios'], '8.4.2');
assert.match(pkg.scripts['cap:sync:ios'], /cap sync ios/);
for (const dependency of ['Capacitor', 'CapacitorHaptics', 'CapacitorLocalNotifications', 'CapacitorPreferences']) {
  assert.match(iosPackage, new RegExp(dependency));
}

assert.match(storage, /platform === 'android' \|\| platform === 'ios'/);
assert.match(swift, /kSecClassGenericPassword/);
assert.match(swift, /kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly/);
assert.doesNotMatch(swift, /UserDefaults|print\(/);
assert.match(project, /SecureTokenPlugin\.swift in Sources/);
assert.match(project, /MyViewController\.swift in Sources/);
assert.match(viewController, /class MyViewController: CAPBridgeViewController/);
assert.match(viewController, /capacitorDidLoad\(\)/);
assert.match(viewController, /bridge\?\.registerPluginInstance\(SecureTokenPlugin\(\)\)/);
assert.match(storyboard, /customClass="MyViewController"/);
assert.match(storyboard, /customModule="App"/);
assert.match(project, /PrivacyInfo\.xcprivacy in Resources/);
assert.match(privacy, /NSPrivacyAccessedAPICategoryUserDefaults/);
assert.match(privacy, /CA92\.1/);
for (const dataType of ['Name', 'EmailAddress', 'UserID', 'DeviceID', 'OtherUserContent', 'ProductInteraction']) {
  assert.match(privacy, new RegExp(`NSPrivacyCollectedDataType${dataType}`));
}
assert.match(privacy, /<key>NSPrivacyTracking<\/key>\s*<false\/>/);

assert.match(api, /platform: 'android' \| 'ios' \| 'web'/);
assert.doesNotMatch(api, /\.\.\.payload, platform: 'android'/);
assert.match(ui, /type="file" accept="\.txt,text\/plain"/);
assert.match(ui, /crypto\.subtle\.digest\('SHA-256'/);
assert.match(ui, /pendingTextImport/);
assert.match(ui, /offlineRepository\?\.clear/);
assert.match(ui, /mediaCache\.clear/);
assert.match(styles, /safe-area-inset-top/);
assert.match(styles, /safe-area-inset-bottom/);

assert.match(route, /\/imports\/text/);
assert.match(controller, /MobileIdempotencyService/);
assert.match(controller, /library\.text_import/);
assert.match(controller, /selected_language !== 'english'/);
assert.match(controller, /'max:200000'/);
assert.match(controller, /ImportService/);
assert.match(apiContract, /POST \/imports\/text/);
assert.match(apiContract, /library\.text_import/);
assert.match(apiContract, /never retries an ambiguous upload\s+automatically/);

for (const source of [plan, adr]) {
  assert.match(source, /Accepted under current goal authorization/);
  assert.match(source, /Keychain/);
  assert.match(source, /\.txt/);
  assert.match(source, /真实|Real iOS|real iOS/);
}
assert.match(storeMaterials, /Status: release candidate; not submitted/);
assert.match(storeMaterials, /Tracking: No/);
assert.match(storeMaterials, /Required external values and evidence before submission/);
assert.match(privacyNotice, /does not track users across apps or websites/);
assert.match(privacyNotice, /server-data\s+deletion/);
assert.match(privacyNotice, /does not offer account creation/);
assert.match(privacyNotice, /future mobile release adds account creation/);
assert.match(devicePlaybook, /CODE_SIGNING_ALLOWED=NO/);
assert.match(devicePlaybook, /testing environment and dedicated testing database/);
assert.match(devicePlaybook, /Never print or screenshot the bearer token itself/);
assert.match(devicePlaybook, /Only after actual App Store Connect review/);

console.log('M9 iOS MVP source and release guard passed.');
