import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const senseReview = await readFile(new URL('../../resources/js/components/Senses/SenseReview.vue', import.meta.url), 'utf8');
const customStudy = await readFile(new URL('../../resources/js/components/CustomStudy/CustomStudy.vue', import.meta.url), 'utf8');

assert.match(senseReview, /ReviewDurationTracker/, 'SenseReview must use the shared duration tracker');
assert.match(senseReview, /review_duration_ms\s*[:=]/, 'SenseReview must submit review_duration_ms with the rating');
assert.match(senseReview, /visibilitychange/, 'SenseReview must pause timing while the page is hidden');
assert.doesNotMatch(
    senseReview,
    /createTracker\(Date\.now/,
    'SenseReview must use the tracker default monotonic clock',
);

assert.doesNotMatch(
    customStudy,
    /ReviewDurationTracker|review_duration_ms/,
    'Custom Study must remain outside formal Review Time',
);

console.log('Review duration integration guard passed');