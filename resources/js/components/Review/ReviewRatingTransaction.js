import { runAuthoritativeRatingRecovery } from './ReviewRatingRecovery.js';

export function createReviewRatingTransaction(
    recoverRating = runAuthoritativeRatingRecovery,
) {
    let sequence = 0;

    return Object.freeze({
        begin() {
            sequence += 1;
            return sequence;
        },
        invalidate() {
            sequence += 1;
        },
        isCurrent(candidate) {
            return Number.isInteger(candidate) && candidate === sequence;
        },
        recover(options) {
            return recoverRating(options);
        },
    });
}
