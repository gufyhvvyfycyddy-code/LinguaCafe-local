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
        <AiStudyCardPendingListDialog
            v-model="aiPendingListDialog"
            :pending-items="aiPendingItems"
            :dismissed-items="aiPendingDismissedItems" :processed-items="aiPendingProcessedItems"
            :status-filter="aiPendingListStatusFilter"
            @update:status-filter="aiPendingListStatusFilter = $event"
            :loading="aiPendingListLoading"
            :error="aiPendingListError"
            :message="aiPendingListMessage"
            :dismiss-loading-id="aiPendingDismissLoadingId"
            :restore-loading-id="aiPendingRestoreLoadingId"
            @dismiss="dismissAiPendingItem"
            @restore="restoreAiPendingItem"
            @open-preview="openAiStudyCardPreview"
        />

        <!-- V3-V5: 生成预览弹窗（含 V4 AI 推荐词粘贴 + V5 生成学习卡结果） -->
        <AiStudyCardPreviewDialog
            v-model="aiStudyCardPreviewDialog"
            :pending-items="aiPendingItems"
            :selected-item-ids="aiPreviewSelectedItemIds"
            :ai-recommendation-json-input="aiRecommendationJsonInput"
            :ai-recommendations="aiRecommendations"
            :ai-selected-recommendation-indices="aiSelectedRecommendationIndices"
            :ai-recommendation-parse-error="aiRecommendationParseError"
            :ai-recommendation-summary="aiRecommendationSummary"
            :ai-preview-package="aiPreviewPackage"
            :ai-preview-package-error="aiPreviewPackageError"
            :ai-preview-package-loading="aiPreviewPackageLoading"
            :ai-preview-copy-message="aiPreviewCopyMessage"
            :ai-preview-copied="aiPreviewCopied"
            :ai-final-candidates-package="aiFinalCandidatesPackage"
            :ai-final-candidates-error="aiFinalCandidatesError"
            :ai-final-candidates-loading="aiFinalCandidatesLoading"
            :ai-final-copy-message="aiFinalCopyMessage"
            :ai-final-copied="aiFinalCopied"
            :ai-generate-cards-result="aiGenerateCardsResult"
            :ai-generate-cards-loading="aiGenerateCardsLoading"
            @update:ai-recommendation-json-input="aiRecommendationJsonInput = $event"
            @toggle-preview-item="togglePreviewItemSelection"
            @select-all-preview="selectAllPreviewItems"
            @deselect-all-preview="deselectAllPreviewItems"
            @parse-ai-recommendations="parseAiRecommendations"
            @clear-ai-recommendations="clearAiRecommendations"
            @toggle-ai-recommendation="toggleAiRecommendationSelection"
            @select-all-ai-recommendations="selectAllAiRecommendations"
            @deselect-all-ai-recommendations="deselectAllAiRecommendations"
            @generate-preview-package="generatePreviewPackage"
            @copy-preview-package="copyPreviewPackage"
            @apply-v6-recommendations="applyV6Recommendations"
            @generate-final-candidates-package="generateFinalCandidatesPackage"
            @copy-final-candidates-package="copyFinalCandidatesPackage"
            @open-generate-cards-dialog="openGenerateCardsDialog"
            @go-to-sense-reviews="goToSenseReviews"
            @dismiss-result="aiGenerateCardsResult = null"
        />

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
import AiStudyCardPendingListDialog from './AiStudyCardPendingListDialog.vue';
import AiStudyCardPreviewDialog from './AiStudyCardPreviewDialog.vue';
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
import { copyTextToClipboard } from '../../services/AiStudyCardClipboardService.js';

/**
 * AiStudyCardDesktopWorkflow — Desktop AIStudyCard V1-V5 feature island (container).
 * Owns all V1-V5 state; delegates rendering to PendingListDialog, PreviewDialog,
 * GenerateCardsDialog. Clipboard via AiStudyCardClipboardService.
 * AI recommendations default to UNSELECTED. No AI / ReviewLog / FSRS / legacy card.
 * (GM52-AIStudyCardDesktopWorkflowDeepModuleSplit-1000-4)
 */
