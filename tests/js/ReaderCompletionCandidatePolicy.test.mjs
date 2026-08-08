import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import { resolveReaderCompletionCandidates } from '../../resources/js/services/ReaderCompletionCandidatePolicy.js';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const textBlockSource = fs.readFileSync(path.join(root, 'resources/js/components/Text/TextBlockGroup.vue'), 'utf8');
const textReaderSource = fs.readFileSync(path.join(root, 'resources/js/components/TextReader/TextReader.vue'), 'utf8');

const resolve = ({
    uniqueWords = [],
    phrases = [],
} = {}) => resolveReaderCompletionCandidates({ uniqueWords, phrases });

test('selects unchecked negative-stage words', () => {
    assert.deepEqual(resolve({
        uniqueWords: [
            { id: 11, definitions_checked: false, stage: -3 },
            { id: 12, definitions_checked: true, stage: -3 },
            { id: 13, definitions_checked: false, stage: 0 },
        ],
    }), [
        { type: 'word', sourceIndex: 0, id: 11 },
    ]);
});

test('selects unchecked negative-stage phrases', () => {
    assert.deepEqual(resolve({
        phrases: [
            { id: 21, definitions_checked: false, stage: -1 },
            { id: 22, definitions_checked: true, stage: -1 },
            { id: 23, definitions_checked: false, stage: 2 },
        ],
    }), [
        { type: 'phrase', sourceIndex: 0, id: 21 },
    ]);
});

test('preserves word-before-phrase order and source-array order', () => {
    assert.deepEqual(resolve({
        uniqueWords: [
            { id: 31, definitions_checked: false, stage: -1 },
            { id: 32, definitions_checked: false, stage: -2 },
        ],
        phrases: [
            { id: 41, definitions_checked: false, stage: -3 },
            { id: 42, definitions_checked: false, stage: -4 },
        ],
    }), [
        { type: 'word', sourceIndex: 0, id: 31 },
        { type: 'word', sourceIndex: 1, id: 32 },
        { type: 'phrase', sourceIndex: 0, id: 41 },
        { type: 'phrase', sourceIndex: 1, id: 42 },
    ]);
});

test('preserves established falsy definitions-checked compatibility', () => {
    const falsyValues = [false, 0, '', null, undefined];
    const uniqueWords = falsyValues.map((definitionsChecked, index) => ({
        id: index,
        definitions_checked: definitionsChecked,
        stage: -1,
    }));

    assert.deepEqual(
        resolve({ uniqueWords }).map(candidate => candidate.id),
        [0, 1, 2, 3, 4],
    );
});

test('preserves loose negative-stage coercion', () => {
    assert.deepEqual(resolve({
        uniqueWords: [
            { id: 'string-negative', definitions_checked: false, stage: '-2' },
            { id: 'zero', definitions_checked: false, stage: null },
            { id: 'missing', definitions_checked: false },
            { id: 'invalid', definitions_checked: false, stage: 'not-a-stage' },
        ],
    }), [
        { type: 'word', sourceIndex: 0, id: 'string-negative' },
    ]);
});

test('retains duplicate and nullable legacy IDs exactly', () => {
    assert.deepEqual(
        resolve({
            uniqueWords: [
                { id: null, definitions_checked: false, stage: -1 },
                { id: 7, definitions_checked: false, stage: -1 },
            ],
            phrases: [
                { id: 7, definitions_checked: false, stage: -1 },
                { definitions_checked: false, stage: -1 },
            ],
        }).map(({ type, id }) => ({ type, id })),
        [
            { type: 'word', id: null },
            { type: 'word', id: 7 },
            { type: 'phrase', id: 7 },
            { type: 'phrase', id: undefined },
        ],
    );
});

test('returns no candidates for checked or non-negative items', () => {
    assert.deepEqual(resolve({
        uniqueWords: [
            { id: 1, definitions_checked: true, stage: -1 },
            { id: 2, definitions_checked: 1, stage: -1 },
            { id: 3, definitions_checked: false, stage: 0 },
        ],
        phrases: [
            { id: 4, definitions_checked: 'yes', stage: -1 },
            { id: 5, definitions_checked: false, stage: 2 },
        ],
    }), []);
});

