// ReviewRatingErrorRecoveryGuard.test.mjs
//
// Task 2000-13 / 2000-14 — Source-code STRUCTURAL guard tests for the
// rating error-recovery refactor in Review.vue and SenseReview.vue.
//
// These are STRUCTURE guards only. Executable BEHAVIOR tests live in
// ReviewRatingRecovery.test.mjs, which imports the actual helper and
// exercises it with deferred Promises. Do not conflate the two.
//
// Guards:
//   Review.vue:
//     1.  imports runAuthoritativeRatingRecovery helper
//     2.  rateReview catch calls runAuthoritativeRatingRecovery
//     3.  catch does NOT set finished=true
//     4.  correctReviews++ is inside .then(), not before the request
//     5.  countReadWords() is inside .then(), not before the request
//     6.  loadReviews() returns a Promise (return axios.post)
//     7.  .then() clears reviewError on success
//     8.  has persistent error alert for !finished state
//     9.  four rating buttons bind :disabled="ratingLoading" (Task 2000-14)
//     10. next_card logic exists in .then() success path
//
//   SenseReview.vue:
//     11. imports runAuthoritativeRatingRecovery helper
//     12. rate catch calls runAuthoritativeRatingRecovery
//     13. catch does NOT immediately set this.rating=false (no finally)
//     14. .then() success path sets this.rating=false
//     15. .then() success path clears this.error
//     16. reviewedCount++ remains in .then() success path only
//     17. rate() does NOT have .finally that unconditionally resets rating
//
//   Helper (Task 2000-14):
//     18. concurrent calls return same in-flight Promise (not Promise.resolve())
//     19. helper re-locks after reloadQueue() returns
//     20. helper handles sync throw via try/catch

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
const HELPER_PATH = join(
    __dirname, '..', '..',
    'resources', 'js', 'components', 'Review', 'ReviewRatingRecovery.js'
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
const helperSource = existsSync(HELPER_PATH)
    ? readFileSync(HELPER_PATH, 'utf-8')
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
function extractMainCatchBody(methodBody) {
    let searchFrom = 0;
    while (true) {
        const catchIdx = methodBody.indexOf('.catch(', searchFrom);
        if (catchIdx === -1) return '';
        const afterCatch = methodBody.slice(catchIdx, catchIdx + 30);
        if (afterCatch.includes('(error)') || afterCatch.includes('(err)')) {
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

// ==================== Helper module exists ====================

test('ReviewRatingRecovery.js helper module exists and exports runAuthoritativeRatingRecovery', () => {
    assert.ok(helperSource.length > 0, 'ReviewRatingRecovery.js must exist');
    assert.ok(
        helperSource.includes('export function runAuthoritativeRatingRecovery'),
        'helper must export runAuthoritativeRatingRecovery'
    );
});

test('ReviewRatingRecovery.js does NOT import axios', () => {
    // Check for actual import/require statements, not comments mentioning axios.
    const hasAxiosImport = /^\s*import\s+.*axios/m.test(helperSource) ||
        /require\(['"][^'"]*axios['"]\)/.test(helperSource);
    assert.ok(
        !hasAxiosImport,
        'helper must NOT import axios (caller supplies reloadQueue)'
    );
});

// ==================== Review.vue tests ====================

const reviewRateMethod = extractMethod(reviewSource, 'rateReview');
const reviewRateCatch = extractMainCatchBody(reviewRateMethod);
const reviewRateThen = extractFirstThenBody(reviewRateMethod);
const reviewLoadMethod = extractMethod(reviewSource, 'loadReviews');

test('Review.vue imports shared rating request coordinator', () => {
    assert.ok(
        reviewSource.includes('createRatingRequestCoordinator'),
        'Review.vue must import createRatingRequestCoordinator'
    );
});

test('Review.vue rateReview catch delegates recovery to coordinator', () => {
    assert.ok(
        reviewRateCatch.includes('ratingRequestCoordinator.recover'),
        'rateReview catch must delegate to ratingRequestCoordinator.recover'
    );
});

test('Review.vue catch does NOT set finished=true', () => {
    assert.ok(
        !reviewRateCatch.includes('this.finished = true'),
        'catch must NOT set finished=true (would wrongly enter "review complete" state)'
    );
});

test('Review.vue correctReviews++ is inside .then() success path', () => {
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

test('Review.vue loadReviews() returns a Promise (return axios.post)', () => {
    // DEV-RECOVERY-2 (Task 2000-13): loadReviews must return the axios
    // Promise so the recovery helper can reliably await success/failure.
    assert.ok(
        reviewLoadMethod.includes('return axios.post') ||
        reviewLoadMethod.includes('return axios.get'),
        'loadReviews must return the axios Promise (return axios.post/get)'
    );
});

test('Review.vue .then() clears reviewError on success', () => {
    assert.ok(
        reviewRateThen.includes("this.reviewError = ''"),
        '.then() must clear reviewError on successful rating'
    );
});

test('Review.vue has persistent error alert for !finished state', () => {
    assert.ok(
        reviewSource.includes('v-if="reviewError && !finished"'),
        'Template must have a v-alert with v-if="reviewError && !finished" for persistent error display'
    );
});

// ==================== Task 2000-14: Legacy button disabled guards ====================

test('Review.vue "忘了" button binds :disabled="ratingLoading"', () => {
    assert.ok(
        /:disabled="ratingLoading"[^>]*@click="rateReview\('again'\)"/.test(reviewSource) ||
        /@click="rateReview\('again'\)"[^>]*:disabled="ratingLoading"/.test(reviewSource),
        '忘了 button must bind :disabled="ratingLoading"'
    );
});

test('Review.vue "勉强记得" button binds :disabled="ratingLoading"', () => {
    assert.ok(
        /:disabled="ratingLoading"[^>]*@click="rateReview\('hard'\)"/.test(reviewSource) ||
        /@click="rateReview\('hard'\)"[^>]*:disabled="ratingLoading"/.test(reviewSource),
        '勉强记得 button must bind :disabled="ratingLoading"'
    );
});

test('Review.vue "记得" button binds :disabled="ratingLoading"', () => {
    assert.ok(
        /:disabled="ratingLoading"[^>]*@click="rateReview\('good'\)"/.test(reviewSource) ||
        /@click="rateReview\('good'\)"[^>]*:disabled="ratingLoading"/.test(reviewSource),
        '记得 button must bind :disabled="ratingLoading"'
    );
});

test('Review.vue "很熟" button binds :disabled="ratingLoading"', () => {
    assert.ok(
        /:disabled="ratingLoading"[^>]*@click="rateReview\('easy'\)"/.test(reviewSource) ||
        /@click="rateReview\('easy'\)"[^>]*:disabled="ratingLoading"/.test(reviewSource),
        '很熟 button must bind :disabled="ratingLoading"'
    );
});

test('Review.vue next_card logic exists in .then() success path', () => {
    assert.ok(
        reviewRateThen.includes('next_card') || reviewRateThen.includes('nextCard'),
        '.then() success path must contain next_card logic'
    );
});

// ==================== SenseReview.vue tests ====================

const senseRateMethod = extractMethod(senseReviewSource, 'rate');
const senseRateCatch = extractMainCatchBody(senseRateMethod);
const senseRateThen = extractFirstThenBody(senseRateMethod);

test('SenseReview.vue imports shared rating request coordinator', () => {
    assert.ok(
        senseReviewSource.includes('createRatingRequestCoordinator'),
        'SenseReview.vue must import createRatingRequestCoordinator'
    );
});

test('SenseReview.vue rate catch delegates recovery to coordinator', () => {
    assert.ok(
        senseRateCatch.includes('ratingRequestCoordinator.recover'),
        'rate catch must delegate to ratingRequestCoordinator.recover'
    );
});

test('SenseReview.vue catch does NOT have .finally that unconditionally resets rating', () => {
    // The old code had `.finally(() => { this.rating = false; })` which
    // unlocked buttons even on error. The new code delegates to the helper
    // which handles unlock via unlockRating callback.
    assert.ok(
        !senseRateMethod.includes('.finally(') ||
        !senseRateMethod.match(/\.finally\([^}]*this\.rating\s*=\s*false/s),
        'rate() must NOT have a .finally that unconditionally resets this.rating=false'
    );
});

test('SenseReview.vue .then() success path completes coordinator', () => {
    assert.ok(
        senseRateThen.includes('ratingRequestCoordinator.succeed'),
        '.then() success path must complete the coordinator after confirmed rating'
    );
});

test('SenseReview.vue .then() success path clears this.error', () => {
    assert.ok(
        senseRateThen.includes("this.error = ''"),
        '.then() success path must clear this.error on successful rating'
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

// ==================== Task 2000-14: Helper concurrency + re-lock guards ====================

test('Helper concurrent calls return SAME in-flight Promise (not Promise.resolve())', () => {
    // The old code returned Promise.resolve() for the second concurrent call,
    // which let the second caller settle before the first reload finished.
    // The new code must return the same inFlightPromise.
    assert.ok(
        helperSource.includes('inFlightPromise'),
        'helper must use inFlightPromise (not a boolean inFlight flag)'
    );
    assert.ok(
        /if\s*\(inFlightPromise\)\s*\{[\s\S]*return\s+inFlightPromise/.test(helperSource),
        'helper must return inFlightPromise for concurrent calls (not Promise.resolve())'
    );
    assert.ok(
        !/if\s*\(inFlight\)\s*\{[\s\S]*return\s+Promise\.resolve\(\)/.test(helperSource),
        'helper must NOT use old pattern: if(inFlight){ return Promise.resolve() }'
    );
});

test('Helper re-locks after reloadQueue() returns', () => {
    // Task 2000-14: after calling opts.reloadQueue(), the helper must call
    // opts.lockRating() again because reloadQueue (e.g. Legacy loadReviews)
    // may synchronously reset the lock state.
    assert.ok(
        /opts\.reloadQueue\(\)[\s\S]*opts\.lockRating\(\)/.test(helperSource),
        'helper must call opts.lockRating() AFTER opts.reloadQueue() returns'
    );
});

test('Helper handles sync throw from reloadQueue via try/catch', () => {
    // Task 2000-14: if reloadQueue() throws synchronously, the helper must
    // catch it and convert to a rejected Promise (not let it escape).
    assert.ok(
        /try\s*\{[\s\S]*opts\.reloadQueue\(\)[\s\S]*\}\s*catch/.test(helperSource),
        'helper must wrap opts.reloadQueue() in try/catch for sync throw safety'
    );
});

console.log(`\n${passed} tests passed.`);
