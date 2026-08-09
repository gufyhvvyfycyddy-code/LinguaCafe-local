export const READER_UNFAMILIAR_SCHEMA_VERSION = 'linguacafe_ai_reading_assist_v2';

function numberIndex(value) {
    const index = Number(value);
    return Number.isInteger(index) && index >= 0 ? index : null;
}

export function readerUnfamiliarTargetKey(target) {
    if (!target) return '';
    const start = numberIndex(target.start_word_index);
    const end = numberIndex(target.end_word_index);
    if (start === null || end === null) return '';
    return `${target.kind || ''}:${start}:${end}`;
}

export function resolveReaderUnfamiliarTarget({ selection = [], words = [] } = {}) {
    if (!Array.isArray(selection) || selection.length === 0 || !Array.isArray(words)) {
        return { ok: false, error: '请选择一个词或同一句中的连续词组。' };
    }

    const indexes = selection
        .map(item => numberIndex(item && item.wordIndex))
        .filter(index => index !== null)
        .sort((a, b) => a - b);

    if (indexes.length !== selection.length || new Set(indexes).size !== indexes.length) {
        return { ok: false, error: '无法确定这次标记在文章中的位置，请重新选择。' };
    }

    const start = indexes[0];
    const end = indexes[indexes.length - 1];
    if (end - start + 1 !== indexes.length) {
        return { ok: false, error: '词组必须是同一句中的连续内容，请重新拖选。' };
    }

    const sourceTokens = [];
    for (let index = start; index <= end; index++) {
        const token = words[index];
        if (!token || token.is_structure || token.word === 'NEWLINE' || token.word === 'PARAGRAPH_BREAK') {
            return { ok: false, error: '标记不能跨段落、换行或结构标记。' };
        }
        sourceTokens.push(token);
    }

    const sentenceIndexes = new Set(sourceTokens.map(token => token.sentence_index));
    if (sentenceIndexes.size !== 1) {
        return { ok: false, error: '词组不能跨句标记，请只选择同一句中的内容。' };
    }

    const surface = sourceTokens.map((token, index) => {
        const suffix = index < sourceTokens.length - 1 && token.spaceAfter ? ' ' : '';
        return `${token.word || ''}${suffix}`;
    }).join('').trim();

    if (!surface) {
        return { ok: false, error: '没有可标记的文字。' };
    }

    const target = Object.freeze({
        kind: sourceTokens.length === 1 ? 'word' : 'phrase',
        start_word_index: start,
        end_word_index: end,
        sentence_index: sourceTokens[0].sentence_index,
        surface,
    });

    return { ok: true, target };
}


export function readerUnfamiliarWordIndexes(targets = []) {
    const indexes = new Set();
    for (const target of Array.isArray(targets) ? targets : []) {
        const start = numberIndex(target && target.start_word_index);
        const end = numberIndex(target && target.end_word_index);
        if (start === null || end === null || end < start) continue;
        for (let index = start; index <= end; index++) indexes.add(index);
    }
    return [...indexes].sort((a, b) => a - b);
}

export function buildReaderAiAssistV2SourceRequest(chapterId, targets = []) {
    return {
        chapterId,
        schema_version: READER_UNFAMILIAR_SCHEMA_VERSION,
        marked_targets: (Array.isArray(targets) ? targets : []).map(target => ({
            kind: target.kind,
            start_word_index: Number(target.start_word_index),
            end_word_index: Number(target.end_word_index),
        })),
    };
}
