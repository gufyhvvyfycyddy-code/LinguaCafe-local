// SenseReviewIntervalPreviewGuard.test.mjs
//
// SenseReview-IntervalPreview-1000-5
//
// Node built-in assert tests for the sense review answer interval
// preview feature. Guards the cross-cutting contract between:
//   - SenseReviewIntervalPresentation.js (pure presentation helpers)
//   - SenseReviewRatingControls.vue (pure presentational component)
//   - SenseReview.vue (sole axios orchestrator for preview GET)
//   - Backend endpoint GET /reviews/senses/{reviewCard}/interval-preview
//
// These tests guard:
//   1.  Presentation helper file exists.
//   2.  formatIntervalSeconds is a pure function (no axios/Vue/DOM/FSRS).
//   3.  normalizeIntervalPreview is a pure function.
//   4.  formatDueAtTooltip is a pure function.
//   5.  Four ratings all format correctly (non-empty strings).
//   6.  Minute boundary: 60s → "1 分钟".
//   7.  Hour boundary: 3600s → "1 小时".
//   8.  Day boundary: 86400s → "1 天".
//   9.  Month boundary: 60 days → "2 个月".
//  10.  Year boundary: 365 days → "1 年".
//  11.  Sub-minute: 30s → "小于 1 分钟".
//  12.  Invalid input: null/undefined/negative/string → ''.
//  13.  No NaN, no undefined, no negative in output.
//  14.  RatingControls.vue does NOT import axios.
//  15.  RatingControls.vue does NOT call window.location.
//  16.  RatingControls.vue has intervalPreviews/previewLoading/previewError props.
//  17.  RatingControls.vue still emits 'rating' with again/hard/good/easy.
//  18.  RatingControls.vue hotkeys still 1/2/3/4 (from presentation).
//  19.  SenseReview.vue is the sole preview request layer (has loadIntervalPreview).
//  20.  SenseReview.vue uses correct endpoint path /interval-preview.
//  21.  SenseReview.vue has intervalPreviewRequestSequence for race protection.
//  22.  SenseReview.vue clears preview on card switch (currentCard watch).
//  23.  SenseReview.vue does NOT request preview before answer shown (showAnswer watch).
//  24.  Preview loading does NOT disable rating buttons (disabled prop unchanged).
//  25.  Preview error does NOT disable rating buttons.
//  26.  No ReviewLog frontend write (no POST to /review-log).
//  27.  No FSRS frontend calculation (no stability/difficulty math in presentation).
//  28.  normalizeIntervalPreview handles missing ratings gracefully.
//  29.  normalizeIntervalPreview handles null payload gracefully.
//  30.  SenseReview.vue invalidates preview in rate() method.

import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const PRESENTATION_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'Senses', 'SenseReviewIntervalPresentation.js');
const CONTROLS_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'Senses', 'SenseReviewRatingControls.vue');
const REVIEW_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'Senses', 'SenseReview.vue');

let passed = 0;
function test(name, fn) {
    try {
        fn();
        passed++;
        console.log(`  \u2713 ${name}`);
    } catch (e) {
        console.error(`  \u2717 ${name}`);
        console.error(`    ${e.message}`);
        process.exitCode = 1;
    }
}

console.log('SenseReviewIntervalPreview guard tests\n');

// Dynamically import the presentation module.
const presentationUrl = new URL('file:///' + PRESENTATION_PATH.replace(/\\/g, '/'));
const { formatIntervalSeconds, formatDueAtTooltip, normalizeIntervalPreview } = await import(presentationUrl);

const presentationSrc = existsSync(PRESENTATION_PATH) ? readFileSync(PRESENTATION_PATH, 'utf-8') : '';
const controlsSrc = existsSync(CONTROLS_PATH) ? readFileSync(CONTROLS_PATH, 'utf-8') : '';
const reviewSrc = existsSync(REVIEW_PATH) ? readFileSync(REVIEW_PATH, 'utf-8') : '';

// 1. Presentation helper file exists.
test('SenseReviewIntervalPresentation.js file exists', () => {
    assert.ok(existsSync(PRESENTATION_PATH), 'SenseReviewIntervalPresentation.js must exist');
});

