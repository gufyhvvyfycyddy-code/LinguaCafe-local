import assert from 'node:assert/strict';
import { createTracker, pause, resume, durationMs } from '../../resources/js/components/Review/ReviewDurationTracker.js';

const tracker = createTracker(1000, true);
assert.equal(durationMs(tracker, 2500), 1500);
pause(tracker, 3000);
assert.equal(durationMs(tracker, 9000), 2000, 'hidden time must not accumulate');
resume(tracker, 10000);
assert.equal(durationMs(tracker, 10500), 2500);
assert.equal(durationMs(createTracker(0, true), 900000), 600000, 'duration is capped at ten minutes');
assert.equal(durationMs(createTracker(0, false), 900000), 0);
console.log('ReviewDurationTracker tests passed');
