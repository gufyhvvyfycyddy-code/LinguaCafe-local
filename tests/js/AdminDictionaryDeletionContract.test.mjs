import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const componentSource = fs.readFileSync(
    path.join(root, 'resources/js/components/Admin/AdminDictionarySettings.vue'),
    'utf8',
);

function deletionMethodSource() {
    const start = componentSource.indexOf('deleteDictionaryConfirm()');
    const end = componentSource.indexOf('            loadDictionaries() {', start);

    assert.notEqual(start, -1, 'deleteDictionaryConfirm method must exist');
    assert.notEqual(end, -1, 'loadDictionaries method must follow deleteDictionaryConfirm');

    return componentSource.slice(start, end);
}

test('dictionary deletion uses the DELETE HTTP method', () => {
    const source = deletionMethodSource();

    assert.equal(
        source.includes("axios.delete('/dictionaries/delete/' + this.deleteDialog.id)"),
        true,
    );
    assert.equal(source.includes("axios.get('/dictionaries/delete/'"), false);
});

test('dictionary deletion retains the established success and error cleanup flow', () => {
    const source = deletionMethodSource();

    assert.equal(source.includes('this.deleteDialog.active = false'), true);
    assert.equal(source.includes('this.loadDictionaries()'), true);
    assert.equal(source.includes('this.errorDialog.active = true'), true);
    assert.equal(source.includes('.catch(() => {'), true);
});
