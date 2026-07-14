/**
 * AiStudyCardPendingWorkflowService
 * ================================
 * Thin axios wrappers for the V1-V4 pending / preview / final-candidates
 * endpoints of the AI Study Card workflow. These functions only call the
 * LOCAL `/ai-study-card/*` endpoints. They do NOT call any external AI
 * provider, do NOT write ReviewLog, do NOT change FSRS, and do NOT create
 * legacy word ReviewCard.
 *
 * Design rules:
 *   - Pure functions (no Vue, no Vuex store, no DOM).
 *   - The only side effect is the HTTP request issued by the axios instance
 *     passed into each function by the caller.
 *   - Errors are shaped into Error objects carrying a `message` field
 *     suitable for UI display.
 *
 * Why this service exists:
 *   Previously VocabularySideBox.vue and VocabularyBox.vue each carried their
 *   own copy of the V1-V4 axios calls. Any change to the request shape would
 *   have to be applied in two places. This service gives V1-V4 API calls a
 *   single entry point (GM52-AIStudyCardV5-DesktopWorkflowFeatureIsland-1000-2).
 */

/**
 * Create a pending AI study card item for the current word.
 *
 * POST /ai-study-card/pending-items
 */
export function createPendingItem(axios, context) {
    return axios.post('/ai-study-card/pending-items', {
        chapter_id: context.chapterId,
        text_block_index: context.sentenceIndex,
        sentence_index: context.sentenceIndex,
        sentence_id: String(context.sentenceIndex),
        word: context.word,
        surface: context.surface || context.word,
        lemma: context.lemma || context.word,
        sentence_text: context.sentenceText || '',
        source_payload: {
            source: context.source || 'reader_vocabulary_workflow',
        },
    }).then((response) => response.data).catch((error) => {
        const shaped = new Error(
            error.response && error.response.data && error.response.data.message
                ? error.response.data.message
                : '加入待 AI 解释失败。'
        );
        shaped.original = error;
        throw shaped;
    });
}

/**
 * List pending or dismissed AI study card items.
 *
 * GET /ai-study-card/pending-items?status=pending|dismissed&chapter_id=...
 */
export function listPendingItems(axios, { chapterId, status }) {
    const params = { status };
    if (chapterId) {
        params.chapter_id = chapterId;
    }
    return axios.get('/ai-study-card/pending-items', { params })
        .then((response) => {
            const items = response.data && response.data.items ? response.data.items : [];
            return { items, raw: response.data };
        }).catch((error) => {
            const shaped = new Error(
                error.response && error.response.data && error.response.data.message
                    ? error.response.data.message
                    : '加载待解释列表失败。'
            );
            shaped.original = error;
            throw shaped;
        });
}

/**
 * Dismiss a pending AI study card item.
 *
 * POST /ai-study-card/pending-items/{id}/dismiss
 */
export function dismissPendingItem(axios, itemId) {
    return axios.post(`/ai-study-card/pending-items/${itemId}/dismiss`)
        .then((response) => ({
            message: response.data && response.data.message ? response.data.message : '已取消。',
            raw: response.data,
        })).catch((error) => {
            const shaped = new Error(
                error.response && error.response.data && error.response.data.message
                    ? error.response.data.message
                    : '取消失败。'
            );
            shaped.original = error;
            throw shaped;
        });
}

/**
 * Restore a previously dismissed AI study card item.
 *
 * POST /ai-study-card/pending-items/{id}/restore
 */
export function restorePendingItem(axios, itemId) {
    return axios.post(`/ai-study-card/pending-items/${itemId}/restore`)
        .then((response) => ({
            message: response.data && response.data.message ? response.data.message : '已恢复。',
            raw: response.data,
        })).catch((error) => {
            const shaped = new Error(
                error.response && error.response.data && error.response.data.message
                    ? error.response.data.message
                    : '恢复失败。'
            );
            shaped.original = error;
            throw shaped;
        });
}

/**
 * Build the V3 safe preview package for the selected pending items.
 *
 * POST /ai-study-card/pending-items/preview-package
 *
 * Returns { success, package } on success, or throws an Error with a
 * user-facing message.
 */
export function buildPreviewPackage(axios, selectedItemIds) {
    return axios.post('/ai-study-card/pending-items/preview-package', {
        item_ids: selectedItemIds,
    }).then((response) => {
        if (response.data && response.data.success) {
            return { success: true, package: response.data.package };
        }
        const msg = response.data && response.data.message
            ? response.data.message
            : '生成安全包失败。';
        const err = new Error(msg);
        err.payload = response.data;
        throw err;
    }).catch((error) => {
        if (error && error.payload) {
            throw error;
        }
        const shaped = new Error(
            error.response && error.response.data && error.response.data.message
                ? error.response.data.message
                : '生成安全包失败。'
        );
        shaped.original = error;
        throw shaped;
    });
}

