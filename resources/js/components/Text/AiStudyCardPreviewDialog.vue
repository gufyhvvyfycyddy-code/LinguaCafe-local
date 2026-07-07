<template>
    <v-dialog :value="value" @input="$emit('input', $event)" max-width="760" scrollable>
        <v-card>
            <v-card-title class="d-flex align-center">
                <v-icon small class="mr-2">mdi-rocket-launch</v-icon>
                生成 AI 示意卡预览
                <v-spacer />
                <v-btn icon small @click="$emit('input', false)"><v-icon>mdi-close</v-icon></v-btn>
            </v-card-title>
            <v-card-text style="max-height: 65vh;">
                <!-- 安全说明 -->
                <v-alert
                    dense
                    text
                    type="info"
                    class="mt-2"
                >
                    当前只是预览，不会调用 AI，也不会生成复习卡。
                </v-alert>

                <!-- V3: 用户已选词区域（带勾选 + 来源句子 + 位置 + 数量） -->
                <div class="mt-4">
                    <div class="d-flex align-center mb-2">
                        <v-icon x-small class="mr-1">mdi-account-check</v-icon>
                        <span class="text-subtitle-1 font-weight-medium">你已选的词</span>
                        <v-spacer />
                        <span class="text-caption text--secondary">
                            共 {{ pendingItems.length }} 个，已勾选 {{ selectedItemIds.length }} 个
                        </span>
                    </div>
                    <div v-if="pendingItems.length === 0" class="text-caption text--secondary pa-3 rounded" style="border: 1px dashed var(--v-gray2-base);">
                        暂无已选词。请先在阅读页点词并加入「待 AI 解释」。
                    </div>
                    <div v-else>
                        <div class="d-flex align-center mb-2">
                            <v-btn x-small text color="primary" @click="$emit('select-all-preview')">全选</v-btn>
                            <v-btn x-small text color="secondary" @click="$emit('deselect-all-preview')">全不选</v-btn>
                        </div>
                        <v-list dense class="rounded" style="border: 1px solid var(--v-gray2-base);">
                            <v-list-item v-for="item in pendingItems" :key="item.id" class="px-2">
                                <v-list-item-action class="mr-2">
                                    <v-checkbox
                                        :input-value="selectedItemIds.includes(item.id)"
                                        @change="$emit('toggle-preview-item', item.id)"
                                        hide-details
                                        dense
                                    />
                                </v-list-item-action>
                                <v-list-item-content>
                                    <v-list-item-title class="d-flex align-center">
                                        <span class="font-weight-medium default-font">{{ item.word }}</span>
                                        <span v-if="item.lemma && item.lemma !== item.word" class="text-caption text--secondary ml-2">({{ item.lemma }})</span>
                                        <v-chip x-small class="ml-2" color="primary" text-color="white">待解释</v-chip>
                                    </v-list-item-title>
                                    <v-list-item-subtitle v-if="item.sentence_text" class="text-caption text--secondary mt-1" style="white-space: normal; line-height: 1.4;">
                                        来源句子：{{ item.sentence_text }}
                                    </v-list-item-subtitle>
                                    <v-list-item-subtitle class="text-caption text--secondary mt-1">
                                        章节 #{{ item.chapter_id }} | 文本块 #{{ item.text_block_index }}<span v-if="item.sentence_index !== null && item.sentence_index !== undefined"> | 句子 #{{ item.sentence_index }}</span>
                                    </v-list-item-subtitle>
                                </v-list-item-content>
                            </v-list-item>
                        </v-list>
                    </div>
                </div>

                <!-- V4: AI 推荐词区域（粘贴导入 + 解析 + 去重 + 默认不选 + 勾选） -->
                <AiStudyCardRecommendationPanel
                    :json-input="aiRecommendationJsonInput"
                    :recommendations="aiRecommendations"
                    :selected-indices="aiSelectedRecommendationIndices"
                    :parse-error="aiRecommendationParseError"
                    :summary="aiRecommendationSummary"
                    @update:json-input="$emit('update:ai-recommendation-json-input', $event)"
                    @parse="$emit('parse-ai-recommendations')"
                    @clear="$emit('clear-ai-recommendations')"
                    @toggle="$emit('toggle-ai-recommendation', $event)"
                    @select-all="$emit('select-all-ai-recommendations')"
                    @deselect-all="$emit('deselect-all-ai-recommendations')"
                />

                <!-- V6-1: provider-disabled request package -->
                <AiStudyCardV6RequestPackagePanel :selected-item-ids="selectedItemIds" />

                <!-- 规则说明 -->
                <div class="mt-5 pa-3 rounded" style="background: var(--v-gray1-base);">
                    <div class="text-caption font-weight-medium mb-1">生成规则说明：</div>
                    <ul class="text-caption text--secondary" style="line-height: 1.6;">
                        <li>你已选的词会自动进入最终候选包。</li>
                        <li>AI 推荐词默认不选，需手动勾选。</li>
                        <li>AI 推荐词不会与你已选的词重复。</li>
                        <li>只有你确认后，才会生成最终候选包。</li>
                        <li>最终候选包不会生成复习卡，也不会调用 AI。</li>
                        <li>下一阶段才会基于最终候选包生成 WordSense / ReviewCard（需用户再次确认）。</li>
                    </ul>
                </div>

                <!-- V3: 安全生成包展示区域 -->
                <AiStudyCardPackagePanel
                    v-if="aiPreviewPackage"
                    title="安全生成包"
                    icon="mdi-package-variant-closed"
                    copy-button-label="复制生成包"
                    :pkg="aiPreviewPackage"
                    :copy-message="aiPreviewCopyMessage"
                    :copied="aiPreviewCopied"
                    warning-text="这只是生成包，不是 AI 输出，不会生成复习卡。"
                    @copy="$emit('copy-preview-package')"
                />

                <!-- V3: 生成包错误提示 -->
                <v-alert
                    v-if="aiPreviewPackageError"
                    dense
                    text
                    type="error"
                    class="mt-3"
                >{{ aiPreviewPackageError }}</v-alert>

                <!-- V4: 最终候选包展示区域 -->
                <AiStudyCardPackagePanel
                    v-if="aiFinalCandidatesPackage"
                    title="最终候选包"
                    icon="mdi-check-decagram"
                    copy-button-label="复制最终候选包"
                    :pkg="aiFinalCandidatesPackage"
                    :copy-message="aiFinalCopyMessage"
                    :copied="aiFinalCopied"
                    warning-text="这只是最终候选包，不是 AI 输出，不会生成复习卡。下一阶段需你再次确认才会生成 WordSense / ReviewCard。"
                    @copy="$emit('copy-final-candidates-package')"
                />

                <!-- V4: 最终候选包错误提示 -->
                <v-alert
                    v-if="aiFinalCandidatesError"
                    dense
                    text
                    type="error"
                    class="mt-3"
                >{{ aiFinalCandidatesError }}</v-alert>

                <!-- V5: 结果展示由共享组件 AiStudyCardGenerateCardsResult 负责 -->
                <AiStudyCardGenerateCardsResult
                    :result="aiGenerateCardsResult"
                    @go-to-sense-reviews="$emit('go-to-sense-reviews')"
                    @dismiss="$emit('dismiss-result')"
                />
            </v-card-text>
            <v-card-actions class="d-flex pa-3">
                <v-btn text @click="$emit('input', false)">关闭</v-btn>
                <v-spacer />
                <v-btn
                    color="primary"
                    :disabled="selectedItemIds.length === 0 || aiPreviewPackageLoading"
                    :loading="aiPreviewPackageLoading"
                    @click="$emit('generate-preview-package')"
                >
                    <v-icon small class="mr-1">mdi-package-variant</v-icon>
                    准备生成
                </v-btn>
                <v-btn
                    color="success"
                    :disabled="(selectedItemIds.length === 0 && aiSelectedRecommendationIndices.length === 0) || aiFinalCandidatesLoading || !aiPreviewPackage"
                    :loading="aiFinalCandidatesLoading"
                    @click="$emit('generate-final-candidates-package')"
                    class="ml-2"
                >
                    <v-icon small class="mr-1">mdi-check-decagram</v-icon>
                    生成最终候选包
                </v-btn>
                <v-btn
                    color="error"
                    :disabled="!aiFinalCandidatesPackage || aiGenerateCardsLoading"
                    :loading="aiGenerateCardsLoading"
                    @click="$emit('open-generate-cards-dialog')"
                    class="ml-2"
                >
                    <v-icon small class="mr-1">mdi-cards-outline</v-icon>
                    生成学习卡
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
import AiStudyCardGenerateCardsResult from './AiStudyCardGenerateCardsResult.vue';
import AiStudyCardRecommendationPanel from './AiStudyCardRecommendationPanel.vue';
import AiStudyCardPackagePanel from './AiStudyCardPackagePanel.vue';
import AiStudyCardV6RequestPackagePanel from './AiStudyCardV6RequestPackagePanel.vue';

