<template>
    <div class="mt-5">
        <div class="d-flex align-center mb-2">
            <v-icon x-small class="mr-1">mdi-robot</v-icon>
            <span class="text-subtitle-1 font-weight-medium">AI 推荐词</span>
            <v-spacer />
            <span class="text-caption text--secondary">
                共 {{ recommendations.length }} 条，已勾选 {{ selectedIndices.length }} 条
            </span>
        </div>

        <!-- V4: 粘贴 AI 推荐词 JSON -->
        <div class="pa-3 rounded" style="border: 1px dashed var(--v-gray2-base);">
            <div class="text-caption font-weight-medium mb-2">粘贴 AI 返回的推荐词 JSON：</div>
            <v-textarea
                :value="jsonInput"
                @input="$emit('update:json-input', $event)"
                outlined
                dense
                rows="4"
                placeholder='{"schema_version":"ai-study-card-recommendations-v1","recommended_items":[{"word":"agency","lemma":"agency","surface":"agency","reason":"...","sentence_text":"...","confidence":0.86}]}'
                class="text-caption"
                hide-details
            />
            <div class="d-flex mt-2">
                <v-btn x-small color="primary" depressed @click="$emit('parse')">
                    <v-icon x-small class="mr-1">mdi-refresh</v-icon>
                    解析推荐词
                </v-btn>
                <v-btn x-small text color="secondary" class="ml-2" @click="$emit('clear')">
                    <v-icon x-small class="mr-1">mdi-eraser</v-icon>
                    清空推荐词
                </v-btn>
            </div>
            <div class="text-caption text--secondary mt-2">
                规则：AI 推荐词默认不选；不会与你已选的词重复；需手动勾选才会进入最终候选包。
            </div>
        </div>

        <!-- V4: 解析错误提示 -->
        <v-alert
            v-if="parseError"
            dense
            text
            type="error"
            class="mt-2 mb-0"
        >{{ parseError }}</v-alert>

        <!-- V4: 解析摘要 -->
        <div v-if="summary" class="mt-2 pa-2 rounded text-caption" style="background: var(--v-gray1-base);">
            <div class="font-weight-medium mb-1">解析摘要：</div>
            <div>原始推荐数量：{{ summary.original_count }}</div>
            <div>有效推荐数量：{{ summary.valid_count }}</div>
            <div>缺少 word 被丢弃：{{ summary.dropped_missing_word }}</div>
            <div>与用户已选词重复被丢弃：{{ summary.dropped_duplicate_with_user }}</div>
            <div>AI 推荐词内部重复被丢弃：{{ summary.dropped_ai_internal_duplicate }}</div>
        </div>

        <!-- V4: AI 推荐词列表（默认不选，每项 checkbox，reason/confidence/sentence_text 可见） -->
        <div v-if="recommendations.length > 0" class="mt-2">
            <div class="d-flex align-center mb-2">
                <v-btn x-small text color="primary" @click="$emit('select-all')">全选推荐词</v-btn>
                <v-btn x-small text color="secondary" class="ml-2" @click="$emit('deselect-all')">全不选推荐词</v-btn>
            </div>
            <v-list dense class="rounded" style="border: 1px solid var(--v-gray2-base);">
                <v-list-item v-for="(rec, idx) in recommendations" :key="'ai-rec-' + idx" class="px-2">
                    <v-list-item-action class="mr-2">
                        <v-checkbox
                            :input-value="selectedIndices.includes(idx)"
                            @change="$emit('toggle', idx)"
                            hide-details
                            dense
                        />
                    </v-list-item-action>
                    <v-list-item-content>
                        <v-list-item-title class="d-flex align-center">
                            <span class="font-weight-medium default-font">{{ rec.word }}</span>
                            <span v-if="rec.lemma && rec.lemma !== rec.word" class="text-caption text--secondary ml-2">({{ rec.lemma }})</span>
                            <v-chip x-small class="ml-2" color="purple" text-color="white">AI 推荐</v-chip>
                            <span v-if="rec.confidence !== null && rec.confidence !== undefined" class="text-caption text--secondary ml-2">
                                置信度 {{ Math.round(rec.confidence * 100) }}%
                            </span>
                        </v-list-item-title>
                        <v-list-item-subtitle v-if="rec.reason" class="text-caption text--secondary mt-1" style="white-space: normal; line-height: 1.4;">
                            原因：{{ rec.reason }}
                        </v-list-item-subtitle>
                        <v-list-item-subtitle v-if="rec.sentence_text" class="text-caption text--secondary mt-1" style="white-space: normal; line-height: 1.4;">
                            来源句子：{{ rec.sentence_text }}
                        </v-list-item-subtitle>
                    </v-list-item-content>
                </v-list-item>
            </v-list>
        </div>
    </div>
</template>

<script>
/**
 * AiStudyCardRecommendationPanel
 * ==============================
 * Presentational sub-component for the V4 AI recommendation paste / parse /
 * list area.
 *
 * Design rules:
 *   - Pure presentational (props in, events out).
 *   - Does NOT call axios.
 *   - Does NOT import Vuex / mapState.
 *   - Does NOT know about SideBox / Box / parent internals.
 *   - AI recommendations are always shown as UNSELECTED by default; the
 *     parent owns the selectedIndices array and must initialize it as [].
 *
 * Events:
 *   - update:json-input (string)
 *   - parse ()
 *   - clear ()
 *   - toggle (idx)
 *   - select-all ()
 *   - deselect-all ()
 *
 * (GM52-AIStudyCardDesktopWorkflowDeepModuleSplit-1000-4)
 */
export default {
    name: 'AiStudyCardRecommendationPanel',
    props: {
        jsonInput: { type: String, default: '' },
        recommendations: { type: Array, default: () => [] },
        selectedIndices: { type: Array, default: () => [] },
        parseError: { type: String, default: '' },
        summary: { type: Object, default: null },
    },
};
</script>
