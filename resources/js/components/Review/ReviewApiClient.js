import axios from 'axios';

function positiveId(value, name) {
    const id = Number(value);
    if (!Number.isInteger(id) || id <= 0) {
        throw new TypeError(`${name} must be a positive integer`);
    }
    return id;
}

export function createReviewApiClient(http = axios) {
    return Object.freeze({
        loadLegacyQueue(payload) {
            return http.post('/reviews', payload);
        },
        rateLegacyCard(payload) {
            return http.post('/reviews/rate', payload);
        },
        loadSenseQueue(params) {
            return http.get('/reviews/senses', { params });
        },
        rateSenseCard(reviewCardId, payload) {
            const id = positiveId(reviewCardId, 'reviewCardId');
            return http.post(`/reviews/senses/${id}/rate`, payload);
        },
        loadSenseIntervalPreview(reviewCardId) {
            const id = positiveId(reviewCardId, 'reviewCardId');
            return http.get(`/reviews/senses/${id}/interval-preview`);
        },
        loadSenseSessionActions(reviewSessionId) {
            return http.get('/reviews/senses/session-actions', {
                params: { review_session_id: reviewSessionId },
            });
        },
        undoSenseReviewAction(reviewLogId, payload) {
            const id = positiveId(reviewLogId, 'reviewLogId');
            return http.post(`/reviews/senses/review-actions/${id}/undo`, payload);
        },
    });
}