/**
 * AiStudyCardPreviewDialog
 * ========================
 * Presentational sub-component that wraps the V3-V5 preview dialog. Composes
 * the user-selected-words list, the V4 AI recommendation panel, the V3/V4
 * package panels, and the V5 result panel.
 *
 * Design rules:
 *   - Pure presentational (props in, events out).
 *   - Does NOT call axios.
 *   - Does NOT import Vuex / mapState.
 *   - Does NOT know about SideBox / Box / parent internals.
 *   - Delegates all rendering to child presentational components.
 *
 * Events (bubbled to the container):
 *   - input (boolean) — v-model for dialog visibility
 *   - toggle-preview-item (itemId)
 *   - select-all-preview ()
 *   - deselect-all-preview ()
 *   - update:ai-recommendation-json-input (string)
 *   - parse-ai-recommendations ()
 *   - clear-ai-recommendations ()
 *   - toggle-ai-recommendation (idx)
 *   - select-all-ai-recommendations ()
 *   - deselect-all-ai-recommendations ()
 *   - generate-preview-package ()
 *   - copy-preview-package ()
 *   - generate-final-candidates-package ()
 *   - copy-final-candidates-package ()
 *   - open-generate-cards-dialog ()
 *   - go-to-sense-reviews ()
 *   - dismiss-result ()
 *
 * (GM52-AIStudyCardDesktopWorkflowDeepModuleSplit-1000-4)
 */
