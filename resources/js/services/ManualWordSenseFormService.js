const CANONICAL_POS = new Set([
    'noun',
    'verb',
    'adjective',
    'adverb',
    'preposition',
    'conjunction',
    'phrase',
    'other',
]);

const POS_ALIASES = {
    n: 'noun',
    v: 'verb',
    adj: 'adjective',
    adv: 'adverb',
    prep: 'preposition',
    conj: 'conjunction',
};

export function normalizeWordSensePos(value) {
    if (typeof value !== 'string') return '';
    const normalized = value.trim().toLowerCase();
    return POS_ALIASES[normalized] || (CANONICAL_POS.has(normalized) ? normalized : '');
}

function emptyValidationState() {
    return {
        fieldErrors: {
            pos: '',
            sense_zh: '',
        },
        generalError: '',
    };
}

export function validateManualSenseForm(form) {
    const state = emptyValidationState();
    const pos = form && typeof form.pos === 'string' ? form.pos.trim().toLowerCase() : '';
    const senseZh = form && typeof form.sense_zh === 'string' ? form.sense_zh.trim() : '';

    if (!CANONICAL_POS.has(pos)) {
        state.fieldErrors.pos = '词性格式无效，请重新选择词性。';
    }
    if (!senseZh) {
        state.fieldErrors.sense_zh = '请先填写中文释义。';
    }

    return state;
}

export function manualSenseValidationState(error, fallback) {
    const state = emptyValidationState();
    const response = error && error.response;
    const errors = response && response.status === 422 && response.data && response.data.errors;
    if (!errors || typeof errors !== 'object') {
        state.generalError = fallback;
        return state;
    }
    if (errors.pos) state.fieldErrors.pos = '词性格式无效，请重新选择词性。';
    if (errors.sense_zh) state.fieldErrors.sense_zh = '请先填写中文释义。';

    for (const [field, messages] of Object.entries(errors)) {
        if (field === 'pos' || field === 'sense_zh') continue;
        if (Array.isArray(messages) && typeof messages[0] === 'string' && !/<[^>]+>/.test(messages[0])) {
            state.generalError = messages[0];
            break;
        }
    }
    if (!state.fieldErrors.pos && !state.fieldErrors.sense_zh && !state.generalError) {
        state.generalError = fallback;
    }
    return state;
}
