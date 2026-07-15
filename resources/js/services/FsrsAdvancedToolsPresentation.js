const DEFAULT_MIN_REQUIRED = 300;
const DEFAULT_PARAMETER_COUNT = 19;

function nonNegativeNumber(value, fallback = 0) {
    const numeric = Number(value);
    return Number.isFinite(numeric) && numeric >= 0 ? numeric : fallback;
}

function normalizeParameterState(source) {
    if (source === 'default') return 'default';
    if (source === 'optimized') return 'optimized';
    return 'unknown';
}

function parameterPresentation(state, response) {
    const parameterCount = nonNegativeNumber(response?.parameters_count, DEFAULT_PARAMETER_COUNT);
    const lastOptimizedAt = typeof response?.last_optimized_at === 'string'
        ? response.last_optimized_at
        : null;

    if (state === 'optimized') {
        return {
            parameterLabel: '正在优化参数',
            parameterDescription: '已保存优化参数；之后新的复习评分会使用这组参数。已有卡片不会自动重排。',
            restoreButtonLabel: '恢复默认参数',
            canRestoreDefaults: true,
            parameterCount,
            lastOptimizedAt,
        };
    }

    if (state === 'default') {
        return {
            parameterLabel: '当前使用默认参数',
            parameterDescription: '当前参数与默认配置一致。',
            restoreButtonLabel: '当前已是默认参数',
            canRestoreDefaults: false,
            parameterCount,
            lastOptimizedAt: null,
        };
    }

    return {
        parameterLabel: '参数来源需要确认',
        parameterDescription: '当前参数来源无法识别。重新加载诊断后再进行参数操作。',
        restoreButtonLabel: '暂不可恢复默认参数',
        canRestoreDefaults: false,
        parameterCount,
        lastOptimizedAt,
    };
}

export function buildFsrsAdvancedToolsPresentation(response = null, options = {}) {
    const loading = options.loading === true;
    const error = options.error === true;
    const diagnostics = response?.diagnostics && typeof response.diagnostics === 'object'
        ? response.diagnostics
        : {};
    const minRequired = nonNegativeNumber(
        diagnostics.min_required ?? response?.min_required,
        DEFAULT_MIN_REQUIRED,
    ) || DEFAULT_MIN_REQUIRED;
    const eligibleReviewLogs = nonNegativeNumber(
        diagnostics.eligible_review_logs ?? response?.review_count,
        0,
    );
    const trainableCards = nonNegativeNumber(diagnostics.trainable_cards, 0);
    const remainingReviewLogs = Math.max(0, minRequired - eligibleReviewLogs);
    const canPreviewOptimization = !loading && !error && response?.can_optimize === true;

    let dataState = 'empty';
    if (loading) dataState = 'loading';
    else if (error) dataState = 'error';
    else if (canPreviewOptimization) dataState = 'ready';
    else if (eligibleReviewLogs > 0) dataState = 'insufficient';

    const messages = {
        loading: '正在加载参数优化诊断。',
        error: '参数优化诊断加载失败。统计值已隐藏，请重新加载诊断。',
        empty: '还没有可用于参数优化的正式复习记录。继续正常复习后，这里会显示进度。',
        insufficient: `有效记录 ${eligibleReviewLogs} / ${minRequired}，还差 ${remainingReviewLogs} 条；目前有 ${trainableCards} 张卡可用于训练。`,
        ready: `已有 ${eligibleReviewLogs} 条有效记录，可以预览优化结果。预览不会保存参数，也不会重排已有卡片。`,
    };

    const parameterState = normalizeParameterState(response?.parameters_source);
    const parameter = parameterPresentation(parameterState, response);
    const actionBlocked = loading || error;
    const showDiagnosticDetails = !actionBlocked && dataState !== 'empty';

    return {
        dataState,
        parameterState,
        primaryMessage: messages[dataState],
        progressPercent: Math.min(100, Math.round((eligibleReviewLogs / minRequired) * 100)),
        eligibleReviewLogs,
        trainableCards,
        remainingReviewLogs,
        minRequired,
        canPreviewOptimization,
        previewButtonLabel: canPreviewOptimization ? '预览优化结果' : '记录达到 300 条后可预览',
        showDiagnosticDetails,
        diagnosticDetails: {
            excludedReviewLogs: nonNegativeNumber(diagnostics.excluded_review_logs, 0),
            resetReviewLogs: nonNegativeNumber(diagnostics.reset_review_logs, 0),
            eligibleCards: nonNegativeNumber(diagnostics.eligible_cards, 0),
            confirmedSenseCards: nonNegativeNumber(diagnostics.confirmed_sense_cards, 0),
            rejectedWordSenses: nonNegativeNumber(diagnostics.rejected_word_senses, 0),
        },
        ...parameter,
        canRestoreDefaults: parameter.canRestoreDefaults && !actionBlocked,
    };
}
