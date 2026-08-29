import assert from 'node:assert/strict';
import fs from 'node:fs';

const container = fs.readFileSync('resources/js/components/Admin/AdminReviewSettings.vue', 'utf8');
const scheduling = fs.readFileSync('resources/js/components/Admin/ReviewSettings/FsrsSchedulingSettingsPanel.vue', 'utf8');
const planner = fs.readFileSync('resources/js/components/Admin/ReviewSettings/FsrsWorkloadPlannerPanel.vue', 'utf8');
const advanced = fs.readFileSync('resources/js/components/Admin/ReviewSettings/FsrsAdvancedToolsPanel.vue', 'utf8');
const reschedule = fs.readFileSync('resources/js/components/FsrsReschedulePanel.vue', 'utf8');
const api = fs.readFileSync('resources/js/services/AdminReviewSettingsApi.js', 'utf8');

assert.match(container, /<fsrs-scheduling-settings-panel/);
assert.match(container, /<fsrs-workload-planner-panel/);
assert.match(scheduling, /learning_steps_minutes/);
assert.match(scheduling, /relearning_steps_minutes/);
assert.match(scheduling, /maximum_interval_days/);
assert.match(scheduling, /minimum_relearning_interval_days/);
assert.match(scheduling, /easy_days/);
assert.match(scheduling, /auto_advance_enabled/);
assert.match(scheduling, /绝不会自动选择评分/);
assert.match(scheduling, /audio_autoplay/);
assert.match(planner, /30 \/ 90 \/ 365/);
assert.match(planner, /stability、difficulty、retrievability/);
assert.match(advanced, /health_warnings/);
assert.match(advanced, /<fsrs-reschedule-panel/);
assert.match(reschedule, /previewReschedule/);
assert.match(reschedule, /undoReschedule/);
assert.match(api, /getAdvancedReviewSettings/);
assert.match(api, /updateAdvancedReviewSettings/);

console.log('M13 Review Settings UI guard passed.');
