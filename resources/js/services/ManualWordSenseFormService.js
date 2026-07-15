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

export function manualSenseErrorMessage(error, fallback) {
    const response = error && error.response;
    const errors = response && response.status === 422 && response.data && response.data.errors;
    if (!errors || typeof errors !== 'object') return fallback;
    if (errors.pos) return '词性格式无效，请重新选择词性。';
    if (errors.sense_zh) return '请先填写中文释义。';

    for (const messages of Object.values(errors)) {
        if (Array.isArray(messages) && typeof messages[0] === 'string' && !/<[^>]+>/.test(messages[0])) {
            return messages[0];
        }
    }
    return fallback;
}