test('returns no candidates for empty inputs', () => {
    assert.deepEqual(resolve(), []);
});

test('does not mutate frozen source arrays or objects', () => {
    const uniqueWords = Object.freeze([
        Object.freeze({ id: 51, definitions_checked: false, stage: -1 }),
    ]);
    const phrases = Object.freeze([
        Object.freeze({ id: 61, definitions_checked: false, stage: -2 }),
    ]);

    assert.deepEqual(resolve({ uniqueWords, phrases }), [
        { type: 'word', sourceIndex: 0, id: 51 },
        { type: 'phrase', sourceIndex: 0, id: 61 },
    ]);
    assert.equal(Object.hasOwn(uniqueWords[0], 'type'), false);
    assert.equal(Object.hasOwn(phrases[0], 'type'), false);
});

test('TextBlockGroup delegates classification and preserves its compatibility adapter', () => {
    const methodSource = textBlockSource.slice(
        textBlockSource.indexOf('getLeveledUpWordsAndPhrases() {'),
        textBlockSource.indexOf('\n            }', textBlockSource.indexOf('getLeveledUpWordsAndPhrases() {')) + 14,
    );

    assert.match(textBlockSource, /import\s*\{\s*resolveReaderCompletionCandidates\s*\}/);
    assert.equal(
        (textBlockSource.match(/resolveReaderCompletionCandidates\(\{/g) || []).length,
        1,
    );
    assert.match(methodSource, /uniqueWords:\s*this\.uniqueWords/);
    assert.match(methodSource, /phrases:\s*this\.phrases/);
    assert.match(methodSource, /candidate\.type === 'word'\s*\?\s*this\.uniqueWords\[candidate\.sourceIndex\]\s*:\s*this\.phrases\[candidate\.sourceIndex\]/);
    assert.match(methodSource, /sourceItem\.type = candidate\.type/);
    assert.match(methodSource, /wordIds:\s*candidates\.filter\(candidate => candidate\.type === 'word'\)\.map\(candidate => candidate\.id\)/);
    assert.match(methodSource, /phraseIds:\s*candidates\.filter\(candidate => candidate\.type === 'phrase'\)\.map\(candidate => candidate\.id\)/);
    assert.match(methodSource, /wordsAndPhrases/);
    assert.doesNotMatch(methodSource, /definitions_checked|stage < 0/);
});

test('TextReader keeps the established completion-candidate payload inside the R3 preflight/commit boundary', () => {
    assert.match(
        textReaderSource,
        /this\.leveledUpWordsAndPhrases = this\.\$refs\.interactiveText\.getLeveledUpWordsAndPhrases\(\);/,
    );
    assert.match(textReaderSource, /buildFinishBasePayload\(\)/);
    assert.match(textReaderSource, /axios\.post\('\/chapters\/finish', requestPayload\)/);
    assert.match(textReaderSource, /buildReaderFinishRequest\(basePayload, this\.readingSessionId, 'preflight'\)/);
    assert.match(textReaderSource, /buildReaderFinishRequest\(basePayload, this\.readingSessionId, 'commit'\)/);
    assert.match(textReaderSource, /uniqueWords: JSON\.stringify\(this\.\$refs\.interactiveText\.uniqueWords\)/);
    assert.match(textReaderSource, /leveledUpWords: JSON\.stringify\(this\.leveledUpWordsAndPhrases\.wordIds\)/);
    assert.match(textReaderSource, /leveledUpPhrases: JSON\.stringify\(this\.leveledUpWordsAndPhrases\.phraseIds\)/);
    assert.match(textReaderSource, /phrases: JSON\.stringify\(this\.\$refs\.interactiveText\.phrases\)/);
    assert.equal(textReaderSource.includes('ReaderCompletionCandidatePolicy'), false);
});
