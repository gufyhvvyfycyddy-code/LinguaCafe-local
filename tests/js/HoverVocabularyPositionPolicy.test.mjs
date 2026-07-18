import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import { resolveHoverVocabularyPosition } from '../../resources/js/services/HoverVocabularyPositionPolicy.js';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const textBlockSource = fs.readFileSync(path.join(root, 'resources/js/components/Text/TextBlockGroup.vue'), 'utf8');

const baseInput = () => ({
    hoverBoxHeight: 100,
    areaRect: { left: 100, right: 900, top: 50, height: 600 },
    areaScrollTop: 20,
    wordRect: { left: 400, right: 500, top: 300, bottom: 330 },
    preferredPosition: 'bottom',
    correctionsEnabled: true,
});

test('centers horizontally and uses the preferred bottom position', () => {
    assert.deepEqual(resolveHoverVocabularyPosition(baseInput()), {
        positionLeft: 200,
        positionTop: 325,
        arrowPosition: 'bottom',
    });
});

test('preserves the sequential left and right horizontal bounds', () => {
    assert.equal(resolveHoverVocabularyPosition({
        ...baseInput(),
        wordRect: { left: 105, right: 115, top: 300, bottom: 330 },
    }).positionLeft, 8);

    assert.equal(resolveHoverVocabularyPosition({
        ...baseInput(),
        wordRect: { left: 880, right: 900, top: 300, bottom: 330 },
    }).positionLeft, 492);

    assert.equal(resolveHoverVocabularyPosition({
        ...baseInput(),
        areaRect: { left: 100, right: 350, top: 50, height: 600 },
        wordRect: { left: 105, right: 115, top: 300, bottom: 330 },
    }).positionLeft, 8);
});

test('uses the preferred top position when space correction is unnecessary', () => {
    assert.deepEqual(resolveHoverVocabularyPosition({
        ...baseInput(),
        preferredPosition: 'top',
    }), {
        positionLeft: 200,
        positionTop: 145,
        arrowPosition: 'top',
    });
});

test('moves a preferred bottom box to the top when bottom space is insufficient', () => {
    const result = resolveHoverVocabularyPosition({
        ...baseInput(),
        areaRect: { left: 100, right: 900, top: 0, height: 400 },
        areaScrollTop: 0,
        wordRect: { left: 400, right: 500, top: 320, bottom: 350 },
    });

    assert.equal(result.arrowPosition, 'top');
    assert.equal(result.positionTop, 195);
});

test('moves a preferred top box to the bottom only when bottom space is sufficient', () => {
    const result = resolveHoverVocabularyPosition({
        ...baseInput(),
        areaRect: { left: 100, right: 900, top: 0, height: 400 },
        areaScrollTop: 0,
        wordRect: { left: 400, right: 500, top: 50, bottom: 80 },
        preferredPosition: 'top',
    });

    assert.equal(result.arrowPosition, 'bottom');
    assert.equal(result.positionTop, 105);
});

test('keeps top when neither side has enough space', () => {
    const result = resolveHoverVocabularyPosition({
        ...baseInput(),
        hoverBoxHeight: 100,
        areaRect: { left: 100, right: 900, top: 0, height: 180 },
        areaScrollTop: 0,
        wordRect: { left: 400, right: 500, top: 60, bottom: 90 },
        preferredPosition: 'top',
    });

    assert.equal(result.arrowPosition, 'top');
    assert.equal(result.positionTop, -65);
});

test('does not correct the preferred position when corrections are disabled', () => {
    const result = resolveHoverVocabularyPosition({
        ...baseInput(),
        areaRect: { left: 100, right: 900, top: 0, height: 400 },
        areaScrollTop: 0,
        wordRect: { left: 400, right: 500, top: 320, bottom: 350 },
        correctionsEnabled: false,
    });

    assert.equal(result.arrowPosition, 'bottom');
    assert.equal(result.positionTop, 375);
});

test('preserves area scroll offsets in vertical positioning', () => {
    const result = resolveHoverVocabularyPosition({
        ...baseInput(),
        areaRect: { left: 100, right: 900, top: 100, height: 500 },
        areaScrollTop: 200,
        wordRect: { left: 400, right: 500, top: 150, bottom: 175 },
    });

    assert.equal(result.positionTop, 300);
});

test('does not mutate frozen geometry inputs', () => {
    const areaRect = Object.freeze({ left: 100, right: 900, top: 50, height: 600 });
    const wordRect = Object.freeze({ left: 400, right: 500, top: 300, bottom: 330 });
    const input = Object.freeze({ ...baseInput(), areaRect, wordRect });

    assert.doesNotThrow(() => resolveHoverVocabularyPosition(input));
    assert.deepEqual(areaRect, { left: 100, right: 900, top: 50, height: 600 });
    assert.deepEqual(wordRect, { left: 400, right: 500, top: 300, bottom: 330 });
});

test('TextBlockGroup delegates geometry while retaining DOM and Vuex ownership', () => {
    assert.match(textBlockSource, /import\s*\{\s*resolveHoverVocabularyPosition\s*\}/);
    assert.match(textBlockSource, /const position = resolveHoverVocabularyPosition\(/);
    assert.match(textBlockSource, /document\.getElementById\('vocab-hover-box'\)/);
    assert.match(textBlockSource, /getBoundingClientRect\(\)/);
    assert.match(textBlockSource, /propertyName: 'positionLeft', value: position\.positionLeft/);
    assert.match(textBlockSource, /propertyName: 'positionTop', value: position\.positionTop/);
    assert.match(textBlockSource, /propertyName: 'arrowPosition', value: position\.arrowPosition/);
});
