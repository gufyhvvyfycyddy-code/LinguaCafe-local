export function resolveReaderNavigationCandidate({
    words,
    selection,
    direction,
    newWordOnly,
    highlightedWordOnly,
    renderedWordIndexes,
}) {
    const isPrevious = direction === 'previous';
    const currentWordIndex = selection.length
        ? (
            isPrevious
                ? selection[0].wordIndex
                : selection[selection.length - 1].wordIndex
        )
        : (isPrevious ? words.length - 1 : 0);

    if (
        (isPrevious && currentWordIndex == 0)
        || (!isPrevious && currentWordIndex == words.length - 1)
    ) {
        return -1;
    }

    const step = isPrevious ? -1 : 1;
    for (
        let wordIndex = currentWordIndex + step;
        wordIndex >= 0 && wordIndex < words.length;
        wordIndex += step
    ) {
        if (!renderedWordIndexes.has(wordIndex)) {
            continue;
        }

        if (!newWordOnly && !highlightedWordOnly) {
            return wordIndex;
        }

        if (newWordOnly && words[wordIndex].stage == 2) {
            return wordIndex;
        }

        if (highlightedWordOnly && words[wordIndex].stage < 0) {
            return wordIndex;
        }
    }

    return -1;
}
