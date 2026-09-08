// Single owner for FSRS scheduling-state -> Chinese display label.
//
// EP-3: several surfaces (statistics, review-card search/manage, admin FSRS
// status panel, reader word-sense list) each re-implemented this mapping, and
// they had drifted apart -- e.g. the statistics page showed `复习` / `重学`
// while every other surface showed `复习中` / `重新学习`, and the reader
// collapsed `review` down to `学习中`. This module is the one place that maps
// ReviewCard.fsrs_state to its label so the same state reads identically
// everywhere.
//
// Display-only. This never changes fsrs_state data, the FSRS algorithm,
// ReviewCard, ReviewLog, or any scheduling result -- it only names an existing
// state for the UI. Lifecycle state (active / buried / suspended / archived) is
// a different concept owned by ReviewCardLifecyclePresentation.js.

// The four FSRS scheduling states, in the order surfaces display them.
export const FSRS_STATES = ['new', 'learning', 'review', 'relearning'];

// Canonical wording, confirmed against the shipped stats surfaces and the FSRS
// roadmap stats-chip spec (docs/plans/linguacafe-fsrs-roadmap.md:145,
// docs/plans/fsrs-settings-anki-gap-plan.md:25).
export const FSRS_STATE_LABELS = Object.freeze({
    new: '新卡',
    learning: '学习中',
    review: '复习中',
    relearning: '重新学习',
});

// Returns the canonical Chinese label for an FSRS state. For an unknown but
// non-empty value the raw state is returned (so a future backend state is still
// visible rather than hidden); an empty/absent state returns the shared
// fallback.
export function fsrsStateLabel(state, fallback = '学习中') {
    if (state === null || state === undefined || state === '') {
        return fallback;
    }

    return FSRS_STATE_LABELS[state] || state;
}

// Ordered [{ label, value }] options for the four FSRS states, for filter
// dropdowns / chip lists that iterate the states.
export function fsrsStateOptions() {
    return FSRS_STATES.map(value => ({ label: FSRS_STATE_LABELS[value], value }));
}
