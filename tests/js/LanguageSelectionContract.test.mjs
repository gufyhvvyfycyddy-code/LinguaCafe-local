import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const selectionDialog = fs.readFileSync(
    path.join(root, 'resources/js/components/Dialogs/LanguageSelectionDialog.vue'),
    'utf8',
);
const installDialog = fs.readFileSync(
    path.join(root, 'resources/js/components/Admin/AdminInstallLanguageDialog.vue'),
    'utf8',
);

function methodBlock(source, startMarker, endMarker) {
    const start = source.indexOf(startMarker);
    const end = source.indexOf(endMarker, start);

    assert.notEqual(start, -1, `${startMarker} must exist`);
    assert.notEqual(end, -1, `${endMarker} must follow ${startMarker}`);

    return source.slice(start, end);
}

test('both language selection callers use PUT and no GET mutation remains', () => {
    assert.equal(selectionDialog.includes("axios.put('/languages/select/' + language)"), true);
    assert.equal(installDialog.includes("axios.put('/languages/select/' + language)"), true);
    assert.equal(selectionDialog.includes("axios.get('/languages/select/'"), false);
    assert.equal(installDialog.includes("axios.get('/languages/select/'"), false);
});

test('main language dialog blocks duplicate requests and keeps failures visible', () => {
    const source = methodBlock(selectionDialog, 'selectLanguage(newLanguage)', '            languageName,');

    assert.equal(source.includes('if (this.loading)'), true);
    assert.equal(source.includes("this.error = requestErrorMessage(error"), true);
    assert.equal(source.indexOf("document.location.href = '/';") < source.indexOf('.catch('), true);
    assert.equal(selectionDialog.includes(':disabled="loading"'), true);
    assert.equal(selectionDialog.includes('v-if="error"'), true);
});

test('admin install dialog exposes selection loading, retryable errors, and success-only redirect', () => {
    const source = methodBlock(installDialog, 'selectNewLanguage()', '            close()');

    assert.equal(installDialog.includes('selecting: false'), true);
    assert.equal(installDialog.includes('selectionError'), true);
    assert.equal(installDialog.includes(':loading="selecting"'), true);
    assert.equal(installDialog.includes(':disabled="selecting || installing"'), true);
    assert.equal(source.includes('if (this.selecting)'), true);
    assert.equal(source.includes("this.selectionError = requestErrorMessage(error"), true);
    assert.equal(source.includes('catch(function (error) {})'), false);
    assert.equal(source.indexOf("document.location.href = '/admin/languages';") < source.indexOf('.catch('), true);
    assert.equal(source.includes('this.selecting = false'), true);
});
