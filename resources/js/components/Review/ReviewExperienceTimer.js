function monotonicNow() {
    return typeof globalThis !== 'undefined'
        && globalThis.performance
        && typeof globalThis.performance.now === 'function'
        ? globalThis.performance.now()
        : Date.now();
}

function advance(state, nowMs) {
    if (state.runningSinceMs === null) return state;
    const delta = Math.max(0, nowMs - state.runningSinceMs);
    state.sessionElapsedMs += delta;
    if (state.cardId !== null) state.cardElapsedMs += delta;
    state.phaseElapsedMs += delta;
    state.runningSinceMs = nowMs;
    return state;
}

export function createExperienceSession(nowMs = monotonicNow(), visible = true) {
    return {
        cardId: null,
        phase: 'question',
        sessionElapsedMs: 0,
        cardElapsedMs: 0,
        phaseElapsedMs: 0,
        runningSinceMs: visible ? nowMs : null,
        pauseReasons: visible ? [] : ['visibility'],
    };
}

export function startExperienceCard(state, cardId, nowMs = monotonicNow()) {
    advance(state, nowMs);
    state.cardId = cardId;
    state.phase = 'question';
    state.cardElapsedMs = 0;
    state.phaseElapsedMs = 0;
    return state;
}

export function setExperiencePhase(state, phase, nowMs = monotonicNow()) {
    if (!['question', 'answer'].includes(phase) || state.phase === phase) return state;
    advance(state, nowMs);
    state.phase = phase;
    state.phaseElapsedMs = 0;
    return state;
}

export function pauseExperience(state, reason, nowMs = monotonicNow()) {
    if (!state.pauseReasons.includes(reason)) {
        advance(state, nowMs);
        state.pauseReasons.push(reason);
        state.runningSinceMs = null;
    }
    return state;
}

export function resumeExperience(state, reason, nowMs = monotonicNow()) {
    state.pauseReasons = state.pauseReasons.filter((value) => value !== reason);
    if (state.pauseReasons.length === 0 && state.runningSinceMs === null) {
        state.runningSinceMs = nowMs;
    }
    return state;
}

export function experienceSnapshot(state, nowMs = monotonicNow()) {
    advance(state, nowMs);
    return {
        sessionElapsedMs: Math.round(state.sessionElapsedMs),
        cardElapsedMs: Math.round(state.cardElapsedMs),
        phaseElapsedMs: Math.round(state.phaseElapsedMs),
        phase: state.phase,
        paused: state.pauseReasons.length > 0,
        pauseReasons: state.pauseReasons.slice(),
    };
}

export function autoAdvanceAction(experience, phase, phaseElapsedMs) {
    if (!experience || experience.auto_advance_enabled !== true) return null;
    const seconds = phase === 'question'
        ? Number(experience.question_timer_seconds || 0)
        : Number(experience.answer_timer_seconds || 0);
    if (!Number.isFinite(seconds) || seconds <= 0 || phaseElapsedMs < seconds * 1000) return null;
    return phase === 'question' ? 'reveal_answer' : 'wait_for_rating';
}

export function normalizeExperienceConfig(value) {
    const input = value && typeof value === 'object' ? value : {};
    const seconds = (key) => {
        const number = Number(input[key]);
        return Number.isInteger(number) && number >= 0 && number <= 3600 ? number : 0;
    };
    const normalized = {
        show_timer: input.show_timer === true,
        question_timer_seconds: seconds('question_timer_seconds'),
        answer_timer_seconds: seconds('answer_timer_seconds'),
        auto_advance_enabled: input.auto_advance_enabled === true,
    };
    if (normalized.question_timer_seconds === 0 && normalized.answer_timer_seconds === 0) {
        normalized.auto_advance_enabled = false;
    }
    return normalized;
}

export function formatExperienceDuration(milliseconds) {
    const totalSeconds = Math.max(0, Math.floor(Number(milliseconds || 0) / 1000));
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;
    return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
}

export { monotonicNow };
