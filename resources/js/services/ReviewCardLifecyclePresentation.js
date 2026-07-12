/**
 * ReviewCardLifecyclePresentation
 *
 * ADR-0010 (Task A-1)
 *
 * Single source of truth for review card lifecycle *presentation*
 * metadata on the frontend: the Chinese display label, Vuetify color,
 * blocked-reason label, buried remaining-time text, available action
 * labels, and danger level for each lifecycle state and action.
 *
 * This file is PURE CONFIGURATION + PURE FUNCTIONS. It does NOT:
 *  - call any API (no axios, no fetch),
 *  - import Vue or Vuex,
 *  - access the DOM,
 *  - write any state,
 *  - contain FSRS scheduling logic,
 *  - compute buried_until (the backend BuryTimeService does that),
 *  - replicate the state machine (the backend Policy is the only
 *    authority; the frontend consumes the descriptor returned by
 *    GET /review-cards/{id}/lifecycle).
 *
 * The English state/action values (active/buried/suspended/archived,
 * bury/unbury/suspend/resume/archive/restore) MUST stay in sync with
 * the backend ReviewCardLifecyclePolicy. The cross-contract guard lives
 * in tests/js/ReviewCardLifecyclePresentationGuard.test.mjs.
 */

/**
 * The four persistent lifecycle states (must match backend Policy).
 */
export const LIFECYCLE_STATES = ['active', 'buried', 'suspended', 'archived'];

/**
 * The six lifecycle actions (must match backend Policy).
 * Reset and delete are NOT lifecycle actions.
 */
export const LIFECYCLE_ACTIONS = [
    'bury',
    'unbury',
    'suspend',
    'resume',
    'archive',
    'restore',
];

/**
 * Presentation metadata for each lifecycle state.
 *
 * label  — Chinese label shown in badges, filters, and detail drawers.
 * color  — Vuetify color name for chips/badges.
 * hint   — one-line plain-language explanation shown in the "状态说明" UI.
 */
export const LIFECYCLE_PRESENTATION = {
    active: {
        label: '学习中',
        color: 'success',
        hint: '正常参与到期队列，可评分。',
    },
    buried: {
        label: '已埋藏',
        color: 'warning',
        hint: '仅隐藏到明天，不改变学习进度。',
    },
    suspended: {
        label: '已暂停',
        color: 'info',
        hint: '保持学习进度，直到主动恢复。',
    },
    archived: {
        label: '已归档',
        color: 'grey',
        hint: '退出当前学习体系，仍保留历史。',
    },
};

/**
 * Presentation metadata for each lifecycle action.
 *
 * label       — Chinese label shown in the "更多" menu and action buttons.
 * dangerLevel — 'safe' | 'moderate' | 'dangerous'
 *               safe      = no state exit, reversible, no FSRS change
 *               moderate  = exits queue temporarily or long-term
 *               dangerous = would exit the learning system
 * hint        — one-line plain-language explanation of the impact.
 */
export const LIFECYCLE_ACTION_PRESENTATION = {
    bury: {
        label: '埋藏到明天',
        dangerLevel: 'safe',
        hint: '明天自动恢复，不改变学习进度。',
    },
    unbury: {
        label: '解除埋藏',
        dangerLevel: 'safe',
        hint: '立即恢复到可用队列。',
    },
    suspend: {
        label: '暂停复习',
        dangerLevel: 'moderate',
        hint: '保持学习进度，直到主动恢复。',
    },
    resume: {
        label: '恢复复习',
        dangerLevel: 'safe',
        hint: '恢复原定到期时间，不自动设为立即到期。',
    },
    archive: {
        label: '归档',
        dangerLevel: 'moderate',
        hint: '退出当前学习体系，仍保留历史。',
    },
    restore: {
        label: '恢复归档',
        dangerLevel: 'safe',
        hint: '恢复原有 FSRS 数据，重新进入学习体系。',
    },
};

/**
 * Presentation metadata for non-lifecycle operations shown in the same
 * "更多" menu but executed through their own dedicated endpoints.
 *
 * These are NOT lifecycle actions — they are listed here only so the
 * frontend has a single source of truth for the menu labels and hints.
 */
