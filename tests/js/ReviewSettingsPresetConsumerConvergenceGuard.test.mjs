import assert from 'node:assert/strict';
import fs from 'node:fs';

const optimization = fs.readFileSync('app/Services/Settings/FsrsOptimizationSettingsService.php', 'utf8');
const values = fs.readFileSync('app/Services/Settings/SettingValueService.php', 'utf8');
const snapshot = fs.readFileSync('app/Services/Settings/Presets/LegacyReviewSettingsSnapshotService.php', 'utf8');
const config = fs.readFileSync('app/Services/Settings/Presets/ReviewSettingsPresetConfig.php', 'utf8');

assert.doesNotMatch(optimization, /SettingValueService/);
assert.doesNotMatch(optimization, /upsertGlobal|deleteGlobal/);
assert.doesNotMatch(optimization, /fsrs_parameters_previous/);
assert.doesNotMatch(values, /public function upsertGlobal/);
assert.doesNotMatch(values, /public function deleteGlobal/);
assert.doesNotMatch(snapshot, /fsrs_parameters_previous/);
assert.doesNotMatch(config, /fsrs_parameters_previous/);
assert.match(optimization, /'saved_keys' => \[[\s\S]*'fsrs_parameters_optimized_at'/);
assert.match(optimization, /'deleted_count' => 0/);

console.log('Review Settings Preset V1C consumer convergence guard passed.');
