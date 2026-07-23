import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import { resolveReaderHotkey } from '../../resources/js/services/ReaderHotkeyPolicy.js';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const textBlockSource = fs.readFileSync(
    path.join(root, 'resources/js/components/Text/TextBlockGroup.vue'),
    'utf8',
);

const resolve = (overrides = {}) => resolveReaderHotkey({
    enabled: true,
    which: 0,
    ctrlKey: false,
    metaKey: false,
    altKey: false,
    shiftKey: false,
    editableTarget: false,
    blockingSurface: false,
    ...overrides,
});

test('suppresses disabled, modified, editable, and blocked hotkeys', () => {
    assert.equal(resolve({ enabled: false, which: 86 }), null);
    assert.equal(resolve({ ctrlKey: true, which: 86 }), null);
    assert.equal(resolve({ metaKey: true, which: 86 }), null);
    assert.equal(resolve({ altKey: true, which: 86 }), null);
    assert.equal(resolve({ editableTarget: true, which: 39 }), null);
    assert.equal(resolve({ blockingSurface: true, which: 27 }), null);
});

test('maps speech and new-stage keys without preventing defaults', () => {
    assert.deepEqual(resolve({ which: 86 }), {
        action: 'text-to-speech',
        preventDefault: false,
    });
    assert.deepEqual(resolve({ which: 67 }), {
        action: 'set-stage',
        stage: 2,
        preventDefault: false,
    });
});

test('maps top-row digits 0 through 7 to stages 0 through -7', () => {
    for (let which = 48; which <= 55; which++) {
        assert.deepEqual(resolve({ which }), {
            action: 'set-stage',
            stage: 48 - which,
            preventDefault: true,
        });
    }
});

test('maps numpad digits 0 through 7 to stages 0 through -7', () => {
    for (let which = 96; which <= 103; which++) {
        assert.deepEqual(resolve({ which }), {
            action: 'set-stage',
            stage: 96 - which,
            preventDefault: true,
        });
    }
});

test('maps ignore and font-size intents with the established Shift behavior', () => {
    assert.deepEqual(resolve({ which: 88 }), {
        action: 'set-stage',
        stage: 1,
        preventDefault: true,
    });
    assert.deepEqual(resolve({ which: 73 }), {
        action: 'decrease-font-size',
        preventDefault: false,
    });
    assert.equal(resolve({ which: 73, shiftKey: true }), null);
    assert.deepEqual(resolve({ which: 79 }), {
        action: 'increase-font-size',
        preventDefault: true,
    });
});

test('maps scroll intents and preserves the Shift acceleration flag', () => {
    for (const which of [38, 87]) {
        assert.deepEqual(resolve({ which, shiftKey: false }), {
            action: 'scroll',
            direction: 'up',
            accelerated: false,
            preventDefault: true,
        });
        assert.equal(resolve({ which, shiftKey: true }).accelerated, true);
    }
    for (const which of [40, 83]) {
        assert.deepEqual(resolve({ which, shiftKey: false }), {
            action: 'scroll',
            direction: 'down',
            accelerated: false,
            preventDefault: true,
        });
        assert.equal(resolve({ which, shiftKey: true }).accelerated, true);
    }
});

test('maps Anki and unselect intents', () => {
    assert.deepEqual(resolve({ which: 70 }), {
        action: 'add-to-anki',
        preventDefault: true,
    });
    assert.deepEqual(resolve({ which: 27 }), {
        action: 'unselect',
        preventDefault: true,
    });
});

test('maps previous and next navigation with the Shift filter flag', () => {
    for (const which of [37, 65]) {
        assert.deepEqual(resolve({ which, shiftKey: true }), {
            action: 'select-previous',
            highlightedOnly: true,
            preventDefault: true,
        });
    }
    for (const which of [39, 68]) {
        assert.deepEqual(resolve({ which, shiftKey: false }), {
            action: 'select-next',
            highlightedOnly: false,
            preventDefault: true,
        });
    }
});

test('maps plain-text mode and leaves unknown keys alone', () => {
    assert.deepEqual(resolve({ which: 80 }), {
        action: 'toggle-plain-text',
        preventDefault: true,
    });
    assert.equal(resolve({ which: 81 }), null);
    assert.equal(resolve({ which: undefined }), null);
});

test('does not mutate frozen input facts', () => {
    const facts = Object.freeze({
        enabled: true,
        which: 39,
        ctrlKey: false,
        metaKey: false,
        altKey: false,
        shiftKey: true,
        editableTarget: false,
        blockingSurface: false,
    });

    assert.equal(resolveReaderHotkey(facts).highlightedOnly, true);
    assert.equal(facts.which, 39);
});

test('TextBlockGroup delegates decisions but retains DOM and effect ownership', () => {
    assert.match(textBlockSource, /import\s*\{\s*resolveReaderHotkey\s*\}/);
    assert.match(textBlockSource, /let editableTarget =/);
    assert.match(textBlockSource, /const blockingSurface =/);
    assert.match(textBlockSource, /const intent = resolveReaderHotkey\(\{/);
    assert.match(textBlockSource, /if \(intent\.preventDefault\) \{\s*event\.preventDefault\(\);/);
    assert.match(textBlockSource, /case 'set-stage':\s*this\.setStage\(intent\.stage\);/);
    assert.match(textBlockSource, /case 'scroll':\s*this\.scrollText\(intent\.direction, intent\.accelerated\);/);
    assert.match(textBlockSource, /case 'select-previous':\s*this\.selectPreviousWord\(false, intent\.highlightedOnly\);/);
    assert.match(textBlockSource, /case 'select-next':\s*this\.selectNextWord\(false, intent\.highlightedOnly\);/);
    assert.match(
        textBlockSource,
        /case 'toggle-plain-text':\s*this\.unselectAllWords\(\);\s*this\.closeHoverBox\(\);\s*this\.\$emit\('toggle-plain-text-mode'\);/,
    );
    assert.match(textBlockSource, /window\.addEventListener\('keydown', this\.hotkeyHandle\)/);
    assert.match(textBlockSource, /window\.removeEventListener\('keydown', this\.hotkeyHandle\)/);
});
