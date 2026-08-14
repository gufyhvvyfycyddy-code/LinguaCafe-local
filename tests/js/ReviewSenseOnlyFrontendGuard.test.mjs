import assert from 'node:assert/strict';
import fs from 'node:fs';

const review = fs.readFileSync(
    new URL('../../resources/js/components/Review/Review.vue', import.meta.url),
    'utf8',
);
const reviewSettings = fs.readFileSync(
    new URL('../../resources/js/components/Review/ReviewSettings.vue', import.meta.url),
    'utf8',
);

for (const obsolete of [
    "type == 'word'",
    "type == 'phrase'",
    "type != 'sense'",
    '<text-block-group',
    'reviewSentenceMode',
    'vocabularyBottomSheet',
    'vocabularyHoverBox',
    'vocabularyHoverBoxSearch',
    'vocabularyHoverBoxDelay',
    'vocabularyHoverBoxPreferredPosition',
    'textBlockKey',
    'exampleSentence',
]) {
    assert.ok(!review.includes(obsolete), `Review.vue must not restore retired mixed-card frontend path: ${obsolete}`);
}

for (const required of [
    '<sense-sentence-preview',
    'openSenseSource',
    'reviewApi.loadLegacyQueue(',
    'reviewApi.rateLegacyCard(',
    'createReviewRatingTransaction',
    './ReviewDurationTracker.js',
    'review_duration_ms',
]) {
    assert.ok(review.includes(required), `Review.vue must preserve surviving Sense/review runtime: ${required}`);
}

for (const obsolete of [
    'reviewSentenceMode',
    'sentenceModes',
    'vocabularyBottomSheet',
    'vocabularyHoverBox',
    'vocabularyHoverBoxSearch',
    'vocabularyHoverBoxDelay',
    'vocabularyHoverBoxPreferredPosition',
]) {
    assert.ok(!reviewSettings.includes(obsolete), `ReviewSettings.vue must not restore retired mixed-card control: ${obsolete}`);
}

for (const required of [
    'selectedFontType',
    'settings.fontSize',
    'textToSpeechSelectedVoice',
    'settings.textToSpeechSpeed',
    'DefaultLocalStorageManager.saveSettings(this.settings)',
    "this.$emit('input', false)",
]) {
    assert.ok(reviewSettings.includes(required), `ReviewSettings.vue must preserve surviving setting behavior: ${required}`);
}

console.log('Review sense-only frontend guard passed.');
