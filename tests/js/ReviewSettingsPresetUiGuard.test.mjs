import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const container = read('resources/js/components/Admin/AdminReviewSettings.vue');
const manager = read('resources/js/components/Admin/ReviewSettings/CurrentReviewSettingsPreset.vue');
const api = read('resources/js/services/AdminReviewSettingsApi.js');

// V1A identity and language context remain visible inside the V1B manager.
assert.match(container, /<current-review-settings-preset/);
assert.match(container, /:language="language"/);
assert.match(manager, /当前 Preset：\{\{ currentPreset\.name \}\}/);
assert.match(manager, /当前语言：\{\{ currentLanguage \}\}/);
assert.match(manager, /切换只改变当前语言的绑定，不会自动重排已有卡片/);

// V1B management is additive and remains behind the shared API client.
assert.match(manager, /AdminReviewSettingsApi\.listReviewSettingsPresets\(\)/);
assert.match(manager, /新建 Preset/);
assert.match(manager, /复制/);
assert.match(manager, /重命名/);
assert.match(manager, /删除并重新绑定/);
assert.match(manager, /preset\.is_default/);
assert.match(manager, /修改会同时影响/);
assert.doesNotMatch(manager, /axios\./);
assert.match(api, /axios\.get\('\/settings\/review-presets'\)/);
assert.match(api, /axios\.put\('\/settings\/review-presets\/current-language'/);

console.log('Review Settings Preset V1A identity + V1B management UI guard passed.');
