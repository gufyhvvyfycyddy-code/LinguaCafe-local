import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('resources/js/components/Senses/SenseReview.vue', 'utf8');
const bar = fs.readFileSync('resources/js/components/Senses/SenseReviewExperienceBar.vue', 'utf8');
const experienceController = fs.readFileSync('resources/js/components/Senses/SenseReviewExperienceController.vue', 'utf8');
const previous = fs.readFileSync('resources/js/components/Senses/SenseReviewPreviousCardDialog.vue', 'utf8');
const rating = fs.readFileSync('resources/js/components/Senses/SenseReviewRatingControls.vue', 'utf8');
const controller = fs.readFileSync('app/Http/Controllers/SenseReviewController.php', 'utf8');

assert.match(controller, /'experience'\s*=>\s*\[/);
assert.match(experienceController, /normalizeExperienceConfig\(this\.experience\)/);
assert.match(experienceController, /action === 'reveal_answer'/);
assert.match(experienceController, /action === 'wait_for_rating'/);
assert.doesNotMatch(experienceController, /\brate\s*\(/, 'auto advance must never select a rating');
assert.doesNotMatch(experienceController, /ReviewCardMarkerPicker|WordSenseTagBulkPicker|\/review-cards\/manage\/tags/);
assert.doesNotMatch(bar, /slot name="(?:marker|tag)"|\$emit\('bury'\)|mdi-alarm-snooze/);
assert.doesNotMatch(page, /executeLifecycleAction|ReviewCardSchedulingMutationSurface|manual-operations\/(?:preview|apply)/);
assert.match(page, /exitReview\(\)\s*\{\s*window\.location\.href\s*=\s*['"]\/learning-history['"]/);
assert.doesNotMatch(page, /exitReview\(\)\s*\{\s*window\.location\.href\s*=\s*['"]\/review-cards\/manage['"]/);
assert.match(page, /previousCardSnapshot/);
assert.match(bar, /aria-label="复习体验工具条"/);
assert.match(bar, /min-height:\s*44px/);
assert.match(bar, /min-width:\s*44px/);
assert.match(experienceController, /resumeExperience\(this\.timer, 'answer_elapsed'\)/);
assert.match(previous, /只显示最近一次成功评分的响应快照/);
assert.match(rating, /focusFirst\(\)/);
assert.match(experienceController, /aria-live="polite"/);
assert.match(page, /review-reduce-motion/);
assert.match(page, /review-high-contrast/);

console.log('M17 review experience UI guard passed.');
