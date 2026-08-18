import { READER_UNFAMILIAR_SCHEMA_VERSION } from './ReaderUnfamiliarTargetPolicy.js';

const ERROR_MESSAGES = Object.freeze({
    V2_INVALID_JSON: 'AI 返回内容不是严格 JSON，请让 AI 只返回 JSON 后重试。',
    V2_SCHEMA_MISMATCH: 'AI 返回格式版本不匹配，请重新复制最新提示词。',
    V2_STALE_SOURCE: '文章内容已经变化，请重新生成 AI 提示词后再导入。',
    V2_PACKAGE_MISMATCH: 'AI 返回内容不属于当前这份提示词，请重新生成并导入。',
    V2_PART_SET_INCOMPLETE: 'AI 分包内容不完整，请把所有分包结果都准备好后再导入。',
    V2_TARGET_SET_MISMATCH: 'AI 返回的词或词组与本章标记目标不一致，请重新生成提示词。',
    V2_DUPLICATE_OCCURRENCE_ID: 'AI 返回了重复目标，请重新生成结果。',
    V2_IDENTITY_ECHO_MISMATCH: 'AI 改写了目标身份信息，请重新生成结果。',
    V2_CANDIDATE_MISMATCH: 'AI 选择的词义不在当前候选中，请重新生成或人工核对。',
    V2_WORD_SENSE_OWNERSHIP_MISMATCH: '目标词义已经不可用于当前语言，请刷新后重新核对。',
    V2_TRANSLATION_SET_MISMATCH: '句子译文与当前文章不一致，请重新生成结果。',
});

export function readerAiAssistErrorMessage(error, fallback = 'AI 阅读辅助请求失败。') {
    const data = error && error.response ? error.response.data : error;
    const code = data && data.error_code;
    return ERROR_MESSAGES[code] || (data && data.message) || fallback;
}

export function normalizeReaderAiAssistSourceMeta(data = {}) {
    const packages = Array.isArray(data.packages) ? data.packages : [];
    const packageCount = Number(data.package_count ?? data.part_count ?? 1);
    const targetCount = Number(data.target_count ?? 0);
    return {
        schemaVersion: data.schema_version || '',
        targetCount: Number.isFinite(targetCount) ? targetCount : 0,
        packageCount: Number.isFinite(packageCount) && packageCount > 0 ? packageCount : 1,
        packages,
        prompt: data.prompt || '',
    };
}

export function isReaderAiAssistV2(payload = {}) {
    return payload.schema_version === READER_UNFAMILIAR_SCHEMA_VERSION
        || payload.schemaVersion === READER_UNFAMILIAR_SCHEMA_VERSION;
}

export function readerAiAssistPackageKey(pkg = {}) {
    const partIndex = Number(pkg.part_index);
    return Number.isInteger(partIndex) && partIndex > 0 ? String(partIndex) : '';
}

export function readerAiAssistV2InputsComplete(sourceMeta = {}, aiTextByPart = {}) {
    const packages = Array.isArray(sourceMeta.packages) ? sourceMeta.packages : [];
    if (!packages.length) return false;
    return packages.every((pkg) => {
        const key = readerAiAssistPackageKey(pkg);
        return typeof aiTextByPart[key] === 'string' && aiTextByPart[key].trim().length > 0;
    });
}

export function readerAiAssistCandidatesForOccurrence(sourceMeta = {}, occurrenceId = '') {
    const targetId = typeof occurrenceId === 'string' ? occurrenceId.trim() : '';
    if (!targetId) return [];

    const packages = Array.isArray(sourceMeta.packages) ? sourceMeta.packages : [];
    for (const pkg of packages) {
        const wordTargets = Array.isArray(pkg?.source_payload?.word_targets)
            ? pkg.source_payload.word_targets
            : [];
        const target = wordTargets.find(item => item && item.occurrence_id === targetId);
        if (target) {
            return Array.isArray(target.candidate_word_senses) ? target.candidate_word_senses : [];
        }
    }

    return [];
}