export const NON_LIFECYCLE_ACTION_PRESENTATION = {
    reset: {
        label: '重置学习进度',
        dangerLevel: 'moderate',
        hint: '清除调度进度，但不改变归档或暂停状态。',
    },
    delete: {
        label: '删除',
        dangerLevel: 'dangerous',
        hint: '永久删除，无法恢复。',
    },
};

/**
 * Chinese label for a blocked_reason returned by the backend descriptor.
 *
 * @param {string|null} reason — 'temporarily_buried' | 'suspended' | 'archived' | null
 * @returns {string} Chinese label, or '' when reason is null/unknown.
 */
export function blockedReasonLabel(reason) {
    if (reason === 'temporarily_buried') {
        return '已埋藏，暂不进入复习队列';
    }
    if (reason === 'suspended') {
        return '已暂停，不进入复习队列';
    }
    if (reason === 'archived') {
        return '已归档，退出当前学习体系';
    }
    return '';
}

/**
 * Chinese label for a lifecycle state.
 *
 * @param {string} state — 'active' | 'buried' | 'suspended' | 'archived'
 * @returns {string} Chinese label, or the raw state when unknown.
 */
export function stateLabel(state) {
    const entry = LIFECYCLE_PRESENTATION[state];
    return entry ? entry.label : state;
}

/**
 * Vuetify color for a lifecycle state.
 *
 * @param {string} state
 * @returns {string} Vuetify color name, or 'grey' when unknown.
 */
export function stateColor(state) {
    const entry = LIFECYCLE_PRESENTATION[state];
    return entry ? entry.color : 'grey';
}

/**
 * One-line hint for a lifecycle state (used in "状态说明" UI).
 *
 * @param {string} state
 * @returns {string} hint text, or '' when unknown.
 */
export function stateHint(state) {
    const entry = LIFECYCLE_PRESENTATION[state];
    return entry ? entry.hint : '';
}

/**
 * Chinese label for a lifecycle action.
 *
 * @param {string} action — 'bury' | 'unbury' | 'suspend' | 'resume' | 'archive' | 'restore'
 * @returns {string} Chinese label, or the raw action when unknown.
 */
export function actionLabel(action) {
    const entry = LIFECYCLE_ACTION_PRESENTATION[action];
    if (entry) {
        return entry.label;
    }
    const nonLifecycle = NON_LIFECYCLE_ACTION_PRESENTATION[action];
    return nonLifecycle ? nonLifecycle.label : action;
}

/**
 * Danger level for an action.
 *
 * @param {string} action
 * @returns {string} 'safe' | 'moderate' | 'dangerous', or 'safe' when unknown.
 */
export function actionDangerLevel(action) {
    const entry = LIFECYCLE_ACTION_PRESENTATION[action];
    if (entry) {
        return entry.dangerLevel;
    }
    const nonLifecycle = NON_LIFECYCLE_ACTION_PRESENTATION[action];
    return nonLifecycle ? nonLifecycle.dangerLevel : 'safe';
}

/**
 * One-line hint for an action (used in menu tooltips and "状态说明" UI).
 *
 * @param {string} action
 * @returns {string} hint text, or '' when unknown.
 */
export function actionHint(action) {
    const entry = LIFECYCLE_ACTION_PRESENTATION[action];
    if (entry) {
        return entry.hint;
    }
    const nonLifecycle = NON_LIFECYCLE_ACTION_PRESENTATION[action];
    return nonLifecycle ? nonLifecycle.hint : '';
}

/**
 * Vuetify color for an action button, derived from danger level.
 *
 * @param {string} action
 * @returns {string} Vuetify color name.
 */
export function actionColor(action) {
    const level = actionDangerLevel(action);
    if (level === 'dangerous') {
        return 'error';
    }
    if (level === 'moderate') {
        return 'warning';
    }
    return 'primary';
}

/**
 * Human-readable remaining time until a buried card auto-restores.
 *
 * Returns a Chinese string like "还剩 3 小时" or "已到期". Returns ''
 * when buriedUntil is null/invalid or not a future time.
 *
 * This function does NOT compute the buried_until value — it only
 * formats the remaining duration from a value already provided by the
 * backend. The backend BuryTimeService is the sole authority for
 * computing the next-day boundary.
 *
 * @param {string|null} buriedUntil — ISO 8601 string from backend descriptor, or null
 * @param {Date} [now] — current time (injectable for tests); defaults to new Date()
 * @returns {string} Chinese remaining-time text, or '' when not applicable.
 */
