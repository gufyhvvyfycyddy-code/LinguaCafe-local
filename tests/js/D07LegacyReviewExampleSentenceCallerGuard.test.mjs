import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const reviewSource = readFileSync(
    new URL('../../resources/js/components/Review/Review.vue', import.meta.url),
    'utf8',
);

test('legacy Review drops example-sentence GET caller while queue/rate runtime remains', () => {
    assert.doesNotMatch(reviewSource, /\/vocabulary\/example-sentence\//);
    assert.match(reviewSource, /reviewApi\.loadLegacyQueue\(/);
    assert.match(reviewSource, /reviewApi\.rateLegacyCard\(/);
});
