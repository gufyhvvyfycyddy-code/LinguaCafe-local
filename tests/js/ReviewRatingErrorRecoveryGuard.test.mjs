// ReviewRatingErrorRecoveryGuard.test.mjs
//
// Task 2000-12 Track C (DEV-QO-4) — Source-code guard tests for the rating
// error-recovery fix in Review.vue and SenseReview.vue.
//
// When a rating POST fails, the client cannot know whether the server
// succeeded (response lost) or truly failed. The old code had three bugs:
//   1. Review.vue catch set `this.finished = true`, sending the user to
//      the "review complete" page even though cards might still exist.
//   2. Review.vue incremented `correctReviews` and called `countReadWords()`
//      BEFORE the server confirmed, so a failed request left permanently
//      inflated statistics.
//   3. SenseReview.vue catch set `this.error` but immediately reset
//      `this.rating = false` in finally, allowing the user to re-rate the
//      possibly-already-rated card — risking a duplicate ReviewLog.
//
// The fix:
//   - Review.vue: move stat increments into .then(); catch resets
//     animation, shows a recovery message, reloads the authoritative
//     queue via loadReviews(), and keeps ratingLoading=true until the
//     reload settles.
//   - SenseReview.vue: catch calls loadCards() to reload the authoritative
//     queue, keeps this.rating=true until reload settles, and sets a
//     recovery message.
//
// Tests:
//   Review.vue:
//     1.  catch does NOT set finished=true.
//     2.  catch does NOT set finished=true even indirectly.
//     3.  correctReviews++ is inside .then(), not before the request.
//     4.  countReadWords() is inside .then(), not before the request.
//     5.  catch calls loadReviews() to reload the authoritative queue.
//     6.  catch resets intoTheCorrectDeckAnimation to false.
//     7.  catch resets backToDeckAnimation to false.
//     8.  catch resets newCardAnimation to false.
//     9.  catch sets a recovery error message.
//     10. loadReviews .then resets ratingLoading to false.
//     11. loadReviews .catch resets ratingLoading to false.
//     12. v-alert for reviewError && !finished exists (persistent error).
//     13. success path clears reviewError.
//
//   SenseReview.vue:
//     14. catch calls loadCards() to reload the authoritative queue.
//     15. catch does NOT immediately set this.rating=false (no finally).
//     16. catch sets a recovery error message after loadCards settles.
//     17. success path sets this.rating=false and clears this.error.
//     18. no .finally that unconditionally resets this.rating=false.

import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const REVIEW_PATH = join(
    __dirname, '..', '..',
    'resources', 'js', 'components', 'Review', 'Review.vue'
);
const SENSE_REVIEW_PATH = join(
    __dirname, '..', '..',
    'resources', 'js', 'components', 'Senses', 'SenseReview.vue'
);

let passed = 0;
function test(name, fn) {
    try {
        fn();
        passed++;
        console.log(`  \u221a ${name}`);
    } catch (e) {
        console.error('FAIL: ' + name);
        console.error(e.message);
        process.exitCode = 1;
    }
}

const reviewSource = existsSync(REVIEW_PATH)
    ? readFileSync(REVIEW_PATH, 'utf-8')
    : '';
const senseReviewSource = existsSync(SENSE_REVIEW_PATH)
    ? readFileSync(SENSE_REVIEW_PATH, 'utf-8')
    : '';

// Helper: extract the body of a named method from Vue source.
function extractMethod(source, name) {
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
    return source.slice(start, i - 1);
}

// Helper: extract the MAIN .catch block body from an axios chain.
// The main catch is the one that takes an `error` parameter, e.g.
// `.catch((error) => { ... })`. Inner catches like `.catch(() => {})`
// are skipped.
function extractMainCatchBody(methodBody) {
    // Find all .catch( occurrences and pick the one with (error) parameter.
    let searchFrom = 0;
    while (true) {
        const catchIdx = methodBody.indexOf('.catch(', searchFrom);
        if (catchIdx === -1) return '';
        // Check if this catch has an (error) parameter
        const afterCatch = methodBody.slice(catchIdx, catchIdx + 30);
        if (afterCatch.includes('(error)') || afterCatch.includes('(err)')) {
            // Found the main catch. Extract its body.
            const arrowStart = methodBody.indexOf('=>', catchIdx);
            if (arrowStart === -1) return '';
            let i = arrowStart;
            while (i < methodBody.length && methodBody[i] !== '{') i++;
            if (i >= methodBody.length) return '';
            const start = i + 1;
            let depth = 1;
            i = start;
            while (i < methodBody.length && depth > 0) {
                if (methodBody[i] === '{') depth++;
                else if (methodBody[i] === '}') depth--;
                i++;
            }
            return methodBody.slice(start, i - 1);
        }
        searchFrom = catchIdx + 1;
    }
}

