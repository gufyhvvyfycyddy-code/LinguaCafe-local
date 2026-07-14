import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const session = readFileSync(
    new URL('../../resources/js/components/CustomStudy/CustomStudySession.vue', import.meta.url),
    'utf8',
);

assert.match(session, /SenseStudyCard/);
assert.match(session, /SenseExampleDialog/);
assert.match(session, /\/custom-study\/sessions\/answer/);
assert.match(session, /\/custom-study\/sessions\/resume/);
assert.match(session, /sessionStorage/);
assert.match(session, /preferred_occurrence_id/);
assert.match(session, /wait_until/);
assert.match(session, /Boolean\(this\.waitUntil\) && !this\.currentCard/);
assert.match(session, /again/);
assert.match(session, /hard/);
assert.match(session, /good/);
assert.match(session, /easy/);
assert.doesNotMatch(session, /SenseReviewRatingControls/);
assert.doesNotMatch(session, /localStorage/);
assert.doesNotMatch(session, /\$store/);
assert.doesNotMatch(session, /\/reviews\/senses\/.*\/rate/);

console.log('CustomStudy session guard passed.');
