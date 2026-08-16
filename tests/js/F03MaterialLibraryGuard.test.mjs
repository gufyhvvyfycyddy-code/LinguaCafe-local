import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const library = fs.readFileSync(
    new URL('../../resources/js/components/Library/Library.vue', import.meta.url),
    'utf8',
);
const table = fs.readFileSync(
    new URL('../../resources/js/components/Library/BookListLayout/BookListTable.vue', import.meta.url),
    'utf8',
);

test('library offers the four user-facing material classifications', () => {
    for (const label of ['四级真题', '六级真题', '考研真题', '我的材料']) {
        assert.match(library, new RegExp(`label: '${label}'`));
    }
});

test('empty material classifications are not rendered', () => {
    assert.match(library, /materialTypes\.filter\(\(type\) => \{/);
    assert.match(library, /books\.some\(\(book\) => book\.material_type === type\.value\)/);
    assert.match(library, /v-for="type in availableMaterialTypes"/);
});

test('one derived result powers search and every existing layout', () => {
    assert.match(library, /filteredBooks\(\)/);
    assert.match(library, /book\.name,/);
    assert.match(library, /book\.exam_year,/);
    assert.match(library, /book\.exam_set,/);
    assert.equal((library.match(/:books="filteredBooks"/g) || []).length, 3);
    assert.doesNotMatch(library, /axios\.(get|post)\([^\n]*search/);
    assert.doesNotMatch(table, /booksTextFilter|:search=/);
});
