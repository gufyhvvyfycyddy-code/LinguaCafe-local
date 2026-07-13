/**
 * ReviewRatingRecovery.js — pure JS helper that orchestrates the
 * authoritative-queue reload after a rating request fails.
 *
 * Design constraints (Task 2000-13):
 *   - No axios import. The caller supplies `reloadQueue`.
 *   - No ReviewLog / FSRS / lifecycle knowledge.
 *   - No statistics mutation.
 *   - Keeps the rating lock until the reload Promise settles.
 *   - Guards against concurrent recovery (second call is a no-op).
 *   - Never produces an unhandled rejection.
 *
 * Used by:
 *   - resources/js/components/Review/Review.vue (Legacy Review)
 *   - resources/js/components/Senses/SenseReview.vue (Sense Review)
 */

let inFlight = false;

/**
 * Run the authoritative rating recovery flow.
 *
 * @param {Object}   opts
 * @param {Function} opts.reloadQueue        Returns a Promise that settles
 *                                           when the authoritative queue
 *                                           reload completes. Called once.
 * @param {Function} opts.lockRating         Called immediately to disable
 *                                           rating buttons.
 * @param {Function} opts.unlockRating       Called after the reload settles
 *                                           (success or failure) to re-enable
 *                                           rating buttons.
 * @param {Function} opts.setRecoveryMessage Called after a successful reload
 *                                           ONLY IF no load error is present.
 * @param {Function} opts.preserveLoadError  Returns truthy if a load error is
 *                                           already visible (recovery message
 *                                           must not overwrite it).
 * @returns {Promise<void>} Resolves when the recovery flow is complete.
 *                          Never rejects.
 */
export function runAuthoritativeRatingRecovery(opts) {
    // Concurrent recovery guard: if a recovery is already in flight, the
    // second call is a no-op. This prevents a second reloadQueue() call.
    if (inFlight) {
        return Promise.resolve();
    }
    inFlight = true;

    // Immediately lock rating buttons. This happens synchronously before
    // the reload Promise is even created.
    opts.lockRating();

    // Call reloadQueue() exactly once. The helper does not care whether the
    // returned Promise resolves or rejects — both paths unlock afterwards.
    const reloadPromise = opts.reloadQueue();

    // Wait for the reload to settle. We use .then(..., ...) instead of
    // .then(...).catch(...) so that a rejection is handled in the same
    // chain and cannot escape as an unhandled rejection.
    return Promise.resolve(reloadPromise).then(
        () => {
            // Reload succeeded. Show the recovery message ONLY IF no load
            // error is currently visible (preserveLoadError returns falsy).
            // If a load error exists, the recovery message must not
            // overwrite it.
            if (!opts.preserveLoadError()) {
                opts.setRecoveryMessage();
            }
        },
        () => {
            // Reload failed. The load error is set by reloadQueue itself
            // (e.g. loadReviews/loadCards catch block). We deliberately do
            // NOT call setRecoveryMessage here — the load error takes
            // precedence and must remain visible.
        }
    ).finally(() => {
        // Always unlock after the reload settles, regardless of success or
        // failure. This guarantees the user can eventually interact again.
        opts.unlockRating();
        inFlight = false;
    });
}

/**
 * Reset the in-flight guard. Test-only helper.
 */
export function _resetForTest() {
    inFlight = false;
}
