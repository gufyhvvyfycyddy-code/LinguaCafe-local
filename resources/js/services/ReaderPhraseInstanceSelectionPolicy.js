const normalizeWordKey = word => (word || '').toString().trim().toLowerCase();

export function resolveReaderPhraseInstanceSelection({
    words,
    wordIndex,
    phraseIndex,
    uniqueWords,
    uniqueWordMap,
}) {
    let currentWordIndex = wordIndex;
    const selection = [];

    while (
        currentWordIndex > 0
        && (
            words[currentWordIndex - 1].word === 'NEWLINE'
            || words[currentWordIndex - 1].phraseIndexes.includes(phraseIndex)
        )
    ) {
        currentWordIndex--;
    }

    do {
        const word = words[currentWordIndex];
        if (word.word !== 'NEWLINE') {
            const uniqueWordIndex = uniqueWordMap.get(normalizeWordKey(word.word));
            if (uniqueWordIndex !== undefined && uniqueWords[uniqueWordIndex]) {
                const uniqueWord = uniqueWords[uniqueWordIndex];
                selection.push({
                    word: word.word,
                    reading: uniqueWord.reading,
                    kanji: uniqueWord.kanji,
                    sentence_index: word.sentence_index,
                    wordIndex: currentWordIndex,
                    uniqueWordIndex,
                    spaceAfter: word.spaceAfter,
                });
            }
        }

        currentWordIndex++;
    } while (
        currentWordIndex < words.length
        && (
            words[currentWordIndex].word === 'NEWLINE'
            || words[currentWordIndex].phraseIndexes.includes(phraseIndex)
        )
    );

    return selection;
}
