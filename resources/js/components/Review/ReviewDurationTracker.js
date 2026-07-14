const MAX_DURATION_MS = 600000;

export function createTracker(nowMs = Date.now(), visible = true) {
    return { elapsedMs: 0, startedAtMs: visible ? nowMs : null };
}

export function pause(tracker, nowMs = Date.now()) {
    if (tracker.startedAtMs !== null) {
        tracker.elapsedMs += Math.max(0, nowMs - tracker.startedAtMs);
        tracker.startedAtMs = null;
    }
    return tracker;
}

export function resume(tracker, nowMs = Date.now()) {
    if (tracker.startedAtMs === null) tracker.startedAtMs = nowMs;
    return tracker;
}

export function durationMs(tracker, nowMs = Date.now()) {
    const active = tracker.startedAtMs === null ? 0 : Math.max(0, nowMs - tracker.startedAtMs);
    return Math.min(MAX_DURATION_MS, Math.round(tracker.elapsedMs + active));
}

export { MAX_DURATION_MS };
