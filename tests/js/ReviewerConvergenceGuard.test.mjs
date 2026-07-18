import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const legacy = readFileSync(
    new URL('../../resources/js/components/Review/Review.vue', import.meta.url),
    'utf8',
);
const sense = readFileSync(
    new URL('../../resources/js/components/Senses/SenseReview.vue', import.meta.url),
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

test('Sense Review uses the shared formal request owners', () => {
    assert.match(sense, /import \{ createReviewApiClient \} from '\.\.\/Review\/ReviewApiClient\.js'/);
    assert.match(sense, /import \{ createReviewRatingTransaction \} from '\.\.\/Review\/ReviewRatingTransaction\.js'/);
    assert.match(sense, /const reviewApi = createReviewApiClient\(\)/);
    assert.match(sense, /ratingTransaction: createReviewRatingTransaction\(\)/);
    assert.match(sense, /reviewApi\.loadSenseQueue\(params\)/);
    assert.match(sense, /reviewApi\.rateSenseCard\(this\.currentCard\.review_card_id, payload\)/);
    assert.match(sense, /reviewApi\.loadSenseIntervalPreview\(cardId\)/);
    assert.match(sense, /this\.ratingTransaction\.begin\(\)/);
    assert.match(sense, /this\.ratingTransaction\.isCurrent\(seq\)/);
    assert.match(sense, /this\.ratingTransaction\.recover\(/);
    assert.match(sense, /beforeDestroy[\s\S]*this\.ratingTransaction\.invalidate\(\)/);
    assert.doesNotMatch(sense, /axios\.get\(['"]\/reviews\/senses['"]/);
    assert.doesNotMatch(sense, /axios\.get\([^\n]*\/interval-preview/);
    assert.doesNotMatch(sense, /axios\.post\([^\n]*\/reviews\/senses\/[^\n]*\/rate/);
});

test('Sense session actions have one extracted owner', () => {
    const surface = readFileSync(
        new URL('../../resources/js/components/Senses/SenseReviewSessionActionsSurface.vue', import.meta.url),
        'utf8',
    );
    assert.match(sense, /<SenseReviewSessionActionsSurface/);
    assert.match(sense, /sessionActionProjection:\s*\{/);
    assert.doesNotMatch(sense, /sessionActions\s*:/);
    assert.doesNotMatch(sense, /sessionActionsLoading\s*:/);
    assert.doesNotMatch(sense, /sessionActionRequestSequence\s*:/);
    assert.doesNotMatch(sense, /\/reviews\/senses\/(session-actions|review-actions)/);
    assert.match(surface, /reviewApi\.loadSenseSessionActions/);
    assert.match(surface, /reviewApi\.undoSenseReviewAction/);
});
