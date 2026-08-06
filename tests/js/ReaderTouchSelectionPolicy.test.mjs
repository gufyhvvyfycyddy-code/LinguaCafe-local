import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import {
    READER_TOUCH_LONG_PRESS_MS,
    activateReaderTouchLongPress,
    createReaderTouchSelectionGesture,
    resolveReaderTouchEndAction,
    updateReaderTouchSelectionGesture,
} from '../../resources/js/services/ReaderTouchSelectionPolicy.js';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const textBlockSource = fs.readFileSync(
    path.join(root, 'resources/js/components/Text/TextBlockGroup.vue'),
    'utf8',
);

const start = () => createReaderTouchSelectionGesture({
    wordIndex: '7',
    clientX: 20,
    clientY: 40,
});

test('normalizes the touched word and exposes the frozen long-press delay', () => {
    assert.equal(start().wordIndex, 7);
    assert.equal(READER_TOUCH_LONG_PRESS_MS, 450);
});

test('a stationary short gesture resolves to tap', () => {
    assert.equal(resolveReaderTouchEndAction(start()), 'tap');
});

test('movement inside the tolerance remains a tap', () => {
    const gesture = updateReaderTouchSelectionGesture(start(), {
        clientX: 26,
        clientY: 47,
    });
    assert.equal(gesture.moved, false);
    assert.equal(resolveReaderTouchEndAction(gesture), 'tap');
});

test('pre-activation movement beyond the tolerance yields native-scroll cancel', () => {
    const gesture = updateReaderTouchSelectionGesture(start(), {
        clientX: 20,
        clientY: 55,
    });
    assert.equal(gesture.moved, true);
    assert.equal(resolveReaderTouchEndAction(gesture), 'cancel');
});

test('a stationary long press activates phrase selection and finishes on release', () => {
    const gesture = activateReaderTouchLongPress(start());
    assert.equal(gesture.longPressActivated, true);
    assert.equal(resolveReaderTouchEndAction(gesture), 'finish');
});

test('movement before the timer prevents long-press activation', () => {
    const moved = updateReaderTouchSelectionGesture(start(), {
        clientX: 35,
        clientY: 40,
    });
    const gesture = activateReaderTouchLongPress(moved);
    assert.equal(gesture.longPressActivated, false);
    assert.equal(resolveReaderTouchEndAction(gesture), 'cancel');
});

test('policy transitions do not mutate the prior gesture', () => {
    const original = Object.freeze(start());
    const moved = updateReaderTouchSelectionGesture(original, {
        clientX: 40,
        clientY: 40,
    });
    assert.equal(original.moved, false);
    assert.equal(moved.moved, true);
});

test('missing or cancelled gestures resolve safely', () => {
    assert.equal(resolveReaderTouchEndAction(null), 'cancel');
    assert.equal(updateReaderTouchSelectionGesture(null, {
        clientX: 0,
        clientY: 0,
    }), null);
});

test('TextBlockGroup delegates touch classification to the policy', () => {
    assert.match(textBlockSource, /createReaderTouchSelectionGesture/);
    assert.match(textBlockSource, /updateReaderTouchSelectionGesture/);
    assert.match(textBlockSource, /activateReaderTouchLongPress/);
    assert.match(textBlockSource, /resolveReaderTouchEndAction/);
    assert.match(textBlockSource, /READER_TOUCH_LONG_PRESS_MS/);
});
