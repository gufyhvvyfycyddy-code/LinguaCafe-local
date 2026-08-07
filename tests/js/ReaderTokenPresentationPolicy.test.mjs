import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import {
    isReaderLastWordOfSentence,
    isReaderSectionMarker,
    resolveReaderAiTranslation,
    resolveReaderTokenClasses,
    shouldShowReaderHighlightedWordFurigana,
    shouldShowReaderNewWordFurigana,
    usesReaderSpacelessLanguage,
} from '../../resources/js/services/ReaderTokenPresentationPolicy.js';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const textBlockSource = fs.readFileSync(path.join(root, 'resources/js/components/Text/TextBlockGroup.vue'), 'utf8');

test('applies spaceless layout only to the established languages', () => {
    assert.equal(usesReaderSpacelessLanguage('chinese'), true);
    assert.equal(usesReaderSpacelessLanguage('japanese'), true);
    assert.equal(usesReaderSpacelessLanguage('thai'), true);
    assert.equal(usesReaderSpacelessLanguage('english'), false);
    assert.equal(usesReaderSpacelessLanguage('Japanese'), false);
    assert.equal(usesReaderSpacelessLanguage(null), false);
});

test('recognizes bracketed uppercase section markers only', () => {
    assert.equal(isReaderSectionMarker('[A]'), true);
    assert.equal(isReaderSectionMarker('[Z]'), true);
    assert.equal(isReaderSectionMarker('[a]'), false);
    assert.equal(isReaderSectionMarker('[AA]'), false);
    assert.equal(isReaderSectionMarker('[1]'), false);
});

test('preserves the legacy section-marker prefix and length rule', () => {
    assert.equal(isReaderSectionMarker('_SECT_A_'), true);
    assert.equal(isReaderSectionMarker('_SECT_1_'), true);
    assert.equal(isReaderSectionMarker('_SECT_A'), false);
    assert.equal(isReaderSectionMarker('SECT_A__'), false);
    assert.equal(isReaderSectionMarker(null), false);
    assert.equal(isReaderSectionMarker({}), false);
});

test('finds a sentence boundary at the next non-structure token', () => {
    const words = [
        { word: 'alpha', sentence_index: 0 },
        { word: 'NEWLINE', sentence_index: 0, is_structure: true },
        { word: 'beta', sentence_index: 1 },
    ];

    assert.equal(isReaderLastWordOfSentence(words, 0), true);
});

test('does not mark a token when the next non-structure token stays in the sentence', () => {
    const words = [
        { word: 'alpha', sentence_index: 0 },
        { word: 'NEWLINE', sentence_index: 0, is_structure: true },
        { word: 'beta', sentence_index: 0 },
    ];

    assert.equal(isReaderLastWordOfSentence(words, 0), false);
});

test('marks the final non-structure token and rejects invalid or structure anchors', () => {
    const words = [
        { word: 'alpha', sentence_index: 0 },
        { word: 'NEWLINE', sentence_index: 0, is_structure: true },
    ];

    assert.equal(isReaderLastWordOfSentence(words, 0), true);
    assert.equal(isReaderLastWordOfSentence(words, 1), false);
    assert.equal(isReaderLastWordOfSentence(words, 9), false);
    assert.equal(isReaderLastWordOfSentence([], 0), false);
});

test('looks up AI translations with strict sentence-index equality', () => {
    const translations = [
        { sentence_index: 1, translation_zh: '第一句' },
        { sentence_index: '2', translation_zh: '第二句' },
    ];

    assert.equal(resolveReaderAiTranslation(translations, 1), '第一句');
    assert.equal(resolveReaderAiTranslation(translations, 2), '');
    assert.equal(resolveReaderAiTranslation(translations, '2'), '第二句');
});

test('preserves empty and missing AI translation behavior', () => {
    assert.equal(resolveReaderAiTranslation([], 1), '');
    assert.equal(resolveReaderAiTranslation(null, 1), '');
    assert.equal(resolveReaderAiTranslation([{ sentence_index: 1 }], 1), undefined);
});

test('resolves the established token class map', () => {
    const word = {
        stage: -2,
        selected: false,
        hover: true,
        sourceHighlight: true,
        phraseIndexes: [4, 5],
        spaceAfter: true,
        phraseStart: false,
        phraseEnd: true,
    };

    assert.deepEqual(resolveReaderTokenClasses({
        word,
        hideAllHighlights: false,
        hideNewWordHighlights: false,
    }), {
        'no-highlight': false,
        word: true,
        'selected-font': true,
        highlighted: true,
        'source-highlight': true,
        'reader-unfamiliar-target': false,
        phrase: 2,
        'space-after': true,
        'phrase-start': false,
        'phrase-end': true,
    });
});

test('adds Reader-only unfamiliar presentation without changing stage semantics', () => {
    const word = {
        stage: -2,
        selected: false,
        hover: false,
        sourceHighlight: false,
        phraseIndexes: [],
        spaceAfter: false,
    };
    const classes = resolveReaderTokenClasses({
        word,
        hideAllHighlights: false,
        hideNewWordHighlights: false,
        markedUnfamiliar: true,
    });
    assert.equal(classes['reader-unfamiliar-target'], true);
    assert.equal(word.stage, -2);
});

