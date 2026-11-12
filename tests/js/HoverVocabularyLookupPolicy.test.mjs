import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

import { resolveHoverVocabularyLookup } from '../../resources/js/services/HoverVocabularyLookupPolicy.js';

const normalizeLemma = (term) => term.toLowerCase().replace(/^the\s+/, '');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const textBlockSource = fs.readFileSync(path.join(root, 'resources/js/components/Text/TextBlockGroup.vue'), 'utf8');

test('closes only for disabled hover, plain text, or null words', () => {
    for (const input of [
        { hoverBoxEnabled: false, searchEnabled: true, plainTextMode: false, hoveredWords: [] },
        { hoverBoxEnabled: true, searchEnabled: true, plainTextMode: true, hoveredWords: [] },
        { hoverBoxEnabled: true, searchEnabled: true, plainTextMode: false, hoveredWords: null },
    ]) {
        assert.deepEqual(resolveHoverVocabularyLookup({ ...input, normalizeLemma }), { mode: 'closed', term: '' });
    }
});

test('disabled search preserves local-only behavior for words and empty arrays', () => {
    for (const hoveredWords of [[], [{ word: 'Surface', lemma: 'Lemma', spaceAfter: false }]]) {
        assert.deepEqual(resolveHoverVocabularyLookup({
            hoverBoxEnabled: true,
            searchEnabled: false,
            plainTextMode: false,
            hoveredWords,
            normalizeLemma,
        }), { mode: 'local-only', term: '' });
    }
});

test('one word prefers a non-empty raw lemma', () => {
    assert.deepEqual(resolveHoverVocabularyLookup({
        hoverBoxEnabled: true,
        searchEnabled: true,
        plainTextMode: false,
        hoveredWords: [{ word: 'SURFACE', lemma: 'The Lemma', spaceAfter: false }],
        normalizeLemma,
    }), { mode: 'search', term: 'lemma' });
});

test('one word falls back only when the raw lemma is empty', () => {
    const base = { hoverBoxEnabled: true, searchEnabled: true, plainTextMode: false, normalizeLemma };
    assert.deepEqual(resolveHoverVocabularyLookup({
        ...base,
        hoveredWords: [{ word: 'Surface', lemma: '', spaceAfter: false }],
    }), { mode: 'search', term: 'Surface' });
    assert.deepEqual(resolveHoverVocabularyLookup({
        ...base,
        hoveredWords: [{ word: 'Surface', lemma: 'The ', spaceAfter: false }],
    }), { mode: 'search', term: '' });
});

test('phrases honor inter-token spaces without a trailing space', () => {
    assert.deepEqual(resolveHoverVocabularyLookup({
        hoverBoxEnabled: true,
        searchEnabled: true,
        plainTextMode: false,
        hoveredWords: [
            { word: 'look', lemma: '', spaceAfter: true },
            { word: 'up', lemma: '', spaceAfter: true },
        ],
        normalizeLemma,
    }), { mode: 'search', term: 'look up' });
});

test('an empty array remains an empty-term search', () => {
    assert.deepEqual(resolveHoverVocabularyLookup({
        hoverBoxEnabled: true,
        searchEnabled: true,
        plainTextMode: false,
        hoveredWords: [],
        normalizeLemma,
    }), { mode: 'search', term: '' });
});

test('does not mutate frozen hover inputs', () => {
    const word = Object.freeze({ word: 'Surface', lemma: 'Lemma', spaceAfter: true });
    const hoveredWords = Object.freeze([word]);
    assert.doesNotThrow(() => resolveHoverVocabularyLookup({
        hoverBoxEnabled: true,
        searchEnabled: true,
        plainTextMode: false,
        hoveredWords,
        normalizeLemma,
    }));
    assert.deepEqual(hoveredWords, [word]);
});

test('dictionary request failure clears loading only for the currently hovered word', async () => {
    const start = textBlockSource.indexOf('            makeHoverVocabularyBoxSearchRequest(term) {');
    const end = textBlockSource.indexOf('            unselectAllWordsOnEmptyClick(event) {', start);
    assert.ok(start >= 0 && end > start);
    const sourceMethod = textBlockSource.slice(start, end).trim().replace(/,$/, '');
    let rejectRequest;
    const request = new Promise((resolve, reject) => { rejectRequest = reject; });
    const lookup = vm.runInNewContext(`({${sourceMethod}})`, {
        ReaderLookupApi: { searchReaderHoverDictionary: () => request },
        ReaderLookupResponse: {},
    }).makeHoverVocabularyBoxSearchRequest;
    const state = { dictionarySearchTerm: '', dictionaryTranslation: 'loading', key: 0 };
    let repositioned = 0;
    const owner = {
        $props: { vocabularyHoverBoxSearch: true, language: 'english' }, selection: [], anyApiDictionaryEnabled: false,
        $store: { state: { hoverVocabularyBox: state }, commit: (name, { propertyName, value }) => { state[propertyName] = value; } },
        $nextTick: (callback) => callback(), updateHoverVocabularyBoxPosition: () => { repositioned++; },
    };
    lookup.call(owner, 'Technicity');
    rejectRequest(new Error('offline'));
    await Promise.resolve(); await Promise.resolve();
    assert.equal(state.dictionaryTranslation, 'dictionary-search-error');
    assert.equal(state.key, 1);
    assert.equal(repositioned, 1);

    const staleState = { dictionarySearchTerm: '', dictionaryTranslation: 'loading', key: 0 };
    let rejectStale;
    const staleRequest = new Promise((resolve, reject) => { rejectStale = reject; });
    const staleLookup = vm.runInNewContext(`({${sourceMethod}})`, {
        ReaderLookupApi: { searchReaderHoverDictionary: () => staleRequest }, ReaderLookupResponse: {},
    }).makeHoverVocabularyBoxSearchRequest;
    owner.$store.state.hoverVocabularyBox = staleState;
    owner.$store.commit = (name, { propertyName, value }) => { staleState[propertyName] = value; };
    staleLookup.call(owner, 'Old');
    staleState.dictionarySearchTerm = 'new';
    rejectStale(new Error('late failure'));
    await Promise.resolve(); await Promise.resolve();
    assert.equal(staleState.dictionaryTranslation, 'loading');
    assert.equal(staleState.key, 0);

    const hoverSource = fs.readFileSync(path.join(root, 'resources/js/components/Text/VocabularyHoverBox.vue'), 'utf8');
    assert.match(hoverSource, /dictionary-search-error/);
    assert.match(hoverSource, /查询失败，请点击查看详细释义/);
});

test('TextBlockGroup delegates hover decisions while using the dedicated request owner', () => {
    assert.match(textBlockSource, /import\s*\{\s*resolveHoverVocabularyLookup\s*\}/);
    assert.match(textBlockSource, /const lookupDecision = resolveHoverVocabularyLookup\(/);
    assert.match(textBlockSource, /lookupDecision\.mode === 'closed'/);
    assert.match(textBlockSource, /lookupDecision\.mode === 'local-only'/);
    assert.match(textBlockSource, /makeHoverVocabularyBoxSearchRequest\(lookupDecision\.term\)/);
    assert.match(textBlockSource, /ReaderLookupApi\.searchReaderHoverDictionary\(this\.\$props\.language, term\)/);
});
