import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const dialog = fs.readFileSync(
    new URL('../../resources/js/components/Library/Import/ImportDialog.vue', import.meta.url),
    'utf8',
);
const libraryOptions = fs.readFileSync(
    new URL('../../resources/js/components/Library/Import/ImportLibraryOptions.vue', import.meta.url),
    'utf8',
);
const textFileSource = fs.readFileSync(
    new URL('../../resources/js/components/Library/Import/ImportSource/ImportTextFileSource.vue', import.meta.url),
    'utf8',
);

test('material metadata is collected by the existing import flow', () => {
    for (const field of ['materialType', 'examYear', 'examSet', 'questionType']) {
        assert.match(libraryOptions, new RegExp(`v-model="${field}"`));
        assert.match(libraryOptions, new RegExp(`${field}: this\\.${field}`));
        assert.match(dialog, new RegExp(`data\\.set\\('${field}', this\\.${field}\\)`));
    }
    assert.match(dialog, /axios\.post\('\/import', data\)/);
    assert.match(dialog, /if \(this\.bookId === -1\)/);
});

test('exam metadata controls validity while personal material stays simple', () => {
    assert.match(libraryOptions, /materialType !== 'personal'/);
    assert.match(libraryOptions, /rules\.examYear/);
    assert.match(libraryOptions, /rules\.examSet/);
    assert.match(libraryOptions, /materialType === 'personal'/);
});

test('invalid text files remain blocked by the existing source step', () => {
    assert.match(textFileSource, /extension !== 'txt'/);
    assert.match(textFileSource, /isImportSourceValid:\s*false/);
});
