/**
 * SenseReviewRatingPresentation
 *
 * SenseReview-RatingPresentation-1000-2
 *
 * Single source of truth for SenseReview rating *presentation* metadata
 * on the frontend: the Chinese display label, Vuetify color, hotkey
 * digit, and numeric score for each of the four allowed ratings.
 *
 * This file is PURE CONFIGURATION. It does NOT:
 *  - call any API,
 *  - access Vuex,
 *  - write any state,
 *  - contain FSRS scheduling logic,
 *  - change the English rating values consumed by the API.
 *
 * The English values (again / hard / good / easy) and the numeric scores
 * MUST stay in sync with the backend SenseReviewRatingContract
 * (app/Services/SenseReviewRatingContract.php). The cross-contract guard
 * lives in tests/js/SenseReviewRatingPresentationGuard.test.mjs.
 *
 * Final user-visible labels (fixed in 1000-2):
 *   again → 忘了        (hotkey 1, score 1, color error)
 *   hard  → 勉强记得    (hotkey 2, score 2, color warning)
 *   good  → 记得        (hotkey 3, score 3, color primary)
 *   easy  → 很熟        (hotkey 4, score 4, color success)
 */
export const RATING_PRESENTATION = [
    {
        value: 'again',
        label: '忘了',
        color: 'error',
        hotkey: 1,
        score: 1,
    },
    {
        value: 'hard',
        label: '勉强记得',
        color: 'warning',
        hotkey: 2,
        score: 2,
    },
    {
        value: 'good',
        label: '记得',
        color: 'primary',
        hotkey: 3,
        score: 3,
    },
    {
        value: 'easy',
        label: '很熟',
        color: 'success',
        hotkey: 4,
        score: 4,
    },
];

/**
 * All allowed rating English values in stable display order.
 */
export const RATING_VALUES = RATING_PRESENTATION.map((r) => r.value);

/**
 * Look up the presentation entry for a rating value.
 * Returns undefined for unknown values.
 */
export function getRatingPresentation(value) {
    return RATING_PRESENTATION.find((r) => r.value === value);
}

/**
 * Chinese label for a rating value, or undefined when unknown.
 */
export function labelForRating(value) {
    const entry = getRatingPresentation(value);
    return entry ? entry.label : undefined;
}

/**
 * Vuetify color for a rating value, or 'grey' when unknown.
 */
export function colorForRating(value) {
    const entry = getRatingPresentation(value);
    return entry ? entry.color : 'grey';
}

/**
 * Build the single-line hotkey hint string, e.g.
 *   "快捷键：1 忘了 / 2 勉强记得 / 3 记得 / 4 很熟"
 */
export function hotkeyHintText() {
    return '快捷键：' + RATING_PRESENTATION
        .map((r) => `${r.hotkey} ${r.label}`)
        .join(' / ');
}
