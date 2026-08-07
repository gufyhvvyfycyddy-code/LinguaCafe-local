const displayedInflectionNames = [
    'Non-past',
    'Non-past, polite',
    'Past',
    'Past, polite',
    'Te-form',
    'Potential',
    'Passive',
    'Causative',
    'Causative Passive',
    'Imperative',
];

export const DICTIONARY_LOOKUP_UNAVAILABLE = 'DICTIONARY_LOOKUP_UNAVAILABLE';

export function shouldApplyReaderDictionaryResponse(currentTerm, responseTerm) {
    return currentTerm === responseTerm && currentTerm !== '';
}

export function normalizeDictionaryWarnings(warnings) {
    if (!Array.isArray(warnings)) {
        return [];
    }

    return warnings
        .filter(warning => warning && typeof warning === 'object')
        .map(warning => ({
            dictionaryId: Number.isInteger(warning.dictionary_id) ? warning.dictionary_id : null,
            dictionaryName: typeof warning.dictionary_name === 'string' ? warning.dictionary_name : '',
            code: typeof warning.code === 'string' ? warning.code : 'DICTIONARY_QUERY_FAILED',
            message: typeof warning.message === 'string' && warning.message.trim() !== ''
                ? warning.message.trim()
                : '部分词典暂时不可用。',
        }));
}

export function resolveReaderLookupEnvelope(responseData, resultKey) {
    const data = responseData && typeof responseData === 'object' ? responseData : {};
    const value = data[resultKey];

    return {
        term: typeof data.term === 'string' ? data.term : '',
        results: Array.isArray(value) ? value : [],
        warnings: normalizeDictionaryWarnings(data.warnings),
        configured: data.configured !== false,
    };
}

export function resolveReaderLookupError(error) {
    const code = error?.response?.data?.error?.code || '';
    const message = error?.response?.data?.error?.message || '';

    return {
        code,
        message,
        unavailable: code === DICTIONARY_LOOKUP_UNAVAILABLE,
    };
}

export function joinReaderDictionaryDefinitions(definitions) {
    return Array.isArray(definitions) ? definitions.join(';') : '';
}

export function flattenReaderApiDefinitions(responseData) {
    let definitions = [];

    if (!Array.isArray(responseData)) {
        return definitions;
    }

    responseData.forEach((item) => {
        definitions = definitions.concat(item ? item.definitions : undefined);
    });

    return definitions;
}

export function resolveReaderDisplayedInflections(responseData) {
    if (responseData === '[]' || responseData == '' || responseData === null) {
        return null;
    }

    let data = responseData;
    if (typeof responseData === 'string') {
        data = JSON.parse(responseData);
    }
    if (!Array.isArray(data) || data.length === 0) {
        return null;
    }

    const inflections = [];

    for (let index = 0; index < data.length; index++) {
        if (!displayedInflectionNames.includes(data[index].name)) {
            continue;
        }

        let inflectionIndex = inflections.findIndex(
            item => item.name === data[index].name
        );
        if (inflectionIndex == -1) {
            inflections.push({
                name: data[index].name,
            });
            inflectionIndex = inflections.length - 1;
        }

        if (data[index].form == 'aff-plain:') {
            inflections[inflectionIndex].affPlain = data[index].value;
        }
        if (data[index].form == 'aff-formal:') {
            inflections[inflectionIndex].affFormal = data[index].value;
        }
        if (data[index].form == 'neg-plain:') {
            inflections[inflectionIndex].negPlain = data[index].value;
        }
        if (data[index].form == 'neg-formal:') {
            inflections[inflectionIndex].negFormal = data[index].value;
        }
    }

    return inflections;
}
