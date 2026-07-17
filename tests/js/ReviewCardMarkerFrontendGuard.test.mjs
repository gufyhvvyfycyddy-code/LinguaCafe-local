import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const picker = read('resources/js/components/ReviewCards/ReviewCardMarkerPicker.vue');
const api = read('resources/js/services/ReviewCardMarkerApi.js');
const manage = read('resources/js/components/ReviewCards/ReviewCardManage.vue');
const table = read('resources/js/components/ReviewCards/ReviewCardTableSurface.vue');
const review = read('resources/js/components/Senses/SenseReview.vue');
const customStudy = read('resources/js/components/CustomStudy/CustomStudy.vue');
const search = read('resources/js/components/ReviewCards/ReviewCardSearchSurface.vue');

assert.match(picker, /markerChoices/);
assert.match(picker, /\$emit\('change'/);
assert.match(api, /review-cards\/manage\/markers/);
assert.match(api, /review-cards\/\$\{reviewCardId\}\/marker/);
assert.match(table, /review-card-marker-picker/);
assert.match(table, /bulk-marker/);
assert.match(manage, /ReviewCardMarkerMutationSurface/);
assert.match(review, /ReviewCardMarkerPicker/);
assert.match(review, /updateMarker/);
assert.match(customStudy, /value="marked"/);
assert.match(search, /flag:1/);
assert.doesNotMatch(api, /ReviewLog|fsrs|lifecycle/);

console.log('ReviewCard marker frontend guard passed.');
