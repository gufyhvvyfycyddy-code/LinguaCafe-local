export function resolveReaderCompletionCandidates({
    uniqueWords = [],
    phrases = [],
} = {}) {
    const candidates = [];

    uniqueWords.forEach((word, sourceIndex) => {
        if (!word.definitions_checked && word.stage < 0) {
            candidates.push({
                type: 'word',
                sourceIndex,
                id: word.id,
            });
        }
    });

    phrases.forEach((phrase, sourceIndex) => {
        if (!phrase.definitions_checked && phrase.stage < 0) {
            candidates.push({
                type: 'phrase',
                sourceIndex,
                id: phrase.id,
            });
        }
    });

    return candidates;
}
