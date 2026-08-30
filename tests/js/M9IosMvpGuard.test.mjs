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
const infoPlist = read('mobile', 'ios', 'App', 'App', 'Info.plist');
const project = read('mobile', 'ios', 'App', 'App.xcodeproj', 'project.pbxproj');
const iosPackage = read('mobile', 'ios', 'App', 'CapApp-SPM', 'Package.swift');
const route = read('routes', 'api.php');
const controller = read('app', 'Http', 'Controllers', 'Mobile', 'MobileTextImportController.php');
const mobileWordSenseController = read('app', 'Http', 'Controllers', 'Mobile', 'MobileWordSenseController.php');
const mobileReadingTargetController = read('app', 'Http', 'Controllers', 'Mobile', 'MobileReadingUnfamiliarTargetController.php');
const readingManualSenseOwner = read('app', 'Services', 'ReadingManualSenseCreationService.php');
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
const readerAcceptanceWorkflow = read('.github', 'workflows', 'ios-reader-simulator-acceptance.yml');
const readerPabServer = read('tests', 'Support', 'start-ios-reader-pab-server.php');
const commandTimeoutHarness = read('tests', 'Support', 'run-command-with-timeout.php');
const readerSmokeCommand = read('app', 'Console', 'Commands', 'PrepareMobileReaderSmokeData.php');

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
assert.match(infoPlist, /<key>NSAppTransportSecurity<\/key>\s*<dict>\s*<key>NSAllowsLocalNetworking<\/key>\s*<true\/>\s*<\/dict>/);
assert.match(infoPlist, /<key>NSLocalNetworkUsageDescription<\/key>\s*<string>[^<]*本地学习服务器[^<]*<\/string>/);
assert.doesNotMatch(infoPlist, /NSAllowsArbitraryLoads(?:InWebContent)?/);