// 2. formatIntervalSeconds is a pure function — no axios/Vue/DOM/FSRS in source.
test('formatIntervalSeconds source has no axios/Vue/DOM/FSRS imports', () => {
    // Strip comments to avoid false positives from documentation.
    const stripped = presentationSrc
        .replace(/\/\/[^\n]*/g, '')
        .replace(/\/\*[\s\S]*?\*\//g, '');
    assert.ok(!stripped.includes('axios'), 'must not import axios');
    assert.ok(!stripped.includes('import Vue'), 'must not import Vue');
    assert.ok(!stripped.includes('document.'), 'must not access DOM');
    assert.ok(!stripped.includes('window.'), 'must not access window');
    assert.ok(!stripped.includes('stability'), 'must not compute FSRS stability');
    assert.ok(!stripped.includes('difficulty'), 'must not compute FSRS difficulty');
});

// 3. normalizeIntervalPreview is a pure function.
test('normalizeIntervalPreview is exported and callable', () => {
    assert.strictEqual(typeof normalizeIntervalPreview, 'function');
});

// 4. formatDueAtTooltip is a pure function.
test('formatDueAtTooltip is exported and callable', () => {
    assert.strictEqual(typeof formatDueAtTooltip, 'function');
});

// 5. Four ratings all format correctly (non-empty strings with 预计 prefix).
test('four ratings all produce non-empty interval text with 预计 prefix', () => {
    const payload = {
        ratings: {
            again: { due_at: '2026-07-11T10:10:00Z', interval_seconds: 600, next_state: 'relearning' },
            hard:  { due_at: '2026-07-12T10:00:00Z', interval_seconds: 86400, next_state: 'review' },
            good:  { due_at: '2026-07-15T10:00:00Z', interval_seconds: 345600, next_state: 'review' },
            easy:  { due_at: '2026-07-20T10:00:00Z', interval_seconds: 777600, next_state: 'review' },
        },
    };
    const result = normalizeIntervalPreview(payload);
    assert.ok(result.again.text.startsWith('预计 '), 'again text must start with 预计 ');
    assert.ok(result.hard.text.startsWith('预计 '), 'hard text must start with 预计 ');
    assert.ok(result.good.text.startsWith('预计 '), 'good text must start with 预计 ');
    assert.ok(result.easy.text.startsWith('预计 '), 'easy text must start with 预计 ');
});

// 6. Minute boundary: 60s → "1 分钟".
test('60 seconds formats as 1 分钟', () => {
    assert.strictEqual(formatIntervalSeconds(60), '1 分钟');
});

// 7. Hour boundary: 3600s → "1 小时".
test('3600 seconds formats as 1 小时', () => {
    assert.strictEqual(formatIntervalSeconds(3600), '1 小时');
});

// 8. Day boundary: 86400s → "1 天".
test('86400 seconds formats as 1 天', () => {
    assert.strictEqual(formatIntervalSeconds(86400), '1 天');
});

// 9. Month boundary: 60 days → "2 个月".
test('60 days (5184000s) formats as 2 个月', () => {
    assert.strictEqual(formatIntervalSeconds(5184000), '2 个月');
});

// 10. Year boundary: 365 days → "1 年".
test('365 days (31536000s) formats as 1 年', () => {
    assert.strictEqual(formatIntervalSeconds(31536000), '1 年');
});

// 11. Sub-minute: 30s → "小于 1 分钟".
test('30 seconds formats as 小于 1 分钟', () => {
    assert.strictEqual(formatIntervalSeconds(30), '小于 1 分钟');
});

// 12. Invalid input: null/undefined/negative/string → ''.
test('invalid input returns empty string', () => {
    assert.strictEqual(formatIntervalSeconds(null), '');
    assert.strictEqual(formatIntervalSeconds(undefined), '');
    assert.strictEqual(formatIntervalSeconds(-1), '');
    assert.strictEqual(formatIntervalSeconds('abc'), '');
    assert.strictEqual(formatIntervalSeconds(NaN), '');
    assert.strictEqual(formatIntervalSeconds(Infinity), '');
});

// 13. No NaN, no undefined, no negative in output.
test('output never contains NaN/undefined/negative', () => {
    const values = [0, 1, 30, 59, 60, 120, 3599, 3600, 86400, 345600, 777600, 5184000, 31536000, 999999999];
    for (const v of values) {
        const result = formatIntervalSeconds(v);
        assert.ok(typeof result === 'string', `result for ${v} must be string`);
        assert.ok(!result.includes('NaN'), `result for ${v} must not contain NaN`);
        assert.ok(!result.includes('undefined'), `result for ${v} must not contain undefined`);
        assert.ok(!result.includes('-'), `result for ${v} must not contain negative sign`);
    }
});

// 14. RatingControls.vue does NOT import axios.
test('RatingControls.vue does not import axios', () => {
    assert.ok(!controlsSrc.includes('axios'), 'RatingControls must not import axios');
});

// 15. RatingControls.vue does NOT call window.location.
test('RatingControls.vue does not access window.location', () => {
    // Strip comments to avoid false positives.
    const stripped = controlsSrc.replace(/\/\/[^\n]*/g, '').replace(/\/\*[\s\S]*?\*\//g, '').replace(/<!--[\s\S]*?-->/g, '');
    assert.ok(!stripped.includes('window.location'), 'RatingControls must not access window.location');
});

// 16. RatingControls.vue has intervalPreviews/previewLoading/previewError props.
test('RatingControls.vue has interval preview props', () => {
    assert.ok(controlsSrc.includes('intervalPreviews'), 'must have intervalPreviews prop');
    assert.ok(controlsSrc.includes('previewLoading'), 'must have previewLoading prop');
    assert.ok(controlsSrc.includes('previewError'), 'must have previewError prop');
});

// 17. RatingControls.vue still emits 'rating' with again/hard/good/easy.
test('RatingControls.vue still emits rating event', () => {
    assert.ok(controlsSrc.includes("$emit('rating'"), "must emit 'rating' event");
});

// 18. RatingControls.vue hotkeys still come from presentation (not hardcoded).
test('RatingControls.vue does not redefine hotkeys locally', () => {
    // The component should not have its own hotkey: 1/2/3/4 definitions;
    // those come from RATING_PRESENTATION.
    const stripped = controlsSrc.replace(/\/\/[^\n]*/g, '').replace(/\/\*[\s\S]*?\*\//g, '');
    assert.ok(!stripped.includes('hotkey: 1'), 'must not redefine hotkey locally');
});

// 19. SenseReview.vue is the sole preview request layer (has loadIntervalPreview).
test('SenseReview.vue has loadIntervalPreview method', () => {
    assert.ok(reviewSrc.includes('loadIntervalPreview'), 'must have loadIntervalPreview method');
});

// 20. SenseReview.vue uses correct endpoint path /interval-preview.
test('SenseReview.vue uses /interval-preview endpoint', () => {
    assert.ok(reviewSrc.includes('/interval-preview'), 'must use /interval-preview endpoint path');
});

// 21. SenseReview.vue has intervalPreviewRequestSequence for race protection.
test('SenseReview.vue has intervalPreviewRequestSequence', () => {
    assert.ok(reviewSrc.includes('intervalPreviewRequestSequence'), 'must have requestSequence for race protection');
});

// 22. SenseReview.vue clears preview on card switch (currentCard watch).
test('SenseReview.vue watches currentCard to clear stale preview', () => {
    // Search for the watcher definition (not the computed property).
    const watchIdx = reviewSrc.indexOf('currentCard(newCard');
    assert.ok(watchIdx >= 0, 'must have currentCard watcher with newCard param');
    const chunk = reviewSrc.slice(watchIdx, watchIdx + 500);
    assert.ok(chunk.includes('intervalPreviews'), 'currentCard watcher must clear intervalPreviews');
});

// 23. SenseReview.vue does NOT request preview before answer shown.
test('SenseReview.vue only requests preview when showAnswer becomes true', () => {
    const watchIdx = reviewSrc.indexOf('showAnswer(');
    assert.ok(watchIdx >= 0, 'must have showAnswer watcher');
    const chunk = reviewSrc.slice(watchIdx, watchIdx + 200);
    assert.ok(chunk.includes('val && this.currentCard'), 'must gate on showAnswer being true');
    assert.ok(chunk.includes('loadIntervalPreview'), 'must call loadIntervalPreview only when answer shown');
});

// 24. Preview loading does NOT disable rating buttons.
test('preview loading is not in disabled prop expression', () => {
    // Extract the :disabled binding from the SenseReviewRatingControls usage.
    const controlsIdx = reviewSrc.indexOf('<SenseReviewRatingControls');
    assert.ok(controlsIdx >= 0, 'must use SenseReviewRatingControls');
    const chunk = reviewSrc.slice(controlsIdx, controlsIdx + 400);
    // Find the :disabled attribute value specifically.
    const disabledMatch = chunk.match(/:disabled="([^"]*)"/);
    assert.ok(disabledMatch, 'must have :disabled attribute');
    const disabledExpr = disabledMatch[1];
    assert.ok(!disabledExpr.includes('intervalPreviewLoading'), 'disabled prop must NOT include preview loading');
});

// 25. Preview error does NOT disable rating buttons.
test('preview error is not in disabled prop expression', () => {
    const controlsIdx = reviewSrc.indexOf('<SenseReviewRatingControls');
    const chunk = reviewSrc.slice(controlsIdx, controlsIdx + 400);
    const disabledMatch = chunk.match(/:disabled="([^"]*)"/);
    assert.ok(disabledMatch, 'must have :disabled attribute');
    const disabledExpr = disabledMatch[1];
    assert.ok(!disabledExpr.includes('intervalPreviewError'), 'disabled prop must NOT include preview error');
});

// 26. No ReviewLog frontend write (no POST to /review-log).
test('no frontend ReviewLog write in preview feature', () => {
    assert.ok(!reviewSrc.includes('/review-log'), 'must not POST to /review-log');
    assert.ok(!presentationSrc.includes('ReviewLog'), 'presentation must not reference ReviewLog');
});

// 27. No FSRS frontend calculation (no stability/difficulty math in presentation).
test('presentation helper does not compute FSRS values', () => {
    assert.ok(!presentationSrc.includes('stability'), 'must not compute stability');
    assert.ok(!presentationSrc.includes('difficulty'), 'must not compute difficulty');
    assert.ok(!presentationSrc.includes('fsrs_state'), 'must not compute fsrs_state');
});

// 28. normalizeIntervalPreview handles missing ratings gracefully.
test('normalizeIntervalPreview handles missing ratings', () => {
    const payload = {
        ratings: {
            again: { due_at: '2026-07-11T10:10:00Z', interval_seconds: 600, next_state: 'relearning' },
            // hard, good, easy missing
        },
    };
    const result = normalizeIntervalPreview(payload);
    assert.ok(result.again.text.length > 0, 'again should have text');
    assert.strictEqual(result.hard.text, '', 'hard should be empty');
    assert.strictEqual(result.good.text, '', 'good should be empty');
    assert.strictEqual(result.easy.text, '', 'easy should be empty');
});

// 29. normalizeIntervalPreview handles null payload gracefully.
test('normalizeIntervalPreview handles null payload', () => {
    const result = normalizeIntervalPreview(null);
    assert.ok(result.again, 'must have again key');
    assert.ok(result.hard, 'must have hard key');
    assert.ok(result.good, 'must have good key');
    assert.ok(result.easy, 'must have easy key');
    assert.strictEqual(result.again.text, '', 'again text should be empty');
});

// 30. SenseReview.vue invalidates preview in rate() method.
test('SenseReview.vue invalidates preview at start of rate()', () => {
    const rateIdx = reviewSrc.indexOf('rate(rating)');
    assert.ok(rateIdx >= 0, 'must have rate method');
    const nextMethodIdx = reviewSrc.indexOf('continueOverLimit()', rateIdx);
    assert.ok(nextMethodIdx > rateIdx, 'must find the method boundary after rate()');
    const chunk = reviewSrc.slice(rateIdx, nextMethodIdx);
    assert.ok(chunk.includes('intervalPreviews = null'), 'rate() must clear intervalPreviews');
    assert.ok(chunk.includes('intervalPreviewRequestSequence++'), 'rate() must bump requestSequence');
});

// 31. formatDueAtTooltip returns "预计 " prefix for valid ISO.
test('formatDueAtTooltip returns 预计 prefix for valid ISO', () => {
    const result = formatDueAtTooltip('2026-07-11T10:10:00Z');
    assert.ok(result.startsWith('预计 '), 'tooltip must start with 预计 ');
    assert.ok(result.length > 6, 'tooltip must have date/time content');
});

// 32. formatDueAtTooltip returns '' for invalid input.
test('formatDueAtTooltip returns empty for invalid input', () => {
    assert.strictEqual(formatDueAtTooltip(null), '');
    assert.strictEqual(formatDueAtTooltip(''), '');
    assert.strictEqual(formatDueAtTooltip('not-a-date'), '');
});

console.log(`\n${passed} passed`);
