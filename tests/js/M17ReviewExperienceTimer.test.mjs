import assert from 'node:assert/strict';
import {
    autoAdvanceAction,
    createExperienceSession,
    experienceSnapshot,
    formatExperienceDuration,
    normalizeExperienceConfig,
    pauseExperience,
    resumeExperience,
    setExperiencePhase,
    startExperienceCard,
} from '../../resources/js/components/Review/ReviewExperienceTimer.js';
import {
    loadReviewExperiencePreferences,
    normalizeReviewExperiencePreferences,
    saveReviewExperiencePreferences,
} from '../../resources/js/components/Review/ReviewExperiencePreferences.js';

const state = createExperienceSession(0, true);
startExperienceCard(state, 41, 0);
assert.equal(experienceSnapshot(state, 2500).cardElapsedMs, 2500);
pauseExperience(state, 'visibility', 3000);
assert.equal(experienceSnapshot(state, 9000).cardElapsedMs, 3000, 'hidden time must not accumulate');
pauseExperience(state, 'manual', 9000);
resumeExperience(state, 'visibility', 10000);
assert.equal(experienceSnapshot(state, 11000).cardElapsedMs, 3000, 'one remaining reason keeps timer paused');
resumeExperience(state, 'manual', 12000);
assert.equal(experienceSnapshot(state, 13000).cardElapsedMs, 4000);
setExperiencePhase(state, 'answer', 13000);
assert.equal(experienceSnapshot(state, 14500).phaseElapsedMs, 1500);

const config = { auto_advance_enabled: true, question_timer_seconds: 2, answer_timer_seconds: 3 };
assert.equal(autoAdvanceAction(config, 'question', 1999), null);
assert.equal(autoAdvanceAction(config, 'question', 2000), 'reveal_answer');
assert.equal(autoAdvanceAction(config, 'answer', 3000), 'wait_for_rating');
assert.equal(autoAdvanceAction({ ...config, auto_advance_enabled: false }, 'answer', 9000), null);
assert.equal(formatExperienceDuration(65000), '01:05');
assert.deepEqual(normalizeExperienceConfig({ show_timer: true, question_timer_seconds: 5, answer_timer_seconds: 99999, auto_advance_enabled: true }), {
    show_timer: true, question_timer_seconds: 5, answer_timer_seconds: 0, auto_advance_enabled: true,
});
assert.equal(normalizeExperienceConfig({ auto_advance_enabled: true }).auto_advance_enabled, false);

const memory = new Map();
const storage = { getItem: key => memory.get(key) ?? null, setItem: (key, value) => memory.set(key, value) };
assert.deepEqual(loadReviewExperiencePreferences(storage, true), { fontSize: 20, highContrast: false, reduceMotion: true });
saveReviewExperiencePreferences(storage, { fontSize: 28, highContrast: true, reduceMotion: false });
assert.deepEqual(loadReviewExperiencePreferences(storage, true), { fontSize: 28, highContrast: true, reduceMotion: false });
assert.deepEqual(normalizeReviewExperiencePreferences({ fontSize: 200, highContrast: 'yes' }), { fontSize: 20, highContrast: false, reduceMotion: false });

console.log('M17 review experience timer tests passed.');
