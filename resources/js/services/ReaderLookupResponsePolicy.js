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

export function shouldApplyReaderDictionaryResponse(currentTerm, responseTerm) {
    return currentTerm === responseTerm && currentTerm !== '';
}

export function joinReaderDictionaryDefinitions(definitions) {
    return definitions.join(';');
}

export function flattenReaderApiDefinitions(responseData) {
    let definitions = [];

    responseData.forEach((item) => {
        definitions = definitions.concat(item.definitions);
    });

    return definitions;
}

export function resolveReaderDisplayedInflections(responseData) {
    if (responseData === '[]' || responseData == '') {
        return null;
    }

    const data = JSON.parse(responseData);
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