export function buriedRemainingText(buriedUntil, now) {
    if (!buriedUntil) {
        return '';
    }
    const target = new Date(buriedUntil);
    if (isNaN(target.getTime())) {
        return '';
    }
    const current = now || new Date();
    const diffMs = target.getTime() - current.getTime();
    if (diffMs <= 0) {
        return '已到期';
    }
    const diffHours = Math.floor(diffMs / (1000 * 60 * 60));
    const diffMinutes = Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60));
    if (diffHours >= 1) {
        return `还剩 ${diffHours} 小时`;
    }
    if (diffMinutes >= 1) {
        return `还剩 ${diffMinutes} 分钟`;
    }
    return '即将恢复';
}

/**
 * The full list of items shown in the SenseReview "更多" menu, in
 * stable display order. Each item has: action, label, hint,
 * dangerLevel, color, isLifecycle.
 *
 * The caller is responsible for filtering this list based on the
 * card's effective state and available_actions (returned by the
 * backend descriptor). This function does NOT replicate the state
 * machine.
 */
export const MORE_MENU_ITEMS = [
    { action: 'bury', label: actionLabel('bury'), hint: actionHint('bury'), dangerLevel: actionDangerLevel('bury'), color: actionColor('bury'), isLifecycle: true },
    { action: 'unbury', label: actionLabel('unbury'), hint: actionHint('unbury'), dangerLevel: actionDangerLevel('unbury'), color: actionColor('unbury'), isLifecycle: true },
    { action: 'suspend', label: actionLabel('suspend'), hint: actionHint('suspend'), dangerLevel: actionDangerLevel('suspend'), color: actionColor('suspend'), isLifecycle: true },
    { action: 'resume', label: actionLabel('resume'), hint: actionHint('resume'), dangerLevel: actionDangerLevel('resume'), color: actionColor('resume'), isLifecycle: true },
    { action: 'archive', label: actionLabel('archive'), hint: actionHint('archive'), dangerLevel: actionDangerLevel('archive'), color: actionColor('archive'), isLifecycle: true },
    { action: 'restore', label: actionLabel('restore'), hint: actionHint('restore'), dangerLevel: actionDangerLevel('restore'), color: actionColor('restore'), isLifecycle: true },
    { action: 'reset', label: actionLabel('reset'), hint: actionHint('reset'), dangerLevel: actionDangerLevel('reset'), color: actionColor('reset'), isLifecycle: false },
    { action: 'delete', label: actionLabel('delete'), hint: actionHint('delete'), dangerLevel: actionDangerLevel('delete'), color: actionColor('delete'), isLifecycle: false },
];

/**
 * The list of state-filter options for the ReviewCardManage page,
 * in stable display order. Each item has: value (filter key), label.
 *
 * 'all' shows every card regardless of lifecycle state.
 */
export const STATE_FILTERS = [
    { value: 'active', label: '学习中' },
    { value: 'buried', label: '已埋藏' },
    { value: 'suspended', label: '已暂停' },
    { value: 'archived', label: '已归档' },
    { value: 'all', label: '全部' },
];

/**
 * The list of batch operations supported on the ReviewCardManage page.
 *
 * Bulk bury, bulk reset, and bulk delete are NOT supported.
 */
export const BATCH_OPERATIONS = [
    'suspend',
    'resume',
    'archive',
    'restore',
    'unbury',
];

/**
 * Build the short status explanation entries shown in the "状态说明"
 * UI on the management page. Returns an array of {label, hint} pairs.
 *
 * Does NOT expose lifecycle_version, fsrs_enabled mirror, or database
 * column names.
 */
export function stateExplanationEntries() {
    return [
        { label: '埋藏', hint: '仅隐藏到明天。' },
        { label: '暂停', hint: '无限期停止复习。' },
        { label: '归档', hint: '退出当前学习体系。' },
        { label: '重置', hint: '重新开始调度。' },
        { label: '删除', hint: '永久移除。' },
    ];
}
