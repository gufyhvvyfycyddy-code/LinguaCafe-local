// ReviewQueueOrderFrontendGuard.test.mjs
//
// ADR-0015 V1 — Review Queue Order (Frontend convergence)
//
// Source-code guard tests for Review.vue. Verifies the legacy review page
// no longer uses Math.random() to pick the next card and instead uses the
// queue-first-card (index 0) approach, so the frontend order matches the
// backend Queue Order returned by /reviews.
//
// Tests:
//   1.  No Math.random in Review.vue.
//   2.  No Math.floor in Review.vue.
//   3.  No shuffle in Review.vue.
//   4.  currentReviewIndex is set to 0 in next() (queue first card).
//   5.  ADR-0015 reference exists in the comment.
//   6.  currentReviewIndex still initialized to -1 on reset.
//   7.  loadReviews still POSTs to /reviews.
//   8.  rateReview still POSTs to /reviews/rate.
//   9.  DEV-QO-5: rateReview passes ignoreDailyLimits to /reviews/rate.
//  10.  DEV-QO-5: rateReview reads response.data.next_card.
//  11.  DEV-QO-5: rateReview reads response.data.summary.
//  12.  DEV-QO-5: next_card insert/move-to-front logic exists.
//  13.  DEV-QO-6: ratingLoading flag exists in data().
//  14.  DEV-QO-6: ratingRequestSequence flag exists in data().
//  15.  DEV-QO-6: rateReview checks ratingLoading before proceeding.
//  16.  DEV-QO-6: rateReview increments ratingRequestSequence.
//  17.  DEV-QO-6: stale response check (seq !== this.ratingRequestSequence).
//  18.  DEV-QO-6: loadReviews increments ratingRequestSequence.
//  19.  DEV-QO-6: beforeDestroy increments ratingRequestSequence.
//  20.  DEV-QO-6: enableIgnoreDailyLimits increments ratingRequestSequence.
//  21.  DEV-QO-6: ratingLoading restored to false in finally.

import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const COMPONENT_PATH = join(
    __dirname, '..', '..',
    'resources', 'js', 'components', 'Review', 'Review.vue'
);

let passed = 0;
function test(name, fn) {
    try {
        fn();
        passed++;
        console.log(`  ✓ ${name}`);
    } catch (e) {
        console.error('FAIL: ' + name);
        console.error(e.message);
        process.exitCode = 1;
    }
}

const source = existsSync(COMPONENT_PATH) ? readFileSync(COMPONENT_PATH, 'utf-8') : '';

// Helper: extract the body of a named method from the Vue source.
function extractMethod(name) {
    const re = new RegExp(name + '\\s*\\([^)]*\\)\\s*\\{');
    const m = source.match(re);
    if (!m) return '';
    const start = m.index + m[0].length;
    let depth = 1;
    let i = start;
    while (i < source.length && depth > 0) {
        if (source[i] === '{') depth++;
        else if (source[i] === '}') depth--;
        i++;
    }
    return source.slice(m.index, i);
}

// 1. No Math.random in Review.vue
test('no Math.random in Review.vue', () => {
    assert.ok(!/Math\.random/.test(source), 'Review.vue must not use Math.random');
});

// 2. No Math.floor in Review.vue
test('no Math.floor in Review.vue', () => {
    assert.ok(!/Math\.floor/.test(source), 'Review.vue must not use Math.floor');
});

// 3. No shuffle in Review.vue
test('no shuffle in Review.vue', () => {
    assert.ok(!/shuffle/.test(source), 'Review.vue must not shuffle');
});

// 4. currentReviewIndex is set to 0 in next() (queue first card)
test('next() sets currentReviewIndex to 0 (queue first card)', () => {
    const body = extractMethod('next');
    assert.ok(body.includes('this.currentReviewIndex = 0'),
        'next() must set currentReviewIndex = 0 instead of random');
});

// 5. ADR-0015 reference exists in the comment
test('ADR-0015 reference exists in next() comment', () => {
    const body = extractMethod('next');
    assert.ok(body.includes('ADR-0015'), 'next() comment must reference ADR-0015');
});

// 6. currentReviewIndex still initialized to -1 on reset
test('currentReviewIndex initialized to -1 on reset', () => {
    assert.ok(/currentReviewIndex\s*=\s*-1/.test(source),
        'currentReviewIndex must still be reset to -1');
});

// 7. loadReviews still POSTs to /reviews
test('loadReviews POSTs to /reviews', () => {
    const body = extractMethod('loadReviews');
    assert.ok(body.includes("'/reviews'") || body.includes('"/reviews"'),
        'loadReviews must still POST to /reviews');
});

// 8. rateReview still POSTs to /reviews/rate
test('rateReview POSTs to /reviews/rate', () => {
    const body = extractMethod('rateReview');
    assert.ok(body.includes('/reviews/rate'),
        'rateReview must still POST to /reviews/rate');
});

