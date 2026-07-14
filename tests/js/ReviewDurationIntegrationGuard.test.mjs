import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const senseReview = await readFile(new URL('../../resources/js/components/Senses/SenseReview.vue', import.meta.url), 'utf8');
const legacyReview = await readFile(new URL('../../resources/js/components/Review/Review.vue', import.meta.url), 'utf8');
const customStudy = await readFile(new URL('../../resources/js/components/CustomStudy/CustomStudy.vue', import.meta.url), 'utf8');

for (const [name, source] of [['SenseReview', senseReview], ['Review', legacyReview]]) {
    assert.match(source, /ReviewDurationTracker/ , `${name} must use the shared duration tracker`);
    assert.match(source, /review_duration_ms\s*[:=]/, `${name} must submit review_duration_ms with the rating`);
    assert.match(source, /visibilitychange/, `${name} must pause timing while the page is hidden`);
}

assert.doesNotMatch(customStudy, /ReviewDurationTracker|review_duration_ms/, 'Custom Study must remain outside formal Review Time');

console.log('Review duration integration guard passed');
