import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

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

test('B-008 a failing optional import integration cannot hide ordinary import types', async () => {
    const optionalService = fs.readFileSync(
        new URL('../../app/Services/Settings/SettingValueService.php', import.meta.url), 'utf8',
    );
    const source = fs.readFileSync(
        new URL('../../resources/js/components/Library/Import/ImportTypeSelection.vue', import.meta.url), 'utf8',
    );
    assert.match(optionalService, /if \(!\$setting\)\s*\{\s*return false;/);
    assert.doesNotMatch(optionalService, /Missing jellyfinEnabled setting/);
    for (const type of ['plain-text', 'text-file', 'e-book']) {
        assert.match(source, new RegExp("selectImportType\\('" + type + "'\\)"));
    }
    const script = source.match(/<script>([\s\S]*?)<\/script>/)?.[1];
    assert.ok(script);
    const sandbox = { axios: { get: () => Promise.resolve({ data: [] }) } };
    vm.runInNewContext(script.replace(/^\s*import [^\n]*\r?\n/gm, '')
        .replace('export default', 'globalThis.component ='), sandbox);
    const component = sandbox.component;

    sandbox.axios.get = url => url.includes('jellyfin')
        ? Promise.reject(new Error('optional Jellyfin unavailable'))
        : Promise.resolve({ data: ['english'] });
    const first = { ...component.data(), $props: { language: 'english' } };
    await component.mounted.call(first);
    assert.equal(first.loading, false);
    assert.equal(first.websiteImportSupported, true);
    assert.equal(first.jellyfinEnabled, false);
    assert.match(first.error, /仍可使用粘贴文本/);

    sandbox.axios.get = url => url.includes('jellyfin')
        ? Promise.resolve({ data: true })
        : Promise.reject(new Error('optional website config unavailable'));
    const second = { ...component.data(), $props: { language: 'english' } };
    await component.mounted.call(second);
    assert.equal(second.loading, false);
    assert.equal(second.websiteImportSupported, false);
    assert.equal(second.jellyfinEnabled, true);
});

test('B-009 pasted max-chapter input reaches form submission and invalid values are rejected', () => {
    const importOptions = fs.readFileSync(
        new URL('../../resources/js/components/Library/Import/ImportOptions.vue', import.meta.url), 'utf8',
    );

    const submitted = [];
    class FormDataStub {
        constructor() { this.fields = new Map(); }
        set(key, value) { this.fields.set(key, String(value)); }
        get(key) { return this.fields.get(key); }
    }
    const sandbox = {
        FormData: FormDataStub,
        axios: { post(url, form) { submitted.push({ url, form }); return Promise.resolve({ status: 200, data: {} }); } },
    };
    const loadComponent = source => {
        const script = source.match(/<script>([\s\S]*?)<\/script>/)?.[1];
        assert.ok(script);
        vm.runInNewContext(script.replace(/^\s*import [^\n]*\r?\n/gm, '')
            .replace('export default', 'globalThis.component ='), sandbox);
        return sandbox.component;
    };

    const optionsComponent = loadComponent(importOptions);
    assert.doesNotMatch(importOptions, /@keyup="importOptionsChanged"|@click="importOptionsChanged"/);
    assert.match(importOptions, /v-model="maximumCharactersPerChapter"/);

    const updates = [];
    const options = {
        maximumCharactersPerChapter: '15000',
        eBookChapterSortMethod: 'spine',
        $refs: { importOptionsForm: { validate: () => true } },
        $nextTick(callback) { callback(); },
        $emit(name, payload) { updates.push({ name, payload }); },
        importOptionsChanged: optionsComponent.methods.importOptionsChanged,
    };
    optionsComponent.watch.maximumCharactersPerChapter.call(options);
    assert.equal(updates.length, 1);
    assert.equal(updates[0].payload.maximumCharactersPerChapter, '15000');

    const dialogComponent = loadComponent(dialog);
    const parent = dialogComponent.data();
    parent.importType = 'plain-text';
    parent.importText = 'A public-domain text excerpt.';
    parent.$refs = { importOptions: options };
    parent.$emit = () => {};
    dialogComponent.methods.finishImport.call(parent);
    assert.equal(submitted.length, 1);
    assert.equal(submitted[0].url, '/import');
    assert.equal(submitted[0].form.get('maximumCharactersPerChapter'), '15000');
    assert.equal(submitted[0].form.get('eBookChapterSortMethod'), 'spine');

    options.maximumCharactersPerChapter = '15001';
    dialogComponent.methods.finishImport.call(parent);
    assert.equal(submitted.length, 1, 'invalid max must not send another import request');
    assert.equal(parent.isImportOptionsValid, false);
});