// 9. DEV-QO-5: rateReview passes ignoreDailyLimits to /reviews/rate
test('rateReview passes ignoreDailyLimits to /reviews/rate', () => {
    const body = extractMethod('rateReview');
    assert.ok(body.includes('ignoreDailyLimits'),
        'rateReview must pass ignoreDailyLimits to /reviews/rate');
    assert.ok(/payload\.ignoreDailyLimits\s*=/.test(body),
        'rateReview must set payload.ignoreDailyLimits when flag is true');
});

// 10. DEV-QO-5: rateReview reads response.data.next_card
test('rateReview reads response.data.next_card', () => {
    const body = extractMethod('rateReview');
    assert.ok(body.includes('response.data.next_card') || body.includes('response.data && response.data.next_card'),
        'rateReview must read response.data.next_card from server response');
});

// 11. DEV-QO-5: rateReview reads response.data.summary
test('rateReview reads response.data.summary', () => {
    const body = extractMethod('rateReview');
    assert.ok(body.includes('response.data.summary') || body.includes('response.data && response.data.summary'),
        'rateReview must read response.data.summary from server response');
    assert.ok(/this\.dailyLimitSummary\s*=\s*response\.data\.summary/.test(body),
        'rateReview must update dailyLimitSummary from server response');
});

// 12. DEV-QO-5: next_card insert/move-to-front logic exists
test('rateReview has next_card insert/move-to-front logic', () => {
    const body = extractMethod('rateReview');
    assert.ok(body.includes('findIndex'),
        'rateReview must use findIndex to locate next_card in remaining queue');
    assert.ok(body.includes('unshift'),
        'rateReview must use unshift to insert/move next_card to front');
});

// 13. DEV-QO-6: ratingLoading flag exists in data()
test('ratingLoading flag exists in data()', () => {
    assert.ok(/ratingLoading\s*:\s*false/.test(source),
        'data() must include ratingLoading: false');
});

// 14. DEV-QO-6: ratingRequestSequence flag exists in data()
test('ratingRequestSequence flag exists in data()', () => {
    assert.ok(/ratingRequestSequence\s*:\s*0/.test(source),
        'data() must include ratingRequestSequence: 0');
});

// 15. DEV-QO-6: rateReview checks ratingLoading before proceeding
test('rateReview checks ratingLoading before proceeding', () => {
    const body = extractMethod('rateReview');
    assert.ok(/if\s*\(\s*this\.ratingLoading\s*\)/.test(body),
        'rateReview must check ratingLoading and return early if true');
});

// 16. DEV-QO-6: rateReview increments ratingRequestSequence
test('rateReview increments ratingRequestSequence', () => {
    const body = extractMethod('rateReview');
    assert.ok(/\+\+this\.ratingRequestSequence/.test(body),
        'rateReview must increment ratingRequestSequence');
});

// 17. DEV-QO-6: stale response check (seq !== this.ratingRequestSequence)
test('rateReview has stale response check', () => {
    const body = extractMethod('rateReview');
    assert.ok(/seq\s*!==\s*this\.ratingRequestSequence/.test(body),
        'rateReview must check seq !== this.ratingRequestSequence to drop stale responses');
});

// 18. DEV-QO-6: loadReviews increments ratingRequestSequence
test('loadReviews increments ratingRequestSequence', () => {
    const body = extractMethod('loadReviews');
    assert.ok(/this\.ratingRequestSequence\+\+/.test(body),
        'loadReviews must increment ratingRequestSequence to invalidate in-flight requests');
});

// 19. DEV-QO-6: beforeDestroy increments ratingRequestSequence
test('beforeDestroy increments ratingRequestSequence', () => {
    // beforeDestroy uses "beforeDestroy: function () {" syntax, so we
    // search the whole source for the pattern near beforeDestroy.
    const idx = source.indexOf('beforeDestroy');
    assert.ok(idx !== -1, 'beforeDestroy must exist');
    // Search within 300 chars after beforeDestroy for the increment.
    const region = source.slice(idx, idx + 300);
    assert.ok(/this\.ratingRequestSequence\+\+/.test(region),
        'beforeDestroy must increment ratingRequestSequence to invalidate in-flight requests');
});

// 20. DEV-QO-6: enableIgnoreDailyLimits increments ratingRequestSequence
test('enableIgnoreDailyLimits increments ratingRequestSequence', () => {
    const body = extractMethod('enableIgnoreDailyLimits');
    assert.ok(/this\.ratingRequestSequence\+\+/.test(body),
        'enableIgnoreDailyLimits must increment ratingRequestSequence to invalidate in-flight requests');
});

// 21. DEV-QO-6: ratingLoading restored to false in finally
test('ratingLoading restored to false in finally', () => {
    const body = extractMethod('rateReview');
    assert.ok(/finally/.test(body),
        'rateReview must use finally to restore ratingLoading');
    assert.ok(/this\.ratingLoading\s*=\s*false/.test(body),
        'rateReview must set ratingLoading = false in finally');
});

console.log(`\n${passed} tests passed.`);
