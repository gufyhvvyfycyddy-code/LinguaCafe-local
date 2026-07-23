import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import { resolveReaderNavigationCandidate } from '../../resources/js/services/ReaderNavigationPolicy.js';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const textBlockSource = fs.readFileSync(path.join(root, 'resources/js/components/Text/TextBlockGroup.vue'), 'utf8');

const words = [
    { word: 'alpha', stage: 2 },
    { word: 'space', stage: 2 },
    { word: 'beta', stage: 0 },
    { word: 'gamma', stage: -2 },
    { word: 'delta', stage: 1 },
];

const renderedWordIndexes = new Set([0, 2, 3, 4]);

const resolve = ({
    sourceWords = words,
    selection = [{ wordIndex: 2 }],
    direction = 'next',
    newWordOnly = false,
    highlightedWordOnly = false,
    rendered = renderedWordIndexes,
} = {}) => resolveReaderNavigationCandidate({
    words: sourceWords,
    selection,
    direction,
    newWordOnly,
    highlightedWordOnly,
    renderedWordIndexes: rendered,
});

test('selects the first rendered candidate in either direction', () => {
    assert.equal(resolve({ direction: 'previous' }), 0);
    assert.equal(resolve({ direction: 'next' }), 3);
});

test('anchors previous navigation at the first selected word', () => {
    assert.equal(resolve({
        selection: [{ wordIndex: 2 }, { wordIndex: 3 }],
        direction: 'previous',
    }), 0);
});

test('anchors next navigation at the last selected word', () => {
    assert.equal(resolve({
        selection: [{ wordIndex: 0 }, { wordIndex: 2 }],
        direction: 'next',
    }), 3);
});

test('preserves the legacy no-selection boundary defaults', () => {
    assert.equal(resolve({ selection: [], direction: 'previous' }), 3);
    assert.equal(resolve({ selection: [], direction: 'next' }), 2);
});

test('skips source tokens that do not have rendered word elements', () => {
    assert.equal(resolve({
        selection: [{ wordIndex: 0 }],
        direction: 'next',
    }), 2);
});

test('filters for new words with loose stage-two compatibility', () => {
    const sourceWords = words.map(word => ({ ...word }));
    sourceWords[4].stage = '2';

    assert.equal(resolve({
        sourceWords,
        selection: [{ wordIndex: 0 }],
        direction: 'next',
        newWordOnly: true,
    }), 4);
});

test('filters for highlighted words in either direction', () => {
    assert.equal(resolve({
        selection: [{ wordIndex: 0 }],
        direction: 'next',
        highlightedWordOnly: true,
    }), 3);
    assert.equal(resolve({
        selection: [{ wordIndex: 4 }],
        direction: 'previous',
        highlightedWordOnly: true,
    }), 3);
});

test('preserves the established OR behavior when both filters are enabled', () => {
    assert.equal(resolve({
        selection: [{ wordIndex: 0 }],
        direction: 'next',
        newWordOnly: true,
        highlightedWordOnly: true,
    }), 3);
});

test('returns minus one at boundaries and when no candidate matches', () => {
    assert.equal(resolve({
        selection: [{ wordIndex: 0 }],
        direction: 'previous',
    }), -1);
    assert.equal(resolve({
        selection: [{ wordIndex: 4 }],
        direction: 'next',
    }), -1);
    assert.equal(resolve({
        selection: [{ wordIndex: 2 }],
        direction: 'next',
        newWordOnly: true,
    }), -1);
});

test('returns minus one for an empty word array', () => {
    assert.equal(resolve({
        sourceWords: [],
        selection: [],
        rendered: new Set(),
    }), -1);
});

test('does not mutate frozen words, selection, or rendered indexes', () => {
    const frozenWords = Object.freeze(words.map(word => Object.freeze({ ...word })));
    const frozenSelection = Object.freeze([Object.freeze({ wordIndex: 2 })]);
    const frozenRendered = Object.freeze(new Set(renderedWordIndexes));

    assert.equal(resolve({
        sourceWords: frozenWords,
        selection: frozenSelection,
        rendered: frozenRendered,
    }), 3);
    assert.equal(frozenSelection[0].wordIndex, 2);
    assert.equal(frozenWords[3].stage, -2);
    assert.equal(frozenRendered.size, 4);
});

test('TextBlockGroup delegates both scans but retains DOM measurement and selection effects', () => {
    const previousMethod = textBlockSource.slice(
        textBlockSource.indexOf('selectPreviousWord(newWordOnly, highlightedWordOnly)'),
        textBlockSource.indexOf('selectNextWord(newWordOnly, highlightedWordOnly)'),
    );
    const nextMethod = textBlockSource.slice(
        textBlockSource.indexOf('selectNextWord(newWordOnly, highlightedWordOnly)'),
        textBlockSource.indexOf('collectRenderedWordIndexes() {'),
    );

    assert.match(textBlockSource, /import\s*\{\s*resolveReaderNavigationCandidate\s*\}/);
    assert.equal(
        (textBlockSource.match(/resolveReaderNavigationCandidate\(\{/g) || []).length,
        2,
    );
    assert.equal(
        (textBlockSource.match(/renderedWordIndexes: this\.collectRenderedWordIndexes\(\)/g) || []).length,
        2,
    );
    assert.match(textBlockSource, /document\.querySelectorAll\('\.word\[wordindex\]'\)/);
    assert.match(textBlockSource, /renderedWordIndexes\.add\(parseInt\(wordElement\.getAttribute\('wordindex'\)\)\)/);
    [previousMethod, nextMethod].forEach((methodSource) => {
        assert.match(
            methodSource,
            /if \(wordToSelect === -1\) \{\s*return;\s*\}\s*this\.unselectAllWords\(\);\s*this\.\$nextTick\(\(\) => \{\s*this\.startSelection\(wordToSelect\);\s*this\.finishSelection\(\);\s*\}\);/,
        );
    });
});
