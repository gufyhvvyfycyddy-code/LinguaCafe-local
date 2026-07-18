const closedDecision = () => ({ mode: 'closed', term: '' });
const localOnlyDecision = () => ({ mode: 'local-only', term: '' });

export function resolveHoverVocabularyLookup({
    hoverBoxEnabled,
    searchEnabled,
    plainTextMode,
    hoveredWords,
    normalizeLemma,
}) {
    if (!hoverBoxEnabled || plainTextMode || hoveredWords === null) {
        return closedDecision();
    }

    if (!searchEnabled) {
        return localOnlyDecision();
    }

    let term = '';
    if (hoveredWords.length === 1) {
        const word = hoveredWords[0];
        term = word.lemma.length ? normalizeLemma(word.lemma) : word.word;
    } else {
        term = hoveredWords.map((word, index) => (
            word.word + (word.spaceAfter && index < hoveredWords.length - 1 ? ' ' : '')
        )).join('');
    }

    return { mode: 'search', term };
}
