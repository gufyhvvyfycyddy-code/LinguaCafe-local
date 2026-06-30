/**
 * WordSenseCandidateService
 * ============================
 * Pure functions for the word sense candidate query flow shared between
 * WordSensesList.vue and any future components that display sense candidates.
 *
 * Design rules:
 *   - Pure functions only (no Vue, no Vuex store, no axios import, no DOM).
 *   - The only side effect is the GET request issued by the httpClient passed
 *     into `fetchWordSenseCandidates` by the caller.
 *   - The service does NOT save data, does NOT open any form, and does NOT
 *     mutate state. Callers own state mutations and UI transitions.
 *
 * Why this service exists:
 *   Previously WordSensesList.vue carried its own axios call with no stale
 *   guard. When the user clicked words in quick succession, a late response
 *   from an old lemma could overwrite the current lemma's sense list. This
 *   service gives sense candidate queries a single entry point with a stable
 *   lookup-key pattern that callers use to discard stale responses.
 */

/**
 * Build the lookup context for a sense candidate request.
 *
 * @param {Object} input
 * @param {string} input.lemma
 * @param {string} input.language
 * @returns {{lemma: string, language: string}|null}
 *         null when lemma or language is missing.
 */
export function buildWordSenseCandidateLookupContext({ lemma, language }) {
    if (!lemma || !language) {
        return null;
    }
    return {
        lemma: lemma.trim(),
        language: language,
    };
}

/**
 * Build a stable lookup key for guarding against stale sense-candidate
 * responses.
 *
 * The key stays frontend-local and is only used to compare whether the
 * response still belongs to the latest requested lemma.
 *
 * @param {{lemma: string, language: string}|null} context
 * @returns {string}
 */
export function buildWordSenseCandidateLookupKey(context) {
    if (!context) {
        return '';
    }
    return [
        context.language,
        context.lemma,
    ].join('|');
}

/**
 * Issue the sense candidate lookup request.
 *
 * The service does NOT mutate state; it only returns the normalized result
 * so the caller can update its own data properties.
 *
 * @param {object} httpClient - Usually axios. Must expose `.get(url, config)`.
 * @param {{lemma: string, language: string}} context
 * @returns {Promise<Array>}
 */
export function fetchWordSenseCandidates(httpClient, context) {
    if (!context) {
        return Promise.resolve([]);
    }
    return httpClient.get('/senses/candidates', {
        params: {
            lemma: context.lemma,
            language: context.language,
        },
    }).then((response) => {
        const data = response && response.data;
        return Array.isArray(data) ? data : [];
    });
}
