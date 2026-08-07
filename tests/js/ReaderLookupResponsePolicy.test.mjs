import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import {
    DICTIONARY_LOOKUP_UNAVAILABLE,
    flattenReaderApiDefinitions,
    joinReaderDictionaryDefinitions,
    normalizeDictionaryWarnings,
    resolveReaderDisplayedInflections,
    resolveReaderLookupEnvelope,
    resolveReaderLookupError,
    shouldApplyReaderDictionaryResponse,
} from '../../resources/js/services/ReaderLookupResponsePolicy.js';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const textBlockSource = fs.readFileSync(path.join(root, 'resources/js/components/Text/TextBlockGroup.vue'), 'utf8');

test('accepts only the current non-empty strict dictionary term', () => {
    assert.equal(shouldApplyReaderDictionaryResponse('alpha', 'alpha'), true);
    assert.equal(shouldApplyReaderDictionaryResponse('alpha', 'beta'), false);
    assert.equal(shouldApplyReaderDictionaryResponse('', ''), false);
    assert.equal(shouldApplyReaderDictionaryResponse(2, '2'), false);
    assert.equal(shouldApplyReaderDictionaryResponse('2', 2), false);
});

test('normalizes degraded lookup envelopes without mutating the response', () => {
    const source = Object.freeze({
        term: 'alpha',
        results: Object.freeze([{ name: 'dictionary' }]),
        warnings: Object.freeze([Object.freeze({
            dictionary_id: 7,
            dictionary_name: 'Broken',
            code: 'DICTIONARY_TABLE_MISSING',
            message: '不可用',
        })]),
        configured: true,
    });

    assert.deepEqual(resolveReaderLookupEnvelope(source, 'results'), {
        term: 'alpha',
        results: [{ name: 'dictionary' }],
        warnings: [{
            dictionaryId: 7,
            dictionaryName: 'Broken',
            code: 'DICTIONARY_TABLE_MISSING',
            message: '不可用',
        }],
        configured: true,
    });
    assert.equal(source.results.length, 1);
});

test('normalizes malformed warnings and classifies total outage errors', () => {
    assert.deepEqual(normalizeDictionaryWarnings(null), []);
    assert.deepEqual(normalizeDictionaryWarnings([null, 'bad', {}]), [{
        dictionaryId: null,
        dictionaryName: '',
        code: 'DICTIONARY_QUERY_FAILED',
        message: '部分词典暂时不可用。',
    }]);
    assert.deepEqual(resolveReaderLookupError({
        response: {
            data: {
                error: {
                    code: DICTIONARY_LOOKUP_UNAVAILABLE,
                    message: 'unavailable',
                },
            },
        },
    }), {
        code: DICTIONARY_LOOKUP_UNAVAILABLE,
        message: 'unavailable',
        unavailable: true,
    });
});

test('joins local dictionary definitions with the established delimiter', () => {
    assert.equal(joinReaderDictionaryDefinitions(['first', 'second']), 'first;second');
    assert.equal(joinReaderDictionaryDefinitions(['only']), 'only');
    assert.equal(joinReaderDictionaryDefinitions([]), '');
});

test('flattens API definitions in response-item order', () => {
    assert.deepEqual(flattenReaderApiDefinitions([
        { definitions: ['a', 'b'] },
        { definitions: ['c'] },
        { definitions: [] },
    ]), ['a', 'b', 'c']);
});

test('preserves Array.concat handling for non-array API definitions', () => {
    assert.deepEqual(flattenReaderApiDefinitions([
        { definitions: 'single' },
        { definitions: undefined },
    ]), ['single', undefined]);
});

test('returns null for the established empty inflection response values', () => {
    assert.equal(resolveReaderDisplayedInflections('[]'), null);
    assert.equal(resolveReaderDisplayedInflections(''), null);
    assert.equal(resolveReaderDisplayedInflections(0), null);
});

test('filters inflections to the established display names', () => {
    const result = resolveReaderDisplayedInflections(JSON.stringify([
        { name: 'Non-past', form: 'aff-plain:', value: 'plain' },
        { name: 'Volitional', form: 'aff-plain:', value: 'hidden' },
        { name: 'Past', form: 'aff-plain:', value: 'past' },
    ]));

    assert.deepEqual(result, [
        { name: 'Non-past', affPlain: 'plain' },
        { name: 'Past', affPlain: 'past' },
    ]);
});

test('retains all ten displayed inflection names in first-seen order', () => {
    const names = [
        'Imperative',
        'Non-past',
        'Non-past, polite',
        'Past',
        'Past, polite',
        'Te-form',
        'Potential',
        'Passive',
        'Causative',
        'Causative Passive',
    ];
    const response = names.map((name, index) => ({
        name,
        form: 'aff-plain:',
        value: String(index),
    }));

    assert.deepEqual(
        resolveReaderDisplayedInflections(JSON.stringify(response)).map(item => item.name),
        names,
    );
});

