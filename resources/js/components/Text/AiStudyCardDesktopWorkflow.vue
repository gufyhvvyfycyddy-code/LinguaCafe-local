<template>
    <div class="ai-study-card-desktop-workflow">
        <!-- V1: 待 AI 解释入口按钮 -->
        <v-btn
            small
            rounded
            outlined
            color="primary"
            class="mb-2"
            :loading="aiStudyCardPendingLoading"
            @click="markAiStudyCardPending"
        >
            <v-icon x-small class="mr-1">mdi-lightbulb-outline</v-icon>
            待 AI 解释
        </v-btn>
        <v-btn
            small
            rounded
            depressed
            color="info"
            class="mb-2"
            @click="openAiPendingListDialog"
        >
            <v-icon x-small class="mr-1">mdi-format-list-bulleted</v-icon>
            待 AI 解释列表
        </v-btn>
        <v-alert
            v-if="aiStudyCardPendingMessage"
            dense
            text
            class="mb-3"
            :type="aiStudyCardPendingError ? 'error' : 'success'"
        >{{ aiStudyCardPendingMessage }}</v-alert>

        <!-- V3: 待 AI 解释列表面板（含已取消视图 + 恢复按钮） -->
        <v-dialog v-model="aiPendingListDialog" max-width="640" scrollable>
            <v-card>
                <v-card-title class="d-flex align-center">
                    <v-icon small class="mr-2">mdi-format-list-bulleted</v-icon>
                    待 AI 解释的词
                    <v-spacer />
                    <v-btn icon small @click="aiPendingListDialog = false"><v-icon>mdi-close</v-icon></v-btn>
                </v-card-title>
                <v-card-text style="max-height: 60vh;">
                    <!-- V3: 待解释 / 已取消 切换 -->
                    <div class="d-flex align-center mt-2 mb-2">
                        <v-btn-toggle v-model="aiPendingListStatusFilter" dense mandatory>
                            <v-btn x-small value="pending">
                                待解释 ({{ aiPendingItems.length }})
                            </v-btn>
                            <v-btn x-small value="dismissed">
                                已取消 ({{ aiPendingDismissedItems.length }})
                            </v-btn>
                        </v-btn-toggle>
                    </div>

                    <v-alert
                        v-if="aiPendingListError"
                        dense
                        text
                        type="error"
                        class="mt-2"
                    >{{ aiPendingListError }}</v-alert>
                    <v-alert
                        v-if="aiPendingListMessage"
                        dense
                        text
                        type="success"
                        class="mt-2"
                    >{{ aiPendingListMessage }}</v-alert>

                    <div v-if="aiPendingListLoading" class="text-center pa-4">
                        <v-progress-circular indeterminate color="primary" />
                    </div>

                    <!-- 待解释列表 -->
                    <template v-else-if="aiPendingListStatusFilter === 'pending'">
                        <div v-if="aiPendingItems.length === 0" class="text-center text--secondary pa-4">
                            暂无待 AI 解释的词。
                        </div>
                        <v-list v-else dense>
                            <v-list-item v-for="item in aiPendingItems" :key="item.id" class="px-0">
                                <v-list-item-content>
                                    <v-list-item-title class="d-flex align-center">
                                        <span class="font-weight-medium default-font">{{ item.word }}</span>
                                        <span v-if="item.lemma && item.lemma !== item.word" class="text-caption text--secondary ml-2">({{ item.lemma }})</span>
                                    </v-list-item-title>
                                    <v-list-item-subtitle v-if="item.sentence_text" class="text-caption text--secondary mt-1" style="white-space: normal; line-height: 1.4;">
                                        {{ item.sentence_text }}
                                    </v-list-item-subtitle>
                                    <v-list-item-subtitle class="text-caption text--secondary mt-1">
                                        状态：{{ item.status === 'pending' ? '待解释' : item.status }}
                                        <span class="ml-2">| 添加于 {{ formatDate(item.created_at) }}</span>
                                    </v-list-item-subtitle>
                                </v-list-item-content>
                                <v-list-item-action>
                                    <v-btn
                                        x-small
                                        rounded
                                        depressed
                                        color="error"
                                        :loading="aiPendingDismissLoadingId === item.id"
                                        @click="dismissAiPendingItem(item.id)"
                                    >
                                        <v-icon x-small class="mr-1">mdi-close</v-icon>
                                        取消
                                    </v-btn>
                                </v-list-item-action>
                            </v-list-item>
                        </v-list>
                    </template>

                    <!-- V3: 已取消列表（含恢复按钮） -->
                    <template v-else-if="aiPendingListStatusFilter === 'dismissed'">
                        <div v-if="aiPendingDismissedItems.length === 0" class="text-center text--secondary pa-4">
                            暂无已取消的词。
                        </div>
                        <v-list v-else dense>
                            <v-list-item v-for="item in aiPendingDismissedItems" :key="item.id" class="px-0">
                                <v-list-item-content>
                                    <v-list-item-title class="d-flex align-center">
                                        <span class="font-weight-medium default-font">{{ item.word }}</span>
                                        <span v-if="item.lemma && item.lemma !== item.word" class="text-caption text--secondary ml-2">({{ item.lemma }})</span>
                                    </v-list-item-title>
                                    <v-list-item-subtitle v-if="item.sentence_text" class="text-caption text--secondary mt-1" style="white-space: normal; line-height: 1.4;">
                                        {{ item.sentence_text }}
                                    </v-list-item-subtitle>
                                    <v-list-item-subtitle class="text-caption text--secondary mt-1">
                                        状态：已取消
                                        <span class="ml-2">| 添加于 {{ formatDate(item.created_at) }}</span>
                                    </v-list-item-subtitle>
                                </v-list-item-content>
                                <v-list-item-action>
                                    <v-btn
                                        x-small
                                        rounded
                                        depressed
                                        color="success"
                                        :loading="aiPendingRestoreLoadingId === item.id"
                                        @click="restoreAiPendingItem(item.id)"
                                    >
                                        <v-icon x-small class="mr-1">mdi-restore</v-icon>
                                        恢复
                                    </v-btn>
                                </v-list-item-action>
                            </v-list-item>
                        </v-list>
                    </template>
                </v-card-text>
                <v-card-actions class="d-flex flex-column align-stretch pa-3">
                    <v-btn
                        block
                        color="primary"
                        :disabled="aiPendingItems.length === 0"
                        @click="openAiStudyCardPreview"
                    >
                        <v-icon small class="mr-1">mdi-rocket-launch</v-icon>
                        生成 AI 示意卡
                    </v-btn>
                    <div class="text-caption text--secondary mt-2 text-center">
                        当前共 {{ aiPendingItems.length }} 个待解释词
                    </div>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- V3-V5: 生成预览弹窗（含 V4 AI 推荐词粘贴 + V5 生成学习卡） -->
        <v-dialog v-model="aiStudyCardPreviewDialog" max-width="760" scrollable>
            <v-card>
                <v-card-title class="d-flex align-center">
                    <v-icon small class="mr-2">mdi-rocket-launch</v-icon>
                    生成 AI 示意卡预览
                    <v-spacer />
                    <v-btn icon small @click="aiStudyCardPreviewDialog = false"><v-icon>mdi-close</v-icon></v-btn>
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
                                共 {{ aiPendingItems.length }} 个，已勾选 {{ aiPreviewSelectedItemIds.length }} 个
                            </span>
                        </div>
                        <div v-if="aiPendingItems.length === 0" class="text-caption text--secondary pa-3 rounded" style="border: 1px dashed var(--v-gray2-base);">
                            暂无已选词。请先在阅读页点词并加入「待 AI 解释」。
                        </div>
                        <div v-else>
                            <div class="d-flex align-center mb-2">
                                <v-btn x-small text color="primary" @click="selectAllPreviewItems">全选</v-btn>
                                <v-btn x-small text color="secondary" @click="deselectAllPreviewItems">全不选</v-btn>
                            </div>
                            <v-list dense class="rounded" style="border: 1px solid var(--v-gray2-base);">
                                <v-list-item v-for="item in aiPendingItems" :key="item.id" class="px-2">
                                    <v-list-item-action class="mr-2">
                                        <v-checkbox
                                            :input-value="aiPreviewSelectedItemIds.includes(item.id)"
                                            @change="togglePreviewItemSelection(item.id)"
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

                    <!-- AI 推荐词区域（V4: 粘贴导入 + 解析 + 去重 + 默认不选 + 勾选） -->
                    <div class="mt-5">
                        <div class="d-flex align-center mb-2">
                            <v-icon x-small class="mr-1">mdi-robot</v-icon>
                            <span class="text-subtitle-1 font-weight-medium">AI 推荐词</span>
                            <v-spacer />
                            <span class="text-caption text--secondary">
                                共 {{ aiRecommendations.length }} 条，已勾选 {{ aiSelectedRecommendationIndices.length }} 条
                            </span>
                        </div>

                        <!-- V4: 粘贴 AI 推荐词 JSON -->
                        <div class="pa-3 rounded" style="border: 1px dashed var(--v-gray2-base);">
                            <div class="text-caption font-weight-medium mb-2">粘贴 AI 返回的推荐词 JSON：</div>
                            <v-textarea
                                v-model="aiRecommendationJsonInput"
                                outlined
                                dense
                                rows="4"
                                placeholder='{"schema_version":"ai-study-card-recommendations-v1","recommended_items":[{"word":"agency","lemma":"agency","surface":"agency","reason":"...","sentence_text":"...","confidence":0.86}]}'
                                class="text-caption"
                                hide-details
                            />
                            <div class="d-flex mt-2">
                                <v-btn x-small color="primary" depressed @click="parseAiRecommendations" :loading="false">
                                    <v-icon x-small class="mr-1">mdi-refresh</v-icon>
                                    解析推荐词
                                </v-btn>
                                <v-btn x-small text color="secondary" class="ml-2" @click="clearAiRecommendations">
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
                            v-if="aiRecommendationParseError"
                            dense
                            text
                            type="error"
                            class="mt-2 mb-0"
                        >{{ aiRecommendationParseError }}</v-alert>

                        <!-- V4: 解析摘要 -->
                        <div v-if="aiRecommendationSummary" class="mt-2 pa-2 rounded text-caption" style="background: var(--v-gray1-base);">
                            <div class="font-weight-medium mb-1">解析摘要：</div>
                            <div>原始推荐数量：{{ aiRecommendationSummary.original_count }}</div>
                            <div>有效推荐数量：{{ aiRecommendationSummary.valid_count }}</div>
                            <div>缺少 word 被丢弃：{{ aiRecommendationSummary.dropped_missing_word }}</div>
                            <div>与用户已选词重复被丢弃：{{ aiRecommendationSummary.dropped_duplicate_with_user }}</div>
                            <div>AI 推荐词内部重复被丢弃：{{ aiRecommendationSummary.dropped_ai_internal_duplicate }}</div>
                        </div>

                        <!-- V4: AI 推荐词列表（默认不选，每项 checkbox，reason/confidence/sentence_text 可见） -->
                        <div v-if="aiRecommendations.length > 0" class="mt-2">
                            <div class="d-flex align-center mb-2">
                                <v-btn x-small text color="primary" @click="selectAllAiRecommendations">全选推荐词</v-btn>
                                <v-btn x-small text color="secondary" class="ml-2" @click="deselectAllAiRecommendations">全不选推荐词</v-btn>
                            </div>
                            <v-list dense class="rounded" style="border: 1px solid var(--v-gray2-base);">
                                <v-list-item v-for="(rec, idx) in aiRecommendations" :key="'ai-rec-' + idx" class="px-2">
                                    <v-list-item-action class="mr-2">
                                        <v-checkbox
                                            :input-value="aiSelectedRecommendationIndices.includes(idx)"
                                            @change="toggleAiRecommendationSelection(idx)"
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
                    <div v-if="aiPreviewPackage" class="mt-5">
                        <div class="d-flex align-center mb-2">
                            <v-icon x-small class="mr-1">mdi-package-variant-closed</v-icon>
                            <span class="text-subtitle-1 font-weight-medium">安全生成包</span>
                            <v-spacer />
                            <v-btn
                                x-small
                                rounded
                                depressed
                                color="primary"
                                @click="copyPreviewPackage"
                            >
                                <v-icon x-small class="mr-1">mdi-content-copy</v-icon>
                                复制生成包
                            </v-btn>
                        </div>
                        <v-alert
                            v-if="aiPreviewCopyMessage"
                            dense
                            text
                            :type="aiPreviewCopied ? 'success' : 'error'"
                            class="mb-2"
                        >{{ aiPreviewCopyMessage }}</v-alert>
                        <v-alert
                            dense
                            text
                            type="warning"
                            class="mb-2"
                        >
                            这只是生成包，不是 AI 输出，不会生成复习卡。
                        </v-alert>
                        <pre class="pa-3 rounded text-caption" style="background: var(--v-gray1-base); max-height: 240px; overflow: auto; white-space: pre-wrap; word-break: break-all;">{{ JSON.stringify(aiPreviewPackage, null, 2) }}</pre>
                    </div>

                    <!-- V3: 生成包错误提示 -->
                    <v-alert
                        v-if="aiPreviewPackageError"
                        dense
                        text
                        type="error"
                        class="mt-3"
                    >{{ aiPreviewPackageError }}</v-alert>

                    <!-- V4: 最终候选包展示区域 -->
                    <div v-if="aiFinalCandidatesPackage" class="mt-5">
                        <div class="d-flex align-center mb-2">
                            <v-icon x-small class="mr-1">mdi-check-decagram</v-icon>
                            <span class="text-subtitle-1 font-weight-medium">最终候选包</span>
                            <v-spacer />
                            <v-btn
                                x-small
                                rounded
                                depressed
                                color="primary"
                                @click="copyFinalCandidatesPackage"
                            >
                                <v-icon x-small class="mr-1">mdi-content-copy</v-icon>
                                复制最终候选包
                            </v-btn>
                        </div>
                        <v-alert
                            v-if="aiFinalCopyMessage"
                            dense
                            text
                            :type="aiFinalCopied ? 'success' : 'error'"
                            class="mb-2"
                        >{{ aiFinalCopyMessage }}</v-alert>
                        <v-alert
                            dense
                            text
                            type="warning"
                            class="mb-2"
                        >
                            这只是最终候选包，不是 AI 输出，不会生成复习卡。下一阶段需你再次确认才会生成 WordSense / ReviewCard。
                        </v-alert>
                        <pre class="pa-3 rounded text-caption" style="background: var(--v-gray1-base); max-height: 280px; overflow: auto; white-space: pre-wrap; word-break: break-all;">{{ JSON.stringify(aiFinalCandidatesPackage, null, 2) }}</pre>
                    </div>

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
                        @go-to-sense-reviews="goToSenseReviews"
                        @dismiss="aiGenerateCardsResult = null"
                    />
                </v-card-text>
                <v-card-actions class="d-flex pa-3">
                    <v-btn text @click="aiStudyCardPreviewDialog = false">关闭</v-btn>
                    <v-spacer />
                    <v-btn
                        color="primary"
                        :disabled="aiPreviewSelectedItemIds.length === 0 || aiPreviewPackageLoading"
                        :loading="aiPreviewPackageLoading"
                        @click="generatePreviewPackage"
                    >
                        <v-icon small class="mr-1">mdi-package-variant</v-icon>
                        准备生成
                    </v-btn>
                    <v-btn
                        color="success"
                        :disabled="(aiPreviewSelectedItemIds.length === 0 && aiSelectedRecommendationIndices.length === 0) || aiFinalCandidatesLoading || !aiPreviewPackage"
                        :loading="aiFinalCandidatesLoading"
                        @click="generateFinalCandidatesPackage"
                        class="ml-2"
                    >
                        <v-icon small class="mr-1">mdi-check-decagram</v-icon>
                        生成最终候选包
                    </v-btn>
                    <v-btn
                        color="error"
                        :disabled="!aiFinalCandidatesPackage || aiGenerateCardsLoading"
                        :loading="aiGenerateCardsLoading"
                        @click="openGenerateCardsDialog"
                        class="ml-2"
                    >
                        <v-icon small class="mr-1">mdi-cards-outline</v-icon>
                        生成学习卡
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- V5: 确认生成学习卡对话框（共享组件） -->
        <AiStudyCardGenerateCardsDialog
            v-model="aiGenerateCardsDialog"
            :items="aiGenerateCardsItems"
            :loading="aiGenerateCardsLoading"
            :error="aiGenerateCardsError"
            @confirm="confirmGenerateCards"
        />
    </div>
