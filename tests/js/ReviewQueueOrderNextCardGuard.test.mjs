// ReviewQueueOrderNextCardGuard.test.mjs
//
// DEV-QO-5 / DEV-QO-6 — Review.vue next_card consumption & stale-response guard
//
// Source-code guard tests focusing on the next_card consumption logic and
// the rating request race protection in Review.vue. These tests verify the
// presence and correctness of the behavioral patterns (not just keyword
// existence) for:
//   - next_card null handling (finish vs continue)
//   - next_card move-to-front when already in queue
//   - next_card insert-at-front when not in queue
//   - practiceMode bypass (no axios, no ReviewLog)
//   - stale response drop (seq mismatch)
//   - ratingLoading double-click protection
//   - dailyLimitSummary update from server response

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

const rateReviewBody = extractMethod('rateReview');

// 1. next_card null + empty queue → finish
test('next_card null with empty queue calls finish()', () => {
    assert.ok(rateReviewBody.includes('nextCard'),
        'rateReview must extract nextCard from response');
    // The null branch must check reviews.length === 0 and call finish()
    assert.ok(/nextCard.*?else\s*\{/s.test(rateReviewBody) || /else\s*\{[^}]*reviews\.length\s*===\s*0/.test(rateReviewBody),
        'rateReview must have an else branch for null nextCard');
    assert.ok(/reviews\.length\s*===\s*0/.test(rateReviewBody),
        'rateReview must check reviews.length === 0 when nextCard is null');
    assert.ok(/this\.finish\(\)/.test(rateReviewBody),
        'rateReview must call finish() when queue is empty');
});

// 2. next_card null + non-empty queue → continue (old response compat)
test('next_card null with non-empty queue continues with next()', () => {
    assert.ok(/reviews\.length\s*===\s*0[\s\S]*?else[\s\S]*?setTimeout\(this\.next/.test(rateReviewBody),
        'rateReview must call setTimeout(this.next, ...) when queue is not empty and nextCard is null');
});

// 3. next_card move-to-front when already in queue (no duplicate)
test('next_card already in queue is moved to front (no duplicate)', () => {
    assert.ok(/findIndex/.test(rateReviewBody),
        'rateReview must use findIndex to check if nextCard exists in queue');
    assert.ok(/review_card_id/.test(rateReviewBody),
        'rateReview must match by review_card_id');
    assert.ok(/existingIdx\s*!==\s*-1/.test(rateReviewBody),
        'rateReview must check existingIdx !== -1');
    assert.ok(/splice\(existingIdx,\s*1\)/.test(rateReviewBody),
        'rateReview must splice out the existing card before moving to front');
    assert.ok(/unshift\(existing\)/.test(rateReviewBody),
        'rateReview must unshift the existing card to front');
});

// 4. next_card insert-at-front when not in queue
test('next_card not in queue is inserted at front', () => {
    assert.ok(/unshift\(nextCard\)/.test(rateReviewBody),
        'rateReview must unshift(nextCard) when card is not in queue');
});

// 5. practiceMode bypass: no axios, no ReviewLog
test('practiceMode bypasses axios and ReviewLog', () => {
    assert.ok(/if\s*\(!this\.practiceMode\)/.test(rateReviewBody),
        'rateReview must check !this.practiceMode');
    // The else branch (practiceMode) must NOT call axios.post
    const elseMatch = rateReviewBody.match(/else\s*\{([\s\S]*?)\},?\s*$/);
    assert.ok(elseMatch, 'rateReview must have an else branch for practiceMode');
    const elseBranch = elseMatch[1];
    assert.ok(!/axios\.post/.test(elseBranch),
        'practiceMode else branch must NOT call axios.post (no ReviewLog)');
    assert.ok(/this\.reviews\.splice/.test(elseBranch),
        'practiceMode else branch must still splice the rated card');
});

// 6. Stale response drop (seq mismatch)
test('stale response is dropped when seq mismatches', () => {
    // In .then()
    assert.ok(/seq\s*!==\s*this\.ratingRequestSequence/.test(rateReviewBody),
        'rateReview .then() must check seq !== this.ratingRequestSequence');
    // In .catch()
    assert.ok(/catch[\s\S]*?seq\s*!==\s*this\.ratingRequestSequence/.test(rateReviewBody),
        'rateReview .catch() must also check seq !== this.ratingRequestSequence');
    // In .finally()
    assert.ok(/finally[\s\S]*?seq\s*===\s*this\.ratingRequestSequence/.test(rateReviewBody),
        'rateReview .finally() must check seq === this.ratingRequestSequence before resetting ratingLoading');
});

// 7. ratingLoading double-click protection
test('ratingLoading prevents double-click duplicate ReviewLog', () => {
    assert.ok(/if\s*\(\s*this\.ratingLoading\s*\)\s*\{[\s\S]*?return/.test(rateReviewBody),
        'rateReview must return early if ratingLoading is true');
    assert.ok(/this\.ratingLoading\s*=\s*true/.test(rateReviewBody),
        'rateReview must set ratingLoading = true before axios call');
});

// 8. dailyLimitSummary updated from server response
test('dailyLimitSummary is updated from server response', () => {
    assert.ok(/response\.data\.summary/.test(rateReviewBody),
        'rateReview must read response.data.summary');
    assert.ok(/this\.dailyLimitSummary\s*=\s*response\.data\.summary/.test(rateReviewBody),
        'rateReview must assign response.data.summary to this.dailyLimitSummary');
});

// 9. ignoreDailyLimits passed to /reviews/rate
test('ignoreDailyLimits is passed to /reviews/rate payload', () => {
    assert.ok(/payload\.ignoreDailyLimits\s*=\s*true/.test(rateReviewBody),
        'rateReview must set payload.ignoreDailyLimits = true when flag is set');
    assert.ok(/if\s*\(\s*this\.ignoreDailyLimits\s*\)/.test(rateReviewBody),
        'rateReview must check this.ignoreDailyLimits before adding to payload');
});

// 10. Current card removed before next_card processing
test('current card is spliced before next_card processing', () => {
    // The splice of current card must come BEFORE the nextCard logic
    const spliceIdx = rateReviewBody.indexOf('this.reviews.splice(this.currentReviewIndex, 1)[0]');
    const nextCardIdx = rateReviewBody.indexOf('const nextCard');
    assert.ok(spliceIdx !== -1, 'rateReview must splice the current card');
    assert.ok(nextCardIdx !== -1, 'rateReview must define nextCard');
    assert.ok(spliceIdx < nextCardIdx,
        'rateReview must splice current card BEFORE processing nextCard');
});

// 11. correctReviews counter still incremented
test('correctReviews counter is still incremented', () => {
    assert.ok(/this\.correctReviews\s*\+\+/.test(rateReviewBody),
        'rateReview must still increment correctReviews');
});

// 12. No Math.random used to pick next card
test('no Math.random used to pick next card in rateReview', () => {
    assert.ok(!/Math\.random/.test(rateReviewBody),
        'rateReview must not use Math.random to pick next card');
});

console.log(`\n${passed} tests passed.`);
