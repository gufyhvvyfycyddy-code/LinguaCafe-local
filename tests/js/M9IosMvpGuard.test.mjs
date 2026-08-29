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
const acceptance = read('docs', 'testing', 'm9-ios-mvp-release-acceptance-2026-08-01.md');
const devicePlaybook = read('docs', 'testing', 'm9-ios-device-and-store-acceptance-playbook.md');
const iosGitignore = read('mobile', 'ios', '.gitignore');
const storeMaterials = read('docs', 'release', 'm9-ios-app-store-materials.md');
const privacyNotice = read('docs', 'release', 'mobile-privacy-and-data-deletion.md');
const generatedWebIntegrity = read('mobile', 'scripts', 'ios-generated-web-integrity.mjs');
const xcodeCapabilityWorkflow = read('.github', 'workflows', 'ios-xcode-capability-probe.yml');

assert.equal(pkg.dependencies['@capacitor/core'], '8.4.2');
assert.equal(pkg.dependencies['@capacitor/ios'], '8.4.2');
assert.match(pkg.scripts['cap:sync:ios'], /cap sync ios/);
assert.match(pkg.scripts['ios:generated-web-integrity'], /ios-generated-web-integrity\.mjs/);
for (const dependency of ['Capacitor', 'CapacitorHaptics', 'CapacitorLocalNotifications', 'CapacitorPreferences']) {
  assert.match(iosPackage, new RegExp(dependency));
}
for (const plugin of ['haptics', 'local-notifications', 'preferences']) {
  assert.match(iosPackage, new RegExp(`\.\./\.\./\.\./node_modules/@capacitor/${plugin}`));
}
assert.doesNotMatch(iosPackage, /\\node_modules\\/);

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

assert.match(adr, /Accepted under current goal authorization/);
assert.match(plan, /Implementation Accepted \/ iOS capability cluster Not Complete/);
for (const source of [plan, adr]) {
  assert.match(source, /Keychain/);
  assert.match(source, /\.txt/);
  assert.match(source, /真实|Real iOS|real iOS/);
}
assert.match(plan, /22 tracked iOS source\/config files/);
assert.match(plan, /must not be staged/);
assert.match(plan, /post-sync integrity\s+gate/);
assert.match(plan, /zero sourcemaps/);
assert.match(plan, /HTTPS, pagination and\s+local-debug safeguards/);
assert.match(storeMaterials, /Status: release candidate; not submitted/);
assert.match(storeMaterials, /Tracking: No/);
assert.match(storeMaterials, /Required external values and evidence before submission/);
assert.match(privacyNotice, /does not track users across apps or websites/);
assert.match(privacyNotice, /## Server data deletion/);
assert.match(privacyNotice, /do not offer or link to account creation/);
assert.match(privacyNotice, /future mobile release\s+adds or links to mobile account creation/);
assert.match(acceptance, /2026-08-06 repository publication revalidation/);
assert.match(acceptance, /currently stale/);
assert.match(acceptance, /contains four sourcemaps/);
assert.match(acceptance, /must run controlled\s+`cap sync ios`/);
assert.match(acceptance, /does not promote the iOS capability cluster to complete/);
for (const ignoredPath of [
  'App/App/public',
  'DerivedData',
  'xcuserdata',
  '*.mobileprovision',
  '*.p12',
  '*.xcarchive',
  'capacitor-cordova-ios-plugins',
  'App/App/capacitor.config.json',
  'App/App/config.xml',
]) {
  assert.match(iosGitignore, new RegExp(ignoredPath.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
}
assert.match(devicePlaybook, /CODE_SIGNING_ALLOWED=NO/);
assert.match(devicePlaybook, /Post-sync Web asset integrity gate/);
assert.match(devicePlaybook, /referenced JS filenames are identical/);
assert.match(devicePlaybook, /`shasum -a 256` values for index, referenced main JS and referenced CSS are\s+identical/);
assert.match(devicePlaybook, /find ios\/App\/App\/public -name '\*\.map'/);
for (const safeguard of ['正式移动端仅允许 HTTPS', '服务器分页信息无效', '仅用于本地调试']) {
  assert.match(devicePlaybook, new RegExp(safeguard));
}
assert.match(devicePlaybook, /testing environment and dedicated testing database/);
assert.match(devicePlaybook, /Never print or screenshot the bearer token itself/);
assert.match(devicePlaybook, /Only after actual App Store Connect review/);

assert.match(generatedWebIntegrity, /createHash\('sha256'\)/);
assert.match(generatedWebIntegrity, /generated asset set differs/);
assert.match(generatedWebIntegrity, /generated asset hashes differ/);
assert.match(generatedWebIntegrity, /generated iOS public contains sourcemaps/);
for (const safeguard of ['正式移动端仅允许 HTTPS', '服务器分页信息无效', '仅用于本地调试']) {
  assert.match(generatedWebIntegrity, new RegExp(safeguard));
}

assert.match(xcodeCapabilityWorkflow, /workflow_dispatch:/);
assert.match(xcodeCapabilityWorkflow, /workflow_call:/);
assert.doesNotMatch(xcodeCapabilityWorkflow, /^\s*(?:push|pull_request|schedule):/m);
assert.match(xcodeCapabilityWorkflow, /permissions:\s*\n\s*contents: read/);
assert.match(xcodeCapabilityWorkflow, /runs-on: macos-26/);
assert.match(xcodeCapabilityWorkflow, /Xcode_26\.6\.app/);
assert.match(xcodeCapabilityWorkflow, /npm ci/);
assert.match(xcodeCapabilityWorkflow, /npm test/);
assert.match(xcodeCapabilityWorkflow, /npm run cap:sync:ios/);
assert.match(xcodeCapabilityWorkflow, /npm run ios:generated-web-integrity/);
assert.match(xcodeCapabilityWorkflow, /M9IosMvpGuard\.test\.mjs/);
assert.match(xcodeCapabilityWorkflow, /xcodebuild -resolvePackageDependencies/);
assert.match(xcodeCapabilityWorkflow, /xcrun simctl list devices available/);
assert.match(xcodeCapabilityWorkflow, /generic\/platform=iOS Simulator/);
assert.match(xcodeCapabilityWorkflow, /CODE_SIGNING_ALLOWED=NO/);
assert.doesNotMatch(xcodeCapabilityWorkflow, /secrets\.|upload-artifact|TestFlight|xcodebuild\s+archive/);

console.log('M9 iOS MVP source and release guard passed.');