/**
 * Build the V4 final candidates package.
 *
 * POST /ai-study-card/pending-items/final-candidates-package
 *
 * Returns { success, package } on success, or throws an Error with a
 * user-facing message.
 */
export function buildFinalCandidatesPackage(axios, payload) {
    return axios.post('/ai-study-card/pending-items/final-candidates-package', payload)
        .then((response) => {
            if (response.data && response.data.success) {
                return { success: true, package: response.data.package };
            }
            const msg = response.data && response.data.message
                ? response.data.message
                : '生成最终候选包失败。';
            const err = new Error(msg);
            err.payload = response.data;
            throw err;
        }).catch((error) => {
            if (error && error.payload) {
                throw error;
            }
            const shaped = new Error(
                error.response && error.response.data && error.response.data.message
                    ? error.response.data.message
                    : '生成最终候选包失败。'
            );
            shaped.original = error;
            throw shaped;
        });
}

/** Build the V4 request payload without coupling the Vue container to it. */
export function createFinalCandidatesPayload({ selectedItemIds, recommendations, selectedRecommendationIndices, recommendationSummary, previewPackage }) {
    const selectedAi = selectedRecommendationIndices.map(index => recommendations[index]).filter(Boolean);
    const unselectedAi = recommendations.filter((recommendation, index) => !selectedRecommendationIndices.includes(index));

    return {
        selected_item_ids: selectedItemIds,
        selected_ai_recommendations: selectedAi,
        unselected_ai_recommendations: unselectedAi,
        dedupe_summary: recommendationSummary || {
            original_ai_count: 0, valid_ai_count: 0, dropped_missing_word: 0,
            dropped_duplicate_with_user: 0, dropped_ai_internal_duplicate: 0,
        },
        source_preview_package: previewPackage,
    };
}

/**
 * Build the V6 provider-disabled request package.
 *
 * POST /ai-study-card/v6/recommendations/request-package
 *
 * This is still a LOCAL LinguaCafe endpoint. It does NOT call any external AI
 * provider and returns only ai-study-card-v6-request-package-v1.
 */
export function buildV6RequestPackage(axios, selectedItemIds, contextPolicy = 'selected_items_with_sentence') {
    return axios.post('/ai-study-card/v6/recommendations/request-package', {
        item_ids: selectedItemIds,
        context_policy: contextPolicy,
    }).then((response) => {
        if (response.data && response.data.success) {
            return { success: true, package: response.data.package };
        }
        const msg = response.data && response.data.message
            ? response.data.message
            : '生成 V6 请求包失败。';
        const err = new Error(msg);
        err.payload = response.data;
        throw err;
    }).catch((error) => {
        if (error && error.payload) {
            throw error;
        }
        const shaped = new Error(
            error.response && error.response.data && error.response.data.message
                ? error.response.data.message
                : '生成 V6 请求包失败。'
        );
        shaped.original = error;
        throw shaped;
    });
}

/**
 * Ask the local LinguaCafe backend to run the V6 provider-preview flow.
 *
 * POST /ai-study-card/v6/recommendations/provider-preview
 *
 * This never calls an external provider from the browser. The browser sends
 * only the already generated V6 request package to the local backend. Returned
 * recommendations are preview-only and still require user confirmation.
 */
export function buildV6ProviderPreview(axios, requestPackage) {
    return axios.post('/ai-study-card/v6/recommendations/provider-preview', {
        request_package: requestPackage,
    }).then((response) => {
        if (response.data && response.data.success) {
            return {
                success: true,
                package: response.data.package,
                safetyFlags: response.data.safety_flags || {},
            };
        }
        const msg = response.data && response.data.message
            ? response.data.message
            : '生成 V6 AI 推荐预览失败。';
        const err = new Error(msg);
        err.payload = response.data;
        throw err;
    }).catch((error) => {
        if (error && error.payload) {
            throw error;
        }
        const shaped = new Error(
            error.response && error.response.data && error.response.data.message
                ? error.response.data.message
                : '生成 V6 AI 推荐预览失败。'
        );
        shaped.original = error;
        throw shaped;
    });
}

// Re-export the V5 generate-cards helper so callers can import the whole
// workflow API from a single module if they prefer.
export {
    buildGenerateCardItems,
    filterConfirmedGenerateCardItems,
    generateAiStudyCards,
} from './AiStudyCardGenerateCardsService';
