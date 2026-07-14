/**
 * AiStudyCardRecommendationParserService
 * =====================================
 * Pure functions for parsing and de-duplicating AI-recommended words that the
 * user pastes into the V4 recommendation JSON textarea.
 *
 * Design rules:
 *   - Pure functions only (no Vue, no Vuex store, no DOM, no axios).
 *   - No side effects. Callers own state mutations.
 *   - Does NOT call any external AI provider.
 *   - Does NOT write ReviewLog / FSRS / ReviewCard / WordSense.
 *
 * Why this service exists:
 *   Previously VocabularySideBox.vue and VocabularyBox.vue each carried their
 *   own copy of the V4 parse + dedupe rules. Any change to the dedupe rules
 *   would have to be applied in two places. This service gives the V4
 *   recommendation parsing rules a single entry point
 *   (GM52-AIStudyCardV5-DesktopWorkflowFeatureIsland-1000-2).
 */

/**
 * Build the set of user-selected keys (lowercased lemma or word) used for
 * deduplication. The pending items come from the V3 pending list, and only
 * the items whose id is in selectedIds contribute to the key set.
 *
 * @param {Array<Object>} pendingItems Items from the pending list.
 * @param {Array<number|string>} selectedIds Ids the user ticked in the preview.
 * @returns {Object<string, boolean>} Map of lowercased key -> true.
 */
export function buildUserSelectedKeys(pendingItems, selectedIds) {
    const keys = {};
    if (!Array.isArray(pendingItems) || !Array.isArray(selectedIds)) {
        return keys;
    }
    const selected = pendingItems.filter(i => selectedIds.includes(i.id));
    selected.forEach(item => {
        const key = (item.lemma || item.word || '').trim().toLowerCase();
        if (key) keys[key] = true;
    });
    return keys;
}

/**
 * Parse the raw JSON text the user pasted into the AI recommendation textarea.
 *
 * Returns a result object:
 *   {
 *     ok: true|false,
 *     error: '',                // non-empty when ok === false
 *     recommendations: [],      // valid recommendations
 *     summary: {                // statistics
 *       original_count,
 *       valid_count,
 *       dropped_missing_word,
 *       dropped_duplicate_with_user,
 *       dropped_ai_internal_duplicate,
 *     },
 *   }
 *
 * Rules:
 *   - recommended_items must be an array.
 *   - Each item must have a non-empty `word` (string after trim).
 *   - lemma defaults to word if absent/empty.
 *   - Items whose lemma matches a user-selected key are dropped
 *     (dropped_duplicate_with_user).
 *   - Items whose lemma repeats within the AI list are dropped
 *     (dropped_ai_internal_duplicate).
 *   - AI reason is kept as reference display only — NEVER auto-filled into
 *     sense_zh (V5 hardening).
 *   - All recommendations default to UNSELECTED. The caller is responsible
 *     for tracking selected indices.
 *
 * @param {string} jsonText Raw JSON text pasted by the user.
 * @param {Array<Object>} pendingItems Pending items from the V3 list.
 * @param {Array<number|string>} selectedIds Ids the user ticked in the preview.
 * @returns {Object} Result object (see above).
 */
