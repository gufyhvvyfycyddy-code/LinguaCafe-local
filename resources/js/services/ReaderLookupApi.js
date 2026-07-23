export function getApiDictionaryEnabled() {
    return axios.get('/dictionaries/api/is-enabled');
}

export function searchReaderInflections(term) {
    return axios.post('/dictionaries/search/inflections', { term });
}

export function searchReaderHoverDictionary(language, term) {
    return axios.post('/dictionaries/search-for-hover-vocabulary', {
        language,
        term,
    });
}

export function searchReaderApiDictionary(language, term) {
    return axios.post('/dictionaries/api/search', {
        language,
        term,
    });
}
