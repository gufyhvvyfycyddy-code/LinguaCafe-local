import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import { resolveReaderPhraseInstanceSelection } from '../../resources/js/services/ReaderPhraseInstanceSelectionPolicy.js';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const textBlockSource = fs.readFileSync(path.join(root, 'resources/js/components/Text/TextBlockGroup.vue'), 'utf8');

const uniqueWords = [
    { word: 'alpha', reading: 'A', kanji: '' },
    { word: 'beta', reading: 'B', kanji: 'β' },
    { word: 'gamma', reading: 'G', kanji: 'γ' },
    { word: 'delta', reading: 'D', kanji: '' },
];

const uniqueWordMap = new Map(uniqueWords.map((word, index) => [word.word, index]));

const makeWord = (word, phraseIndexes, sentenceIndex = 0, spaceAfter = true) => ({
    word,
    phraseIndexes,
    sentence_index: sentenceIndex,
    spaceAfter,
});

const words = [
    makeWord('alpha', []),
    makeWord('beta', [7]),
    makeWord('gamma', [7]),
    makeWord('delta', []),
];

const resolve = ({
    sourceWords = words,
    wordIndex = 2,
    phraseIndex = 7,
    sourceUniqueWords = uniqueWords,
    sourceUniqueWordMap = uniqueWordMap,
} = {}) => resolveReaderPhraseInstanceSelection({
    words: sourceWords,
    wordIndex,
    phraseIndex,
    uniqueWords: sourceUniqueWords,
    uniqueWordMap: sourceUniqueWordMap,
});

test('resolves the complete phrase instance around the selected word', () => {
    assert.deepEqual(resolve(), [
        {
            word: 'beta',
            reading: 'B',
            kanji: 'β',
            sentence_index: 0,
            wordIndex: 1,
            uniqueWordIndex: 1,
            spaceAfter: true,
        },
        {
            word: 'gamma',
            reading: 'G',
            kanji: 'γ',
            sentence_index: 0,
            wordIndex: 2,
            uniqueWordIndex: 2,
            spaceAfter: true,
        },
    ]);
});

test('keeps selection descriptors in source order when starting at the last phrase word', () => {
    assert.deepEqual(resolve({ wordIndex: 2 }).map(word => word.word), ['beta', 'gamma']);
});

test('bridges a NEWLINE inside one phrase instance and excludes it from output', () => {
    const sourceWords = [
        makeWord('alpha', []),
        makeWord('beta', [7], 0),
        makeWord('NEWLINE', [], 0, false),
        makeWord('gamma', [7], 1),
        makeWord('delta', []),
    ];

    const result = resolve({ sourceWords, wordIndex: 3 });
    assert.deepEqual(result.map(word => word.word), ['beta', 'gamma']);
    assert.deepEqual(result.map(word => word.wordIndex), [1, 3]);
    assert.deepEqual(result.map(word => word.sentence_index), [0, 1]);
});

test('stops at adjacent words that do not belong to the requested phrase', () => {
    const sourceWords = [
        makeWord('alpha', [8]),
        makeWord('beta', [7]),
        makeWord('gamma', [7, 8]),
        makeWord('delta', [8]),
    ];

    assert.deepEqual(resolve({ sourceWords, wordIndex: 2 }).map(word => word.word), ['beta', 'gamma']);
});

test('uses the exact requested nested phrase index', () => {
    const sourceWords = [
        makeWord('alpha', [8]),
        makeWord('beta', [7, 8]),
        makeWord('gamma', [7, 8]),
        makeWord('delta', [8]),
    ];

    assert.deepEqual(resolve({ sourceWords, phraseIndex: 7 }).map(word => word.word), ['beta', 'gamma']);
    assert.deepEqual(resolve({ sourceWords, phraseIndex: 8 }).map(word => word.word), ['alpha', 'beta', 'gamma', 'delta']);
});

test('skips tokens missing from the unique-word map', () => {
    const sourceWords = [
        makeWord('beta', [7]),
        makeWord('missing', [7]),
        makeWord('gamma', [7]),
    ];

    assert.deepEqual(resolve({ sourceWords, wordIndex: 1 }).map(word => word.word), ['beta', 'gamma']);
});

test('skips mapped indexes without a valid unique-word record', () => {
    const sourceWords = [makeWord('beta', [7]), makeWord('missing', [7])];
    const sourceMap = new Map([['beta', 1], ['missing', 99]]);

    assert.deepEqual(resolve({
        sourceWords,
        wordIndex: 0,
        sourceUniqueWordMap: sourceMap,
    }).map(word => word.word), ['beta']);
});

test('uses the existing normalized unique-word keys', () => {
    const sourceWords = [makeWord(' BETA ', [7])];
    assert.deepEqual(resolve({ sourceWords, wordIndex: 0 }), [{
        word: ' BETA ',
        reading: 'B',
        kanji: 'β',
        sentence_index: 0,
        wordIndex: 0,
        uniqueWordIndex: 1,
        spaceAfter: true,
    }]);
});

test('handles phrase instances at the beginning and end of the word array', () => {
    const sourceWords = [
        makeWord('alpha', [7]),
        makeWord('beta', [7]),
        makeWord('gamma', []),
        makeWord('delta', [8], 0, false),
    ];

    assert.deepEqual(resolve({ sourceWords, wordIndex: 0 }).map(word => word.word), ['alpha', 'beta']);
    assert.deepEqual(resolve({
        sourceWords,
        wordIndex: 3,
        phraseIndex: 8,
    }).map(word => word.word), ['delta']);
});

test('does not mutate frozen inputs', () => {
    const frozenWords = Object.freeze(words.map(word => Object.freeze({
        ...word,
        phraseIndexes: Object.freeze([...word.phraseIndexes]),
    })));
    const frozenUniqueWords = Object.freeze(uniqueWords.map(word => Object.freeze({ ...word })));

    const result = resolve({
        sourceWords: frozenWords,
        sourceUniqueWords: frozenUniqueWords,
    });

    assert.deepEqual(result.map(word => word.word), ['beta', 'gamma']);
    assert.deepEqual(frozenWords[1].phraseIndexes, [7]);
    assert.equal(frozenUniqueWords[1].reading, 'B');
});

test('TextBlockGroup delegates phrase range resolution but retains phrase cycling and effects', () => {
    assert.match(textBlockSource, /import\s*\{\s*resolveReaderPhraseInstanceSelection\s*\}/);
    assert.match(textBlockSource, /this\.selectPhraseInstanceByWord\(this\.ongoingSelection\[0\]\.wordIndex, phraseIndexes\[0\]\)/);
    assert.match(textBlockSource, /this\.selectPhraseInstanceByWord\(this\.ongoingSelection\[0\]\.wordIndex, phraseIndexes\[i \+ 1\]\)/);
    assert.match(textBlockSource, /this\.ongoingSelection = resolveReaderPhraseInstanceSelection\(\{/);
    assert.match(textBlockSource, /uniqueWordMap: this\.uniqueWordMap/);
    assert.match(textBlockSource, /this\.updatePhraseLookupCount\(this\.selectedPhrase\)/);
    assert.match(textBlockSource, /this\.updateVocabBoxDataAfterSelection\(\)/);
});
