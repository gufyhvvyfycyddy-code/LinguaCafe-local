/**
 * SenseReviewLeechPresentation
 *
 * ADR-0011 (Task A-6)
 *
 * Single source of truth for sense leech governance *presentation*
 * metadata on the frontend: the Chinese display label, Vuetify color,
 * severity text, reason labels, suggestion labels, and copy-package
 * filename for each leech status / reason / suggestion.
 *
 * This file is PURE CONFIGURATION + PURE FUNCTIONS. It does NOT:
 *  - call any API (no axios, no fetch),
 *  - import Vue or Vuex,
 *  - access the DOM,
 *  - write any state,
 *  - contain FSRS scheduling logic,
 *  - compute leech classification (the backend Policy is the only
 *    authority; the frontend consumes the descriptor returned by
 *    GET /reviews/senses/{id}/leech),
 *  - call any AI provider,
 *  - create WordSense / ReviewCard / ReviewLog.
 *
 * The English status/reason/suggestion values MUST stay in sync with
 * the backend SenseReviewLeechPolicy. The cross-contract guard lives
 * in tests/js/SenseReviewLeechPresentationGuard.test.mjs.
 */

/**
 * The three leech classification statuses (must match backend Policy).
 */
export const LEECH_STATUSES = ['stable', 'struggling', 'leech'];

/**
 * The reason codes (must match backend SenseReviewLeechPolicy constants).
 */
export const LEECH_REASONS = [
    'recent_again_count_high',
    'recent_hard_count_high',
    'lapses_high',
    'stability_declining',
    'low_success_after_multiple_reviews',
];

/**
 * The suggestion codes (must match backend SenseReviewLeechPolicy constants).
 */
export const LEECH_SUGGESTIONS = [
    'continue_review',
    'rewrite_example',
    'edit_sense',
    'suspend_temporarily',
    'view_history',
];

/**
 * Presentation metadata for each leech status.
 *
 * label  — Chinese label shown in badges, filters, and panels.
 * color  — Vuetify color name for chips/badges.
 * hint   — one-line plain-language explanation.
 */
export const LEECH_PRESENTATION = {
    stable: {
        label: '正常',
        color: 'success',
        hint: '正常学习中，没有明显的遗忘问题。',
    },
    struggling: {
        label: '需关注',
        color: 'warning',
        hint: '近期有明显困难，但还未到反复遗忘的程度。',
    },
    leech: {
        label: '高遗忘',
        color: 'error',
        hint: '反复遗忘，建议治理：换例句、暂停整理或编辑释义。',
    },
};

/**
 * Presentation metadata for each reason code.
 */
export const LEECH_REASON_PRESENTATION = {
    recent_again_count_high: {
        label: '最近"再来"次数较多',
        hint: '多次评分"再来"说明这个词义还没记住。',
    },
    recent_hard_count_high: {
        label: '最近"困难"次数较多',
        hint: '多次评分"困难"说明记忆不稳固。',
    },
    lapses_high: {
        label: '遗忘次数较多',
        hint: 'FSRS 遗忘次数已达到需关注的水平。',
    },
    stability_declining: {
        label: '记忆稳定性下降',
        hint: '近期记忆稳定性呈下降趋势。',
    },
    low_success_after_multiple_reviews: {
        label: '多次复习后成功率仍低',
        hint: '复习多次后正确率仍不足 40%。',
    },
};

/**
 * Presentation metadata for each suggestion code.
 */
export const LEECH_SUGGESTION_PRESENTATION = {
    continue_review: {
        label: '继续复习',
        hint: '保持当前节奏继续复习即可。',
    },
    rewrite_example: {
        label: '生成重写提示包',
        hint: '生成一个提示包，复制到外部 AI 改写例句或释义。',
    },
    edit_sense: {
        label: '编辑词义',
        hint: '直接编辑中文释义或例句。',
    },
    suspend_temporarily: {
        label: '暂停复习',
        hint: '暂时从复习队列中移除，整理后再恢复。',
    },
    view_history: {
        label: '查看历史',
        hint: '查看最近的评分记录和遗忘模式。',
    },
};

/**
 * Get the Chinese label for a leech status.
 *
 * @param {string} status - stable | struggling | leech
 * @returns {string}
 */
export function statusLabel(status) {
    return (LEECH_PRESENTATION[status] || { label: status }).label;
}

/**
 * Get the Vuetify color for a leech status.
 *
 * @param {string} status - stable | struggling | leech
 * @returns {string}
 */
export function statusColor(status) {
    return (LEECH_PRESENTATION[status] || { color: 'grey' }).color;
}

/**
 * Get the hint text for a leech status.
 *
 * @param {string} status - stable | struggling | leech
 * @returns {string}
 */
export function statusHint(status) {
    return (LEECH_PRESENTATION[status] || { hint: '' }).hint;
}

/**
 * Get the Chinese label for a reason code.
 *
 * @param {string} reason
 * @returns {string}
 */
export function reasonLabel(reason) {
    return (LEECH_REASON_PRESENTATION[reason] || { label: reason }).label;
}

/**
 * Get the Chinese label for a suggestion code.
 *
 * @param {string} suggestion
 * @returns {string}
 */
export function suggestionLabel(suggestion) {
    return (LEECH_SUGGESTION_PRESENTATION[suggestion] || { label: suggestion }).label;
}

/**
 * Get the hint text for a suggestion code.
 *
 * @param {string} suggestion
 * @returns {string}
 */
export function suggestionHint(suggestion) {
    return (LEECH_SUGGESTION_PRESENTATION[suggestion] || { hint: '' }).hint;
}

/**
 * Convert a severity score (0-100) to a human-readable text.
 *
 * @param {number} severity
 * @returns {string}
 */
export function severityText(severity) {
    if (severity <= 0) return '无';
    if (severity < 30) return '轻微';
    if (severity < 60) return '中等';
    if (severity < 85) return '较高';
    return '严重';
}

/**
 * Convert a severity score (0-100) to a Vuetify color.
 *
 * @param {number} severity
 * @returns {string}
 */
export function severityColor(severity) {
    if (severity <= 0) return 'grey';
    if (severity < 30) return 'success';
    if (severity < 60) return 'warning';
    return 'error';
}

/**
 * Generate a filename for a rewrite package copy operation.
 *
 * @param {number} reviewCardId
 * @param {string} lemma
 * @returns {string}
 */
export function copyFilename(reviewCardId, lemma) {
    const safeLemma = (lemma || 'sense').replace(/[^a-zA-Z0-9_-]/g, '_').substring(0, 30);
    return `leech-rewrite-${reviewCardId}-${safeLemma}.json`;
}

/**
 * The review-page hint text shown when a card is struggling.
 *
 * @returns {string}
 */
export function strugglingHintText() {
    return '这个词义最近容易忘。';
}

/**
 * The review-page suggestion text shown when a card is struggling.
 *
 * @returns {string}
 */
export function strugglingSuggestionText() {
    return '建议先换一个更清楚的例句，或暂停整理后再复习。';
}

/**
 * The review-page panel text shown when a card is leech.
 *
 * @returns {string}
 */
export function leechPanelText() {
    return '这个词义反复遗忘，建议进行治理。';
}

/**
 * The "not calling AI" notice text shown in the rewrite package dialog.
 *
 * @returns {string}
 */
export function noAiNoticeText() {
    return 'LinguaCafe 不会调用任何 AI。请手动复制以下内容到外部 AI（如 ChatGPT、Claude），改写后再回到这里编辑词义。';
}
