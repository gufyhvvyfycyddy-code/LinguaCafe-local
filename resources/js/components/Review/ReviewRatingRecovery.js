/**
 * ReviewRatingRecovery.js — pure JS helper that orchestrates the
 * authoritative-queue reload after a rating request fails.
 *
 * Design constraints (Task 2000-13 / 2000-14):
 *   - No axios import. The caller supplies `reloadQueue`.
 *   - No ReviewLog / FSRS / lifecycle knowledge.
 *   - No statistics mutation.
 *   - Keeps the rating lock until the reload Promise settles.
 *   - Re-confirms the lock AFTER reloadQueue() returns, because the
 *     caller's reload function (e.g. Legacy loadReviews) may
 *     synchronously reset the lock state at its start.
 *   - Guards against concurrent recovery: the second call returns the
 *     SAME in-flight Promise (not a fresh Promise.resolve()), so the
 *     caller awaits the first recovery's completion.
 *   - Handles synchronous throws from reloadQueue(): unlocks, clears
 *     in-flight state, and never produces an unhandled rejection.
 *   - Never produces an unhandled rejection.
 *
 * Used by:
 *   - resources/js/components/Review/Review.vue (Legacy Review)
 *   - resources/js/components/Senses/SenseReview.vue (Sense Review)
 */

let inFlightPromise = null;

/**
 * Run the authoritative rating recovery flow.
 *
 * @param {Object}   opts
 * @param {Function} opts.reloadQueue        Returns a Promise that settles
 *                                           when the authoritative queue
 *                                           reload completes. Called once.
 *                                           May synchronously mutate the
 *                                           lock state (helper re-locks).
 * @param {Function} opts.lockRating         Called immediately to disable
 *                                           rating buttons, and again
 *                                           after reloadQueue() returns.
 * @param {Function} opts.unlockRating       Called after the reload settles
 *                                           (success or failure) to re-enable
 *                                           rating buttons.
 * @param {Function} opts.setRecoveryMessage Called after a successful reload
 *                                           ONLY IF no load error is present.
 * @param {Function} opts.preserveLoadError  Returns truthy if a load error is
 *                                           already visible (recovery message
 *                                           must not overwrite it).
 * @returns {Promise<void>} Resolves when the recovery flow is complete.
 *                          Never rejects. Concurrent calls return the same
 *                          in-flight Promise.
 */
export function runAuthoritativeRatingRecovery(opts) {
    // Concurrent recovery guard: if a recovery is already in flight,
    // return the SAME in-flight Promise so the caller awaits the first
    // recovery's completion rather than settling immediately.
    if (inFlightPromise) {
        return inFlightPromise;
    }

    // Immediately lock rating buttons. This happens synchronously before
    // the reload Promise is even created.
    opts.lockRating();

    let reloadPromise;

    try {
        // Call reloadQueue() exactly once. Wrap in Promise.resolve so a
        // non-Promise return value (or a sync throw) is normalized.
        reloadPromise = Promise.resolve(opts.reloadQueue());

        // Task 2000-14: reloadQueue() may synchronously reset the page
        // lock state (e.g. Legacy loadReviews sets ratingLoading=false
        // at its start). Re-confirm the lock AFTER reloadQueue() returns
        // so the lock survives the reload's synchronous prologue.
        opts.lockRating();
    } catch (error) {
        // reloadQueue() threw synchronously. Convert to a rejected
        // Promise so the single .then(..., ...) chain handles it without
        // producing an unhandled rejection.
        reloadPromise = Promise.reject(error);
    }

    // Wait for the reload to settle. We use .then(..., ...) instead of
    // .then(...).catch(...) so that a rejection is handled in the same
    // chain and cannot escape as an unhandled rejection.
    inFlightPromise = reloadPromise.then(
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
        // Clear in-flight state so the next recovery can run.
        inFlightPromise = null;
    });

    return inFlightPromise;
}

/**
 * Reset the in-flight guard. Test-only helper.
 */
export function _resetForTest() {
    inFlightPromise = null;
}
