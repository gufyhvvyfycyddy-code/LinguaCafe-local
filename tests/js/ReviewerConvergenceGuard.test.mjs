import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const legacy = readFileSync(
    new URL('../../resources/js/components/Review/Review.vue', import.meta.url),
    'utf8',
);

test('legacy Review uses the shared formal request owners', () => {
    assert.match(legacy, /import \{ createReviewApiClient \} from '.\/ReviewApiClient\.js'/);
    assert.match(legacy, /import \{ createReviewRatingTransaction \} from '.\/ReviewRatingTransaction\.js'/);
    assert.match(legacy, /const reviewApi = createReviewApiClient\(\)/);
    assert.match(legacy, /ratingTransaction: createReviewRatingTransaction\(\)/);
    assert.match(legacy, /reviewApi\.loadLegacyQueue\(data\)/);
    assert.match(legacy, /reviewApi\.rateLegacyCard\(payload\)/);
    assert.match(legacy, /this\.ratingTransaction\.begin\(\)/);
    assert.match(legacy, /this\.ratingTransaction\.isCurrent\(seq\)/);
    assert.match(legacy, /this\.ratingTransaction\.recover\(/);
    assert.match(legacy, /beforeDestroy[\s\S]*this\.ratingTransaction\.invalidate\(\)/);
    assert.doesNotMatch(legacy, /axios\.post\(['"]\/reviews['"]/);
    assert.doesNotMatch(legacy, /axios\.post\(['"]\/reviews\/rate['"]/);
    assert.doesNotMatch(legacy, /ratingRequestSequence/);
});