assert.match(api, /platform: 'android' \| 'ios' \| 'web'/);
assert.doesNotMatch(api, /\.\.\.payload, platform: 'android'/);
assert.match(api, /markReadingUnfamiliarTarget/);
assert.match(api, /reading_session_id\?: string/);
assert.match(api, /source_revision\?: string/);
assert.match(api, /occurrence_id\?: string/);
assert.match(ui, /ensureReadingSenseContext/);
assert.match(ui, /markReadingUnfamiliarTarget/);
assert.match(ui, /startReadingSession\(\s*chapterId,\s*session\.reading_session_id/);
assert.match(ui, /target\.candidate_word_senses\.push/);
assert.match(ui, /result\.word_sense\.review_card_id/);
assert.match(ui, /token\.canonical_token_index === null \|\| token\.selection_kind === 'phrase'/);
assert.match(route, /\/chapters\/\{chapter\}\/reading-unfamiliar-targets/);
assert.match(mobileReadingTargetController, /ReadingUnfamiliarTargetService/);
assert.match(mobileWordSenseController, /ReadingManualSenseCreationService/);
assert.match(readingManualSenseOwner, /lockManualSenseCreationContext/);
assert.match(readingManualSenseOwner, /RESOLUTION_NEW_SENSE/);
assert.match(readingManualSenseOwner, /LEARNING_ORIGIN_READING/);
assert.match(readingManualSenseOwner, /bindReadingEvidenceToSense/);
assert.doesNotMatch(readingManualSenseOwner, /ReviewLog/);
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
assert.match(apiContract, /POST \/chapters\/\{chapter\}\/reading-unfamiliar-targets/);
assert.match(apiContract, /reading_session_id \+ source_revision \+ occurrence_id/);
assert.match(apiContract, /SOURCE_READING_OCCURRENCE/);
assert.match(apiContract, /READING_TARGET_STALE_SOURCE/);
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
assert.match(xcodeCapabilityWorkflow, /actions\/checkout@v6/);
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
assert.match(xcodeCapabilityWorkflow, /xcrun simctl boot /);
assert.match(xcodeCapabilityWorkflow, /xcrun simctl bootstatus/);
assert.match(xcodeCapabilityWorkflow, /xcrun simctl install/);
assert.match(xcodeCapabilityWorkflow, /xcrun simctl launch/);
assert.match(xcodeCapabilityWorkflow, /xcrun simctl terminate/);
assert.match(xcodeCapabilityWorkflow, /xcrun simctl shutdown/);
assert.match(xcodeCapabilityWorkflow, /mobile-dev-inc\/Maestro\/releases\/download\/cli-2\.7\.0\/maestro\.zip/);
assert.match(xcodeCapabilityWorkflow, /a4ccab6b604617e7aef6db4f885666056eabe5cfa32befaa3bc994041b8fcbb5/);
assert.match(xcodeCapabilityWorkflow, /shasum -a 256 -c -/);
assert.match(xcodeCapabilityWorkflow, /Run rendered iOS login-shell smoke/);
assert.match(xcodeCapabilityWorkflow, /IOS · CONNECTED MVP/);
assert.match(xcodeCapabilityWorkflow, /设备令牌由系统 Keychain 保护；应用不会保存密码。/);
assert.match(xcodeCapabilityWorkflow, /inputText: "http:\/\/127\.0\.0\.1:8878"/);
assert.match(xcodeCapabilityWorkflow, /仅用于本地调试；Android\/iOS 正式版可能拒绝明文连接；正式使用应配置 HTTPS。/);
assert.ok(xcodeCapabilityWorkflow.indexOf('Start simulator boot') < xcodeCapabilityWorkflow.indexOf('Install mobile dependencies'));
assert.ok(xcodeCapabilityWorkflow.indexOf('Install mobile dependencies') < xcodeCapabilityWorkflow.indexOf('Wait for simulator and launch app'));
assert.doesNotMatch(xcodeCapabilityWorkflow, /secrets\.|upload-artifact|TestFlight|xcodebuild\s+archive|maestro\s+cloud|MAESTRO_CLOUD|API_KEY/i);

assert.match(readerAcceptanceWorkflow, /workflow_dispatch:/);
assert.match(readerAcceptanceWorkflow, /workflow_call:/);
assert.match(readerAcceptanceWorkflow, /runs-on: macos-26/);
assert.match(readerAcceptanceWorkflow, /APP_ENV: testing/);
assert.match(readerAcceptanceWorkflow, /DB_CONNECTION: mysql/);
assert.match(readerAcceptanceWorkflow, /TestingDatabaseHealthTest\.php/);
assert.match(readerAcceptanceWorkflow, /run-pab-r3-browser-acceptance\.php/);
assert.match(readerAcceptanceWorkflow, /start-ios-reader-pab-server\.php/);
assert.match(readerAcceptanceWorkflow, /__testing\/acceptance-sentinel/);
assert.match(readerAcceptanceWorkflow, /setOrientation: PORTRAIT/);
assert.match(readerAcceptanceWorkflow, /setOrientation: LANDSCAPE_RIGHT/);
assert.match(readerAcceptanceWorkflow, /duration: 60000/);
assert.match(readerAcceptanceWorkflow, /\.\*H10 iOS Reader \$\{READER_MARKER\}\.\*/);
assert.match(readerAcceptanceWorkflow, /先回想这个词在这里的意思，再选择你的真实情况。/);
assert.match(readerAcceptanceWorkflow, /assertNotVisible: .*创建学习词义/);
assert.match(readerAcceptanceWorkflow, /run-command-with-timeout\.php --seconds=300/);
assert.match(readerAcceptanceWorkflow, /run-command-with-timeout\.php --seconds=180/);
assert.match(readerAcceptanceWorkflow, /run-command-with-timeout\.php --seconds=30/);
assert.match(readerAcceptanceWorkflow, /grep -E '\(\/api\/v1\/mobile\|\/__testing\/acceptance-sentinel\)'/);
assert.match(readerAcceptanceWorkflow, /rustup toolchain install 1\.98\.0 --profile minimal --no-self-update/);
assert.match(readerAcceptanceWorkflow, /RUSTUP_TOOLCHAIN=1\.98\.0/);
assert.match(readerAcceptanceWorkflow, /composer install --no-interaction --prefer-dist --no-progress/);
assert.doesNotMatch(readerAcceptanceWorkflow, /getcomposer\.org\/installer|composer-setup\.php/);
assert.match(readerAcceptanceWorkflow, /READING_TARGET_STALE_SOURCE|reading-unfamiliar-targets/);
assert.match(readerAcceptanceWorkflow, /learning_started_origin='reading'/);
assert.match(readerAcceptanceWorkflow, /source='reading_occurrence'/);
assert.match(readerAcceptanceWorkflow, /COUNT\(\*\) FROM review_logs WHERE user_id=\$USER_ID/);
assert.doesNotMatch(readerAcceptanceWorkflow, /^\s*(?:push|pull_request|schedule):/m);
assert.doesNotMatch(readerAcceptanceWorkflow, /sqlite|migrate:fresh|migrate:refresh|migrate:reset|db:wipe|TRUNCATE|DROP DATABASE/i);
assert.doesNotMatch(readerAcceptanceWorkflow, /secrets\.|upload-artifact|TestFlight|xcodebuild\s+archive|maestro\s+cloud|MAESTRO_CLOUD|API_KEY|notify\.ps1/i);
assert.match(readerPabServer, /LINGUACAFE_TEST_SENTINEL/);
assert.match(readerPabServer, /H10IosReaderBindingTest\.php/);
assert.match(readerPabServer, /IOS_READER_PAB_FOCUSED_TEST_FAILED/);
assert.match(readerPabServer, /smoke:mobile-reader-data/);
assert.match(commandTimeoutHarness, /Symfony\\Component\\Process\\Process/);
assert.match(commandTimeoutHarness, /ProcessTimedOutException/);
assert.match(commandTimeoutHarness, /COMMAND_TIMEOUT_EXCEEDED/);
assert.match(readerSmokeCommand, /app\(\)->environment\('testing'\)/);
assert.match(readerSmokeCommand, /LINGUACAFE_TEST_SENTINEL/);
assert.match(readerSmokeCommand, /__testing_acceptance_sentinel_/);

console.log('M9 iOS MVP source and release guard passed.');
