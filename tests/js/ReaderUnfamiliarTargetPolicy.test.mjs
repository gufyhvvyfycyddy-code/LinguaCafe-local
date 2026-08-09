import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

import {
    buildReaderAiAssistV2SourceRequest,
    readerUnfamiliarTargetKey,
    readerUnfamiliarWordIndexes,
    resolveReaderUnfamiliarTarget,
} from '../../resources/js/services/ReaderUnfamiliarTargetPolicy.js';

const words = [
    { word: 'The', sentence_index: 0, spaceAfter: true },
    { word: 'river', sentence_index: 0, spaceAfter: true },
    { word: 'bank', sentence_index: 0, spaceAfter: false },
    { word: '.', sentence_index: 0, spaceAfter: true },
    { word: 'Next', sentence_index: 1, spaceAfter: true },
    { word: 'line', sentence_index: 1, spaceAfter: false },
];

const select = (...indexes) => indexes.map(index => ({ wordIndex: index }));

test('single explicit selection becomes a word target without learning-stage semantics', () => {
    const result = resolveReaderUnfamiliarTarget({ selection: select(2), words });
    assert.equal(result.ok, true);
    assert.deepEqual(result.target, {
        kind: 'word',
        start_word_index: 2,
        end_word_index: 2,
        sentence_index: 0,
        surface: 'bank',
    });
    assert.equal(Object.hasOwn(result.target, 'stage'), false);
});

test('same-sentence drag becomes a bounded phrase target', () => {
    const result = resolveReaderUnfamiliarTarget({ selection: select(1, 2), words });
    assert.equal(result.ok, true);
    assert.equal(result.target.kind, 'phrase');
    assert.equal(result.target.surface, 'river bank');
});

test('cross-sentence, gaps and structure boundaries fail closed', () => {
    assert.equal(resolveReaderUnfamiliarTarget({ selection: select(2, 3, 4), words }).ok, false);
    assert.equal(resolveReaderUnfamiliarTarget({ selection: select(1, 3), words }).ok, false);
    const structureWords = words.map(word => ({ ...word }));
    structureWords[2] = { word: 'NEWLINE', sentence_index: 0, is_structure: true };
    assert.equal(resolveReaderUnfamiliarTarget({ selection: select(1, 2), words: structureWords }).ok, false);
});

test('target identity and marked word indexes use the current server-snapshot shape', () => {
    const target = resolveReaderUnfamiliarTarget({ selection: select(2), words }).target;
    assert.equal(readerUnfamiliarTargetKey(target), 'word:2:2');
    assert.deepEqual(readerUnfamiliarWordIndexes([target]), [2]);
});

test('V2 source request sends only chapter identity and positional marked targets', () => {
    const targets = [
        { kind: 'word', start_word_index: 2, end_word_index: 2, sentence_index: 0, surface: 'bank' },
        { kind: 'phrase', start_word_index: 4, end_word_index: 5, sentence_index: 1, surface: 'Next line' },
    ];
    assert.deepEqual(buildReaderAiAssistV2SourceRequest(12, targets), {
        chapterId: 12,
        schema_version: 'linguacafe_ai_reading_assist_v2',
        marked_targets: [
            { kind: 'word', start_word_index: 2, end_word_index: 2 },
            { kind: 'phrase', start_word_index: 4, end_word_index: 5 },
        ],
    });
});

test('TextBlockGroup keeps ordinary lookup and explicit unfamiliar marking as separate paths', () => {
    const source = fs.readFileSync('resources/js/components/Text/TextBlockGroup.vue', 'utf8');
    assert.match(source, /unfamiliarMarkMode/);
    assert.match(source, /finishUnfamiliarMarkSelection/);
    assert.match(source, /\$emit\('mark-unfamiliar'/);
    assert.match(source, /updateWordLookupCount/);
});
