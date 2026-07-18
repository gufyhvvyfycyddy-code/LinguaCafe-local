import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { pathToFileURL } from 'node:url';

const modulePath = path.resolve('resources/js/services/ReviewCardMarkerPresentation.js');

test('marker presentation exposes the stable Anki-compatible values', async () => {
    assert.equal(fs.existsSync(modulePath), true, 'Marker presentation module must exist');
    const presentation = await import(pathToFileURL(modulePath));

    assert.deepEqual(
        presentation.REVIEW_CARD_MARKERS.map(option => option.value),
        [0, 1, 2, 3, 4, 5, 6, 7]
    );
    assert.deepEqual(
        presentation.REVIEW_CARD_MARKERS.map(option => option.label),
        ['无标记', '红色', '橙色', '绿色', '蓝色', '粉色', '青色', '紫色']
    );
    for (const option of presentation.REVIEW_CARD_MARKERS) {
        assert.equal(typeof option.color, 'string');
        assert.equal(typeof option.icon, 'string');
        assert.equal(Object.isFrozen(option), true);
    }
    assert.equal(Object.isFrozen(presentation.REVIEW_CARD_MARKERS), true);
});

test('markerOption normalizes known values and falls back to none', async () => {
    const presentation = await import(pathToFileURL(modulePath));

    assert.equal(presentation.markerOption(4).label, '蓝色');
    assert.equal(presentation.markerOption('7').label, '紫色');
    assert.equal(presentation.markerOption(-1).value, 0);
    assert.equal(presentation.markerOption('unknown').value, 0);
});
