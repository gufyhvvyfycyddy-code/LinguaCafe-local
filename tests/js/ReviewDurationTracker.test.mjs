import assert from 'node:assert/strict';
import { createTracker, pause, resume, durationMs, monotonicNow, MAX_DURATION_MS } from '../../resources/js/components/Review/ReviewDurationTracker.js';

// Existing baseline tests (injected numeric clock — wall clock irrelevant).
const tracker = createTracker(1000, true);
assert.equal(durationMs(tracker, 2500), 1500);
pause(tracker, 3000);
assert.equal(durationMs(tracker, 9000), 2000, 'hidden time must not accumulate');
resume(tracker, 10000);
assert.equal(durationMs(tracker, 10500), 2500);
assert.equal(durationMs(createTracker(0, true), 900000), 600000, 'duration is capped at ten minutes');
assert.equal(durationMs(createTracker(0, false), 900000), 0);

// C2: multiple pause is idempotent — does not double-count or lose time.
{
    const t = createTracker(1000, true);
    pause(t, 2000); // elapsedMs = 1000, startedAtMs = null
    pause(t, 3000); // already paused → no-op
    pause(t, 4000); // already paused → no-op
    assert.equal(t.elapsedMs, 1000, 'multiple pause must not double-count');
    assert.equal(t.startedAtMs, null, 'stays paused after repeated pause');
    assert.equal(durationMs(t, 5000), 1000, 'duration frozen while paused');
}

// C2: multiple resume is idempotent — does not reset the start time.
{
    const t = createTracker(1000, true);
    pause(t, 2000); // elapsedMs = 1000, paused
    resume(t, 3000); // startedAtMs = 3000
    resume(t, 4000); // already running → no-op, startedAtMs stays 3000
    resume(t, 5000); // already running → no-op
    assert.equal(t.startedAtMs, 3000, 'multiple resume must not reset startedAtMs');
    assert.equal(durationMs(t, 6000), 4000, 'duration = 1000 (first interval) + 3000 (resume to read)');
}

// C1: monotonicNow returns a number and is callable.
{
    const now = monotonicNow();
    assert.equal(typeof now, 'number', 'monotonicNow must return a number');
    // Two successive calls should be non-decreasing (monotonic).
    const later = monotonicNow();
    assert.ok(later >= now, 'monotonicNow must be non-decreasing');
}

// C1: default clock works without explicit nowMs (uses monotonicNow).
{
    const t = createTracker(undefined, true);
    assert.equal(t.elapsedMs, 0);
    assert.notEqual(t.startedAtMs, null, 'visible tracker must start running with default clock');
    const d1 = durationMs(t);
    const d2 = durationMs(t);
    assert.ok(d2 >= d1, 'default-clock duration must be non-decreasing');
    assert.ok(d2 <= MAX_DURATION_MS, 'default-clock duration must respect cap');
}

// C2: initially-hidden tracker stays at zero until resumed.
{
    const t = createTracker(0, false);
    assert.equal(t.startedAtMs, null, 'hidden tracker must start paused');
    assert.equal(durationMs(t, 5000), 0, 'hidden tracker accumulates nothing');
    resume(t, 6000);
    assert.equal(durationMs(t, 7000), 1000, 'resumed tracker accumulates from resume point');
}

// C2: pause with a nowMs earlier than startedAtMs does not decrease elapsedMs.
{
    const t = createTracker(5000, true);
    pause(t, 3000); // clock went backwards: Math.max(0, 3000-5000) = 0
    assert.equal(t.elapsedMs, 0, 'backwards pause must not decrease elapsedMs');
    assert.equal(t.startedAtMs, null);
}

console.log('ReviewDurationTracker tests passed');
