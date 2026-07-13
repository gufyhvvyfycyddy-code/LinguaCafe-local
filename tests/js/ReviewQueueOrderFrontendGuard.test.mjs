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

console.log(`\n${passed} tests passed.`);