test('preserves loose stage-two handling for hidden new-word highlights', () => {
    const word = {
        stage: '2',
        selected: false,
        hover: false,
        phraseIndexes: [],
    };

    assert.equal(resolveReaderTokenClasses({
        word,
        hideAllHighlights: false,
        hideNewWordHighlights: true,
    })['no-highlight'], true);
    assert.equal(resolveReaderTokenClasses({
        word,
        hideAllHighlights: true,
        hideNewWordHighlights: false,
    })['no-highlight'], true);
});

test('shows new-word furigana only under all established conditions', () => {
    const word = { word: '日本', furigana: 'にほん', stage: '2' };

    assert.equal(shouldShowReaderNewWordFurigana({
        word,
        furiganaOnNewWords: true,
        plainTextMode: false,
    }), true);
    assert.equal(shouldShowReaderNewWordFurigana({
        word,
        furiganaOnNewWords: false,
        plainTextMode: false,
    }), false);
    assert.equal(shouldShowReaderNewWordFurigana({
        word: { ...word, furigana: '' },
        furiganaOnNewWords: true,
        plainTextMode: false,
    }), false);
    assert.equal(shouldShowReaderNewWordFurigana({
        word: { ...word, furigana: '日本' },
        furiganaOnNewWords: true,
        plainTextMode: false,
    }), false);
    assert.equal(shouldShowReaderNewWordFurigana({
        word,
        furiganaOnNewWords: true,
        plainTextMode: true,
    }), false);
});

test('shows highlighted-word furigana only under all established conditions', () => {
    const word = { word: '日本', furigana: 'にほん', stage: '-4' };

    assert.equal(shouldShowReaderHighlightedWordFurigana({
        word,
        furiganaOnHighlightedWords: true,
        plainTextMode: false,
    }), true);
    assert.equal(shouldShowReaderHighlightedWordFurigana({
        word: { ...word, stage: 2 },
        furiganaOnHighlightedWords: true,
        plainTextMode: false,
    }), false);
    assert.equal(shouldShowReaderHighlightedWordFurigana({
        word,
        furiganaOnHighlightedWords: false,
        plainTextMode: false,
    }), false);
    assert.equal(shouldShowReaderHighlightedWordFurigana({
        word,
        furiganaOnHighlightedWords: true,
        plainTextMode: true,
    }), false);
});

test('does not mutate frozen presentation inputs', () => {
    const word = Object.freeze({
        word: 'alpha',
        furigana: 'alpha-reading',
        stage: 2,
        selected: false,
        hover: false,
        phraseIndexes: Object.freeze([]),
    });
    const words = Object.freeze([
        Object.freeze({ word: 'alpha', sentence_index: 0 }),
    ]);
    const translations = Object.freeze([
        Object.freeze({ sentence_index: 0, translation_zh: '译文' }),
    ]);

    resolveReaderTokenClasses({ word, hideAllHighlights: false, hideNewWordHighlights: false });
    shouldShowReaderNewWordFurigana({ word, furiganaOnNewWords: true, plainTextMode: false });
    isReaderLastWordOfSentence(words, 0);
    assert.equal(resolveReaderAiTranslation(translations, 0), '译文');
    assert.equal(Object.hasOwn(word, 'no-highlight'), false);
});

test('TextBlockGroup delegates presentation while retaining the token DOM boundary', () => {
    assert.equal(textBlockSource.includes("import * as ReaderTokenPresentation from './../../services/ReaderTokenPresentationPolicy'"), true);
    assert.equal(textBlockSource.includes("'spaceless-language': usesSpacelessLanguage()"), true);
    assert.equal(textBlockSource.includes(':class="readerTokenClasses(word, wordIndex)"'), true);
    assert.equal(textBlockSource.includes('const wordIndex = this.words.indexOf(word)'), false);
    assert.equal(textBlockSource.includes('v-if="showNewWordFurigana(word)"'), true);
    assert.equal(textBlockSource.includes('v-if="showHighlightedWordFurigana(word)"'), true);
    assert.equal(textBlockSource.includes('isSectionMarker: ReaderTokenPresentation.isReaderSectionMarker'), true);
    assert.equal(textBlockSource.includes('ReaderTokenPresentation.isReaderLastWordOfSentence(this.words, wordIndex)'), true);
    assert.equal(textBlockSource.includes('ReaderTokenPresentation.resolveReaderAiTranslation(this.aiSentenceTranslations, sentenceIndex)'), true);

    assert.equal((textBlockSource.match(/<rt v-if=/g) || []).length, 2);
    assert.equal((textBlockSource.match(/<ruby class="rubyword selected-font"/g) || []).length, 1);
    assert.equal((textBlockSource.match(/class="lc-ai-sentence-translation"/g) || []).length, 1);
    assert.equal(textBlockSource.includes('<br v-if="word.is_structure && word.word === \'NEWLINE\'" />'), true);
    assert.equal(textBlockSource.includes('v-else-if="word.is_structure && isSectionMarker(word.word)"'), true);
    assert.equal(textBlockSource.includes(':wordindex="wordIndex"'), true);
    assert.equal(textBlockSource.includes(':phrasestage="word.phraseStage"'), true);
    assert.equal(textBlockSource.includes('@mousedown.stop="startSelectionMouseEvent"'), true);
    assert.equal(textBlockSource.includes('@mouseup.stop="finishSelection"'), true);
    assert.equal(textBlockSource.includes('<template v-for="(word, wordIndex) in words"><!--'), true);
});
