const ENGLISH_ABBREVIATIONS = new Set([
    'mr', 'mrs', 'ms', 'dr', 'prof', 'sr', 'jr',
    'st', 'ave', 'blvd', 'rd', 'inc', 'ltd', 'co', 'corp',
    'etc', 'vs', 'viz',
    'jan', 'feb', 'mar', 'apr', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec',
    'gen', 'col', 'capt', 'lt', 'maj', 'sgt', 'cpl', 'pvt',
    'rev', 'hon', 'gov', 'sen', 'rep',
    'no', 'vol', 'pp', 'ch', 'sec', 'fig', 'eq', 'al',
    'dept', 'univ', 'assn', 'bros',
]);

const COMPOUND_ABBREVIATIONS = new Set([
    'e.g', 'i.e', 'u.s', 'u.k', 'u.n', 'a.m', 'p.m',
    'e.g.', 'i.e.', 'u.s.', 'u.k.', 'u.n.', 'a.m.', 'p.m.',
]);

const MAX_TOKENS_PER_DIRECTION = 120;
const MAX_CONTEXT_LENGTH = 600;

function buildSentenceIndexText(words, selection) {
    if (!selection.length) {
        return '';
    }

    const sentenceIndex = selection[0].sentence_index;
    let sentenceText = '';

    for (let index = 0; index < words.length; index++) {
        if (words[index].word === 'NEWLINE' || words[index].sentence_index !== sentenceIndex) {
            continue;
        }

        sentenceText += words[index].word;
        if (words[index].spaceAfter) {
            sentenceText += ' ';
        }
    }

    return sentenceText.trim();
}

function resolveSelectedWordIndex(words, selection) {
    const selected = selection[0];
    const referenceIndex = words.findIndex(word => word === selected);
    if (referenceIndex !== -1) {
        return referenceIndex;
    }

    const index = selected.wordIndex;
    if (
        index !== undefined
        && Number.isInteger(index)
        && index >= 0
        && index < words.length
        && words[index].word === selected.word
    ) {
        return index;
    }

    return -1;
}

function isHardBoundary(word, isSectionMarker) {
    if (!word) return true;
    if (word.word === 'NEWLINE' || word.word === 'PARAGRAPH_BREAK') return true;
    if (word.is_structure) return true;
    return isSectionMarker(word.word);
}

function isKnownAbbreviationToken(word) {
    if (!word) return false;
    const cleaned = word.replace(/\.+$/, '').toLowerCase();
    return ENGLISH_ABBREVIATIONS.has(cleaned)
        || COMPOUND_ABBREVIATIONS.has(cleaned + '.')
        || COMPOUND_ABBREVIATIONS.has(cleaned);
}

function isDecimalToken(word) {
    return Boolean(word) && /^\d[\d,]*\.\d+$/.test(word);
}

function isInitialismToken(word) {
    return Boolean(word) && /^([A-Z]\.){2,}$/.test(word);
}

function isAbbreviationPrecursor(word) {
    if (!word) return false;
    const cleaned = word.word.replace(/\.+$/, '').toLowerCase();
    return ENGLISH_ABBREVIATIONS.has(cleaned);
}

function isDottedAbbreviationPeriod(words, index) {
    const current = words[index];
    if (!current || current.word !== '.') return false;

    const previous = words[index - 1];
    const next = words[index + 1];
    if (!previous || !/^[A-Za-z]$/.test(previous.word)) return false;

    if (next && /^[A-Za-z]$/.test(next.word)) {
        return true;
    }

    const beforePrevious = words[index - 2];
    const beforeBeforePrevious = words[index - 3];
    return Boolean(
        beforeBeforePrevious
        && beforePrevious
        && /^[A-Za-z]$/.test(beforeBeforePrevious.word)
        && beforePrevious.word === '.'
    );
}

function isDecimalSplit(previous, next) {
    return Boolean(previous && next)
        && /\d$/.test(previous.word)
        && /^\d/.test(next.word);
}

function isSentenceBoundary(words, word, index) {
    if (!word) return false;
    const value = word.word;

    if (value === '?' || value === '!' || value.endsWith('?') || value.endsWith('!')) {
        return true;
    }

    if (value === '.') {
        const previous = index > 0 ? words[index - 1] : null;
        if (!previous || previous.word.endsWith('.')) return true;
        if (isAbbreviationPrecursor(previous)) return false;
        if (isDottedAbbreviationPeriod(words, index)) return false;

        const next = index < words.length - 1 ? words[index + 1] : null;
        if (isDecimalSplit(previous, next)) return false;
        return true;
    }

    if (value.endsWith('.') && value !== '.') {
        if (isKnownAbbreviationToken(value)) return false;
        if (isDecimalToken(value)) return false;
        if (isInitialismToken(value)) return false;
        return true;
    }

    return false;
}

export function resolveReaderSentenceContext({
    words,
    selection,
    language,
    isSectionMarker,
}) {
    if (!selection.length) {
        return '';
    }

    const fallbackText = () => buildSentenceIndexText(words, selection);
    if (language !== 'english') {
        return fallbackText();
    }

    const startIndex = resolveSelectedWordIndex(words, selection);
    if (startIndex < 0) {
        return fallbackText();
    }

    let left = startIndex;
    let tokenCount = startIndex - left;
    while (left > 0 && tokenCount < MAX_TOKENS_PER_DIRECTION) {
        const candidate = words[left - 1];
        if (isHardBoundary(candidate, isSectionMarker)) break;
        if (isSentenceBoundary(words, candidate, left - 1)) break;
        left--;
        tokenCount++;
    }

    let right = startIndex;
    tokenCount = right - startIndex;
    while (right < words.length - 1 && tokenCount < MAX_TOKENS_PER_DIRECTION) {
        const candidate = words[right + 1];
        if (isHardBoundary(candidate, isSectionMarker)) break;
        if (isSentenceBoundary(words, words[right], right)) break;
        right++;
        tokenCount++;
    }

    let text = '';
    for (let index = left; index <= right; index++) {
        text += words[index].word;
        if (words[index].spaceAfter && index < right) {
            text += ' ';
        }
    }
    text = text.trim();

    return text.length > MAX_CONTEXT_LENGTH ? fallbackText() : text;
}
