export function getGlobalSettings(settingNames) {
    return axios.post('/settings/global/get', { settingNames });
}

export function updateGlobalSettings(settings) {
    return axios.post('/settings/global/update', { settings });
}

export function getPresetMetadata() {
    return getGlobalSettings(['reviewSettingsPresetMetadata']);
}

export function listReviewSettingsPresets() {
    return axios.get('/settings/review-presets');
}

export function createReviewSettingsPreset(name) {
    return axios.post('/settings/review-presets', { name });
}

export function cloneReviewSettingsPreset(presetId, name) {
    return axios.post(`/settings/review-presets/${presetId}/clone`, { name });
}

export function renameReviewSettingsPreset(presetId, name) {
    return axios.patch(`/settings/review-presets/${presetId}`, { name });
}

export function deleteReviewSettingsPreset(presetId) {
    return axios.delete(`/settings/review-presets/${presetId}`);
}

export function switchReviewSettingsPreset(presetId) {
    return axios.put('/settings/review-presets/current-language', { preset_id: presetId });
}

export function getReviewCardStats() {
    return axios.get('/review-cards/stats');
}

export function getOptimizationStatus() {
    return axios.get('/settings/fsrs/optimization-status');
}

export function simulateRetentionWorkload() {
    return axios.post('/settings/fsrs/retention-workload-simulation');
}

export function getDailyLimits() {
    return axios.get('/settings/fsrs/daily-limits');
}

export function updateDailyLimits(payload) {
    return axios.post('/settings/fsrs/daily-limits', payload);
}

export function getQueueOrder() {
    return axios.get('/settings/fsrs/queue-order');
}

export function updateQueueOrder(payload) {
    return axios.post('/settings/fsrs/queue-order', payload);
}

export function previewOptimization() {
    return axios.post('/settings/fsrs/optimize');
}

export function applyOptimization() {
    return axios.post('/settings/fsrs/optimize', { confirm: true });
}

export function restoreDefaultParameters() {
    return axios.post('/settings/fsrs/restore-default');
}

export function previewReschedule() {
    return axios.post('/settings/fsrs/reschedule-preview');
}

export function confirmReschedule(payload) {
    return axios.post('/settings/fsrs/reschedule-confirm', payload);
}

export function undoReschedule() {
    return axios.post('/settings/fsrs/reschedule-undo', { confirm: true });
}