</template>

<script>
import axios from 'axios';
import { mapState } from 'vuex';
import AiStudyCardGenerateCardsDialog from './AiStudyCardGenerateCardsDialog.vue';
import AiStudyCardGenerateCardsResult from './AiStudyCardGenerateCardsResult.vue';
import {
    parseAiRecommendations as parseRecommendations,
    rededupeRecommendations,
} from '../../services/AiStudyCardRecommendationParserService.js';
import {
    createPendingItem,
    listPendingItems,
    dismissPendingItem,
    restorePendingItem,
    buildPreviewPackage,
    buildFinalCandidatesPackage,
} from '../../services/AiStudyCardPendingWorkflowService.js';
import {
    buildGenerateCardItems,
    filterConfirmedGenerateCardItems,
    generateAiStudyCards,
} from '../../services/AiStudyCardGenerateCardsService.js';

/**
 * AiStudyCardDesktopWorkflow
 * =========================
 * Desktop AIStudyCard V1-V5 feature island. Owns the entire AI study card
 * workflow (pending list -> preview package -> AI recommendation paste ->
 * final candidates package -> generate cards dialog -> result display).
 *
 * Design rules:
 *   - This component is the ONLY place that owns V1-V5 workflow state.
 *   - Parent components (VocabularySideBox / VocabularyBox) only render this
 *     component; they no longer carry V1-V5 data/methods/templates.
 *   - Reads current word context from the Vuex `vocabularyBox` store (same
 *     source the parents used). No props required for word context.
 *   - Emits `generated` after V5 generation succeeds, `message` for status.
 *   - Does NOT call any external AI provider.
 *   - Does NOT write ReviewLog / FSRS / legacy word ReviewCard.
 *   - Does NOT know about SideBox / Box / BottomSheet internals.
 *
 * Why this component exists (GM52-AIStudyCardV5-DesktopWorkflowFeatureIsland-1000-2):
 *   Previously VocabularySideBox.vue and VocabularyBox.vue each carried a
 *   full copy of V1-V4 state, methods, and templates. The previous round
 *   (GM52-AIStudyCardV5-DesktopArchitectureConvergence-1000-1) only
 *   converged the V5 generate-cards dialog. V1-V4 remained duplicated. This
 *   component converges the entire V1-V5 workflow into one feature island
 *   so future rule changes only need to touch one place.
 */
