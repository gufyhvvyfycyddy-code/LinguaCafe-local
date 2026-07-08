<template>
    <v-dialog
        :value="value"
        @input="$emit('input', $event)"
        max-width="800"
        eager
    >
        <v-card>
            <v-card-title class="text-h6">
                <v-icon small class="mr-2">mdi-cards-outline</v-icon>
                确认生成学习卡
            </v-card-title>
            <v-card-text>
                <v-alert type="info" dense text class="mb-3">
                    这不是 AI 自动调用，是你粘贴 AI 返回内容后的人工确认生成。每个候选项需要填写中文释义（必填），未填写的项会被跳过。英文解释可留空，后续再补。
                </v-alert>
                <v-alert v-if="hasAiRecommendedItems" type="warning" dense text class="mb-3">
                    AI 推荐理由只解释为什么推荐这个词，不等于中文释义。请自己判断词义后填写“中文释义（必填）”，不要直接把推荐理由当作释义。
                </v-alert>
                <div v-if="items.length === 0" class="text-center pa-4 text--secondary">
                    没有可确认的候选项。
                </div>
                <div v-for="(item, idx) in items" :key="'gen-' + idx" class="mb-3 pa-2 rounded" style="border: 1px solid var(--v-gray2-base);">
                    <div class="d-flex align-center mb-1">
                        <v-chip
                            x-small
                            :color="item.source === 'user_selected' ? 'primary' : 'info'"
                            class="mr-2"
                        >{{ item.source === 'user_selected' ? '已选词' : 'AI 推荐' }}</v-chip>
                        <span class="font-weight-medium">{{ item.word }}</span>
                        <span v-if="item.lemma && item.lemma !== item.word" class="ml-1 text--secondary text-caption">({{ item.lemma }})</span>
                    </div>
                    <div v-if="item.reason" class="text-caption mb-1 pa-1 rounded" style="background: var(--v-gray2-base); color: var(--v-secondary-base);">
                        <v-icon x-small class="mr-1">mdi-lightbulb-outline</v-icon>
                        推荐理由（参考说明，不是释义）：{{ item.reason }}
                    </div>
                    <div v-if="item.reason && item.source !== 'user_selected'" class="text-caption mb-1 warning--text">
                        请根据上下文填写中文释义；推荐理由不会自动保存，也不会替你完成释义。
                    </div>
                    <v-text-field
                        v-model="item.sense_zh"
                        label="中文释义（必填）"
                        dense
                        filled
                        rounded
                        hide-details
                        class="mt-1"
                        placeholder="填写中文释义，留空将跳过此项。推荐理由不是释义，请填写中文释义。"
                    />
                    <v-text-field
                        v-model="item.sense_en"
                        label="英文解释（可选，可留空）"
                        dense
                        filled
                        rounded
                        hide-details
                        class="mt-2"
                        placeholder="可留空，后续再补"
                    />
                    <div v-if="item.sentence_text" class="text-caption mt-1 text--secondary">
                        <v-icon x-small class="mr-1">mdi-format-quote-open</v-icon>{{ item.sentence_text }}
                    </div>
                </div>
            </v-card-text>
            <v-card-actions class="pa-3">
                <v-btn text @click="$emit('input', false)">取消</v-btn>
                <v-spacer />
                <span class="text-caption mr-2">
                    共 {{ items.length }} 项，
                    已填 {{ items.filter(i => i.sense_zh && i.sense_zh.trim()).length }} 项
                </span>
                <v-btn
                    color="error"
                    :loading="loading"
                    :disabled="items.length === 0"
                    @click="$emit('confirm')"
                >
                    <v-icon small class="mr-1">mdi-check</v-icon>
                    确认生成学习卡
                </v-btn>
            </v-card-actions>
            <v-alert v-if="error" type="error" dense text class="mx-3 mb-3">
                {{ error }}
            </v-alert>
        </v-card>
    </v-dialog>
</template>

<script>
/**
 * AiStudyCardGenerateCardsDialog
 * ==============================
 * Shared V5 "确认生成学习卡" dialog used by both VocabularySideBox (wide
 * screen) and VocabularyBox (narrow screen fallback).
 *
 * Responsibilities:
 *   - Render the candidate list with source chip / word / lemma / AI reason
 *     (reference only) / sense_zh required input / sense_en optional input /
 *     sentence_text.
 *   - Emit `input` (v-model) to toggle dialog visibility.
 *   - Emit `confirm` when user clicks the confirm button.
 *
 * The dialog does NOT call any backend endpoint, does NOT call AI, does NOT
 * write ReviewLog/FSRS/ReviewCard, and does NOT know about
 * VocabularySideBox / VocabularyBox internals. The parent owns the items
 * array (built via AiStudyCardGenerateCardsService.buildGenerateCardItems),
 * the loading state, the error message, and the actual generate request
 * (via AiStudyCardGenerateCardsService.generateAiStudyCards).
 *
 * GM52-AIStudyCardV5-DesktopArchitectureConvergence-1000-1.
 */
export default {
    name: 'AiStudyCardGenerateCardsDialog',
    computed: {
        hasAiRecommendedItems() {
            return this.items.some(item => item && item.source !== 'user_selected');
        },
    },
    props: {
        // v-model: whether the dialog is open.
        value: {
            type: Boolean,
            default: false,
        },
        // Confirm items built by buildGenerateCardItems().
        // The dialog mutates item.sense_zh / item.sense_en in place via v-model.
        items: {
            type: Array,
            default: () => [],
        },
        // Loading state for the confirm button.
        loading: {
            type: Boolean,
            default: false,
        },
        // Error message to display (empty string = no error).
        error: {
            type: String,
            default: '',
        },
    },
};
</script>