export default {
    name: 'AiStudyCardDesktopWorkflow',
    components: {
        AiStudyCardGenerateCardsDialog,
        AiStudyCardPendingListDialog,
        AiStudyCardPreviewDialog,
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
            aiStudyCardPendingLoading: false, aiStudyCardPendingMessage: '', aiStudyCardPendingError: false,
            // V2/V3: pending list dialog
            aiPendingListDialog: false, aiPendingListLoading: false, aiPendingListMessage: '', aiPendingListError: '',
            aiPendingItems: [], aiPendingDismissLoadingId: null, aiPendingListStatusFilter: 'pending',
            aiPendingDismissedItems: [], aiPendingRestoreLoadingId: null, aiPendingProcessedItems: [],
            // V3: preview dialog + selected ids + preview package
            aiStudyCardPreviewDialog: false, aiPreviewSelectedItemIds: [], aiPreviewPackage: null,
            aiPreviewPackageLoading: false, aiPreviewPackageError: '', aiPreviewCopyMessage: '', aiPreviewCopied: false,
            // V4: AI recommendation paste + parse + dedupe + selection. AI recommendations default to UNSELECTED.
            aiRecommendationJsonInput: '', aiRecommendations: [], aiSelectedRecommendationIndices: [],
            aiRecommendationParseError: '', aiRecommendationSummary: null,
            // V4: final candidates package
            aiFinalCandidatesPackage: null, aiFinalCandidatesLoading: false, aiFinalCandidatesError: '',
            aiFinalCopyMessage: '', aiFinalCopied: false,
            // V5: generate cards dialog + result
            aiGenerateCardsDialog: false, aiGenerateCardsItems: [], aiGenerateCardsLoading: false,
            aiGenerateCardsError: '', aiGenerateCardsResult: null,
        };
    },
    methods: {
        // State reset helpers (DRY for repeated patterns)
        _resetPreviewPackageState() {
            this.aiPreviewPackage = null; this.aiPreviewCopyMessage = ''; this.aiPreviewCopied = false;
        },
        _resetFinalCandidatesState() {
            this.aiFinalCandidatesPackage = null; this.aiFinalCandidatesError = '';
            this.aiFinalCopyMessage = ''; this.aiFinalCopied = false;
        },
        _resetAfterSelectionChange() {
            this._resetPreviewPackageState();
            this.rededupeAiRecommendationsAfterUserSelectionChange();
            this.aiFinalCandidatesPackage = null; this.aiFinalCopyMessage = ''; this.aiFinalCopied = false;
        },
        // V1: mark current word as pending AI explanation
        markAiStudyCardPending() {
            if (this.type !== 'word') return;
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
                chapterId, sentenceIndex,
                word: this.word, surface: this.word,
                lemma: this._studyBase || this._baseWord || this.word,
                sentenceText: this._sentenceText || '',
                source: 'reader_vocabulary_workflow',
            }).then((data) => {
                this.aiStudyCardPendingMessage = data && data.message ? data.message : '已加入待 AI 解释。';
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
        loadAiPendingItems() {
            this.aiPendingListLoading = true;
            this.aiPendingListError = '';
            listPendingItems(axios, { chapterId: this._chapterId, status: 'pending' })
                .then(({ items }) => { this.aiPendingItems = items; })
                .catch((error) => {
                    this.aiPendingListError = error.message || '加载待解释列表失败。';
                    this.aiPendingItems = [];
                })
                .finally(() => { this.aiPendingListLoading = false; });
        },
        loadAiPendingDismissedItems() {
            listPendingItems(axios, { chapterId: this._chapterId, status: 'dismissed' })
                .then(({ items }) => { this.aiPendingDismissedItems = items; })
                .catch(() => { this.aiPendingDismissedItems = []; });
            listPendingItems(axios, { chapterId: this._chapterId, status: 'processed' }).then(({ items }) => { this.aiPendingProcessedItems = items; }).catch(() => { this.aiPendingProcessedItems = []; });
        },
        dismissAiPendingItem(itemId) {
            this.aiPendingDismissLoadingId = itemId;
            this.aiPendingListMessage = '';
            this.aiPendingListError = '';
            dismissPendingItem(axios, itemId)
                .then(({ message }) => {
                    this.aiPendingListMessage = message;
                    const dismissed = this.aiPendingItems.find(i => i.id === itemId);
                    this.aiPendingItems = this.aiPendingItems.filter(i => i.id !== itemId);
                    if (dismissed) this.aiPendingDismissedItems.unshift({ ...dismissed, status: 'dismissed' });
                })
                .catch((error) => { this.aiPendingListError = error.message || '取消失败。'; })
                .finally(() => { this.aiPendingDismissLoadingId = null; });
        },
        restoreAiPendingItem(itemId) {
            this.aiPendingRestoreLoadingId = itemId;
            this.aiPendingListMessage = '';
            this.aiPendingListError = '';
            restorePendingItem(axios, itemId)
                .then(({ message }) => {
                    this.aiPendingListMessage = message;
                    const restored = this.aiPendingDismissedItems.find(i => i.id === itemId);
                    this.aiPendingDismissedItems = this.aiPendingDismissedItems.filter(i => i.id !== itemId);
                    if (restored) this.aiPendingItems.unshift({ ...restored, status: 'pending' });
                })
                .catch((error) => { this.aiPendingListError = error.message || '恢复失败。'; })
                .finally(() => { this.aiPendingRestoreLoadingId = null; });
        },
        // V3: open preview dialog, initialize selection state
        openAiStudyCardPreview() {
            this.aiStudyCardPreviewDialog = true;
            this.aiPreviewSelectedItemIds = this.aiPendingItems.map(i => i.id);
            this._resetPreviewPackageState();
            this.aiPreviewPackageError = '';
            // V4: clear AI recommendation state
            this.aiRecommendationJsonInput = '';
            this.aiRecommendations = [];
            this.aiSelectedRecommendationIndices = [];
            this.aiRecommendationParseError = '';
            this.aiRecommendationSummary = null;
            this._resetFinalCandidatesState();
        },
        togglePreviewItemSelection(itemId) {
            const idx = this.aiPreviewSelectedItemIds.indexOf(itemId);
            if (idx >= 0) this.aiPreviewSelectedItemIds.splice(idx, 1);
            else this.aiPreviewSelectedItemIds.push(itemId);
            this._resetAfterSelectionChange();
        },
        selectAllPreviewItems() {
            this.aiPreviewSelectedItemIds = this.aiPendingItems.map(i => i.id);
            this._resetAfterSelectionChange();
        },
        deselectAllPreviewItems() {
            this.aiPreviewSelectedItemIds = [];
            this._resetAfterSelectionChange();
        },
        // V4: parse AI recommendation JSON (delegates to pure service)
        // AI recommendations default to UNSELECTED
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
            this.aiSelectedRecommendationIndices = [];
            this.aiRecommendationSummary = result.summary;
            if (!result.ok) this.aiRecommendationParseError = result.error;
        },
        clearAiRecommendations() {
            this.aiRecommendationJsonInput = '';
            this.aiRecommendations = [];
            this.aiSelectedRecommendationIndices = [];
            this.aiRecommendationParseError = '';
            this.aiRecommendationSummary = null;
            this._resetFinalCandidatesState();
        },
        applyV6Recommendations(recommendationPackage) {
            if (!recommendationPackage || !Array.isArray(recommendationPackage.recommended_items)) {
                this.aiRecommendationParseError = 'V6 AI 推荐预览格式无效，无法导入推荐词列表。';
                return;
            }
            this.aiRecommendationJsonInput = JSON.stringify(recommendationPackage, null, 2);
            this.parseAiRecommendations();
            this.aiSelectedRecommendationIndices = [];
            this._resetFinalCandidatesState();
        },
        rededupeAiRecommendationsAfterUserSelectionChange() {
            if (this.aiRecommendations.length === 0) return;
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
        toggleAiRecommendationSelection(idx) {
            const i = this.aiSelectedRecommendationIndices.indexOf(idx);
            if (i >= 0) this.aiSelectedRecommendationIndices.splice(i, 1);
            else this.aiSelectedRecommendationIndices.push(idx);
            this.aiFinalCandidatesPackage = null;
            this.aiFinalCopyMessage = '';
            this.aiFinalCopied = false;
        },
        selectAllAiRecommendations() {
            this.aiSelectedRecommendationIndices = this.aiRecommendations.map((_, idx) => idx);
            this.aiFinalCandidatesPackage = null;
            this.aiFinalCopyMessage = '';
            this.aiFinalCopied = false;
        },
        deselectAllAiRecommendations() {
            this.aiSelectedRecommendationIndices = [];
            this.aiFinalCandidatesPackage = null;
            this.aiFinalCopyMessage = '';
            this.aiFinalCopied = false;
        },
        // V3: build safe preview package
        generatePreviewPackage() {
            if (this.aiPreviewSelectedItemIds.length === 0) return;
            this.aiPreviewPackageLoading = true;
            this.aiPreviewPackageError = '';
            this._resetPreviewPackageState();
            buildPreviewPackage(axios, this.aiPreviewSelectedItemIds)
                .then(({ package: pkg }) => { this.aiPreviewPackage = pkg; })
                .catch((error) => { this.aiPreviewPackageError = error.message || '生成安全包失败。'; })
                .finally(() => { this.aiPreviewPackageLoading = false; });
        },
        copyPreviewPackage() {
            if (!this.aiPreviewPackage) return;
            this.copyJsonToClipboard(this.aiPreviewPackage, 'aiPreviewCopied', 'aiPreviewCopyMessage');
        },
        // V4: build final candidates package
        generateFinalCandidatesPackage() {
            if (this.aiPreviewSelectedItemIds.length === 0 && this.aiSelectedRecommendationIndices.length === 0) return;
            if (!this.aiPreviewPackage) {
                this.aiFinalCandidatesError = '请先点击「准备生成」生成安全预览包。';
                return;
            }
            this.aiFinalCandidatesLoading = true;
            this.aiFinalCandidatesError = '';
            this._resetFinalCandidatesState();
            const selectedAi = this.aiSelectedRecommendationIndices.map(idx => this.aiRecommendations[idx]).filter(Boolean);
            const unselectedAi = this.aiRecommendations
                .map((rec, idx) => ({ rec, idx }))
                .filter(({ idx }) => !this.aiSelectedRecommendationIndices.includes(idx))
                .map(({ rec }) => rec);
            const payload = {
                selected_item_ids: this.aiPreviewSelectedItemIds,
                selected_ai_recommendations: selectedAi,
                unselected_ai_recommendations: unselectedAi,
                dedupe_summary: this.aiRecommendationSummary || {
                    original_ai_count: 0, valid_ai_count: 0, dropped_missing_word: 0,
                    dropped_duplicate_with_user: 0, dropped_ai_internal_duplicate: 0,
                },
                source_preview_package: this.aiPreviewPackage,
            };
            buildFinalCandidatesPackage(axios, payload)
                .then(({ package: pkg }) => { this.aiFinalCandidatesPackage = pkg; })
                .catch((error) => { this.aiFinalCandidatesError = error.message || '生成候选包失败，请重试。'; })
                .finally(() => { this.aiFinalCandidatesLoading = false; });
        },
        copyFinalCandidatesPackage() {
            if (!this.aiFinalCandidatesPackage) return;
            this.copyJsonToClipboard(this.aiFinalCandidatesPackage, 'aiFinalCopied', 'aiFinalCopyMessage');
        },
        // V5: open generate cards dialog
        openGenerateCardsDialog() {
            if (!this.aiFinalCandidatesPackage) return;
            this.aiGenerateCardsItems = buildGenerateCardItems(this.aiFinalCandidatesPackage);
            this.aiGenerateCardsResult = null;
            this.aiGenerateCardsError = '';
            this.aiGenerateCardsDialog = true;
        },
        // V5: confirm generate cards (sense_zh required, sense_en optional)
        confirmGenerateCards() {
            if (this.aiGenerateCardsItems.length === 0) return;
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
                    this.loadAiPendingItems(); this.loadAiPendingDismissedItems();
                })
                .catch((error) => { this.aiGenerateCardsError = error.message || '生成学习卡失败。'; })
                .finally(() => { this.aiGenerateCardsLoading = false; });
        },
        // V5: go to /reviews/senses
        goToSenseReviews() {
            window.location.href = '/reviews/senses';
        },
        // Clipboard helper — delegates to AiStudyCardClipboardService.
        copyJsonToClipboard(obj, copiedFlag, messageFlag) {
            const text = JSON.stringify(obj, null, 2);
            copyTextToClipboard(text).then((result) => {
                this[copiedFlag] = result.ok;
                this[messageFlag] = result.message;
            });
        },
    },
};
</script>
