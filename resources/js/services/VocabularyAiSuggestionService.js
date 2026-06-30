/**
 * VocabularyAiSuggestionService
 * =============================
 * Pure functions for the AI vocabulary / phrase suggestion lookup flow shared
 * between the wide-screen VocabularySideBox and the responsive VocabularyBox.
 *
 * Design rules:
 *   - Pure functions only (no Vue, no Vuex store, no axios import, no DOM).
 *   - The only side effect is the GET request issued by the httpClient passed
 *     into `fetchAiSuggestions` by the caller.
 *   - The service does NOT commit Vuex state, does NOT open any form, and does
 *     NOT save data. Callers own state mutations and UI transitions.
 *
 * Why this service exists:
 *   Previously VocabularySideBox.vue and VocabularyBox.vue each carried their
 *   own copy of: (1) lookup-context construction, (2) the axios lookup call +
 *   response shaping, (3) AI vocab/phrase sense payload construction. Any
 *   future change to AI suggestion rules would have to be applied in two
 *   places. This service gives AI suggestion rules a single entry point.
 */

/**
 * Build the lookup context for an AI suggestion request.
 *
 * `sentenceIndex === 0` is a valid value and must NOT be treated as missing.
 *
 * @param {Object} input
 * @param {string|number} input.chapterId
 * @param {number|null} input.sentenceIndex
 * @param {string} input.word
 * @param {string} [input.studyBase]
 * @param {string} [input.storeStudyBase]
 * @param {string} [input.baseWord]
 * @returns {{chapterId: *, word: string, lemma: string, sentenceIndex: number}|null}
 *         null when chapterId / word / sentenceIndex is missing.
 */
export function buildAiSuggestionLookupContext({ chapterId, sentenceIndex, word, studyBase, storeStudyBase, baseWord }) {
    if (!chapterId || !word || sentenceIndex === null || sentenceIndex === undefined) {
        return null;
    }
    const lemma = studyBase || storeStudyBase || baseWord || word;
    return { chapterId, word, lemma, sentenceIndex };
}

/**
 * Issue the AI suggestion lookup request and shape the response.
 *
 * The service does NOT touch Vuex; it only returns a normalized result so the
 * caller can commit whichever mutations it needs.
 *
 * @param {object} httpClient - Usually axios. Must expose `.get(url, config)`.
 * @param {{chapterId: *, word: string, lemma: string, sentenceIndex: number}} context
 * @returns {Promise<{vocabularySuggestions: Array, phraseSuggestions: Array}>}
 */
export function fetchAiSuggestions(httpClient, context) {
    if (!context) {
        return Promise.resolve({ vocabularySuggestions: [], phraseSuggestions: [] });
    }
    return httpClient.get('/chapters/ai-assist/lookup/' + context.chapterId, {
        params: {
            word: context.word,
            lemma: context.lemma,
            sentence_index: context.sentenceIndex,
        }
    }).then((response) => {
        const data = (response && response.data) || {};
        if (!data.success) {
            return { vocabularySuggestions: [], phraseSuggestions: [] };
        }
        return {
            vocabularySuggestions: data.vocabulary_suggestions || [],
            phraseSuggestions: data.phrase_suggestions || [],
        };
    });
}

/**
 * Build the payload passed to `wordSensesList.openAddFormFromAi` for an AI
 * vocabulary suggestion row.
 *
 * @param {Object} vi - AI vocabulary suggestion item
 * @returns {{pos: string, sense_zh: string, source_sentence: string, reason: string}}
 */
export function buildAiVocabSensePayload(vi) {
    return {
        pos: (vi && vi.pos) || 'verb',
        sense_zh: (vi && vi.meaning_zh) || '',
        source_sentence: (vi && vi.source_sentence) || '',
        reason: (vi && vi.reason) || '',
    };
}

/**
 * Build the payload passed to `wordSensesList.openAddFormFromAi` for an AI
 * phrase suggestion row.
 *
 * @param {Object} pi - AI phrase suggestion item
 * @returns {{pos: string, sense_zh: string, source_sentence: string, reason: string}}
 */
export function buildAiPhraseSensePayload(pi) {
    return {
        pos: 'other',
        sense_zh: (pi && pi.meaning_zh) || '',
        source_sentence: (pi && pi.source_sentence) || '',
        reason: '词组：' + ((pi && pi.phrase) || ''),
    };
}

/**
 * Whether a fetch result carries any AI suggestion rows.
 *
 * @param {{vocabularySuggestions: Array, phraseSuggestions: Array}} result
 * @returns {boolean}
 */
export function hasAiSuggestions(result) {
    if (!result) return false;
    const vocab = result.vocabularySuggestions || [];
    const phrase = result.phraseSuggestions || [];
    return vocab.length > 0 || phrase.length > 0;
}
