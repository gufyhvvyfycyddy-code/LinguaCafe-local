import assert from 'node:assert/strict';
import fs from 'node:fs';

const api = fs.readFileSync('resources/js/services/AdminReviewSettingsApi.js', 'utf8');
const manager = fs.readFileSync('resources/js/components/Admin/ReviewSettings/CurrentReviewSettingsPreset.vue', 'utf8');
const container = fs.readFileSync('resources/js/components/Admin/AdminReviewSettings.vue', 'utf8');
const routes = fs.readFileSync('routes/review-settings-presets.php', 'utf8');
const bootstrap = fs.readFileSync('bootstrap/app.php', 'utf8');

assert.match(api, /export function listReviewSettingsPresets/);
assert.match(api, /axios\.get\('\/settings\/review-presets'\)/);
assert.match(api, /export function createReviewSettingsPreset/);
assert.match(api, /export function cloneReviewSettingsPreset/);
assert.match(api, /export function renameReviewSettingsPreset/);
assert.match(api, /export function deleteReviewSettingsPreset/);
assert.match(api, /export function switchReviewSettingsPreset/);

assert.match(manager, /当前 Preset/);
assert.match(manager, /新建 Preset/);
assert.match(manager, /复制/);
assert.match(manager, /重命名/);
assert.match(manager, /删除/);
assert.match(manager, /适用语言/);
assert.match(manager, /修改会同时影响/);
assert.match(manager, /preset\.is_default/);
assert.match(manager, /\$emit\('preset-changed'/);
assert.doesNotMatch(manager, /axios\./);
assert.doesNotMatch(manager, /ReviewLog|fsrs_due_at|lifecycle_state/);

assert.match(container, /@preset-changed="handlePresetChanged"/);
assert.match(container, /settingsRefreshKey/);
assert.match(container, /:key="`goal-\$\{settingsRefreshKey\}`"/);
assert.match(container, /:key="`queue-\$\{settingsRefreshKey\}`"/);
assert.match(container, /:key="`advanced-\$\{settingsRefreshKey\}`"/);

assert.match(routes, /middleware\(\['auth', 'auth\.session', 'admin'\]\)/);
assert.match(routes, /settings\/review-presets/);
assert.match(routes, /current-language/);
assert.match(bootstrap, /routes\/review-settings-presets\.php/);

console.log('Review Settings Preset V1B management UI guard passed.');
