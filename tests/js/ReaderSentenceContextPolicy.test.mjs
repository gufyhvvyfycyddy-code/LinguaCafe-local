import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import { resolveReaderSentenceContext } from '../../resources/js/services/ReaderSentenceContextPolicy.js';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const textBlockSource = fs.readFileSync(path.join(root, 'resources/js/components/Text/TextBlockGroup.vue'), 'utf8');

const token = (word, overrides = {}) => ({
    word,
    sentence_index: 0,
    spaceAfter: true,
    is_structure: false,
    ...overrides,
});

const sectionMarker = word => /^\[[A-Z]\]$/.test(word) || /^_SECT_[A-Z]_$/.test(word);

const resolve = (words, selection, overrides = {}) => resolveReaderSentenceContext({
    words,
    selection,
    language: 'english',
    isSectionMarker: sectionMarker,
    ...overrides,
});

test('returns an empty string for an empty selection', () => {
    assert.equal(resolve([token('unused')], []), '');
});

test('uses sentence_index extraction for non-English input', () => {
    const words = [
        token('Bonjour', { sentence_index: 1 }),
        token('monde', { sentence_index: 1, spaceAfter: false }),
        token('NEWLINE', { sentence_index: 1 }),
        token('Salut', { sentence_index: 2 }),
    ];

    assert.equal(resolve(words, [words[0]], { language: 'french' }), 'Bonjour monde');
});

test('resolves duplicate surface words by selected object identity', () => {
    const words = [
        token('first'),
        token('.', { spaceAfter: false }),
        token('PARAGRAPH_BREAK', { is_structure: true }),
        token('second'),
        token('target', { spaceAfter: false }),
        token('.', { spaceAfter: false }),
    ];

    assert.equal(resolve(words, [words[4]]), 'second target.');
});

test('falls back to a matching wordIndex and then to sentence_index', () => {
    const words = [
        token('Alpha', { sentence_index: 1 }),
        token('.', { sentence_index: 1, spaceAfter: false }),
        token('Beta', { sentence_index: 2 }),
        token('wins', { sentence_index: 2, spaceAfter: false }),
        token('.', { sentence_index: 2, spaceAfter: false }),
    ];

    assert.equal(resolve(words, [{ ...words[2], wordIndex: 2 }]), 'Beta wins.');
    assert.equal(resolve(words, [{ word: 'missing', sentence_index: 2, wordIndex: 99 }]), 'Beta wins.');
});

test('stops at structure and section-marker boundaries', () => {
    const words = [
        token('Ignored'),
        token('.', { spaceAfter: false }),
        token('[A]', { is_structure: false }),
        token('Target'),
        token('works', { spaceAfter: false }),
        token('.', { spaceAfter: false }),
        token('NEWLINE', { is_structure: true }),
        token('After'),
    ];

    assert.equal(resolve(words, [words[3]]), 'Target works.');
});

test('treats question and exclamation punctuation as sentence boundaries', () => {
    const words = [
        token('Question', { spaceAfter: false }),
        token('?', { spaceAfter: true }),
        token('Answer', { spaceAfter: false }),
        token('!', { spaceAfter: true }),
        token('Later'),
    ];

    assert.equal(resolve(words, [words[2]]), 'Answer!');
});

test('keeps known abbreviation tokens and split abbreviations inside a sentence', () => {
    const split = [
        token('Mr', { spaceAfter: false }),
        token('.', { spaceAfter: true }),
        token('Smith'),
        token('stayed', { spaceAfter: false }),
        token('.', { spaceAfter: true }),
        token('Next'),
    ];
    const compound = [
        token('Use'),
        token('e.g.'),
        token('tools', { spaceAfter: false }),
        token('.', { spaceAfter: true }),
        token('Next'),
    ];

    assert.equal(resolve(split, [split[2]]), 'Mr. Smith stayed.');
    assert.equal(resolve(compound, [compound[2]]), 'Use e.g. tools.');
});

test('keeps initialisms and decimal tokens inside a sentence', () => {
    const initialism = [
        token('U.S.'),
        token('retail'),
        token('grew', { spaceAfter: false }),
        token('.', { spaceAfter: true }),
        token('Next'),
    ];
    const decimal = [
        token('Value'),
        token('15.2'),
        token('rose', { spaceAfter: false }),
        token('.', { spaceAfter: true }),
        token('Next'),
    ];

    assert.equal(resolve(initialism, [initialism[1]]), 'U.S. retail grew.');
    assert.equal(resolve(decimal, [decimal[1]]), 'Value 15.2 rose.');
});

test('keeps dotted abbreviation chains and split decimals inside a sentence', () => {
    const dotted = [
        token('U', { spaceAfter: false }),
        token('.', { spaceAfter: false }),
        token('S', { spaceAfter: false }),
        token('.', { spaceAfter: true }),
        token('retail'),
        token('grew', { spaceAfter: false }),
        token('.', { spaceAfter: true }),
        token('Next'),
    ];
    const decimal = [
        token('Value'),
        token('15', { spaceAfter: false }),
        token('.', { spaceAfter: false }),
        token('2'),
        token('rose', { spaceAfter: false }),
        token('.', { spaceAfter: true }),
        token('Next'),
    ];

    assert.equal(resolve(dotted, [dotted[4]]), 'U.S. retail grew.');
    assert.equal(resolve(decimal, [decimal[3]]), 'Value 15.2 rose.');
});

test('preserves spaceAfter when joining tokens', () => {
    const words = [
        token('well', { spaceAfter: false }),
        token('-', { spaceAfter: false }),
        token('formed', { spaceAfter: false }),
        token('.', { spaceAfter: false }),
    ];

    assert.equal(resolve(words, [words[2]]), 'well-formed.');
});

test('limits scanning to 120 tokens in each direction', () => {
    const words = Array.from({ length: 126 }, (_, index) => token(`w${index}`));
    words[125].spaceAfter = false;

    const result = resolve(words, [words[125]]);
    assert.equal(result.split(' ').length, 121);
    assert.ok(result.startsWith('w5 '));
    assert.ok(result.endsWith('w125'));
});

test('falls back to sentence_index when the token window exceeds 600 characters', () => {
    const words = [
        token('prefix'),
        token('NEWLINE'),
        ...Array.from({ length: 70 }, (_, index) => token(`abcdefgh${String(index).padStart(2, '0')}`)),
    ];
    words.at(-1).spaceAfter = false;

    const result = resolve(words, [words[40]]);
    assert.ok(result.startsWith('prefix abcdefgh00'));
    assert.ok(result.endsWith('abcdefgh69'));
});

test('does not mutate frozen inputs', () => {
    const words = Object.freeze([
        Object.freeze(token('Safe')),
        Object.freeze(token('sentence', { spaceAfter: false })),
        Object.freeze(token('.', { spaceAfter: false })),
    ]);
    const selection = Object.freeze([words[1]]);

    assert.equal(resolve(words, selection), 'Safe sentence.');
    assert.equal(words[0].word, 'Safe');
    assert.equal(selection[0], words[1]);
});

test('TextBlockGroup delegates sentence policy while retaining Vuex ownership', () => {
    assert.match(textBlockSource, /import\s*\{\s*resolveReaderSentenceContext\s*\}/);
    assert.match(textBlockSource, /return resolveReaderSentenceContext\(\{/);
    assert.match(textBlockSource, /words: this\.words/);
    assert.match(textBlockSource, /selection: this\.selection/);
    assert.match(textBlockSource, /isSectionMarker: this\.isSectionMarker/);
    assert.match(textBlockSource, /setSentenceText', this\.buildSelectedSentenceTextFromTokenWindow\(\)/);
});
