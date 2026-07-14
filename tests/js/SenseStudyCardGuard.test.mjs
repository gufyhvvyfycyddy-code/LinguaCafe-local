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

test('reuses SenseSentencePreview for question and answer sentences', () => {
    assert.ok(source.includes('SenseSentencePreview'), 'must reuse SenseSentencePreview');
    assert.ok((source.match(/<SenseSentencePreview/g) || []).length >= 2, 'question and answer must use the same sentence component');
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

test('keeps extension points in the review container instead of embedding FSRS controls', () => {
    for (const slot of ['answer-toolbar', 'answer-left-extra', 'answer-right-extra', 'after-answer']) {
        assert.ok(source.includes(`name="${slot}"`), `must expose ${slot} slot`);
    }
    assert.ok(!source.includes('SenseReviewRatingControls'), 'must not own formal rating controls');
    assert.ok(reviewSource.includes('SenseStudyCard'), 'SenseReview must consume the shared presentation component');
});

console.log(`SenseStudyCardGuard: ${passed} passed`);
