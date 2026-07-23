import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import { resolveReaderDragSelection } from '../../resources/js/services/ReaderDragSelectionPolicy.js';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const textBlockSource = fs.readFileSync(path.join(root, 'resources/js/components/Text/TextBlockGroup.vue'), 'utf8');

const words = Array.from({ length: 10 }, (_, index) => ({
    word: `word${index}`,
    sentence_index: Math.floor(index / 5),
    spaceAfter: index !== 9,
    selected: false,
}));

const selected = (...indexes) => indexes.map(wordIndex => ({ wordIndex }));

const resolve = ({
    ongoingSelection = selected(4),
    startingWordIndex = 4,
    targetWordIndex,
    phraseLengthLimit = 14,
    sourceWords = words,
}) => resolveReaderDragSelection({
    words: sourceWords,
    ongoingSelection,
    startingWordIndex,
    targetWordIndex,
    phraseLengthLimit,
});

test('builds an ordered forward drag selection', () => {
    assert.deepEqual(resolve({ targetWordIndex: 7 }).selectedWords, [
        { word: 'word4', wordIndex: 4, sentence_index: 0, spaceAfter: true },
        { word: 'word5', wordIndex: 5, sentence_index: 1, spaceAfter: true },
        { word: 'word6', wordIndex: 6, sentence_index: 1, spaceAfter: true },
        { word: 'word7', wordIndex: 7, sentence_index: 1, spaceAfter: true },
    ]);
});

test('normalizes a reverse drag into source order', () => {
    const result = resolve({ targetWordIndex: 1 });
    assert.deepEqual(result.selectedWordIndexes, [1, 2, 3, 4]);
    assert.deepEqual(result.selectedWords.map(word => word.word), ['word1', 'word2', 'word3', 'word4']);
});

test('returns null for the current first or last selected word', () => {
    assert.equal(resolve({
        ongoingSelection: selected(4, 5, 6),
        targetWordIndex: 4,
    }), null);
    assert.equal(resolve({
        ongoingSelection: selected(4, 5, 6),
        targetWordIndex: 6,
    }), null);
});

test('returns null when a full-length selection is dragged farther outside', () => {
    assert.equal(resolve({
        ongoingSelection: selected(4, 5, 6),
        targetWordIndex: 3,
        phraseLengthLimit: 3,
    }), null);
    assert.equal(resolve({
        ongoingSelection: selected(4, 5, 6),
        targetWordIndex: 7,
        phraseLengthLimit: 3,
    }), null);
});

test('allows a full-length selection to contract inside its current range', () => {
    const result = resolve({
        ongoingSelection: selected(4, 5, 6),
        targetWordIndex: 5,
        phraseLengthLimit: 3,
    });
    assert.deepEqual(result.selectedWordIndexes, [4, 5]);
});

test('preserves the existing reverse-drag length clamp', () => {
    const result = resolve({
        ongoingSelection: selected(5),
        startingWordIndex: 5,
        targetWordIndex: 0,
        phraseLengthLimit: 3,
    });
    assert.deepEqual(result.selectedWordIndexes, [3, 4, 5]);
});

test('preserves the existing forward-drag length formula', () => {
    const result = resolve({
        ongoingSelection: selected(2),
        startingWordIndex: 2,
        targetWordIndex: 8,
        phraseLengthLimit: 3,
    });
    assert.deepEqual(result.selectedWordIndexes, [2, 3, 4]);
});

test('preserves the current forward boundary before the length formula applies', () => {
    const result = resolve({
        ongoingSelection: selected(2),
        startingWordIndex: 2,
        targetWordIndex: 6,
        phraseLengthLimit: 3,
    });
    assert.deepEqual(result.selectedWordIndexes, [2, 3, 4, 5, 6]);
});

test('skips NEWLINE while retaining the source range indexes', () => {
    const sourceWords = words.map(word => ({ ...word }));
    sourceWords[3].word = 'NEWLINE';

    const result = resolve({
        sourceWords,
        ongoingSelection: selected(2),
        startingWordIndex: 2,
        targetWordIndex: 5,
    });
    assert.deepEqual(result.selectedWordIndexes, [2, 4, 5]);
    assert.deepEqual(result.selectedWords.map(word => word.word), ['word2', 'word4', 'word5']);
});

test('accepts the string indexes produced by touch events', () => {
    const result = resolve({ targetWordIndex: '7' });
    assert.deepEqual(result.selectedWordIndexes, [4, 5, 6, 7]);
});

test('does not mutate frozen words or selection', () => {
    const frozenWords = Object.freeze(words.map(word => Object.freeze({ ...word })));
    const ongoingSelection = Object.freeze([Object.freeze({ wordIndex: 4 })]);

    const result = resolve({
        sourceWords: frozenWords,
        ongoingSelection,
        targetWordIndex: 7,
    });

    assert.deepEqual(result.selectedWordIndexes, [4, 5, 6, 7]);
    assert.equal(frozenWords[4].selected, false);
    assert.equal(ongoingSelection[0].wordIndex, 4);
});

test('TextBlockGroup delegates range calculation but retains effects', () => {
    assert.match(textBlockSource, /import\s*\{\s*resolveReaderDragSelection\s*\}/);
    assert.match(textBlockSource, /const dragSelection = resolveReaderDragSelection\(\{/);
    assert.match(textBlockSource, /words: this\.words/);
    assert.match(textBlockSource, /ongoingSelection: this\.ongoingSelection/);
    assert.match(textBlockSource, /this\.words\[i\]\.selected = false/);
    assert.match(textBlockSource, /this\.words\[wordIndex\]\.selected = true/);
    assert.match(textBlockSource, /this\.ongoingSelection = dragSelection\.selectedWords/);
});