export default {
    name: 'AiStudyCardDesktopWorkflow',
    components: {
        AiStudyCardGenerateCardsDialog,
        AiStudyCardGenerateCardsResult,
    },
    computed: mapState({
        type: state => state.vocabularyBox.type,
        word: state => state.vocabularyBox.word,
        _chapterId: state => state.vocabularyBox.chapterId,
        _sentenceIndex: state => state.vocabularyBox.sentenceIndex,
        _sentenceText: state => state.vocabularyBox.sentenceText,
        _studyBase: state => state.vocabularyBox.studyBase,
        _baseWord: state => state.vocabularyBox.baseWord,
    }),
    data() {
        return {
            // V1: pending button feedback
            aiStudyCardPendingLoading: false,
            aiStudyCardPendingMessage: '',
            aiStudyCardPendingError: false,

            // V2/V3: pending list dialog
            aiPendingListDialog: false,
            aiPendingListLoading: false,
            aiPendingListMessage: '',
            aiPendingListError: '',
            aiPendingItems: [],
            aiPendingDismissLoadingId: null,
            aiPendingListStatusFilter: 'pending',
            aiPendingDismissedItems: [],
            aiPendingRestoreLoadingId: null,

            // V3: preview dialog + selected ids + preview package
            aiStudyCardPreviewDialog: false,
            aiPreviewSelectedItemIds: [],
            aiPreviewPackage: null,
            aiPreviewPackageLoading: false,
            aiPreviewPackageError: '',
            aiPreviewCopyMessage: '',
            aiPreviewCopied: false,

            // V4: AI recommendation paste + parse + dedupe + selection
            aiRecommendationJsonInput: '',
            aiRecommendations: [],
            aiSelectedRecommendationIndices: [],
            aiRecommendationParseError: '',
            aiRecommendationSummary: null,

            // V4: final candidates package
            aiFinalCandidatesPackage: null,
            aiFinalCandidatesLoading: false,
            aiFinalCandidatesError: '',
            aiFinalCopyMessage: '',
            aiFinalCopied: false,

            // V5: generate cards dialog + result
            aiGenerateCardsDialog: false,
            aiGenerateCardsItems: [],
            aiGenerateCardsLoading: false,
            aiGenerateCardsError: '',
            aiGenerateCardsResult: null,
        };
    },
    methods: {
        resetAiStudyCardPendingFeedback() {
            this.aiStudyCardPendingLoading = false;
            this.aiStudyCardPendingMessage = '';
            this.aiStudyCardPendingError = false;
        },
        // V1: mark current word as pending AI explanation
        markAiStudyCardPending() {
            if (this.type !== 'word') {
                return;
            }

            const chapterId = this._chapterId;
            const sentenceIndex = this._sentenceIndex;
            if (!chapterId || sentenceIndex === null || sentenceIndex === undefined || !this.word) {
                this.aiStudyCardPendingError = true;
                this.aiStudyCardPendingMessage = '缺少章节或句子位置，暂时无法加入待 AI 解释。';
                return;
            }

            this.aiStudyCardPendingLoading = true;
            this.aiStudyCardPendingError = false;
            this.aiStudyCardPendingMessage = '';

            createPendingItem(axios, {
                chapterId,
                sentenceIndex,
                word: this.word,
                surface: this.word,
                lemma: this._studyBase || this._baseWord || this.word,
                sentenceText: this._sentenceText || '',
                source: 'reader_vocabulary_workflow',
            }).then((data) => {
                this.aiStudyCardPendingMessage = data && data.message
                    ? data.message
                    : '已加入待 AI 解释。';
            }).catch((error) => {
                this.aiStudyCardPendingError = true;
                this.aiStudyCardPendingMessage = error.message || '加入待 AI 解释失败。';
            }).finally(() => {
                this.aiStudyCardPendingLoading = false;
            });
        },
        // V3: open pending list dialog and load pending + dismissed
        openAiPendingListDialog() {
            this.aiPendingListDialog = true;
            this.aiPendingListMessage = '';
            this.aiPendingListError = '';
            this.aiPendingListStatusFilter = 'pending';
            this.loadAiPendingItems();
            this.loadAiPendingDismissedItems();
        },
        // V3: load pending items (optionally filtered by current chapter)
        loadAiPendingItems() {
            this.aiPendingListLoading = true;
            this.aiPendingListError = '';
            listPendingItems(axios, { chapterId: this._chapterId, status: 'pending' })
                .then(({ items }) => {
                    this.aiPendingItems = items;
                })
                .catch((error) => {
                    this.aiPendingListError = error.message || '加载待解释列表失败。';
                    this.aiPendingItems = [];
                })
                .finally(() => {
                    this.aiPendingListLoading = false;
                });
        },
        // V3: load dismissed items
        loadAiPendingDismissedItems() {
            listPendingItems(axios, { chapterId: this._chapterId, status: 'dismissed' })
                .then(({ items }) => {
                    this.aiPendingDismissedItems = items;
                })
                .catch(() => {
                    this.aiPendingDismissedItems = [];
                });
        },
        // V3: dismiss a pending item
        dismissAiPendingItem(itemId) {
            this.aiPendingDismissLoadingId = itemId;
            this.aiPendingListMessage = '';
            this.aiPendingListError = '';
            dismissPendingItem(axios, itemId)
                .then(({ message }) => {
                    this.aiPendingListMessage = message;
                    const dismissed = this.aiPendingItems.find(i => i.id === itemId);
                    this.aiPendingItems = this.aiPendingItems.filter(i => i.id !== itemId);
                    if (dismissed) {
                        this.aiPendingDismissedItems.unshift({ ...dismissed, status: 'dismissed' });
                    }
                })
                .catch((error) => {
                    this.aiPendingListError = error.message || '取消失败。';
                })
                .finally(() => {
                    this.aiPendingDismissLoadingId = null;
                });
        },
        // V3: restore a dismissed item
        restoreAiPendingItem(itemId) {
            this.aiPendingRestoreLoadingId = itemId;
            this.aiPendingListMessage = '';
            this.aiPendingListError = '';
            restorePendingItem(axios, itemId)
                .then(({ message }) => {
                    this.aiPendingListMessage = message;
                    const restored = this.aiPendingDismissedItems.find(i => i.id === itemId);
                    this.aiPendingDismissedItems = this.aiPendingDismissedItems.filter(i => i.id !== itemId);
                    if (restored) {
                        this.aiPendingItems.unshift({ ...restored, status: 'pending' });
                    }
                })
                .catch((error) => {
                    this.aiPendingListError = error.message || '恢复失败。';
                })
                .finally(() => {
                    this.aiPendingRestoreLoadingId = null;
                });
        },
        // V3: open preview dialog, initialize selection state
        openAiStudyCardPreview() {
            this.aiStudyCardPreviewDialog = true;
            // default: all selected
            this.aiPreviewSelectedItemIds = this.aiPendingItems.map(i => i.id);
            this.aiPreviewPackage = null;
            this.aiPreviewPackageError = '';
            this.aiPreviewCopyMessage = '';
            this.aiPreviewCopied = false;
            // V4: clear AI recommendation state
            this.aiRecommendationJsonInput = '';
            this.aiRecommendations = [];
            this.aiSelectedRecommendationIndices = [];
            this.aiRecommendationParseError = '';
            this.aiRecommendationSummary = null;
            this.aiFinalCandidatesPackage = null;
            this.aiFinalCandidatesError = '';
            this.aiFinalCopyMessage = '';
            this.aiFinalCopied = false;
        },
        // V3: toggle one pending item selection
        togglePreviewItemSelection(itemId) {
            const idx = this.aiPreviewSelectedItemIds.indexOf(itemId);
            if (idx >= 0) {
                this.aiPreviewSelectedItemIds.splice(idx, 1);
            } else {
                this.aiPreviewSelectedItemIds.push(itemId);
            }
            this.aiPreviewPackage = null;
            this.aiPreviewCopyMessage = '';
            this.aiPreviewCopied = false;
            // V4: re-dedupe AI recommendations when user selection changes
            this.rededupeAiRecommendationsAfterUserSelectionChange();
            this.aiFinalCandidatesPackage = null;
            this.aiFinalCopyMessage = '';
            this.aiFinalCopied = false;
        },
        // V3: select all
        selectAllPreviewItems() {
            this.aiPreviewSelectedItemIds = this.aiPendingItems.map(i => i.id);
            this.aiPreviewPackage = null;
            this.aiPreviewCopyMessage = '';
            this.aiPreviewCopied = false;
            this.rededupeAiRecommendationsAfterUserSelectionChange();
            this.aiFinalCandidatesPackage = null;
            this.aiFinalCopyMessage = '';
            this.aiFinalCopied = false;
        },
        // V3: deselect all
        deselectAllPreviewItems() {
            this.aiPreviewSelectedItemIds = [];
            this.aiPreviewPackage = null;
            this.aiPreviewCopyMessage = '';
            this.aiPreviewCopied = false;
            this.rededupeAiRecommendationsAfterUserSelectionChange();
            this.aiFinalCandidatesPackage = null;
            this.aiFinalCopyMessage = '';
            this.aiFinalCopied = false;
        },
        // V4: parse AI recommendation JSON (delegates to pure service)
        parseAiRecommendations() {
            this.aiRecommendationParseError = '';
            this.aiRecommendationSummary = null;
            this.aiRecommendations = [];
            this.aiSelectedRecommendationIndices = [];

            const result = parseRecommendations(
                this.aiRecommendationJsonInput,
                this.aiPendingItems,
                this.aiPreviewSelectedItemIds
            );

            this.aiRecommendations = result.recommendations;
            // V4 hardening: AI recommendations default to UNSELECTED
            this.aiSelectedRecommendationIndices = [];
            this.aiRecommendationSummary = result.summary;

            if (!result.ok) {
                this.aiRecommendationParseError = result.error;
            }
        },
        // V4: clear AI recommendations
        clearAiRecommendations() {
            this.aiRecommendationJsonInput = '';
            this.aiRecommendations = [];
            this.aiSelectedRecommendationIndices = [];
            this.aiRecommendationParseError = '';
            this.aiRecommendationSummary = null;
            this.aiFinalCandidatesPackage = null;
            this.aiFinalCandidatesError = '';
            this.aiFinalCopyMessage = '';
            this.aiFinalCopied = false;
        },
        // V4: re-dedupe AI recommendations after user selection changes
        rededupeAiRecommendationsAfterUserSelectionChange() {
            if (this.aiRecommendations.length === 0) {
                return;
            }
            const result = rededupeRecommendations(
                this.aiRecommendations,
                this.aiSelectedRecommendationIndices,
                this.aiPendingItems,
                this.aiPreviewSelectedItemIds
            );
            this.aiRecommendations = result.recommendations;
            this.aiSelectedRecommendationIndices = result.selectedIndices;
            if (this.aiRecommendationSummary && result.dropped > 0) {
                this.aiRecommendationSummary.valid_count = result.recommendations.length;
                this.aiRecommendationSummary.dropped_duplicate_with_user += result.dropped;
            }
        },
        // V4: toggle AI recommendation selection
        toggleAiRecommendationSelection(idx) {
            const i = this.aiSelectedRecommendationIndices.indexOf(idx);
            if (i >= 0) {
                this.aiSelectedRecommendationIndices.splice(i, 1);
            } else {
                this.aiSelectedRecommendationIndices.push(idx);
            }
            this.aiFinalCandidatesPackage = null;
            this.aiFinalCopyMessage = '';
            this.aiFinalCopied = false;
        },
        // V4: select all AI recommendations
        selectAllAiRecommendations() {
            this.aiSelectedRecommendationIndices = this.aiRecommendations.map((_, idx) => idx);
            this.aiFinalCandidatesPackage = null;
            this.aiFinalCopyMessage = '';
            this.aiFinalCopied = false;
        },
        // V4: deselect all AI recommendations
        deselectAllAiRecommendations() {
            this.aiSelectedRecommendationIndices = [];
            this.aiFinalCandidatesPackage = null;
            this.aiFinalCopyMessage = '';
            this.aiFinalCopied = false;
        },
        // V3: build safe preview package
        generatePreviewPackage() {
            if (this.aiPreviewSelectedItemIds.length === 0) {
                return;
            }
            this.aiPreviewPackageLoading = true;
            this.aiPreviewPackageError = '';
            this.aiPreviewPackage = null;
            this.aiPreviewCopyMessage = '';
            this.aiPreviewCopied = false;
            buildPreviewPackage(axios, this.aiPreviewSelectedItemIds)
                .then(({ package: pkg }) => {
                    this.aiPreviewPackage = pkg;
                })
                .catch((error) => {
                    this.aiPreviewPackageError = error.message || '生成安全包失败。';
                })
                .finally(() => {
                    this.aiPreviewPackageLoading = false;
                });
        },
        // V3: copy preview package JSON
        copyPreviewPackage() {
            if (!this.aiPreviewPackage) {
                return;
            }
            this.copyJsonToClipboard(this.aiPreviewPackage, 'aiPreviewCopied', 'aiPreviewCopyMessage');
        },
        // V4: build final candidates package
        generateFinalCandidatesPackage() {
            if (this.aiPreviewSelectedItemIds.length === 0 && this.aiSelectedRecommendationIndices.length === 0) {
                return;
            }
            if (!this.aiPreviewPackage) {
                this.aiFinalCandidatesError = '请先点击「准备生成」生成安全预览包。';
                return;
            }
            this.aiFinalCandidatesLoading = true;
            this.aiFinalCandidatesError = '';
            this.aiFinalCandidatesPackage = null;
            this.aiFinalCopyMessage = '';
            this.aiFinalCopied = false;

            const selectedAi = this.aiSelectedRecommendationIndices
                .map(idx => this.aiRecommendations[idx])
                .filter(Boolean);
            const unselectedAi = this.aiRecommendations
                .map((rec, idx) => ({ rec, idx }))
                .filter(({ idx }) => !this.aiSelectedRecommendationIndices.includes(idx))
                .map(({ rec }) => rec);

            const payload = {
                selected_item_ids: this.aiPreviewSelectedItemIds,
                selected_ai_recommendations: selectedAi,
                unselected_ai_recommendations: unselectedAi,
                dedupe_summary: this.aiRecommendationSummary || {
                    original_ai_count: 0,
                    valid_ai_count: 0,
                    dropped_missing_word: 0,
                    dropped_duplicate_with_user: 0,
                    dropped_ai_internal_duplicate: 0,
                },
                source_preview_package: this.aiPreviewPackage,
            };

            buildFinalCandidatesPackage(axios, payload)
                .then(({ package: pkg }) => {
                    this.aiFinalCandidatesPackage = pkg;
                })
                .catch((error) => {
                    this.aiFinalCandidatesError = error.message || '生成最终候选包失败。';
                })
                .finally(() => {
                    this.aiFinalCandidatesLoading = false;
                });
        },
        // V4: copy final candidates package JSON
        copyFinalCandidatesPackage() {
            if (!this.aiFinalCandidatesPackage) {
                return;
            }
            this.copyJsonToClipboard(this.aiFinalCandidatesPackage, 'aiFinalCopied', 'aiFinalCopyMessage');
        },
        // V5: open generate cards dialog
        openGenerateCardsDialog() {
            if (!this.aiFinalCandidatesPackage) {
                return;
            }
            this.aiGenerateCardsItems = buildGenerateCardItems(this.aiFinalCandidatesPackage);
            this.aiGenerateCardsResult = null;
            this.aiGenerateCardsError = '';
            this.aiGenerateCardsDialog = true;
        },
        // V5: confirm generate cards (sense_zh required, sense_en optional)
        confirmGenerateCards() {
            if (this.aiGenerateCardsItems.length === 0) {
                return;
            }
            this.aiGenerateCardsLoading = true;
            this.aiGenerateCardsError = '';

            const confirmedItems = filterConfirmedGenerateCardItems(this.aiGenerateCardsItems);
            if (confirmedItems.length === 0) {
                this.aiGenerateCardsError = '请至少为一个候选项填写中文释义。';
                this.aiGenerateCardsLoading = false;
                return;
            }

            generateAiStudyCards(axios, this.aiFinalCandidatesPackage, confirmedItems)
                .then((data) => {
                    this.aiGenerateCardsResult = data;
                    this.aiGenerateCardsDialog = false;
                    this.$emit('generated', data);
                })
                .catch((error) => {
                    this.aiGenerateCardsError = error.message || '生成学习卡失败。';
                })
                .finally(() => {
                    this.aiGenerateCardsLoading = false;
                });
        },
        // V5: go to /reviews/senses
        goToSenseReviews() {
            window.location.href = '/reviews/senses';
        },
        // V2: date formatting helper (matches original SideBox/Box format yyyy-mm-dd hh:mm)
        formatDate(value) {
            if (!value) return '';
            const date = new Date(value);
            if (isNaN(date.getTime())) return value;
            const yyyy = date.getFullYear();
            const mm = String(date.getMonth() + 1).padStart(2, '0');
            const dd = String(date.getDate()).padStart(2, '0');
            const hh = String(date.getHours()).padStart(2, '0');
            const mi = String(date.getMinutes()).padStart(2, '0');
            return `${yyyy}-${mm}-${dd} ${hh}:${mi}`;
        },
        // Shared clipboard helper for preview / final package JSON
        copyJsonToClipboard(obj, copiedFlag, messageFlag) {
            const text = JSON.stringify(obj, null, 2);
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(() => {
                    this[copiedFlag] = true;
                    this[messageFlag] = '已复制到剪贴板。';
                }).catch(() => {
                    this[copiedFlag] = false;
                    this[messageFlag] = '复制失败，请手动选择文本复制。';
                });
            } else {
                try {
                    const textarea = document.createElement('textarea');
                    textarea.value = text;
                    textarea.style.position = 'fixed';
                    textarea.style.opacity = '0';
                    document.body.appendChild(textarea);
                    textarea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textarea);
                    this[copiedFlag] = true;
                    this[messageFlag] = '已复制到剪贴板。';
                } catch (e) {
                    this[copiedFlag] = false;
                    this[messageFlag] = '复制失败，请手动选择文本复制。';
                }
            }
        },
    },
};
</script>
