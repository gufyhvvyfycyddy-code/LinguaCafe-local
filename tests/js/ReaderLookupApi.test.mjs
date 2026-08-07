import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import {
    getApiDictionaryEnabled,
    searchReaderApiDictionary,
    searchReaderDictionary,
    searchReaderHoverDictionary,
    searchReaderInflections,
} from '../../resources/js/services/ReaderLookupApi.js';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const textBlockSource = fs.readFileSync(path.join(root, 'resources/js/components/Text/TextBlockGroup.vue'), 'utf8');

function installAxiosStub() {
    const calls = [];
    const returns = [];

    globalThis.axios = {
        get(url) {
            const result = Object.freeze({ call: calls.length });
            calls.push({ method: 'get', url });
            returns.push(result);
            return result;
        },
        post(url, payload) {
            const result = Object.freeze({ call: calls.length });
            calls.push({ method: 'post', url, payload });
            returns.push(result);
            return result;
        },
    };

    return { calls, returns };
}

test('gets API dictionary availability with the exact established contract', () => {
    const stub = installAxiosStub();

    const result = getApiDictionaryEnabled();

    assert.equal(result, stub.returns[0]);
    assert.deepEqual(stub.calls, [{
        method: 'get',
        url: '/dictionaries/api/is-enabled',
    }]);
});

test('posts ordinary dictionary lookup with exact language and term keys', () => {
    const stub = installAxiosStub();

    const result = searchReaderDictionary('english', 'alpha');

    assert.equal(result, stub.returns[0]);
    assert.deepEqual(stub.calls, [{
        method: 'post',
        url: '/dictionaries/search',
        payload: {
            language: 'english',
            term: 'alpha',
        },
    }]);
});

test('posts inflection lookup with only the established term payload', () => {
    const stub = installAxiosStub();

    const result = searchReaderInflections('went');

    assert.equal(result, stub.returns[0]);
    assert.deepEqual(stub.calls, [{
        method: 'post',
        url: '/dictionaries/search/inflections',
        payload: { term: 'went' },
    }]);
});

test('posts local hover lookup with exact language and term keys', () => {
    const stub = installAxiosStub();

    const result = searchReaderHoverDictionary('english', 'alpha');

    assert.equal(result, stub.returns[0]);
    assert.deepEqual(stub.calls, [{
        method: 'post',
        url: '/dictionaries/search-for-hover-vocabulary',
        payload: {
            language: 'english',
            term: 'alpha',
        },
    }]);
});

test('posts API dictionary lookup with exact language and term keys', () => {
    const stub = installAxiosStub();

    const result = searchReaderApiDictionary('english', 'beta');

    assert.equal(result, stub.returns[0]);
    assert.deepEqual(stub.calls, [{
        method: 'post',
        url: '/dictionaries/api/search',
        payload: {
            language: 'english',
            term: 'beta',
        },
    }]);
});

test('each API helper makes exactly one request and returns axios identity', () => {
    const stub = installAxiosStub();

    const results = [
        getApiDictionaryEnabled(),
        searchReaderDictionary('english', 'term'),
        searchReaderInflections('term'),
        searchReaderHoverDictionary('english', 'term'),
        searchReaderApiDictionary('english', 'term'),
    ];

    assert.equal(stub.calls.length, 5);
    assert.deepEqual(results, stub.returns);
});

test('TextBlockGroup delegates dictionary transport while retaining orchestration and effects', () => {
    const mounted = textBlockSource.slice(
        textBlockSource.indexOf('mounted()'),
        textBlockSource.indexOf('beforeDestroy()'),
    );
    const inflectionMethod = textBlockSource.slice(
        textBlockSource.indexOf('requestInflections: function(term)'),
        textBlockSource.indexOf('selectPhraseInstanceByWord: function'),
    );
    const hoverRequestMethod = textBlockSource.slice(
        textBlockSource.indexOf('makeHoverVocabularyBoxSearchRequest(term)'),
        textBlockSource.indexOf('unselectAllWordsOnEmptyClick(event)'),
    );

    assert.equal(textBlockSource.includes("import * as ReaderLookupApi from './../../services/ReaderLookupApi'"), true);

    assert.equal(mounted.includes('ReaderLookupApi.getApiDictionaryEnabled().then((response) => {'), true);
    assert.equal(mounted.includes("axios.get('/settings/get-anki-settings').then((response) => {"), true);
    assert.equal(mounted.includes("this.anyApiDictionaryEnabled = response.data"), true);

    assert.equal(inflectionMethod.includes('ReaderLookupApi.searchReaderInflections(term).then((response) => {'), true);
    assert.equal(inflectionMethod.includes("this.$props.language !== 'japanese'"), true);
    assert.equal(inflectionMethod.includes("this.$store.commit('vocabularyBox/setInflections', [])"), true);
    assert.equal(inflectionMethod.includes('ReaderLookupResponse.resolveReaderDisplayedInflections(response.data.inflections)'), true);

    assert.equal(hoverRequestMethod.includes('ReaderLookupApi.searchReaderHoverDictionary(this.$props.language, term).then((response) => {'), true);
    assert.equal(hoverRequestMethod.includes('ReaderLookupApi.searchReaderApiDictionary(this.$props.language, term).then((response) => {'), true);
    assert.equal(hoverRequestMethod.includes('if (this.anyApiDictionaryEnabled)'), true);
    assert.equal(hoverRequestMethod.includes('ReaderLookupResponse.shouldApplyReaderDictionaryResponse'), true);
    assert.equal(hoverRequestMethod.includes('ReaderLookupResponse.flattenReaderApiDefinitions'), true);
    assert.equal(hoverRequestMethod.includes('ReaderLookupResponse.resolveReaderLookupError'), true);
    assert.equal(hoverRequestMethod.includes("'dictionary-unavailable'"), true);
    assert.equal(hoverRequestMethod.includes('this.updateHoverVocabularyBoxPosition()'), true);

    assert.equal(textBlockSource.includes("axios.get('/dictionaries/api/is-enabled')"), false);
    assert.equal(textBlockSource.includes("axios.post('/dictionaries/search/inflections'"), false);
    assert.equal(textBlockSource.includes("axios.post('/dictionaries/search-for-hover-vocabulary'"), false);
    assert.equal(textBlockSource.includes("axios.post('/dictionaries/api/search'"), false);
});
