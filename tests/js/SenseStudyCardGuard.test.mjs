import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);
const componentPath = join(__dirname, '..', '..', 'resources', 'js', 'components', 'Senses', 'SenseStudyCard.vue');
const reviewPath = join(__dirname, '..', '..', 'resources', 'js', 'components', 'Senses', 'SenseReview.vue');
const source = existsSync(componentPath) ? readFileSync(componentPath, 'utf8') : '';
const reviewSource = existsSync(reviewPath) ? readFileSync(reviewPath, 'utf8') : '';

let passed = 0;
function test(name, callback) {
    try {
        callback();
        passed++;
    } catch (error) {
        console.error(`FAIL: ${name}`);
        console.error(error.message);
        process.exitCode = 1;
    }
}

test('shared presentation component exists', () => {
    assert.ok(existsSync(componentPath), 'SenseStudyCard.vue must exist');
});

test('declares the frozen presentation props', () => {
    for (const prop of ['card', 'showAnswer', 'fontSize']) {
        assert.ok(source.includes(prop), `SenseStudyCard must declare ${prop}`);
    }
});

test('renders the current source sentence once and reveals its translation without duplicating the sentence', () => {
    assert.ok(source.includes('SenseSentencePreview'), 'must reuse SenseSentencePreview');
    assert.equal((source.match(/<SenseSentencePreview/g) || []).length, 1, 'the current source sentence must render exactly once');
    assert.ok(source.includes('showAnswer && card.example_sentence_zh'), 'sentence translation must stay hidden until the answer is revealed');
});

test('emits reveal and view-source without owning review actions', () => {
    assert.ok(source.includes("$emit('reveal')"), 'must emit reveal');
    assert.ok(source.includes("$emit('view-source')"), 'must emit view-source');
    for (const forbidden of ['axios', 'ReviewLog', 'FsrsScheduling', '/rate', 'window.location', 'localStorage', 'sessionStorage']) {
        assert.ok(!source.includes(forbidden), `presentation component must not contain ${forbidden}`);
    }
});

test('hides answer-only fields until reveal and suppresses empty optional fields', () => {
    assert.ok(source.includes('v-if="showAnswer"'), 'answer content must be gated by showAnswer');
    assert.ok(source.includes('hasSenseEn'), 'must suppress empty sense_en');
    assert.ok(source.includes('hasAliases'), 'must suppress empty aliases');
    assert.ok(source.includes('hasCollocations'), 'must suppress empty collocations');
});

test('keeps extension points in the review container instead of embedding formal rating controls', () => {
    for (const slot of ['answer-toolbar', 'answer-left-extra', 'answer-right-extra', 'after-answer']) {
        assert.ok(source.includes(`name="${slot}"`), `must expose ${slot} slot`);
    }
    assert.ok(!source.includes('SenseReviewRatingControls'), 'must not own formal rating controls');
    assert.ok(reviewSource.includes('SenseStudyCard'), 'SenseReview must consume the shared presentation component');
});

test('keeps FSRS engineering details out of the question header and behind More > 复习信息', () => {
    const fields = ['fsrs_state', 'fsrs_reps', 'fsrs_due_at', 'fsrs_stability', 'fsrs_difficulty', 'fsrs_lapses'];
    const headerStart = reviewSource.indexOf('<template #header-meta>');
    const headerEnd = reviewSource.indexOf('</template>', headerStart);
    const toolbarStart = reviewSource.indexOf('<template #answer-toolbar>');
    const toolbarEnd = reviewSource.indexOf('<template #answer-left-extra>', toolbarStart);
    const dialogStart = reviewSource.indexOf('<v-dialog v-model="fsrsDetailOpen"');
    const dialogEnd = dialogStart >= 0 ? reviewSource.indexOf('</v-dialog>', dialogStart) : -1;
    assert.ok(headerStart >= 0 && headerEnd > headerStart, 'header-meta slot must exist');
    assert.ok(toolbarStart >= 0 && toolbarEnd > toolbarStart, 'answer-toolbar slot must exist');
    const header = reviewSource.slice(headerStart, headerEnd);
    const toolbar = reviewSource.slice(toolbarStart, toolbarEnd);
    const dialog = dialogStart >= 0 && dialogEnd > dialogStart ? reviewSource.slice(dialogStart, dialogEnd) : '';
    assert.ok(toolbar.includes('复习信息'), 'More menu must expose review information');
    const toolbarHasFields = fields.every(field => toolbar.includes(field));
    const dialogHasFields = fields.every(field => dialog.includes(field));
    assert.ok(toolbarHasFields || (toolbar.includes('fsrsDetailOpen = true') && dialogHasFields), 'review information must remain accessible only through More');
    for (const field of fields) {
        assert.ok(!header.includes(field), `${field} must not be visible in the question header`);
    }
});

console.log(`SenseStudyCardGuard: ${passed} passed`);