export function buildReaderAiAssistV2ImportRequest(
    chapterId,
    sourceMeta = {},
    aiTextByPart = {},
    applyTrustAi = false,
) {
    const packages = Array.isArray(sourceMeta.packages) ? sourceMeta.packages : [];
    return {
        chapterId,
        schema_version: sourceMeta.schemaVersion || READER_UNFAMILIAR_SCHEMA_VERSION,
        parts: packages.map((pkg) => ({
            manifest_token: pkg.manifest_token || '',
            ai_text: String(aiTextByPart[readerAiAssistPackageKey(pkg)] || ''),
        })),
        apply_trust_ai: Boolean(applyTrustAi),
    };
}

function legacyWordToResult(item) {
    return {
        ...item,
        occurrence_id: item.occurrence_id || null,
        surface: item.surface || '',
        lemma: item.lemma || item.suggested_lemma || '',
        suggested_lemma: item.suggested_lemma || item.lemma || '',
        sense_zh: item.sense_zh || item.meaning_zh || item.new_sense?.sense_zh || '',
        meaning_zh: item.meaning_zh || item.sense_zh || item.new_sense?.sense_zh || '',
        sense_en: item.sense_en || item.new_sense?.sense_en || '',
        result: item.result || null,
    };
}

function legacyPhraseToResult(item) {
    return {
        ...item,
        occurrence_id: item.occurrence_id || null,
        phrase: item.phrase || '',
        sense_zh: item.sense_zh || item.meaning_zh || '',
        meaning_zh: item.meaning_zh || item.sense_zh || '',
        sense_en: item.sense_en || '',
    };
}

export function normalizeReaderAiAssistPreview(data = {}) {
    const items = data.items || data;
    const sentenceTranslations = Array.isArray(items.sentence_translations) ? items.sentence_translations : [];
    const wordResults = Array.isArray(items.word_results)
        ? items.word_results.map(legacyWordToResult)
        : (Array.isArray(items.vocabulary_items) ? items.vocabulary_items.map(legacyWordToResult) : []);
    const phraseResults = Array.isArray(items.phrase_results)
        ? items.phrase_results.map(legacyPhraseToResult)
        : (Array.isArray(items.phrase_items) ? items.phrase_items.map(legacyPhraseToResult) : []);
    const warnings = Array.isArray(items.warnings) ? items.warnings : [];
    const sourceSummary = data.summary || {};

    return {
        raw: data,
        schema_version: data.schema_version || items.schema_version || '',
        package_id: data.package_id || items.package_id || null,
        source_revision: data.source_revision || items.source_revision || null,
        part_index: Number(data.part_index || items.part_index || 1),
        part_count: Number(data.part_count || items.part_count || 1),
        items: {
            sentence_translations: sentenceTranslations,
            word_results: wordResults,
            phrase_results: phraseResults,
            // Compatibility aliases keep the existing Reader detail rendering stable.
            vocabulary_items: wordResults,
            phrase_items: phraseResults,
            warnings,
        },
        summary: {
            sentence_translation_count: Number(sourceSummary.sentence_translation_count ?? sentenceTranslations.length),
            word_result_count: Number(sourceSummary.word_result_count ?? sourceSummary.vocabulary_item_count ?? wordResults.length),
            vocabulary_item_count: Number(sourceSummary.vocabulary_item_count ?? sourceSummary.word_result_count ?? wordResults.length),
            phrase_result_count: Number(sourceSummary.phrase_result_count ?? sourceSummary.phrase_item_count ?? phraseResults.length),
            phrase_item_count: Number(sourceSummary.phrase_item_count ?? sourceSummary.phrase_result_count ?? phraseResults.length),
            warning_count: Number(sourceSummary.warning_count ?? warnings.length),
            target_count: Number(sourceSummary.target_count ?? data.target_count ?? (wordResults.length + phraseResults.length)),
        },
    };
}

export function readerAiAssistResultLabel(result) {
    return {
        matched_existing: '匹配已学词义',
        new_sense: '可能是新词义',
        ambiguous: '需要人工核对',
    }[result] || '';
}
