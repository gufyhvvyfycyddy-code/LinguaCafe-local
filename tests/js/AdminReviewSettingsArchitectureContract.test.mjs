import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname.replace(/^\/(.:)/, '$1')), '../..');
const read = relativePath => fs.readFileSync(path.join(root, relativePath), 'utf8');
const exists = relativePath => fs.existsSync(path.join(root, relativePath));
const lineCount = source => source.split(/\r?\n/).length;

const containerPath = 'resources/js/components/Admin/AdminReviewSettings.vue';
const apiPath = 'resources/js/services/AdminReviewSettingsApi.js';
const panelPaths = [
    'resources/js/components/Admin/ReviewSettings/CurrentReviewSettingsPreset.vue',
    'resources/js/components/Admin/ReviewSettings/FsrsGoalSettingsPanel.vue',
    'resources/js/components/Admin/ReviewSettings/FsrsQueueOrderSettingsPanel.vue',
    'resources/js/components/Admin/ReviewSettings/FsrsStatusPanel.vue',
    'resources/js/components/Admin/ReviewSettings/FsrsAdvancedToolsPanel.vue',
    'resources/js/components/Admin/ReviewSettings/LegacySrsSettingsPanel.vue',
];
const backendPaths = [
    'app/Services/Settings/SettingValueService.php',
    'app/Services/Settings/FsrsOptimizationSettingsService.php',
    'app/Services/Settings/FsrsDailyLimitsSettingsService.php',
    'app/Services/Settings/FsrsQueueOrderSettingsService.php',
];

for (const file of [containerPath, apiPath, ...panelPaths, ...backendPaths]) {
    assert.ok(exists(file), `missing settings architecture file: ${file}`);
}

const container = read(containerPath);
const api = read(apiPath);
const settingsService = read('app/Services/SettingsService.php');

assert.ok(lineCount(container) <= 180, `AdminReviewSettings.vue must stay a thin container; got ${lineCount(container)} lines`);
assert.doesNotMatch(container, /axios\./, 'settings container must not call axios directly');
assert.match(container, /FsrsGoalSettingsPanel/);
assert.match(container, /FsrsQueueOrderSettingsPanel/);
assert.match(container, /FsrsStatusPanel/);
assert.match(container, /FsrsAdvancedToolsPanel/);
assert.match(container, /LegacySrsSettingsPanel/);
assert.match(container, /@stats-loaded="handleStatsLoaded"/);
assert.match(container, /@stats-changed="refreshStats"/);

const expectedApiMethods = [
    'getGlobalSettings',
    'updateGlobalSettings',
    'listReviewSettingsPresets',
    'createReviewSettingsPreset',
    'cloneReviewSettingsPreset',
    'renameReviewSettingsPreset',
    'deleteReviewSettingsPreset',
    'switchReviewSettingsPreset',
    'getReviewCardStats',
    'getOptimizationStatus',
    'simulateRetentionWorkload',
    'getDailyLimits',
    'updateDailyLimits',
    'getQueueOrder',
    'updateQueueOrder',
    'previewOptimization',
    'applyOptimization',
    'restoreDefaultParameters',
    'previewReschedule',
    'confirmReschedule',
    'undoReschedule',
];
for (const method of expectedApiMethods) {
    assert.match(api, new RegExp(`export function ${method}\\b`), `API client must export ${method}`);
}
assert.equal((api.match(/axios\./g) || []).length, 21, 'all 21 settings HTTP calls must live in the API client');

for (const panelPath of panelPaths) {
    const panel = read(panelPath);
    assert.doesNotMatch(panel, /axios\./, `${panelPath} must use the shared API client`);
    assert.match(panel, /AdminReviewSettingsApi/, `${panelPath} must import the shared API client`);
}

assert.ok(lineCount(settingsService) <= 240, `SettingsService must be a compatibility facade; got ${lineCount(settingsService)} lines`);
assert.match(settingsService, /SettingValueService/);
assert.match(settingsService, /FsrsOptimizationSettingsService/);
assert.match(settingsService, /FsrsDailyLimitsSettingsService/);
assert.match(settingsService, /FsrsQueueOrderSettingsService/);

const publicMethods = [
    'isJellyfinEnabled',
    'getAnkiSettings',
    'getGlobalSettingsByName',
    'updateGlobalSettings',
    'getUserSettingsByName',
    'updateUserSettings',
    'getFsrsOptimizationStatus',
    'preflightFsrsOptimization',
    'computeFsrsOptimizationPreview',
    'applyFsrsOptimizedParameters',
    'getFsrsDailyLimits',
    'updateFsrsDailyLimits',
    'getFsrsQueueOrder',
    'updateFsrsQueueOrder',
    'restoreFsrsDefaultParameters',
];
for (const method of publicMethods) {
    assert.match(settingsService, new RegExp(`public function ${method}\\b`), `SettingsService must preserve ${method}`);
}
assert.doesNotMatch(settingsService, /Setting::|ReviewLog::|ReviewCard::|WordSense::|DB::/, 'compatibility facade must not contain persistence or domain queries');

console.log('Admin review settings architecture contract passed.');
