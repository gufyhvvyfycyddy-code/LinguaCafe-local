import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const root = process.cwd();
const files = [
  'app/Services/FsrsSchedulingService.php',
  'app/Services/FsrsReschedulePreviewService.php',
  'app/Services/FsrsRetentionWorkloadSimulationService.php',
  'app/Services/Settings/FsrsOptimizationSettingsService.php',
  'app/Console/Commands/GptSenseWorkflow.php',
];

const contents = files.map((path) => [path, readFileSync(join(root, path), 'utf8')]);

test('FSRS availability checks use the canonical namespaced class constant', () => {
  let totalChecks = 0;

  for (const [path, content] of contents) {
    const checks = content.split('class_exists(\\fsrs\\FSRS::class)').length - 1;
    assert.ok(checks >= 1, `${path} must use fsrs\\FSRS::class for availability checks`);
    totalChecks += checks;

    assert.ok(!content.includes("class_exists('\\\\fsrs\\\\FSRS')"), `${path} must not use a leading-slash class string`);
    assert.ok(!content.includes("class_exists('\\\\\\\\fsrs\\\\\\\\FSRS')"), `${path} must not use a double-leading-slash class string`);
  }

  assert.equal(totalChecks, 6, 'all six FSRS availability checks should use the canonical class constant');
});
