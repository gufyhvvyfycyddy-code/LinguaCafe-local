export const READING_SENSE_DECISIONS = Object.freeze([
    'matched_existing',
    'new_sense',
    'ambiguous',
]);

export const READING_SENSE_CONFIDENCE = Object.freeze(['high', 'medium', 'low']);

export function readingSenseDecisionLabel(value) {
    return {
        matched_existing: '匹配已学词义',
        new_sense: '可能是新词义',
        ambiguous: '需要人工判断',
    }[value] || '待核对';
}

export function readingSenseConfidenceLabel(value) {
    return {
        high: '高置信',
        medium: '中置信',
        low: '低置信',
    }[value] || '未知置信度';
}

function normalizedEvidence(item = {}) {
    if (item.evidence || item.verification) return item.evidence || item.verification;
    if (!item.resolution) return null;
    return {
        resolution: item.resolution,
        word_sense_id: item.word_sense_id || null,
        resolution_source: item.resolution_source || null,
        ai_confidence: item.ai_confidence || null,
        binding_current: item.binding_current !== false,
        updated_at: item.updated_at || null,
    };
}

export function readingSenseVerificationState(item = {}) {
    const evidence = normalizedEvidence(item);
    if (!evidence || evidence.binding_current === false) return 'pending';
    if (evidence.resolution === 'excluded') return 'excluded';
    if (['matched_existing', 'new_sense'].includes(evidence.resolution)) return 'verified';
    return 'pending';
}

export function readingSenseVerificationSummary(items = []) {
    const summary = { total: 0, pending: 0, verified: 0, excluded: 0 };
    for (const item of Array.isArray(items) ? items : []) {
        const state = readingSenseVerificationState(item);
        summary.total++;
        if (Object.hasOwn(summary, state)) summary[state]++;
        else summary.pending++;
    }
    return summary;
}

export function isTrustAiVerified(item = {}) {
    const evidence = normalizedEvidence(item);
    return Boolean(
        evidence
        && evidence.binding_current !== false
        && evidence.resolution_source === 'trust_ai'
        && evidence.resolution === 'matched_existing'
        && item.result === 'matched_existing'
        && item.confidence === 'high'
    );
}

export function isReadingSenseWordTarget(item = {}) {
    return (item.target_type || item.kind || 'word') === 'word';
}

export function candidateOptions(item = {}) {
    if (!isReadingSenseWordTarget(item)) return [];
    return (Array.isArray(item.candidate_word_senses) ? item.candidate_word_senses : [])
        .map(candidate => {
            const value = Number(candidate.word_sense_id ?? candidate.sense_id);
            return {
                value,
                text: [candidate.sense_zh, candidate.sense_en, candidate.pos]
                    .filter(Boolean)
                    .join(' · '),
                candidate: { ...candidate, word_sense_id: value, sense_id: value },
            };
        })
        .filter(option => Number.isInteger(option.value) && option.value > 0 && option.text);
}

export function buildReadingSenseResolutionIntent(item, action, wordSenseId = null) {
    if (!item || !item.occurrence_id || !isReadingSenseWordTarget(item)) return null;

    if (action === 'match_existing') {
        const id = Number(wordSenseId);
        if (!Number.isInteger(id) || id <= 0) return null;
        const allowedIds = new Set(candidateOptions(item).map(option => Number(option.value)));
        if (!allowedIds.has(id)) return null;
        return {
            occurrence_id: item.occurrence_id,
            resolution: 'matched_existing',
            word_sense_id: id,
        };
    }

    if (action === 'new_sense') {
        return {
            occurrence_id: item.occurrence_id,
            resolution: 'new_sense',
            word_sense_id: null,
        };
    }

    if (action === 'exclude') {
        return {
            occurrence_id: item.occurrence_id,
            resolution: 'excluded',
            word_sense_id: null,
        };
    }

    return null;
}

export function normalizeReadingSenseVerificationItems(payload = {}) {
    const raw = Array.isArray(payload.verification_items)
        ? payload.verification_items
        : (Array.isArray(payload.items) ? payload.items : []);
    return raw
        .filter(item => item && item.occurrence_id)
        .map(item => ({
            ...item,
            evidence: normalizedEvidence(item),
            candidate_word_senses: Array.isArray(item.candidate_word_senses)
                ? item.candidate_word_senses
                : [],
        }));
}

export function mergeReadingSenseVerificationItems({ targets = [], assistItems = [], evidenceItems = [] } = {}) {
    const byId = new Map();
    for (const target of Array.isArray(targets) ? targets : []) {
        if (!target || !target.occurrence_id) continue;
        byId.set(target.occurrence_id, {
            ...target,
            target_type: target.kind,
            candidate_word_senses: Array.isArray(target.candidate_word_senses)
                ? target.candidate_word_senses
                : [],
            evidence: null,
        });
    }
    for (const assist of Array.isArray(assistItems) ? assistItems : []) {
        if (!assist || !assist.occurrence_id) continue;
        const previous = byId.get(assist.occurrence_id) || {};
        byId.set(assist.occurrence_id, {
            ...previous,
            ...assist,
            candidate_word_senses: previous.candidate_word_senses?.length
                ? previous.candidate_word_senses
                : (assist.candidate_word_senses || []),
        });
    }
    for (const evidence of Array.isArray(evidenceItems) ? evidenceItems : []) {
        if (!evidence || !evidence.occurrence_id) continue;
        const previous = byId.get(evidence.occurrence_id) || {};
        byId.set(evidence.occurrence_id, {
            ...previous,
            ...evidence,
            candidate_word_senses: evidence.candidate_word_senses?.length
                ? evidence.candidate_word_senses
                : (previous.candidate_word_senses || []),
            evidence: normalizedEvidence(evidence),
        });
    }
    return [...byId.values()].sort((a, b) => Number(a.start_word_index || 0) - Number(b.start_word_index || 0));
}
