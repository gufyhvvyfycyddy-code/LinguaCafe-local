const MAX_DURATION_MS = 600000;

// ponytail: ADR-0019 mandates monotonic performance.now(); fall back to Date.now()
// only when the Performance API is genuinely absent (e.g., very old runtimes).
function monotonicNow() {
    return typeof globalThis !== 'undefined'
        && globalThis.performance
        && typeof globalThis.performance.now === 'function'
        ? globalThis.performance.now()
        : Date.now();
}

export function createTracker(nowMs = monotonicNow(), visible = true) {
    return { elapsedMs: 0, startedAtMs: visible ? nowMs : null };
}

export function pause(tracker, nowMs = monotonicNow()) {
    if (tracker.startedAtMs !== null) {
        tracker.elapsedMs += Math.max(0, nowMs - tracker.startedAtMs);
        tracker.startedAtMs = null;
    }
    return tracker;
}

export function resume(tracker, nowMs = monotonicNow()) {
    if (tracker.startedAtMs === null) tracker.startedAtMs = nowMs;
    return tracker;
}

export function durationMs(tracker, nowMs = monotonicNow()) {
    const active = tracker.startedAtMs === null ? 0 : Math.max(0, nowMs - tracker.startedAtMs);
    return Math.min(MAX_DURATION_MS, Math.round(tracker.elapsedMs + active));
}

export { MAX_DURATION_MS, monotonicNow };