test('groups repeated names and maps the four established form fields', () => {
    const result = resolveReaderDisplayedInflections(JSON.stringify([
        { name: 'Past', form: 'aff-plain:', value: 'a' },
        { name: 'Past', form: 'aff-formal:', value: 'b' },
        { name: 'Past', form: 'neg-plain:', value: 'c' },
        { name: 'Past', form: 'neg-formal:', value: 'd' },
    ]));

    assert.deepEqual(result, [{
        name: 'Past',
        affPlain: 'a',
        affFormal: 'b',
        negPlain: 'c',
        negFormal: 'd',
    }]);
});

test('later inflection values overwrite the same field', () => {
    assert.deepEqual(resolveReaderDisplayedInflections(JSON.stringify([
        { name: 'Potential', form: 'aff-plain:', value: 'first' },
        { name: 'Potential', form: 'aff-plain:', value: 'last' },
    ])), [{
        name: 'Potential',
        affPlain: 'last',
    }]);
});

test('ignores unrecognized form fields while retaining the displayed name', () => {
    assert.deepEqual(resolveReaderDisplayedInflections(JSON.stringify([
        { name: 'Passive', form: 'other:', value: 'ignored' },
    ])), [{
        name: 'Passive',
    }]);
});

test('preserves JSON parse failures', () => {
    assert.throws(
        () => resolveReaderDisplayedInflections('{bad json'),
        SyntaxError,
    );
});

test('does not mutate frozen response arrays or objects', () => {
    const apiResponse = Object.freeze([
        Object.freeze({ definitions: Object.freeze(['a']) }),
    ]);
    const inflectionRecords = Object.freeze([
        Object.freeze({ name: 'Past', form: 'aff-plain:', value: 'past' }),
    ]);

    assert.deepEqual(flattenReaderApiDefinitions(apiResponse), ['a']);
    assert.deepEqual(
        resolveReaderDisplayedInflections(JSON.stringify(inflectionRecords)),
        [{ name: 'Past', affPlain: 'past' }],
    );
    assert.equal(apiResponse[0].definitions.length, 1);
});

test('TextBlockGroup delegates response rules while retaining transport and effects', () => {
    const inflectionMethod = textBlockSource.slice(
        textBlockSource.indexOf('requestInflections: function(term)'),
        textBlockSource.indexOf('selectPhraseInstanceByWord: function'),
    );
    const hoverRequestMethod = textBlockSource.slice(
        textBlockSource.indexOf('makeHoverVocabularyBoxSearchRequest(term)'),
        textBlockSource.indexOf('unselectAllWordsOnEmptyClick(event)'),
    );

    assert.equal(textBlockSource.includes("import * as ReaderLookupResponse from './../../services/ReaderLookupResponsePolicy'"), true);
    assert.equal(inflectionMethod.includes("ReaderLookupResponse.resolveReaderLookupEnvelope(response.data, 'inflections')"), true);
    assert.equal(inflectionMethod.includes('ReaderLookupResponse.resolveReaderDisplayedInflections(response.data.inflections)'), true);
    assert.equal(inflectionMethod.includes('ReaderLookupApi.searchReaderInflections(term)'), true);
    assert.equal(inflectionMethod.includes("this.$store.commit('vocabularyBox/setInflections', [])"), true);
    assert.equal(inflectionMethod.includes("this.$store.commit('vocabularyBox/setInflections', inflections)"), true);
    assert.equal(inflectionMethod.includes('displayedInflections'), false);
    assert.equal(inflectionMethod.includes('JSON.parse(response.data)'), false);

    assert.equal(hoverRequestMethod.includes('ReaderLookupResponse.shouldApplyReaderDictionaryResponse'), true);
    assert.equal(hoverRequestMethod.includes('ReaderLookupResponse.joinReaderDictionaryDefinitions'), true);
    assert.equal(hoverRequestMethod.includes('ReaderLookupResponse.flattenReaderApiDefinitions'), true);
    assert.equal(hoverRequestMethod.includes('ReaderLookupApi.searchReaderHoverDictionary(this.$props.language, term)'), true);
    assert.equal(hoverRequestMethod.includes('ReaderLookupApi.searchReaderApiDictionary(this.$props.language, term)'), true);
    assert.equal(hoverRequestMethod.includes("propertyName: 'dictionaryTranslation'"), true);
    assert.equal(hoverRequestMethod.includes("propertyName: 'apiTranslations'"), true);
    assert.equal(hoverRequestMethod.includes('ReaderLookupResponse.resolveReaderLookupEnvelope'), true);
    assert.equal(hoverRequestMethod.includes('ReaderLookupResponse.resolveReaderLookupError'), true);
    assert.equal(hoverRequestMethod.includes("'dictionary-unavailable'"), true);
    assert.equal(hoverRequestMethod.includes('this.updateHoverVocabularyBoxPosition()'), true);
    assert.equal(hoverRequestMethod.includes("response.data.definitions.join(';')"), false);
    assert.equal(hoverRequestMethod.includes('apiDefinitions.concat'), false);
});
