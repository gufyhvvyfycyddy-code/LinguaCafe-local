<template>
    <div class="mb-2">
        <!-- Loading state -->
        <div v-if="loading" class="d-flex align-center mt-2 mb-2">
            <v-progress-circular indeterminate size="14" width="2" color="primary" class="mr-2" />
            <span class="text-caption">加载 AI 建议中...</span>
        </div>

        <!-- Error state -->
        <v-alert v-else-if="error" dense text type="error" class="mt-2 mb-2" small>
            AI 建议读取失败，不影响手动添加释义。
        </v-alert>

        <!-- Suggestions -->
        <div v-if="vocabularySuggestions.length || phraseSuggestions.length">
            <div class="text-caption font-weight-medium mb-1 mt-2">AI 建议</div>

            <!-- Vocab suggestions -->
            <template v-for="(vi, viIndex) in vocabularySuggestions">
                <div :key="'ai-v-' + viIndex" class="ai-suggestion-card rounded pa-2 mb-2">
                    <div class="d-flex align-center mb-1">
                        <v-chip x-small outlined class="mr-1">{{ vi.pos || '未知' }}</v-chip>
                        <v-chip x-small :color="vi.confidence === 'high' ? 'green' : 'orange'" text-color="white">{{ vi.confidence }}</v-chip>
                        <v-spacer />
                        <v-btn x-small outlined color="primary" @click="$emit('use-vocab-suggestion', vi)">
                            使用此释义
                        </v-btn>
                    </div>
                    <div class="text-body-2 mb-1">{{ vi.meaning_zh }}</div>
                    <div v-if="vi.reason" class="text-caption text--secondary">{{ vi.reason }}</div>
                    <div v-if="vi.source_sentence" class="mt-1">
                        <span v-if="!expandedSource['vocab-' + viIndex]" class="text-caption primary--text" style="cursor:pointer;" @click="expandedSource['vocab-' + viIndex] = true">
                            查看来源句
                        </span>
                        <template v-else>
                            <div class="text-caption text--secondary mt-1">
                                <v-icon x-small class="mr-1">mdi-format-quote-open</v-icon>
                                {{ vi.source_sentence }}
                            </div>
                            <span class="text-caption primary--text" style="cursor:pointer;" @click="expandedSource['vocab-' + viIndex] = false">
                                收起来源句
                            </span>
                        </template>
                    </div>
                </div>
            </template>

            <!-- Phrase suggestions -->
            <template v-for="(pi, piIndex) in phraseSuggestions">
                <div :key="'ai-p-' + piIndex" class="ai-suggestion-card rounded pa-2 mb-2">
                    <div class="d-flex align-center mb-1">
                        <v-chip x-small outlined color="purple" class="mr-1">词组</v-chip>
                        <v-chip x-small :color="pi.confidence === 'high' ? 'green' : 'orange'" text-color="white">{{ pi.confidence }}</v-chip>
                        <v-spacer />
                        <v-btn x-small outlined color="primary" @click="$emit('use-phrase-suggestion', pi)">
                            用于当前单词
                        </v-btn>
                    </div>
                    <div class="text-body-2 mb-1">{{ pi.phrase }}</div>
                    <div class="text-caption mb-1">{{ pi.meaning_zh }}</div>
                    <div v-if="pi.trigger_words && pi.trigger_words.length" class="text-caption text--secondary">
                        触发词：{{ pi.trigger_words.join(', ') }}
                    </div>
                    <div v-if="pi.source_sentence" class="mt-1">
                        <span v-if="!expandedSource['phrase-' + piIndex]" class="text-caption primary--text" style="cursor:pointer;" @click="expandedSource['phrase-' + piIndex] = true">
                            查看来源句
                        </span>
                        <template v-else>
                            <div class="text-caption text--secondary mt-1">
                                <v-icon x-small class="mr-1">mdi-format-quote-open</v-icon>
                                {{ pi.source_sentence }}
                            </div>
                            <span class="text-caption primary--text" style="cursor:pointer;" @click="expandedSource['phrase-' + piIndex] = false">
                                收起来源句
                            </span>
                        </template>
                    </div>
                </div>
            </template>
        </div>
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
    data() {
        return {
            expandedSource: {},
        };
    },
};
</script>