export function parseAiRecommendations(jsonText, pendingItems, selectedIds) {
    const empty = {
        ok: false,
        error: '',
        recommendations: [],
        summary: {
            original_count: 0,
            valid_count: 0,
            dropped_missing_word: 0,
            dropped_duplicate_with_user: 0,
            dropped_ai_internal_duplicate: 0,
        },
    };

    const text = (jsonText || '').trim();
    if (!text) {
        return { ...empty, error: '请粘贴 AI 推荐词 JSON。' };
    }

    let parsed;
    try {
        parsed = JSON.parse(text);
    } catch (e) {
        return { ...empty, error: 'JSON 格式错误：' + (e.message || '无法解析。') };
    }

    if (!parsed || typeof parsed !== 'object') {
        return { ...empty, error: 'JSON 根对象必须是对象。' };
    }

    // schema_version is optional; we only warn (no block).
    const items = parsed.recommended_items;
    if (!Array.isArray(items)) {
        return { ...empty, error: 'recommended_items 必须是数组。' };
    }

    const userSelectedKeys = buildUserSelectedKeys(pendingItems, selectedIds);

    const validRecommendations = [];
    const seenKeys = {};
    let droppedMissingWord = 0;
    let droppedDuplicateWithUser = 0;
    let droppedAiInternalDuplicate = 0;

    items.forEach((raw) => {
        if (!raw || typeof raw !== 'object') {
            droppedMissingWord++;
            return;
        }
        const word = (raw.word || '').toString().trim();
        if (!word) {
            droppedMissingWord++;
            return;
        }
        const lemma = (raw.lemma || '').toString().trim() || word;
        const key = lemma.toLowerCase();
        if (userSelectedKeys[key]) {
            droppedDuplicateWithUser++;
            return;
        }
        if (seenKeys[key]) {
            droppedAiInternalDuplicate++;
            return;
        }
        seenKeys[key] = true;
        validRecommendations.push({
            word: word,
            lemma: lemma,
            surface: (raw.surface || '').toString().trim() || word,
            reason: (raw.reason || '').toString().trim() || '无说明',
            sentence_text: raw.sentence_text ? (raw.sentence_text).toString().trim() : '',
            confidence: raw.confidence !== undefined && raw.confidence !== null ? raw.confidence : null,
        });
    });

    const summary = {
        original_count: items.length,
        valid_count: validRecommendations.length,
        dropped_missing_word: droppedMissingWord,
        dropped_duplicate_with_user: droppedDuplicateWithUser,
        dropped_ai_internal_duplicate: droppedAiInternalDuplicate,
    };

    if (validRecommendations.length === 0) {
        return {
            ok: false,
            error: '没有有效的 AI 推荐词。',
            recommendations: [],
            summary,
        };
    }

    return {
        ok: true,
        error: '',
        recommendations: validRecommendations,
        summary,
    };
}

/**
 * Convert a V6 provider-preview package into the same state shape used by the
 * manually pasted recommendation flow. Recommendations remain unselected.
 */
export function importV6Recommendations(recommendationPackage, pendingItems, selectedIds) {
    const jsonInput = JSON.stringify(recommendationPackage || {}, null, 2);
    const result = parseAiRecommendations(jsonInput, pendingItems, selectedIds);

    return {
        ...result,
        jsonInput,
        selectedIndices: [],
        importNotice: result.recommendations.length > 0
            ? '已从 V6 AI 推荐预览导入 ' + result.recommendations.length + ' 条推荐词，默认未勾选。请手动勾选需要的词，再继续生成；最终生成学习卡前仍必须填写中文释义。'
            : 'V6 AI 推荐预览没有可导入的新推荐词。重复项已被丢弃，请更换待解释词后重试。',
    };
}

/**
 * Re-dedupe existing recommendations after the user changes which pending
 * items are selected. Removes recommendations whose lemma matches a newly
 * selected pending item. Preserves the selection state of the kept items
 * (by remapping old indices to new positions).
 *
 * Returns a new object; does NOT mutate inputs.
 *
 * @param {Array<Object>} recommendations Current AI recommendations.
 * @param {Array<number>} selectedIndices Currently selected recommendation indices.
 * @param {Array<Object>} pendingItems Pending items from the V3 list.
 * @param {Array<number|string>} selectedIds Ids the user ticked in the preview.
 * @returns {Object} { recommendations, selectedIndices, dropped, summaryDelta }
 */
export function rededupeRecommendations(recommendations, selectedIndices, pendingItems, selectedIds) {
    if (!Array.isArray(recommendations) || recommendations.length === 0) {
        return {
            recommendations: [],
            selectedIndices: [],
            dropped: 0,
            summaryDelta: 0,
        };
    }

    const userSelectedKeys = buildUserSelectedKeys(pendingItems, selectedIds);

    const kept = [];
    const keptIndices = [];
    let dropped = 0;
    recommendations.forEach((rec, idx) => {
        const key = (rec.lemma || rec.word || '').trim().toLowerCase();
        if (userSelectedKeys[key]) {
            dropped++;
            return;
        }
        kept.push(rec);
        if (Array.isArray(selectedIndices) && selectedIndices.includes(idx)) {
            keptIndices.push(kept.length - 1);
        }
    });

    return {
        recommendations: kept,
        selectedIndices: keptIndices,
        dropped,
        summaryDelta: dropped,
    };
}
