import assert from 'node:assert/strict';
import test from 'node:test';

import { createReviewRatingTransaction } from '../../resources/js/components/Review/ReviewRatingTransaction.js';

test('sequence identity invalidates every earlier request', () => {
    const transaction = createReviewRatingTransaction(() => Promise.resolve());

    const first = transaction.begin();
    assert.equal(transaction.isCurrent(first), true);

    const second = transaction.begin();
    assert.equal(transaction.isCurrent(first), false);
    assert.equal(transaction.isCurrent(second), true);

    transaction.invalidate();
    assert.equal(transaction.isCurrent(second), false);
    assert.equal(transaction.isCurrent('3'), false);
});

test('transactions keep sequence state per mounted page', () => {
    const firstPage = createReviewRatingTransaction(() => Promise.resolve());
    const secondPage = createReviewRatingTransaction(() => Promise.resolve());

    const firstSequence = firstPage.begin();
    const secondSequence = secondPage.begin();
    firstPage.invalidate();

    assert.equal(firstPage.isCurrent(firstSequence), false);
    assert.equal(secondPage.isCurrent(secondSequence), true);
});

test('recovery options are delegated unchanged', async () => {
    const calls = [];
    const expected = Promise.resolve();
    const transaction = createReviewRatingTransaction((options) => {
        calls.push(options);
        return expected;
    });
    const options = { reloadQueue() {}, lockRating() {} };

    assert.equal(transaction.recover(options), expected);
    await expected;
    assert.deepEqual(calls, [options]);
});