export default {
    name: 'AiStudyCardPreviewDialog',
    components: {
        AiStudyCardGenerateCardsResult,
        AiStudyCardRecommendationPanel,
        AiStudyCardPackagePanel,
        AiStudyCardV6RequestPackagePanel,
    },
    props: {
        value: { type: Boolean, default: false },
        pendingItems: { type: Array, default: () => [] },
        selectedItemIds: { type: Array, default: () => [] },
        aiRecommendationJsonInput: { type: String, default: '' },
        aiRecommendations: { type: Array, default: () => [] },
        aiSelectedRecommendationIndices: { type: Array, default: () => [] },
        aiRecommendationParseError: { type: String, default: '' },
        aiRecommendationSummary: { type: Object, default: null },
        aiPreviewPackage: { type: Object, default: null },
        aiPreviewPackageError: { type: String, default: '' },
        aiPreviewPackageLoading: { type: Boolean, default: false },
        aiPreviewCopyMessage: { type: String, default: '' },
        aiPreviewCopied: { type: Boolean, default: false },
        aiFinalCandidatesPackage: { type: Object, default: null },
        aiFinalCandidatesError: { type: String, default: '' },
        aiFinalCandidatesLoading: { type: Boolean, default: false },
        aiFinalCopyMessage: { type: String, default: '' },
        aiFinalCopied: { type: Boolean, default: false },
        aiGenerateCardsResult: { type: Object, default: null },
        aiGenerateCardsLoading: { type: Boolean, default: false },
    },
};
</script>
