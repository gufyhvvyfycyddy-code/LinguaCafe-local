export function resolveReaderDragSelection({
    words,
    ongoingSelection,
    startingWordIndex,
    targetWordIndex,
    phraseLengthLimit,
}) {
    const firstSelectedIndex = ongoingSelection[0].wordIndex;
    const lastSelectedIndex = ongoingSelection[ongoingSelection.length - 1].wordIndex;

    if (
        targetWordIndex == firstSelectedIndex
        || (
            targetWordIndex < firstSelectedIndex
            && ongoingSelection.length == phraseLengthLimit
        )
        || (
            targetWordIndex > lastSelectedIndex
            && ongoingSelection.length == phraseLengthLimit
        )
        || targetWordIndex == lastSelectedIndex
    ) {
        return null;
    }

    let firstWordIndex = startingWordIndex;
    let lastWordIndex = targetWordIndex;

    if (firstWordIndex > lastWordIndex) {
        firstWordIndex = targetWordIndex;
        lastWordIndex = startingWordIndex;
    }

    if (firstWordIndex < startingWordIndex - phraseLengthLimit + 1) {
        firstWordIndex = startingWordIndex - phraseLengthLimit + 1;
    }

    if (lastWordIndex - firstWordIndex > phraseLengthLimit + 1) {
        lastWordIndex -= lastWordIndex - firstWordIndex - phraseLengthLimit + 1;
    }

    const selectedWords = [];
    const selectedWordIndexes = [];
    for (let index = 0; index < words.length; index++) {
        if (index < firstWordIndex || index > lastWordIndex || words[index].word === 'NEWLINE') {
            continue;
        }

        selectedWordIndexes.push(index);
        selectedWords.push({
            word: words[index].word,
            wordIndex: index,
            sentence_index: words[index].sentence_index,
            spaceAfter: words[index].spaceAfter,
        });
    }

    return { selectedWords, selectedWordIndexes };
}
