<template>
    <div class="ai-suggestion-rows">
        <!-- Loading state -->
        <div v-if="loading" class="ai-row compact rounded mb-1">
            <span class="ai-tag">AI</span>
            <span class="ai-text text-caption">加载 AI 建议中...</span>
            <v-progress-circular indeterminate size="12" width="2" color="primary" class="ml-1" />
        </div>

        <!-- Error state -->
        <div v-else-if="error" class="ai-row compact rounded mb-1">
            <span class="ai-tag">AI</span>
            <span class="ai-text text-caption">AI 建议读取失败，不影响词典查询</span>
        </div>

        <!-- Vocab suggestions -->
        <template v-for="(vi, viIndex) in vocabularySuggestions">
            <div :key="'ai-v-' + viIndex" class="ai-row compact rounded mb-1">
                <span class="ai-tag">AI</span>
                <v-chip v-if="vi.pos" x-small outlined class="ai-pos-chip">{{ vi.pos }}</v-chip>
                <v-chip
                    v-if="vi.confidence === 'high'"
                    x-small
                    color="green"
                    text-color="white"
                    class="ai-conf-chip"
                >高</v-chip>
                <span
                    class="ai-text"
                    :title="buildVocabTitle(vi)"
                >{{ vi.meaning_zh }}</span>
                <v-btn
                    x-small
                    outlined
                    color="primary"
                    class="ai-use-btn"
                    @click="$emit('use-vocab-suggestion', vi)"
                >
                    使用此释义
                </v-btn>
            </div>
        </template>

        <!-- Phrase suggestions -->
        <template v-for="(pi, piIndex) in phraseSuggestions">
            <div :key="'ai-p-' + piIndex" class="ai-row compact rounded mb-1">
                <span class="ai-tag ai-tag-phrase">AI 词组</span>
                <span
                    class="ai-text"
                    :title="buildPhraseTitle(pi)"
                >{{ pi.phrase }} · {{ pi.meaning_zh }}</span>
                <v-btn
                    x-small
                    outlined
                    color="primary"
                    class="ai-use-btn"
                    @click="$emit('use-phrase-suggestion', pi)"
                >
                    用于当前单词
                </v-btn>
            </div>
        </template>
    </div>
</template>

<script>
export default {
    props: {
        vocabularySuggestions: {
            type: Array,
            default: () => [],
        },
        phraseSuggestions: {
            type: Array,
            default: () => [],
        },
        loading: {
            type: Boolean,
            default: false,
        },
        error: {
            type: String,
            default: '',
        },
    },
    methods: {
        buildVocabTitle(vi) {
            const parts = [];
            if (vi.reason) parts.push('理由：' + vi.reason);
            if (vi.source_sentence) parts.push('来源句：' + vi.source_sentence);
            return parts.join('\n');
        },
        buildPhraseTitle(pi) {
            const parts = [];
            if (pi.trigger_words && pi.trigger_words.length) {
                parts.push('触发词：' + pi.trigger_words.join(', '));
            }
            if (pi.source_sentence) parts.push('来源句：' + pi.source_sentence);
            return parts.join('\n');
        },
    },
};
</script>

<style scoped>
.ai-suggestion-rows {
    margin-bottom: 4px;
}

.ai-row.compact {
    display: grid;
    grid-template-columns: auto auto auto minmax(0, 1fr) auto;
    align-items: center;
    gap: 6px;
    font-size: 0.85em;
    line-height: 1.3;
    min-height: 28px;
    padding: 2px 6px;
    border-left: 3px solid #2196F3;
    background-color: rgba(33, 150, 243, 0.06);
}

.ai-tag {
    display: inline-block;
    padding: 1px 6px;
    border-radius: 3px;
    font-size: 0.72em;
    font-weight: 600;
    color: white;
    background-color: #2196F3;
    white-space: nowrap;
    line-height: 1.4;
}

.ai-tag-phrase {
    background-color: #1976D2;
}

.ai-pos-chip {
    margin: 0;
    height: 18px !important;
}

.ai-conf-chip {
    margin: 0;
    height: 18px !important;
}

.ai-text {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    cursor: default;
}

.ai-use-btn {
    justify-self: end;
    margin: 0 !important;
}
</style>
