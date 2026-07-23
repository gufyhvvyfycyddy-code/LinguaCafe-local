export function usesReaderSpacelessLanguage(language) {
    return ['chinese', 'japanese', 'thai'].includes(language);
}

export function isReaderSectionMarker(word) {
    if (typeof word !== 'string') {
        return false;
    }

    if (
        word.length === 3
        && word[0] === '['
        && word[2] === ']'
        && word[1] >= 'A'
        && word[1] <= 'Z'
    ) {
        return true;
    }

    return word.startsWith('_SECT_') && word.length === 8;
}

export function isReaderLastWordOfSentence(words = [], wordIndex) {
    const word = words[wordIndex];
    if (!word || word.is_structure) {
        return false;
    }

    for (let index = wordIndex + 1; index < words.length; index++) {
        if (!words[index].is_structure) {
            return words[index].sentence_index !== word.sentence_index;
        }
    }

    return true;
}

export function resolveReaderAiTranslation(aiSentenceTranslations, sentenceIndex) {
    if (!aiSentenceTranslations || !aiSentenceTranslations.length) {
        return '';
    }

    const match = aiSentenceTranslations.find(
        translation => translation.sentence_index === sentenceIndex
    );
    return match ? match.translation_zh : '';
}

export function resolveReaderTokenClasses({
    word,
    hideAllHighlights,
    hideNewWordHighlights,
}) {
    return {
        'no-highlight': hideAllHighlights || (hideNewWordHighlights && word.stage == 2),
        word: true,
        'selected-font': true,
        highlighted: word.selected || word.hover,
        'source-highlight': word.sourceHighlight,
        phrase: word.phraseIndexes.length,
        'space-after': word.spaceAfter,
        'phrase-start': word.phraseStart,
        'phrase-end': word.phraseEnd,
    };
}

export function shouldShowReaderNewWordFurigana({
    word,
    furiganaOnNewWords,
    plainTextMode,
}) {
    return Boolean(word.stage == 2
        && furiganaOnNewWords
        && word.furigana.length
        && word.word !== word.furigana
        && !plainTextMode);
}

export function shouldShowReaderHighlightedWordFurigana({
    word,
    furiganaOnHighlightedWords,
    plainTextMode,
}) {
    return Boolean(word.stage < 0
        && furiganaOnHighlightedWords
        && word.furigana.length
        && word.word !== word.furigana
        && !plainTextMode);
}
