import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const walk = dir => fs.readdirSync(dir, { withFileTypes: true }).flatMap(entry => {
    const file = path.join(dir, entry.name);
    return entry.isDirectory() ? walk(file) : (file.endsWith('.php') ? [file] : []);
});

const allowedLegacyReaders = new Set([
    path.normalize('app/Services/Settings/Presets/LegacyReviewSettingsSnapshotService.php'),
    path.normalize('app/Services/Settings/SettingValueService.php'),
]);
const legacyNames = [
    'fsrsDesiredRetention', 'fsrs_parameters', 'fsrs_parameters_source',
    'fsrs_parameters_optimized_at', 'daily_new_limit_enabled', 'daily_new_limit',
    'daily_review_limit_enabled', 'daily_review_limit', 'new_cards_ignore_review_limit',
    'fsrs_queue_interday_learning_review_order', 'fsrs_queue_new_review_order',
    'fsrs_queue_review_sort_order', 'fsrs_queue_new_sort_order',
];

for (const file of walk('app')) {
    const normalized = path.normalize(file);
    if (allowedLegacyReaders.has(normalized)) continue;
    const source = fs.readFileSync(file, 'utf8');
    if (!source.includes("where('user_id', -1)")) continue;
    for (const name of legacyNames) {
        assert.ok(!source.includes(`'${name}'`), `${file} directly reads preset-owned legacy key ${name}`);
    }
}

const scheduling = fs.readFileSync('app/Services/FsrsSchedulingService.php', 'utf8');
assert.match(scheduling, /desiredRetention\(int \$userId, string \$language\)/);
assert.match(scheduling, /getActiveFsrsParameters\(int \$userId, string \$language\)/);

console.log('Review Settings Preset V1A consumer guard passed.');
