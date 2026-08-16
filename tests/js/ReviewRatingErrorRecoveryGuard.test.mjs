import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const senseReviewSource = readFileSync(
    new URL('../../resources/js/components/Senses/SenseReview.vue', import.meta.url),
    'utf8',
);
const helperSource = readFileSync(
    new URL('../../resources/js/components/Review/ReviewRatingRecovery.js', import.meta.url),
    'utf8',
);

function extractMethod(source, name) {
    const re = new RegExp(name + '\\s*\\([^)]*\\)\\s*\\{');
    const match = source.match(re);
    assert.ok(match, `${name}() must exist`);
    const start = match.index + match[0].length;
    let depth = 1;
    let i = start;
    while (i < source.length && depth > 0) {
        if (source[i] === '{') depth++;
        else if (source[i] === '}') depth--;
        i++;
    }
    return source.slice(match.index, i);
}

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

test('ReviewRatingRecovery.js exports the shared recovery helper without owning HTTP', () => {
    assert.match(helperSource, /export function runAuthoritativeRatingRecovery/);
    const hasAxiosImport = /^\s*import\s+.*axios/m.test(helperSource)
        || /require\(['"][^'"]*axios['"]\)/.test(helperSource);
    assert.equal(hasAxiosImport, false);
});

const senseRateMethod = extractMethod(senseReviewSource, 'rate');
const senseRateCatch = extractMainCatchBody(senseRateMethod);
const senseRateThen = extractFirstThenBody(senseRateMethod);

test('SenseReview keeps shared rating recovery ownership', () => {
    assert.match(senseReviewSource, /import \{ createReviewRatingTransaction \}/);
    assert.match(senseRateCatch, /this\.ratingTransaction\.recover/);
    assert.ok(
        !senseRateMethod.includes('.finally(')
        || !senseRateMethod.match(/\.finally\([^}]*this\.rating\s*=\s*false/s),
        'rate() must not unconditionally unlock rating in finally',
    );
});

test('SenseReview unlocks and clears errors only after successful rating', () => {
    assert.match(senseRateThen, /this\.rating\s*=\s*false/);
    assert.match(senseRateThen, /this\.error\s*=\s*''/);
    assert.match(senseRateThen, /this\.reviewedCount\+\+/);

    const beforeRequest = senseRateMethod.split('reviewApi.rateSenseCard')[0];
    assert.doesNotMatch(beforeRequest, /this\.reviewedCount\+\+/);
});

test('shared helper returns the same in-flight Promise for concurrent recovery', () => {
    assert.match(helperSource, /inFlightPromise/);
    assert.match(helperSource, /if\s*\(inFlightPromise\)\s*\{[\s\S]*return\s+inFlightPromise/);
    assert.doesNotMatch(helperSource, /if\s*\(inFlight\)\s*\{[\s\S]*return\s+Promise\.resolve\(\)/);
});

test('shared helper re-locks after queue reload begins', () => {
    assert.match(helperSource, /opts\.reloadQueue\(\)[\s\S]*opts\.lockRating\(\)/);
});

test('shared helper converts a synchronous reload throw into recovery failure', () => {
    assert.match(helperSource, /try\s*\{[\s\S]*opts\.reloadQueue\(\)[\s\S]*\}\s*catch/);
});