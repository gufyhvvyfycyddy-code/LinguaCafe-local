import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const container = read('resources/js/components/Admin/AdminReviewSettings.vue');
const identity = read('resources/js/components/Admin/ReviewSettings/CurrentReviewSettingsPreset.vue');
const api = read('resources/js/services/AdminReviewSettingsApi.js');

assert.match(container, /<current-review-settings-preset :language="language" \/>/);
assert.match(identity, /当前 Preset：\{\{ presetName \}\}/);
assert.match(identity, /当前语言：\{\{ currentLanguage \}\}/);
assert.match(identity, /AdminReviewSettingsApi\.getPresetMetadata\(\)/);
assert.match(api, /getGlobalSettings\(\['reviewSettingsPresetMetadata'\]\)/);

for (const forbidden of ['新增 Preset', '复制 Preset', '重命名 Preset', '删除 Preset', '切换 Preset']) {
    assert.doesNotMatch(identity, new RegExp(forbidden));
}

console.log('Review Settings Preset V1A UI guard passed.');
