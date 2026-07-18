import assert from 'node:assert/strict';
import test from 'node:test';

import { createReviewApiClient } from '../../resources/js/components/Review/ReviewApiClient.js';

test('formal review client preserves every existing request contract', async () => {
    const calls = [];
    const response = { data: { ok: true } };
    const http = {
        get(url, config) {
            calls.push(['get', url, config]);
            return Promise.resolve(response);
        },
        post(url, payload) {
            calls.push(['post', url, payload]);
            return Promise.resolve(response);
        },
    };
    const client = createReviewApiClient(http);

    assert.equal(await client.loadLegacyQueue({ bookId: 7 }), response);
    assert.equal(await client.rateLegacyCard({ reviewCardId: 9, rating: 'good' }), response);
    assert.equal(await client.loadSenseQueue({ ignoreDailyLimits: true }), response);
    assert.equal(await client.rateSenseCard(11, { rating: 'hard' }), response);
    assert.equal(await client.loadSenseIntervalPreview(11), response);
    assert.equal(await client.loadSenseSessionActions('session-id'), response);
    assert.equal(
        await client.undoSenseReviewAction(13, { review_session_id: 'session-id' }),
        response,
    );

    assert.deepEqual(calls, [
        ['post', '/reviews', { bookId: 7 }],
        ['post', '/reviews/rate', { reviewCardId: 9, rating: 'good' }],
        ['get', '/reviews/senses', { params: { ignoreDailyLimits: true } }],
        ['post', '/reviews/senses/11/rate', { rating: 'hard' }],
        ['get', '/reviews/senses/11/interval-preview', undefined],
        ['get', '/reviews/senses/session-actions', { params: { review_session_id: 'session-id' } }],
        ['post', '/reviews/senses/review-actions/13/undo', { review_session_id: 'session-id' }],
    ]);
});

test('numeric path ids fail before the client can issue a request', () => {
    let called = false;
    const http = {
        get() { called = true; },
        post() { called = true; },
    };
    const client = createReviewApiClient(http);

    assert.throws(() => client.rateSenseCard(0, {}), /reviewCardId/);
    assert.throws(() => client.loadSenseIntervalPreview('bad'), /reviewCardId/);
    assert.throws(() => client.undoSenseReviewAction(-1, {}), /reviewLogId/);
    assert.equal(called, false);
});

test('http rejections remain unchanged', async () => {
    const failure = new Error('network failure');
    const client = createReviewApiClient({
        get() { return Promise.reject(failure); },
        post() { return Promise.reject(failure); },
    });

    await assert.rejects(client.loadSenseQueue({}), error => error === failure);
    await assert.rejects(client.rateLegacyCard({}), error => error === failure);
});