// Helper: extract the FIRST .then block body from an axios chain.
function extractFirstThenBody(methodBody) {
    const thenIdx = methodBody.indexOf('.then(');
    if (thenIdx === -1) return '';
    const arrowStart = methodBody.indexOf('=>', thenIdx);
    if (arrowStart === -1) return '';
    let i = arrowStart;
    while (i < methodBody.length && methodBody[i] !== '{') i++;
    if (i >= methodBody.length) return '';
    const start = i + 1;
    let depth = 1;
    i = start;
    while (i < methodBody.length && depth > 0) {
        if (methodBody[i] === '{') depth++;
        else if (methodBody[i] === '}') depth--;
        i++;
    }
    return methodBody.slice(start, i - 1);
}

// ==================== Review.vue tests ====================

const reviewRateMethod = extractMethod(reviewSource, 'rateReview');
const reviewRateCatch = extractMainCatchBody(reviewRateMethod);
const reviewRateThen = extractFirstThenBody(reviewRateMethod);
const reviewLoadMethod = extractMethod(reviewSource, 'loadReviews');

test('Review.vue rateReview method exists', () => {
    assert.ok(reviewRateMethod.length > 0, 'rateReview method not found');
});

test('Review.vue catch does NOT set finished=true', () => {
    assert.ok(
        !reviewRateCatch.includes('this.finished = true'),
        'catch must NOT set finished=true (would wrongly enter "review complete" state)'
    );
});

test('Review.vue catch calls loadReviews() to reload authoritative queue', () => {
    assert.ok(
        reviewRateCatch.includes('this.loadReviews()'),
        'catch must call loadReviews() to reload the authoritative queue'
    );
});

test('Review.vue catch keeps ratingLoading=true during reload', () => {
    // The catch should set ratingLoading=true after calling loadReviews
    // so buttons stay disabled until the reload settles.
    assert.ok(
        reviewRateCatch.includes('this.ratingLoading = true'),
        'catch must set ratingLoading=true to keep buttons disabled during reload'
    );
});

test('Review.vue catch resets intoTheCorrectDeckAnimation', () => {
    assert.ok(
        reviewRateCatch.includes('this.intoTheCorrectDeckAnimation = false'),
        'catch must reset intoTheCorrectDeckAnimation to false'
    );
});

test('Review.vue catch resets backToDeckAnimation', () => {
    assert.ok(
        reviewRateCatch.includes('this.backToDeckAnimation = false'),
        'catch must reset backToDeckAnimation to false'
    );
});

test('Review.vue catch resets newCardAnimation', () => {
    assert.ok(
        reviewRateCatch.includes('this.newCardAnimation = false'),
        'catch must reset newCardAnimation to false'
    );
});

test('Review.vue catch sets recovery error message', () => {
    assert.ok(
        reviewRateCatch.includes('评分结果状态不确定'),
        'catch must set a recovery error message mentioning 评分结果状态不确定'
    );
    assert.ok(
        reviewRateCatch.includes('重新加载'),
        'catch must mention 重新加载 in the error message'
    );
});

test('Review.vue correctReviews++ is inside .then() success path', () => {
    // The increment must be in .then(), not before the request.
    const beforeRequest = reviewRateMethod.split('axios.post')[0];
    assert.ok(
        !beforeRequest.includes('this.correctReviews ++') &&
        !beforeRequest.includes('this.correctReviews++'),
        'correctReviews++ must NOT be before axios.post (must be in .then() success path)'
    );
    assert.ok(
        reviewRateThen.includes('this.correctReviews ++') ||
        reviewRateThen.includes('this.correctReviews++'),
        'correctReviews++ must be inside .then() success path'
    );
});

test('Review.vue countReadWords() is inside .then() success path', () => {
    const beforeRequest = reviewRateMethod.split('axios.post')[0];
    assert.ok(
        !beforeRequest.includes('this.countReadWords()'),
        'countReadWords() must NOT be before axios.post (must be in .then() success path)'
    );
    assert.ok(
        reviewRateThen.includes('this.countReadWords()'),
        'countReadWords() must be inside .then() success path'
    );
});

