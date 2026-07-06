/**
 * AiStudyCardGenerateCardsService
 * ===============================
 * Pure functions for the V5 "generate study cards" flow shared between the
 * wide-screen VocabularySideBox and the responsive VocabularyBox.
 *
 * Design rules:
 *   - Pure functions only (no Vue, no Vuex store, no DOM).
 *   - The only side effect is the POST request issued by the axios instance
 *     passed into `generateAiStudyCards` by the caller.
 *   - The service does NOT commit Vuex state, does NOT open any dialog, and
 *     does NOT navigate. Callers own state mutations and UI transitions.
 *   - The service does NOT call any external AI provider. The V5 flow only
 *     POSTs to the local `/ai-study-card/generate-cards` endpoint, which
 *     creates confirmed WordSense + target_type=sense ReviewCard without
 *     writing ReviewLog or changing FSRS.
 *
 * Why this service exists:
 *   Previously VocabularySideBox.vue and VocabularyBox.vue each carried their
 *   own copy of: (1) building the confirm-items list from the final
 *   candidates package, (2) filtering confirmed items (sense_zh required),
 *   (3) the axios POST + response/error shaping. Any future change to V5
 *   rules would have to be applied in two places. This service gives V5
 *   generation rules a single entry point (GM52-AIStudyCardV5-DesktopArchitectureConvergence-1000-1).
 */

/**
 * Build the list of confirm items from a V4 final candidates package.
 *
 * Each produced item has:
 *   - source: 'user_selected' | 'ai_recommended'
 *   - sense_zh: '' (user must fill in; required)
 *   - sense_en: '' (optional, may stay empty)
 *   - reason: only present for ai_recommended items, used as reference
 *     display only — NEVER auto-filled into sense_zh.
 *
 * @param {Object} finalCandidatesPackage The V4 final candidates package.
 * @returns {Array<Object>} List of confirm items for the dialog.
 */
export function buildGenerateCardItems(finalCandidatesPackage) {
    if (!finalCandidatesPackage) {
        return [];
    }

    const items = [];
    const pkg = finalCandidatesPackage;

    // user_selected items (from V4 candidate package user_selected_items)
    if (Array.isArray(pkg.user_selected_items)) {
        for (const item of pkg.user_selected_items) {
            items.push({
                source: 'user_selected',
                item_id: item.item_id || null,
                word: item.word || '',
                lemma: item.lemma || item.word || '',
                surface: item.surface || item.word || '',
                chapter_id: item.chapter_id || null,
                text_block_index: item.text_block_index ?? null,
                sentence_index: item.sentence_index ?? null,
                sentence_id: null, // V4 candidate package does not carry sentence_id
                sentence_text: item.sentence_text || '',
                sense_zh: '', // user must input
                sense_en: '',
                pos: '',
                aliases_zh: [],
                collocations: [],
            });
        }
    }

    // ai_recommended_selected items (from V4 candidate package ai_recommended_selected_items)
    if (Array.isArray(pkg.ai_recommended_selected_items)) {
        for (const item of pkg.ai_recommended_selected_items) {
            items.push({
                source: 'ai_recommended',
                item_id: null,
                word: item.word || '',
                lemma: item.lemma || item.word || '',
                surface: item.surface || item.word || '',
                chapter_id: null, // AI recommended words have no chapter_id
                text_block_index: null,
                sentence_index: null,
                sentence_id: null,
                sentence_text: item.sentence_text || '',
                // V5 hardening: reason is only a recommendation hint, never auto-filled into sense_zh.
                // User must manually enter the Chinese definition to avoid saving "reason" as sense_zh.
                reason: item.reason || '', // reference display only
                sense_zh: '', // user must input
                sense_en: '', // optional, may stay empty
                pos: '',
                aliases_zh: [],
                collocations: [],
            });
        }
    }

    return items;
}

/**
 * Filter confirmed items: only return items whose sense_zh is non-empty
 * after trimming. Does NOT mutate the input array.
 *
 * @param {Array<Object>} items Confirm items from the dialog.
 * @returns {Array<Object>} Items with non-empty sense_zh.
 */
export function filterConfirmedGenerateCardItems(items) {
    if (!Array.isArray(items)) {
        return [];
    }
    return items.filter(item => item.sense_zh && item.sense_zh.trim() !== '');
}

/**
 * POST to `/ai-study-card/generate-cards` to create confirmed WordSense +
 * target_type=sense ReviewCard for each confirmed item.
 *
 * Returns a Promise that resolves with the response data and rejects with an
 * Error carrying a `message` field suitable for UI display.
 *
 * The endpoint does NOT call any external AI provider, does NOT write
 * ReviewLog, does NOT change FSRS, and does NOT create legacy word
 * ReviewCard. See AiStudyCardPendingItemService::generateCardsFromConfirmedCandidates.
 *
 * @param {Object} axios The axios instance (window.axios by default).
 * @param {Object} finalCandidatesPackage The V4 final candidates package.
 * @param {Array<Object>} confirmedItems Items with non-empty sense_zh.
 * @returns {Promise<Object>} Resolves with `{ success, message, results, summary, safety_flags }`.
 */
export function generateAiStudyCards(axios, finalCandidatesPackage, confirmedItems) {
    return axios.post('/ai-study-card/generate-cards', {
        final_candidates_package: finalCandidatesPackage,
        confirmed_items: confirmedItems,
    }).then((response) => {
        if (response.data && response.data.success) {
            return response.data;
        }
        const err = new Error(
            (response.data && response.data.message) ? response.data.message : '生成学习卡失败。'
        );
        err.payload = response.data;
        throw err;
    }).catch((error) => {
        if (error && error.payload) {
            // Already shaped by the .then above — rethrow.
            throw error;
        }
        const shaped = new Error(
            error.response && error.response.data && error.response.data.message
                ? error.response.data.message
                : '生成学习卡失败。'
        );
        shaped.original = error;
        throw shaped;
    });
}
