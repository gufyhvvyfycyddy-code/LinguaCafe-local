import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
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

test('TextBlockGroup delegates hover lookup decisions without moving request ownership', () => {
    assert.match(textBlockSource, /import\s*\{\s*resolveHoverVocabularyLookup\s*\}/);
    assert.match(textBlockSource, /const lookupDecision = resolveHoverVocabularyLookup\(/);
    assert.match(textBlockSource, /lookupDecision\.mode === 'closed'/);
    assert.match(textBlockSource, /lookupDecision\.mode === 'local-only'/);
    assert.match(textBlockSource, /makeHoverVocabularyBoxSearchRequest\(lookupDecision\.term\)/);
    assert.match(textBlockSource, /axios\.post\('\/dictionaries\/search-for-hover-vocabulary'/);
});