test('Review.vue .then() clears reviewError on success', () => {
    assert.ok(
        reviewRateThen.includes("this.reviewError = ''"),
        '.then() must clear reviewError on successful rating'
    );
});

test('Review.vue loadReviews .then resets ratingLoading to false', () => {
    assert.ok(
        reviewLoadMethod.includes('this.ratingLoading = false'),
        'loadReviews must reset ratingLoading to false after reload completes'
    );
});

test('Review.vue has persistent error alert for !finished state', () => {
    assert.ok(
        reviewSource.includes('v-if="reviewError && !finished"'),
        'Template must have a v-alert with v-if="reviewError && !finished" for persistent error display'
    );
});

// ==================== SenseReview.vue tests ====================

const senseRateMethod = extractMethod(senseReviewSource, 'rate');
const senseRateCatch = extractMainCatchBody(senseRateMethod);
const senseRateThen = extractFirstThenBody(senseRateMethod);

test('SenseReview.vue rate method exists', () => {
    assert.ok(senseRateMethod.length > 0, 'rate method not found');
});

test('SenseReview.vue catch calls loadCards() to reload authoritative queue', () => {
    assert.ok(
        senseRateCatch.includes('this.loadCards()'),
        'catch must call loadCards() to reload the authoritative queue'
    );
});

test('SenseReview.vue catch does NOT immediately set this.rating=false', () => {
    // The catch should NOT have a direct (top-level) this.rating = false
    // statement that executes before loadCards settles. Instead,
    // this.rating = false must be inside a loadCards().finally() callback.
    //
    // Verify: this.rating = false must appear AFTER this.loadCards() in
    // the catch body (i.e., inside the loadCards callback). If it appears
    // before loadCards(), it's a direct statement that unlocks buttons
    // immediately — which is the bug we're fixing.
    const loadCardsIdx = senseRateCatch.indexOf('this.loadCards()');
    const ratingFalseIdx = senseRateCatch.indexOf('this.rating = false');
    assert.ok(loadCardsIdx !== -1, 'catch must call this.loadCards()');
    assert.ok(ratingFalseIdx !== -1, 'catch must eventually set this.rating = false');
    assert.ok(
        ratingFalseIdx > loadCardsIdx,
        'this.rating = false must appear AFTER this.loadCards() (inside the callback), not before it'
    );
    // Additionally verify .finally( wraps the this.rating = false.
    const finallyIdx = senseRateCatch.indexOf('.finally(');
    assert.ok(
        finallyIdx !== -1 && finallyIdx < ratingFalseIdx,
        'this.rating = false must be inside a .finally() callback'
    );
});

test('SenseReview.vue catch sets recovery error message after loadCards', () => {
    assert.ok(
        senseRateCatch.includes('评分结果状态不确定'),
        'catch must set a recovery error message mentioning 评分结果状态不确定'
    );
    assert.ok(
        senseRateCatch.includes('重新加载'),
        'catch must mention 重新加载 in the error message'
    );
});

test('SenseReview.vue .then() success path sets this.rating=false', () => {
    assert.ok(
        senseRateThen.includes('this.rating = false'),
        '.then() success path must set this.rating=false to unlock buttons after confirmed rating'
    );
});

test('SenseReview.vue .then() success path clears this.error', () => {
    assert.ok(
        senseRateThen.includes("this.error = ''"),
        '.then() success path must clear this.error on successful rating'
    );
});

test('SenseReview.vue rate() does NOT have .finally that unconditionally resets rating', () => {
    // The old code had `.finally(() => { this.rating = false; })` which
    // unlocked buttons even on error. The new code must NOT have this.
    assert.ok(
        !senseRateMethod.includes('.finally(') ||
        !senseRateMethod.match(/\.finally\([^}]*this\.rating\s*=\s*false/s),
        'rate() must NOT have a .finally that unconditionally resets this.rating=false'
    );
});

test('SenseReview.vue reviewedCount++ remains in .then() success path only', () => {
    assert.ok(
        senseRateThen.includes('this.reviewedCount++'),
        'reviewedCount++ must be in .then() success path'
    );
    const beforeRequest = senseRateMethod.split('axios.post')[0];
    assert.ok(
        !beforeRequest.includes('this.reviewedCount++'),
        'reviewedCount++ must NOT be before axios.post'
    );
});

console.log(`\n${passed} tests passed.`);
