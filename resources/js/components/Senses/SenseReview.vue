<template>
    <v-container id="sense-review">
        <v-card outlined class="rounded-lg px-4 pb-4 my-4" :loading="loading">
            <div class="subheader my-4 d-flex align-center">
                词义复习
                <v-spacer></v-spacer>
                <v-chip class="mx-1" color="foreground">到期数量 {{ summary.due_count || 0 }}</v-chip>
                <v-chip class="mx-1" color="foreground">已复习 {{ reviewedCount }}</v-chip>
                <v-chip class="mx-1" color="foreground">剩余 {{ remainingCount }}</v-chip>
                <v-chip class="mx-1 my-1" small outlined>今日已复习 {{ fsrsStats.reviewed_today }}</v-chip>
                <v-btn icon small @click="statsDetailOpen = !statsDetailOpen">
                    <v-icon>{{ statsDetailOpen ? 'mdi-chevron-up' : 'mdi-chart-box-outline' }}</v-icon>
                </v-btn>
            </div>
            <v-expand-transition>
                <div v-if="statsDetailOpen" class="d-flex flex-wrap align-center pb-2">
                    <v-chip class="mx-1 my-1" small outlined>今日重置 {{ fsrsStats.reset_count }}</v-chip>
                    <v-chip class="mx-1 my-1" small outlined>总词义卡 {{ fsrsStats.total }}</v-chip>
                    <v-chip class="mx-1 my-1" small outlined>启用中 {{ fsrsStats.enabled }}</v-chip>
                    <v-chip class="mx-1 my-1" small outlined>已归档 {{ fsrsStats.archived }}</v-chip>
                    <v-chip class="mx-1 my-1" small outlined>当前到期 {{ fsrsStats.due }}</v-chip>
                </div>
            </v-expand-transition>
            <v-alert v-if="statsError" type="warning" dense text class="mt-2 mb-0">{{ statsError }}</v-alert>
            <v-alert v-if="summary.limit_message && !ignoreDailyLimits" type="info" dense outlined class="mt-2 mb-0">
                <div>{{ summary.limit_message }}</div>
                <div v-if="summary.can_continue_over_limit" class="mt-2">
                    <v-btn small color="primary" @click="continueOverLimit">继续复习超额卡片</v-btn>
                </div>
            </v-alert>
            <v-alert v-if="ignoreDailyLimits" type="warning" dense outlined class="mt-2 mb-0 d-flex align-center">
                <span>当前已忽略每日上限。所有到期词义卡都会出现。</span>
                <v-spacer />
                <v-btn small text color="primary" @click="restoreLimits">恢复上限</v-btn>
            </v-alert>
            <!-- Session summary: explicit "end session" button. Only visible
                 after the user has rated at least one card AND the summary
                 is not already shown. Clicking it does NOT write ReviewLog
                 or touch FSRS. -->
            <div v-if="hasReviewed && !showSessionSummary" class="text-center mt-3">
                <v-btn small text color="primary" @click="endSession">结束本次复习</v-btn>
            </div>
            <!-- Report center entry: opens the unified learning report
                 hub. The home page lists all available reports; the user
                 selects one to trigger its GET endpoint. Read-only.
                 This is the ONLY report entry on the page. -->
            <div class="text-center mt-2">
                <v-btn small text color="info" @click="reportCenterOpen = true">学习报告</v-btn>
                <!-- ADR-0009: Session-action history drawer. Shows the
                     most recent 20 ratings in this tab session, with
                     undo buttons for undoable actions. -->
                <v-btn small text color="primary" @click="sessionActionDrawerOpen = true">
                    本次操作（{{ activeSessionActionCount }}）
                </v-btn>
            </div>
        </v-card>

        <v-alert v-if="error" type="error" dense outlined>{{ error }}</v-alert>

        <!-- Session summary view. Shown when the user explicitly ends the
             session OR when the queue naturally drains after at least one
             rating. Mutually exclusive with the review-card view. -->
        <SenseReviewSessionSummary
            v-if="showSummaryView"
            :stats="sessionStats"
            :has-more-cards="remainingCount > 0"
            @continue-review="continueReview"
            @exit-review="exitReview"
        />

        <!-- Report center: single orchestration component. v-model is a
             boolean open state; ReportCenter owns report selection,
             loading, error, payload and async-race protection internally. -->
        <SenseReviewReportCenter v-model="reportCenterOpen" />

        <v-card v-if="currentCard && !showSummaryView" outlined class="rounded-lg pa-5">
            <!-- Lemma / surface form / pos -->
            <div class="d-flex align-center mb-3">
                <div>
                    <div class="text-h5 default-font">{{ currentCard.lemma }}</div>
                    <div class="text--secondary">
                        {{ currentCard.surface_form || currentCard.lemma }}
                        <span v-if="currentCard.pos"> / {{ currentCard.pos }}</span>
                    </div>
                </div>
                <v-spacer></v-spacer>
                <v-chip
                    v-if="currentCardIsInactive"
                    x-small
                    :color="stateColor(currentCardLifecycleState)"
                    class="mr-1"
                >{{ stateLabel(currentCardLifecycleState) }}</v-chip>
                <v-chip class="mr-1">{{ currentCard.fsrs_state }}</v-chip>
                <v-chip>{{ currentCard.fsrs_reps }} 次</v-chip>
            </div>
            <div v-if="buriedRemainingDisplay" class="caption warning--text mb-2">
                {{ buriedRemainingDisplay }}
            </div>

            <!-- Question side -->
            <div class="mb-4">
                <div class="caption text--secondary d-flex align-center">
                    <span>例句</span>
                    <v-chip
                        v-if="currentCard.occurrence_count > 1"
                        x-small
                        outlined
                        color="info"
                        class="ml-2"
                    >本词义已有 {{ currentCard.occurrence_count }} 条来源例句</v-chip>
                </div>
                <v-sheet outlined rounded class="pa-3 mb-3">
                    <div class="default-font">{{ currentCard.example_sentence_en || '暂无例句。' }}</div>
                </v-sheet>
                <div class="body-1 primary--text font-weight-medium">
                    这个句子里的 “{{ currentCard.lemma }}” 是什么意思？
                </div>
            </div>

            <!-- Show answer button -->
            <div v-if="!showAnswer" class="d-flex justify-center mb-4">
                <v-btn
                    depressed
                    rounded
                    color="primary"
                    large
                    :disabled="rating || deleteLoading || resetLoading || lifecycleLoading"
                    @click="showAnswer = true"
                >
                    显示答案
                </v-btn>
            </div>

            <div class="text-center caption grey--text mt-2" v-if="!showAnswer">
                快捷键：Space 显示答案
            </div>

            <!-- Answer side -->
            <template v-if="showAnswer">
                <!-- More menu -->
                <div class="d-flex justify-end mb-3" style="gap: 8px;">
                    <v-menu offset-y left>
                        <template v-slot:activator="{ on, attrs }">
                            <v-btn small text v-bind="attrs" v-on="on">
                                <v-icon small left>mdi-dots-vertical</v-icon>更多
                            </v-btn>
                        </template>
                        <v-list dense>
                            <v-list-item @click="viewSource">
                                <v-list-item-icon><v-icon small>mdi-book-open-page-variant</v-icon></v-list-item-icon>
                                <v-list-item-title>查看原文</v-list-item-title>
                            </v-list-item>
                            <v-list-item @click="startEdit">
                                <v-list-item-icon><v-icon small>mdi-pencil</v-icon></v-list-item-icon>
                                <v-list-item-title>编辑</v-list-item-title>
                            </v-list-item>
                            <v-divider v-if="availableLifecycleActions.length" class="my-1" />
                            <v-list-item
                                v-for="lifecycleAction in availableLifecycleActions"
                                :key="lifecycleAction"
                                :disabled="lifecycleLoading"
                                @click="onLifecycleMenuClick(lifecycleAction)"
                            >
                                <v-list-item-icon>
                                    <v-icon small :color="lifecycleActionColor(lifecycleAction)">{{ lifecycleActionIcon(lifecycleAction) }}</v-icon>
                                </v-list-item-icon>
                                <v-list-item-title>{{ lifecycleActionLabel(lifecycleAction) }}</v-list-item-title>
                            </v-list-item>
                            <v-divider class="my-1" />
                            <v-list-item @click="openResetDialog">
                                <v-list-item-icon><v-icon small>mdi-restore</v-icon></v-list-item-icon>
                                <v-list-item-title>重置学习进度</v-list-item-title>
                            </v-list-item>
                            <v-divider class="my-1" />
                            <v-list-item @click="openDeleteDialog">
                                <v-list-item-icon><v-icon small color="error">mdi-delete</v-icon></v-list-item-icon>
                                <v-list-item-title class="error--text">删除</v-list-item-title>
                            </v-list-item>
                        </v-list>
                    </v-menu>
                </div>

                <v-row dense>
                    <v-col cols="12" md="6">
                        <div class="caption text--secondary">中文释义</div>
                        <div class="sense-main mb-4">{{ currentCard.sense_zh }}</div>

                        <div class="caption text--secondary">英文释义</div>
                        <div class="mb-4">{{ currentCard.sense_en || '暂无英文释义。' }}</div>

                        <div class="caption text--secondary">近义译法</div>
                        <div class="mb-4">
                            <v-chip small class="mr-1 mb-1" v-for="alias in currentCard.aliases_zh" :key="alias">{{ alias }}</v-chip>
                            <span v-if="!currentCard.aliases_zh.length" class="text--secondary">无</span>
                        </div>

                        <div class="caption text--secondary">搭配</div>
                        <div>
                            <v-chip small class="mr-1 mb-1" v-for="collocation in currentCard.collocations" :key="collocation">{{ collocation }}</v-chip>
                            <span v-if="!currentCard.collocations.length" class="text--secondary">无</span>
                        </div>

                        <!-- Understanding aid (extracted sub-component).
                             Pure presentational: renders the collapsible
                             "理解这个词义" block from the normalized aid
                             payload. Owns its own collapse state. -->
                        <SenseReviewUnderstandingAid :aid="understandingAid" />

                        <!-- Learning feedback panel (extracted sub-component).
                             Read-only: no backend calls, no ReviewLog writes,
                             no FSRS changes. :key on review_card_id resets the
                             collapse state on card change. -->
                        <SenseReviewLearningFeedbackPanel
                            v-if="hasLearningFeedback"
                            :key="'feedback-' + currentCard.review_card_id"
                            :learning-feedback="learningFeedback"
                            :fsrs-stability="currentCard.fsrs_stability"
                        />
                    </v-col>
                    <v-col cols="12" md="6">
                        <div class="caption text--secondary">例句</div>
                        <v-sheet outlined rounded class="pa-3 mb-4">
                            <div class="default-font">{{ currentCard.example_sentence_en || '暂无例句。' }}</div>
                            <div class="text--secondary mt-2">{{ currentCard.example_sentence_zh }}</div>
                        </v-sheet>

                        <template v-if="supplementaryExample">
                            <div class="caption text--secondary">补充例句</div>
                            <v-sheet outlined rounded class="pa-3 mb-4 supplementary-example">
                                <div class="default-font">{{ supplementaryExample.sentence_en }}</div>
                                <div class="text--secondary mt-2">{{ supplementaryExample.sentence_zh || '' }}</div>
                                <div v-if="supplementaryExample.chapter_title" class="text-caption text--secondary mt-2">
                                    来源：{{ supplementaryExample.chapter_title }}
                                </div>
                            </v-sheet>
                        </template>

                        <div class="caption text--secondary d-flex align-center" style="cursor: pointer;" @click="fsrsDetailOpen = !fsrsDetailOpen">
                            FSRS：到期 {{ currentCard.fsrs_due_at || '-' }}
                            <v-icon small class="ml-1">{{ fsrsDetailOpen ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                        </div>
                        <v-expand-transition>
                            <div v-if="fsrsDetailOpen">
                                <v-simple-table dense class="no-hover border rounded-lg mt-2">
                                    <tbody>
                                        <tr><td>稳定度</td><td>{{ currentCard.fsrs_stability || '-' }}</td></tr>
                                        <tr><td>难度</td><td>{{ currentCard.fsrs_difficulty || '-' }}</td></tr>
                                        <tr><td>遗忘次数</td><td>{{ currentCard.fsrs_lapses }}</td></tr>
                                    </tbody>
                                </v-simple-table>
                            </div>
                        </v-expand-transition>
                    </v-col>
                </v-row>

                <!-- Rating controls (extracted sub-component). Emits 'rating'
                     with 'again' | 'hard' | 'good' | 'easy'. The parent owns
                     the actual rate() method and API call. Interval preview
                     props (1000-5) are passed down as pure display data;
                     preview loading/error NEVER disables the buttons. -->
                <SenseReviewRatingControls
                    :disabled="rating || deleteLoading || resetLoading || lifecycleLoading"
                    :interval-previews="intervalPreviews"
                    :preview-loading="intervalPreviewLoading"
                    :preview-error="intervalPreviewError"
                    @rating="rate"
                />
            </template>
        </v-card>

        <v-alert v-else-if="!loading && !showSummaryView" type="info" dense outlined>
            当前没有到期词义卡。
        </v-alert>

        <!-- Edit dialog (extracted sub-component). Owns the edit form and
             the save API call. Emits 'saved' so the parent can update its
             card list without re-fetching. -->
        <SenseReviewEditDialog
            v-model="editDialog"
            :card="currentCard"
            @saved="onCardSaved"
        />

        <!-- Lifecycle confirmation dialog (ADR-0010)
             Generic dialog for moderate lifecycle actions (suspend/archive/restore).
             Safe actions (bury/unbury/resume) execute immediately without this dialog. -->
        <v-dialog v-model="lifecycleDialog" max-width="480">
            <v-card>
                <v-card-title>{{ lifecycleDialogTitle }}</v-card-title>
                <v-card-text>
                    <p>{{ lifecycleDialogHint }}</p>
                    <v-alert v-if="lifecycleConflict" type="error" dense text class="mt-2 mb-0">
                        {{ lifecycleConflict }}
                    </v-alert>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="lifecycleDialog = false" :disabled="lifecycleLoading">取消</v-btn>
                    <v-btn :color="lifecycleDialogColor" :loading="lifecycleLoading" @click="performLifecycleAction">确认</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Reset confirmation dialog -->
        <v-dialog v-model="resetDialog" max-width="500">
            <v-card>
                <v-card-title>重置为新学卡</v-card-title>
                <v-card-text>
                    <p>这会清空这张词义卡的 FSRS 记忆状态，并把它重新设为新学卡。</p>
                    <p>复习历史会保留，释义、例句和原文位置不会改变。</p>
                    <p v-if="currentCardIsInactive" class="warning--text">
                        注意：此卡当前为「{{ stateLabel(currentCardLifecycleState) }}」状态，重置不会自动恢复到学习队列。请先恢复生命周期状态。
                    </p>
                    <p v-else>重置后，这张卡会立即重新进入复习队列。</p>
                    <p class="font-weight-bold">确定重置吗？</p>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="resetDialog = false" :disabled="resetLoading">取消</v-btn>
                    <v-btn color="primary" :loading="resetLoading" @click="resetCard">确认重置</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Delete confirmation dialog -->
        <v-dialog v-model="deleteDialog" max-width="480">
            <v-card>
                <v-card-title>确认删除</v-card-title>
                <v-card-text>
                    这会删除这张词义复习卡，并让该释义不再出现在阅读页点词结果中。阅读材料、原文位置和复习历史会保留。此操作不可恢复。确定删除吗？
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="deleteDialog = false" :disabled="deleteLoading">取消</v-btn>
                    <v-btn color="error" :loading="deleteLoading" @click="deleteCard">确认删除</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Source context dialog -->
        <sense-example-dialog
            v-model="sourceDialog"
            :payload="sourcePayload"
            language="english"
            :font-size="16"
        />

        <!-- Snackbar -->
        <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="3000" top>
            {{ snackbar.text }}
            <template #action="{ attrs }">
                <v-btn text v-bind="attrs" @click="snackbar.show = false">关闭</v-btn>
            </template>
        </v-snackbar>

        <!-- ADR-0009: Undo snackbar. Shown after a successful rating with
             the real action metadata from the backend. The "撤销" button
             calls the unified requestUndo with source=sense_review_snackbar.
             After the snackbar closes, undo is still available from the
             session-actions drawer or Ctrl+Z. -->
        <v-snackbar v-model="undoSnackbar.show" :timeout="6000" top color="info">
            {{ undoSnackbar.text }}
            <template #action="{ attrs }">
                <v-btn
                    text
                    v-bind="attrs"
                    :loading="undoSnackbar.action && undoLoadingReviewLogId === undoSnackbar.action.review_log_id"
                    @click="requestUndo(undoSnackbar.action, 'sense_review_snackbar')"
                >撤销</v-btn>
            </template>
        </v-snackbar>

        <!-- ADR-0009: Undo conflict alert. Shown when undo fails (409/404).
             Does NOT change currentCard. Auto-dismisses after 5 seconds. -->
        <v-snackbar v-if="undoConflict" :value="true" :timeout="5000" top color="error">
            {{ undoConflict }}
            <template #action="{ attrs }">
                <v-btn text v-bind="attrs" @click="undoConflict = ''">关闭</v-btn>
            </template>
        </v-snackbar>

        <!-- ADR-0009: Session-action history drawer. Shows the most recent
             20 ratings in this tab session (newest first). Each item shows
             lemma, sense_zh, rating, reviewed_at, previous/new due, undone
             status, and an undo button for undoable actions. Undone actions
             are retained for audit. Only the latest active action has an
             undo button. -->
        <v-dialog v-model="sessionActionDrawerOpen" max-width="640" scrollable>
            <v-card>
                <v-card-title class="d-flex align-center">
                    本次操作（{{ activeSessionActionCount }}）
                    <v-spacer />
                    <v-btn icon small @click="sessionActionDrawerOpen = false">
                        <v-icon small>mdi-close</v-icon>
                    </v-btn>
                </v-card-title>
                <v-divider />
                <v-card-text class="pa-0" style="max-height: 60vh;">
                    <v-progress-linear v-if="sessionActionsLoading" indeterminate />
                    <v-alert v-if="sessionActionsError" type="warning" dense text class="ma-2">
                        {{ sessionActionsError }}
                    </v-alert>
                    <v-list v-if="sessionActions.length" dense>
                        <v-list-item
                            v-for="action in sessionActions"
                            :key="action.review_log_id"
                            two-line
                        >
                            <v-list-item-content>
                                <v-list-item-title class="d-flex align-center">
                                    <span class="font-weight-medium">{{ action.lemma || '未知' }}</span>
                                    <v-chip
                                        x-small
                                        outlined
                                        class="ml-2"
                                        :color="ratingColor(action.rating)"
                                    >{{ action.rating_label || action.rating }}</v-chip>
                                    <v-chip
                                        v-if="action.undone"
                                        x-small
                                        color="grey"
                                        class="ml-2"
                                    >已撤销</v-chip>
                                </v-list-item-title>
                                <v-list-item-subtitle class="text--secondary">
                                    {{ action.sense_zh || '暂无释义' }}
                                    · {{ formatTime(action.reviewed_at) }}
                                    <span v-if="action.new_due_at"> · 到期 {{ formatTime(action.new_due_at) }}</span>
                                    <span v-if="action.undone && action.undone_at" class="ml-2">
                                        · 撤销于 {{ formatTime(action.undone_at) }}
                                        <span v-if="action.undo_source">（{{ undoSourceLabel(action.undo_source) }}）</span>
                                    </span>
                                    <span v-if="!action.undoable && !action.undone && action.blocked_reason" class="ml-2">
                                        · {{ blockedReasonLabel(action.blocked_reason) }}
                                    </span>
                                </v-list-item-subtitle>
                            </v-list-item-content>
                            <v-list-item-action v-if="action.undoable">
                                <v-btn
                                    small
                                    text
                                    color="primary"
                                    :loading="undoLoadingReviewLogId === action.review_log_id"
                                    @click="requestUndo(action, 'sense_review_history')"
                                >撤销</v-btn>
                            </v-list-item-action>
                        </v-list-item>
                    </v-list>
                    <div v-else-if="!sessionActionsLoading" class="text-center text--secondary pa-4">
                        本次复习还没有评分记录。
                    </div>
                </v-card-text>
            </v-card>
        </v-dialog>
    </v-container>
</template>

<script>
    import SenseExampleDialog from '../Review/SenseExampleDialog.vue';
    import SenseReviewSessionSummary from './SenseReviewSessionSummary.vue';
    import SenseReviewLearningFeedbackPanel from './SenseReviewLearningFeedbackPanel.vue';
    import SenseReviewRatingControls from './SenseReviewRatingControls.vue';
    import SenseReviewUnderstandingAid from './SenseReviewUnderstandingAid.vue';
    import SenseReviewEditDialog from './SenseReviewEditDialog.vue';
    import SenseReviewReportCenter from './SenseReviewReportCenter.vue';
    import * as SessionTracker from './SenseReviewSessionTracker.js';
    import { getOrCreateReviewSessionId } from './SenseReviewSessionIdentity.js';
    import { normalizeIntervalPreview } from './SenseReviewIntervalPresentation.js';
    import {
        MORE_MENU_ITEMS,
        actionLabel,
        actionHint,
        actionDangerLevel,
        actionColor,
        stateLabel,
        stateColor,
        blockedReasonLabel,
        buriedRemainingText,
    } from '../../services/ReviewCardLifecyclePresentation.js';

    /**
     * SenseReview.vue — page container (refactored).
     *
     * Responsibilities (after extraction of sub-components):
     *  - Load the due-card queue and FSRS stats.
     *  - Track the current card index and show-answer state.
     *  - Call the rating API and record ratings into the page session.
     *  - Coordinate dialogs (edit / archive / reset / delete / source).
     *  - Maintain page-level session summary state.
     *  - Handle keyboard shortcuts and snackbar.
     *
     * Delegated to sub-components:
     *  - SenseReviewSessionSummary: session summary display.
     *  - SenseReviewLearningFeedbackPanel: learning feedback + forgetting
     *    pattern display (read-only, no API calls).
     *  - SenseReviewRatingControls: the four rating buttons (emits 'rating',
     *    parent owns the API call).
     *  - SenseReviewUnderstandingAid: collapsible understanding-aid block
     *    (pure presentational).
     *  - SenseReviewEditDialog: edit-sense-card dialog (owns form + save API,
     *    emits 'saved' back to parent).
     */
    export default {
        components: {
            SenseExampleDialog,
            SenseReviewSessionSummary,
            SenseReviewLearningFeedbackPanel,
            SenseReviewRatingControls,
            SenseReviewUnderstandingAid,
            SenseReviewEditDialog,
            SenseReviewReportCenter,
        },
        data: function() {
            return {
                loading: false,
                rating: false,
                error: '',
                cards: [],
                summary: {},
                reviewedCount: 0,
                // Edit dialog (state reduced to visibility only; form + save
                // logic live in SenseReviewEditDialog).
                editDialog: false,
                // Reset dialog
                resetDialog: false,
                resetLoading: false,
                // Delete dialog
                deleteDialog: false,
                deleteLoading: false,
                // Lifecycle state machine (ADR-0010)
                // lifecycleDescriptor: cached descriptor from GET /review-cards/{id}/lifecycle.
                //   Contains available_actions, effective_state, version, buried_until, etc.
                // lifecycleLoading: true while a lifecycle POST is in flight.
                // lifecycleDialog: generic confirmation dialog for moderate actions.
                // lifecycleDialogAction: which action is being confirmed.
                // lifecycleConflict: 409/422 error message shown inside the dialog.
                lifecycleDescriptor: null,
                lifecycleLoading: false,
                lifecycleDialog: false,
                lifecycleDialogAction: null,
                lifecycleConflict: '',
                // Source context dialog
                sourceDialog: false,
                sourcePayload: {},
                // Snackbar
                snackbar: {
                    show: false,
                    text: '',
                    color: 'success',
                },
                // FSRS stats
                statsLoading: false,
                statsError: '',
                fsrsStats: {
                    total: 0,
                    enabled: 0,
                    archived: 0,
                    due: 0,
                    by_state: {
                        new: 0,
                        learning: 0,
                        review: 0,
                        relearning: 0,
                    },
                    average_stability: null,
                    average_difficulty: null,
                    lapses_total: 0,
                    reviewed_today: 0,
                    reset_count: 0,
                },
                // UI collapse flags
                statsDetailOpen: false,
                fsrsDetailOpen: false,
                showAnswer: false,
                // Whether the user is in "ignore daily limits" mode
                ignoreDailyLimits: false,
                // Session summary: tracks ratings on the CURRENT page load
                // only. Reset on page refresh (no persistence). Clicking
                // "结束本次复习", viewing the summary, or expanding blocks
                // never writes ReviewLog and never touches FSRS.
                session: SessionTracker.createSession(),
                showSessionSummary: false,
                // Report center: boolean open state. ReportCenter owns
                // report selection, loading, error, payload and async-race
                // protection internally.
                reportCenterOpen: false,
                // Interval preview (1000-5): predicted intervals for the
                // four rating buttons, shown only after the answer is
                // revealed. The parent (this component) is the SOLE
                // orchestrator for the preview GET request; sub-components
                // only receive normalized display data via props.
                //   intervalPreviews: normalized map or null
                //   intervalPreviewLoading: true while GET is in flight
                //   intervalPreviewError: non-empty on failure
                //   intervalPreviewRequestSequence: race-protection counter;
                //     incremented on every new request and on card switch
                //     so stale responses are discarded.
                intervalPreviews: null,
                intervalPreviewLoading: false,
                intervalPreviewError: '',
                intervalPreviewRequestSequence: 0,
                // ADR-0009: Review session identity + stack undo.
                // reviewSessionId: UUID per browser tab (sessionStorage,
                //   refresh-persistent, not shared across tabs).
                // sessionActions: backend timeline (newest first, max 20,
                //   includes undone for audit). Only one active action is
                //   undoable at a time (the latest non-undone in session).
                // sessionActionDrawerOpen: "本次操作" drawer visibility.
                // undoLoadingReviewLogId: review_log_id being undone (prevents
                //   double-click on the same action).
                // undoSnackbar: dedicated snackbar shown after a successful
                //   rating, with an "撤销" action button.
                // undoConflict: error message shown when undo fails (409/404).
                // sessionActionRequestSequence: race-protection counter for
                //   the timeline GET (discards stale responses).
                reviewSessionId: '',
                sessionActions: [],
                sessionActionsLoading: false,
                sessionActionsError: '',
                sessionActionDrawerOpen: false,
                undoLoadingReviewLogId: null,
                undoSnackbar: {
                    show: false,
                    text: '',
                    action: null,
                },
                undoConflict: '',
                sessionActionRequestSequence: 0,
            }
        },
        computed: {
            currentCard() {
                return this.cards.length ? this.cards[0] : null;
            },
            // ADR-0010: Available lifecycle actions for the current card,
            // derived from the backend descriptor. Empty when descriptor
            // is not loaded yet — the menu gracefully hides lifecycle items.
            availableLifecycleActions() {
                if (!this.lifecycleDescriptor) {
                    return [];
                }
                return this.lifecycleDescriptor.available_actions || [];
            },
            // The effective lifecycle state of the current card.
            currentCardLifecycleState() {
                if (!this.lifecycleDescriptor) {
                    return 'active';
                }
                return this.lifecycleDescriptor.effective_state || 'active';
            },
            // Whether the current card is NOT in active state (for badge).
            currentCardIsInactive() {
                return this.currentCardLifecycleState !== 'active';
            },
            // Human-readable remaining time for a buried card.
            buriedRemainingDisplay() {
                if (!this.lifecycleDescriptor || !this.lifecycleDescriptor.buried_until) {
                    return '';
                }
                return buriedRemainingText(this.lifecycleDescriptor.buried_until);
            },
            // Generic lifecycle dialog title.
            lifecycleDialogTitle() {
                if (!this.lifecycleDialogAction) {
                    return '';
                }
                return '确认' + actionLabel(this.lifecycleDialogAction);
            },
            // Generic lifecycle dialog hint text.
            lifecycleDialogHint() {
                if (!this.lifecycleDialogAction) {
                    return '';
                }
                return actionHint(this.lifecycleDialogAction);
            },
            // Generic lifecycle dialog button color.
            lifecycleDialogColor() {
                if (!this.lifecycleDialogAction) {
                    return 'primary';
                }
                return actionColor(this.lifecycleDialogAction);
            },
            remainingCount() {
                return this.cards.length;
            },
            supplementaryExample() {
                if (!this.currentCard) {
                    return null;
                }
                const supp = this.currentCard.supplementary_example;
                if (!supp || !supp.sentence_en) {
                    return null;
                }
                // Never show a supplementary example that duplicates the
                // question example (backend guarantees this, but guard here).
                if (supp.sentence_en === this.currentCard.example_sentence_en) {
                    return null;
                }
                return supp;
            },
            // Understanding aid (sense-level + occurrence-level merged).
            // Backend always returns a normalized structure. Passed as-is to
            // the SenseReviewUnderstandingAid sub-component, which owns all
            // display logic (collapse state + hasAnyContent gate).
            understandingAid() {
                if (!this.currentCard || !this.currentCard.understanding_aid) {
                    return {};
                }
                return this.currentCard.understanding_aid;
            },
            // Learning feedback aggregate (passed to the panel sub-component).
            // The panel owns all display logic (trend labels, colors, hints).
            learningFeedback() {
                if (!this.currentCard || !this.currentCard.learning_feedback) {
                    return null;
                }
                return this.currentCard.learning_feedback;
            },
            hasLearningFeedback() {
                if (!this.learningFeedback) {
                    return false;
                }
                return this.learningFeedback.total_reviews > 0;
            },
            // Session summary computed.
            sessionStats() {
                return SessionTracker.sessionStats(this.session);
            },
            hasReviewed() {
                return SessionTracker.hasReviewed(this.session);
            },
            showSummaryView() {
                return this.showSessionSummary && this.hasReviewed;
            },
            // ADR-0009: The latest undoable action in the current session.
            // Used by Ctrl/Cmd+Z and the undo snackbar. null when no action
            // is undoable (empty session, all undone, or legacy logs).
            latestUndoableAction() {
                if (!this.sessionActions.length) {
                    return null;
                }
                return this.sessionActions.find((a) => a.undoable) || null;
            },
            // ADR-0009: Count of non-undone session actions (for the drawer
            // badge and summary). Undone actions are retained in the drawer
            // for audit but excluded from the "active" count.
            activeSessionActionCount() {
                return this.sessionActions.filter((a) => !a.undone).length;
            },
        },
        watch: {
            // When the answer is revealed, fetch the interval preview and
            // lifecycle descriptor for the current card. Neither is fetched
            // before the answer is shown. Failures do not block rating.
            showAnswer(val) {
                if (val && this.currentCard) {
                    this.loadIntervalPreview();
                    this.fetchLifecycleDescriptor();
                }
            },
            // When the current card changes (new card loaded after rating,
            // or queue refreshed), discard any stale preview and bump the
            // request sequence so any in-flight response is ignored.
            currentCard(newCard, oldCard) {
                const newId = newCard ? newCard.review_card_id : null;
                const oldId = oldCard ? oldCard.review_card_id : null;
                if (newId !== oldId) {
                    this.intervalPreviews = null;
                    this.intervalPreviewError = '';
                    this.intervalPreviewLoading = false;
                    this.intervalPreviewRequestSequence++;
                    // ADR-0010: invalidate lifecycle descriptor cache on
                    // card change so stale available_actions are never shown.
                    this.lifecycleDescriptor = null;
                    this.lifecycleConflict = '';
                }
            },
        },
        beforeDestroy() {
            window.removeEventListener('keyup', this.handleHotkey);
        },
        mounted() {
            // ADR-0009: Create or restore the per-tab review session ID.
            // Uses sessionStorage (per-tab, refresh-persistent, not shared
            // across tabs). This ID is sent with every rating POST and used
            // to scope the session-action timeline and stack-undo.
            this.reviewSessionId = getOrCreateReviewSessionId();
            this.loadCards();
            this.loadFsrsStats();
            // Load the session-action timeline so that after a page refresh
            // the user can still see and undo their recent ratings.
            this.loadSessionActions();
            window.addEventListener('keyup', this.handleHotkey);
        },
        methods: {
            loadFsrsStats() {
                this.statsLoading = true;
                this.statsError = '';
                axios.get('/review-cards/stats')
                    .then((response) => {
                        this.fsrsStats = response.data;
                    })
                    .catch(() => {
                        this.statsError = 'FSRS 统计加载失败。';
                    })
                    .finally(() => {
                        this.statsLoading = false;
                    });
            },
            loadCards() {
                this.loading = true;
                this.error = '';
                const params = {};
                if (this.ignoreDailyLimits) {
                    params.ignoreDailyLimits = true;
                }
                return axios.get('/reviews/senses', { params: params }).then((response) => {
                    this.cards = response.data.cards;
                    this.summary = response.data.summary;
                    this.fsrsDetailOpen = false;
                    this.showAnswer = false;
                    // When the queue naturally drains AND the user has
                    // reviewed at least one card, auto-show the summary.
                    // When no reviews yet, keep the empty-state alert.
                    if (this.cards.length === 0 && this.hasReviewed && !this.showSessionSummary) {
                        this.showSessionSummary = true;
                    }
                }).catch((error) => {
                    this.error = error.response?.data?.message || '词义复习队列加载失败。';
                }).finally(() => {
                    this.loading = false;
                });
            },
            // ==================== Interval preview (1000-5) ====================
            // Fetch the predicted intervals for all four ratings of the
            // current card. Called once when the answer is revealed.
            // Read-only: never writes ReviewLog, never touches FSRS.
            // Race protection: each request captures the current
            // requestSequence; if the card changes or a new request
            // starts before the response returns, the stale response is
            // discarded. Preview failure sets a shared error hint but
            // does NOT disable the rating buttons.
            loadIntervalPreview() {
                if (!this.currentCard) {
                    return;
                }
                const cardId = this.currentCard.review_card_id;
                this.intervalPreviewRequestSequence++;
                const seq = this.intervalPreviewRequestSequence;
                this.intervalPreviewLoading = true;
                this.intervalPreviewError = '';
                this.intervalPreviews = null;
                axios.get('/reviews/senses/' + cardId + '/interval-preview').then((response) => {
                    if (seq !== this.intervalPreviewRequestSequence) {
                        return;
                    }
                    this.intervalPreviews = normalizeIntervalPreview(response.data);
                }).catch(() => {
                    if (seq !== this.intervalPreviewRequestSequence) {
                        return;
                    }
                    this.intervalPreviewError = '预计时间暂不可用，仍可正常评分。';
                }).finally(() => {
                    if (seq !== this.intervalPreviewRequestSequence) {
                        return;
                    }
                    this.intervalPreviewLoading = false;
                });
            },
            rate(rating) {
                if (!this.currentCard) {
                    return;
                }

                this.rating = true;
                this.error = '';
                // Invalidate the interval preview immediately so the old
                // card's predicted intervals cannot bleed into the next
                // card. The requestSequence bump also discards any
                // in-flight preview response.
                this.intervalPreviews = null;
                this.intervalPreviewError = '';
                this.intervalPreviewLoading = false;
                this.intervalPreviewRequestSequence++;
                const payload = { rating: rating };
                if (this.ignoreDailyLimits) {
                    payload.ignoreDailyLimits = true;
                }
                // ADR-0009: Attach the per-tab review session ID so the
                // backend can link this rating into the session-action
                // timeline and make it eligible for stack-undo.
                if (this.reviewSessionId) {
                    payload.review_session_id = this.reviewSessionId;
                }
                // Generate a unique requestId per rate() call. The tracker
                // dedupes by this id so a double-click cannot inflate stats.
                // Only recorded AFTER the backend confirms success.
                const requestId = 'rate-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8);
                const cardSnapshot = {
                    review_card_id: this.currentCard.review_card_id,
                    lemma: this.currentCard.lemma,
                    sense_zh: this.currentCard.sense_zh,
                    rating: rating,
                };
                axios.post(`/reviews/senses/${this.currentCard.review_card_id}/rate`, payload).then((response) => {
                    this.reviewedCount++;
                    this.summary = response.data.summary;
                    // Record this rating into the page session. The
                    // reviewed_card carries the fresh forgetting_pattern
                    // (post-rating trend), used for the "declining" rule.
                    const reviewedCard = response.data.reviewed_card;
                    const entry = {
                        ...cardSnapshot,
                        forgetting_pattern: reviewedCard?.learning_feedback?.forgetting_pattern || { trend: null },
                    };
                    this.session = SessionTracker.recordRating(this.session, entry, requestId);
                    this.loadCards();
                    this.loadFsrsStats();
                    // ADR-0009: Refresh the session-action timeline so the
                    // new rating appears in the drawer and becomes the
                    // latest undoable action.
                    this.loadSessionActions();
                    // ADR-0009: Show the undo snackbar with the real action
                    // metadata from the backend (review_log_id, rating_label,
                    // undoable). Do NOT fake the review_log_id on the frontend.
                    const action = response.data.action;
                    if (action && action.undoable) {
                        this.showUndoSnackbar(action, reviewedCard);
                    }
                }).catch((error) => {
                    this.error = error.response?.data?.message || '词义卡评分失败。';
                }).finally(() => {
                    this.rating = false;
                });
            },
            continueOverLimit() {
                this.ignoreDailyLimits = true;
                this.loadCards();
            },
            restoreLimits() {
                this.ignoreDailyLimits = false;
                this.loadCards();
            },
            // ==================== ADR-0009: Session actions + stack undo ====================
            // Load the session-action timeline from the backend. Called on
            // mount (to restore after refresh), after each rating, and after
            // each undo. Read-only GET; never writes ReviewLog or FSRS.
            // Race protection: each request captures the current sequence
            // counter; stale responses are discarded.
            loadSessionActions() {
                if (!this.reviewSessionId) {
                    return;
                }
                this.sessionActionRequestSequence++;
                const seq = this.sessionActionRequestSequence;
                this.sessionActionsLoading = true;
                this.sessionActionsError = '';
                axios.get('/reviews/senses/session-actions', {
                    params: { review_session_id: this.reviewSessionId },
                }).then((response) => {
                    if (seq !== this.sessionActionRequestSequence) {
                        return;
                    }
                    this.sessionActions = response.data.actions || [];
                }).catch(() => {
                    if (seq !== this.sessionActionRequestSequence) {
                        return;
                    }
                    this.sessionActionsError = '本次操作历史加载失败。';
                }).finally(() => {
                    if (seq !== this.sessionActionRequestSequence) {
                        return;
                    }
                    this.sessionActionsLoading = false;
                });
            },
            // Show the undo snackbar after a successful rating. The snackbar
            // carries the real action metadata (review_log_id, rating_label)
            // from the backend — the frontend never fakes these values.
            showUndoSnackbar(action, reviewedCard) {
                // Extract the interval preview for the chosen rating (if
                // available) to show "预计下次：N 天" in the snackbar.
                let intervalText = '';
                if (this.intervalPreviews && action.rating && this.intervalPreviews[action.rating]) {
                    intervalText = this.intervalPreviews[action.rating].label || '';
                }
                const parts = ['已评分：' + (action.rating_label || action.rating)];
                if (intervalText) {
                    parts.push('预计下次：' + intervalText);
                }
                this.undoSnackbar = {
                    show: true,
                    text: parts.join(' · '),
                    action: action,
                };
            },
            // Unified undo entry point. Called from the snackbar, the
            // session-actions drawer, and Ctrl/Cmd+Z. All three paths
            // converge here so the loading guard, error handling, and
            // post-undo refresh are identical.
            requestUndo(action, source) {
                if (!action || !action.undoable || !action.review_log_id) {
                    return;
                }
                if (this.undoLoadingReviewLogId !== null) {
                    return;
                }
                this.undoLoadingReviewLogId = action.review_log_id;
                this.undoConflict = '';
                const undoRequestId = (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function')
                    ? crypto.randomUUID()
                    : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                        const r = Math.random() * 16 | 0;
                        const v = c === 'x' ? r : (r & 0x3 | 0x8);
                        return v.toString(16);
                    });
                axios.post('/reviews/senses/review-actions/' + action.review_log_id + '/undo', {
                    review_session_id: this.reviewSessionId,
                    undo_request_id: undoRequestId,
                    source: source,
                }).then((response) => {
                    const data = response.data;
                    // Close the undo snackbar (it has served its purpose).
                    this.undoSnackbar.show = false;
                    // Reload the queue so the restored card re-enters at the
                    // correct position. The backend restores the card's FSRS
                    // state to the before-snapshot, so it should be due again.
                    // After reload, move the restored card to the front.
                    const restoredCardId = data.restored_card ? data.restored_card.review_card_id : null;
                    this.loadCards().then(() => {
                        if (restoredCardId) {
                            // Move the restored card to the front of the queue
                            // so the user can immediately re-rate it.
                            const idx = this.cards.findIndex((c) => c.review_card_id === restoredCardId);
                            if (idx > 0) {
                                const [card] = this.cards.splice(idx, 1);
                                this.cards.unshift(card);
                            }
                        }
                        this.showAnswer = false;
                        this.intervalPreviews = null;
                        this.intervalPreviewError = '';
                        this.intervalPreviewLoading = false;
                        this.intervalPreviewRequestSequence++;
                    });
                    // Remove the undone rating from the page session tracker
                    // so the session summary excludes it (A-7).
                    if (restoredCardId) {
                        this.session = SessionTracker.removeRating(this.session, restoredCardId);
                        if (this.reviewedCount > 0) {
                            this.reviewedCount--;
                        }
                    }
                    // Refresh timeline, stats, and summary.
                    this.loadSessionActions();
                    this.loadFsrsStats();
                    this.showSnackbar('已撤销上一次评分，可以重新作答。', 'info');
                }).catch((error) => {
                    const status = error.response?.status;
                    const blockedReason = error.response?.data?.blocked_reason;
                    if (status === 409) {
                        // Conflict: card state changed, not latest, or
                        // different undo_request_id on already-undone log.
                        this.undoConflict = '无法撤销：卡片状态已在其他页面发生变化。';
                    } else if (status === 404) {
                        // Session mismatch or log not found.
                        this.undoConflict = '无法撤销：该操作不属于当前复习会话。';
                    } else {
                        this.undoConflict = '撤销失败，请检查网络后重试。';
                    }
                    // Refresh the timeline so the UI reflects the current
                    // undoable state (the action may no longer be undoable).
                    this.loadSessionActions();
                    // Do NOT change currentCard on failure. Do NOT attempt
                    // to undo a different action automatically.
                }).finally(() => {
                    this.undoLoadingReviewLogId = null;
                });
            },
            handleHotkey(event) {
                // ADR-0009: Ctrl+Z / Cmd+Z triggers undo. Checked BEFORE
                // the other guards so it works even when no card is shown
                // (e.g. summary view), but still respects input/dialog
                // guards and undo-loading state.
                if ((event.ctrlKey || event.metaKey) && (event.key === 'z' || event.key === 'Z')) {
                    // Never trigger in input/textarea/contenteditable.
                    const tag = event.target?.tagName?.toLowerCase();
                    if (['input', 'textarea', 'select'].includes(tag) || event.target?.isContentEditable) {
                        return;
                    }
                    // No dialog that might be using Ctrl+Z for its own purpose.
                    if (this.editDialog || this.lifecycleDialog || this.resetDialog || this.deleteDialog || this.sourceDialog) {
                        return;
                    }
                    // No study report open.
                    if (this.showSessionSummary) {
                        return;
                    }
                    // Not while rating or undoing.
                    if (this.rating || this.undoLoadingReviewLogId !== null) {
                        return;
                    }
                    // Must have an undoable action.
                    if (!this.latestUndoableAction) {
                        return;
                    }
                    event.preventDefault();
                    this.requestUndo(this.latestUndoableAction, 'sense_review_hotkey');
                    return;
                }
                // When the session summary is shown, Space and 1/2/3/4
                // must NOT trigger show-answer or rating.
                if (this.showSessionSummary) {
                    return;
                }
                const tag = event.target?.tagName?.toLowerCase();
                if (['input', 'textarea', 'select'].includes(tag) || event.target?.isContentEditable) {
                    return;
                }
                if (this.editDialog || this.lifecycleDialog || this.resetDialog || this.deleteDialog || this.sourceDialog) {
                    return;
                }
                if (!this.currentCard || this.loading || this.rating || this.lifecycleLoading || this.resetLoading || this.deleteLoading) {
                    return;
                }
                switch (event.key) {
                    case ' ':
                    case 'Spacebar':
                        event.preventDefault();
                        if (!this.showAnswer) {
                            this.showAnswer = true;
                        }
                        break;
                    case '1':
                        if (this.showAnswer) { this.rate('again'); }
                        break;
                    case '2':
                        if (this.showAnswer) { this.rate('hard'); }
                        break;
                    case '3':
                        if (this.showAnswer) { this.rate('good'); }
                        break;
                    case '4':
                        if (this.showAnswer) { this.rate('easy'); }
                        break;
                }
            },
            // ==================== Edit dialog ====================
            // The dialog owns the form + save API. The parent just opens it
            // (passing currentCard) and applies the saved result.
            startEdit() {
                if (!this.currentCard) {
                    return;
                }
                this.editDialog = true;
            },
            onCardSaved(saved) {
                if (!this.cards.length || !saved) {
                    return;
                }
                this.cards[0].pos = saved.pos;
                this.cards[0].sense_zh = saved.sense_zh;
                this.cards[0].sense_en = saved.sense_en;
                this.cards[0].example_sentence_en = saved.example_sentence_en;
                this.cards[0].example_sentence_zh = saved.example_sentence_zh;
                this.cards[0].aliases_zh = saved.aliases_zh || [];
                this.cards[0].collocations = saved.collocations || [];
                this.cards = [...this.cards];
                this.showSnackbar('已保存词义卡片。', 'success');
            },
            // ==================== Source context dialog ====================
            viewSource() {
                if (!this.currentCard) {
                    return;
                }

                const card = {
                    lemma: this.currentCard.lemma,
                    surface_form: this.currentCard.surface_form,
                    sense_zh: this.currentCard.sense_zh,
                    sense_en: this.currentCard.sense_en,
                    example_sentence_en: this.currentCard.example_sentence_en,
                    example_sentence_zh: this.currentCard.example_sentence_zh,
                };

                const params = {};
                if (this.currentCard.displayed_occurrence_id) {
                    params.preferred_occurrence_id = this.currentCard.displayed_occurrence_id;
                }

                axios.get(`/senses/${this.currentCard.word_sense_id}/source-context-list`, { params: params })
                    .then((response) => {
                        const data = response.data || {};
                        const sources = Array.isArray(data.sources) ? data.sources : [];
                        this.sourcePayload = {
                            card: card,
                            context: sources[0] || null,
                            sources: sources,
                            sourceCount: data.count || sources.length,
                            preferredOccurrenceStatus: data.preferred_occurrence_status || null,
                        };
                        this.sourceDialog = true;
                    })
                    .catch(() => {
                        this.sourcePayload = { card: card, context: null, sources: [], sourceCount: 0, error: '获取原文失败。' };
                        this.sourceDialog = true;
                    });
            },
            // ==================== Lifecycle (ADR-0010) ====================
            // Lifecycle actions go through POST /review-cards/{id}/lifecycle-actions
            // with { action, request_id, expected_version, source }.
            // Reset and Delete keep their own dedicated endpoints.
            // Thin wrappers exposing the pure presentation helpers to the template.
            // Vue 2 templates can only call functions registered on the instance.
            stateColor,
            stateLabel,
            // Map a lifecycle action to an MDI icon name.
            lifecycleActionIcon(action) {
                const icons = {
                    bury: 'mdi-alarm-snooze',
                    unbury: 'mdi-alarm-check',
                    suspend: 'mdi-pause-circle-outline',
                    resume: 'mdi-play-circle-outline',
                    archive: 'mdi-archive',
                    restore: 'mdi-archive-arrow-up',
                };
                return icons[action] || 'mdi-circle-medium';
            },
            // Label for a lifecycle action (delegates to presentation helper).
            lifecycleActionLabel(action) {
                return actionLabel(action);
            },
            // Color for a lifecycle action icon (delegates to presentation helper).
            lifecycleActionColor(action) {
                return actionColor(action);
            },
            // Fetch the lifecycle descriptor for the current card.
            // Called when the answer is revealed. Non-blocking: on failure,
            // the menu simply hides lifecycle items.
            fetchLifecycleDescriptor() {
                if (!this.currentCard) {
                    return;
                }
                axios.get(`/review-cards/${this.currentCard.review_card_id}/lifecycle`)
                    .then((response) => {
                        this.lifecycleDescriptor = response.data.lifecycle || null;
                        this.lifecycleConflict = '';
                    })
                    .catch(() => {
                        this.lifecycleDescriptor = null;
                    });
            },
            // Menu click handler: safe actions execute immediately,
            // moderate actions open the confirmation dialog.
            onLifecycleMenuClick(action) {
                if (!this.currentCard) {
                    return;
                }
                const dangerLevel = actionDangerLevel(action);
                if (dangerLevel === 'safe') {
                    this.executeLifecycleAction(action);
                } else {
                    this.openLifecycleDialog(action);
                }
            },
            openLifecycleDialog(action) {
                this.lifecycleDialogAction = action;
                this.lifecycleConflict = '';
                this.lifecycleDialog = true;
            },
            // Execute a lifecycle action via POST. Used by both the
            // immediate path (safe actions) and the dialog confirm button.
            executeLifecycleAction(action) {
                if (!this.currentCard) {
                    return;
                }
                const expectedVersion = this.lifecycleDescriptor
                    ? this.lifecycleDescriptor.version
                    : null;
                const requestId = (window.crypto && typeof window.crypto.randomUUID === 'function')
                    ? window.crypto.randomUUID()
                    : ('lc-' + Date.now() + '-' + Math.random().toString(36).slice(2));

                this.lifecycleLoading = true;
                axios.post(`/review-cards/${this.currentCard.review_card_id}/lifecycle-actions`, {
                    action: action,
                    request_id: requestId,
                    expected_version: expectedVersion,
                    source: 'sense_review',
                }).then((response) => {
                    this.lifecycleDialog = false;
                    const label = actionLabel(action);
                    const alreadyApplied = response.data?.already_applied;
                    this.showSnackbar(
                        alreadyApplied ? `${label}：该操作已应用过。` : `已${label}。`,
                        'success'
                    );
                    this.loadCards();
                    this.loadFsrsStats();
                    // Refresh descriptor after the queue reloads so the
                    // next card's available_actions are correct.
                    this.$nextTick(() => {
                        this.fetchLifecycleDescriptor();
                    });
                }).catch((err) => {
                    const status = err.response?.status;
                    if (status === 409) {
                        // Version conflict: card state changed elsewhere.
                        this.lifecycleConflict = '卡片状态已在其他页面发生变化，已刷新最新状态。';
                        this.fetchLifecycleDescriptor();
                    } else if (status === 422) {
                        // Illegal transition or validation error.
                        this.lifecycleConflict = err.response?.data?.message || '该操作在当前状态下不可用。';
                        this.fetchLifecycleDescriptor();
                    } else if (!err.response) {
                        // Network error — keep dialog open for retry.
                        this.showSnackbar('网络错误，请检查连接后重试。', 'error');
                    } else {
                        this.showSnackbar(err.response?.data?.message || '操作失败。', 'error');
                        this.lifecycleDialog = false;
                    }
                }).finally(() => {
                    this.lifecycleLoading = false;
                });
            },
            // Dialog confirm handler — calls executeLifecycleAction with
            // the action currently selected in the dialog.
            performLifecycleAction() {
                if (!this.lifecycleDialogAction) {
                    return;
                }
                this.executeLifecycleAction(this.lifecycleDialogAction);
            },
            // ==================== Reset ====================
            openResetDialog() {
                if (!this.currentCard) {
                    return;
                }
                this.resetDialog = true;
            },
            resetCard() {
                if (!this.currentCard) {
                    return;
                }

                this.resetLoading = true;
                axios.post(`/review-cards/manage/${this.currentCard.review_card_id}/reset`)
                    .then((response) => {
                        this.resetDialog = false;
                        this.showSnackbar(response.data?.message || '已重置为新学卡。该卡会重新进入复习队列。', 'success');
                        this.loadCards();
                        this.loadFsrsStats();
                    })
                    .catch((err) => {
                        this.showSnackbar(err.response?.data?.message || '重置失败。', 'error');
                    })
                    .finally(() => {
                        this.resetLoading = false;
                    });
            },
            // ==================== Delete ====================
            openDeleteDialog() {
                if (!this.currentCard) {
                    return;
                }
                this.deleteDialog = true;
            },
            deleteCard() {
                if (!this.currentCard) {
                    return;
                }

                this.deleteLoading = true;
                axios.delete(`/review-cards/manage/${this.currentCard.review_card_id}`)
                    .then((response) => {
                        this.deleteDialog = false;
                        const message = response.data?.message || '已彻底删除词义复习卡，复习历史已保留。';
                        this.showSnackbar(message, 'success');
                        this.loadCards();
                        this.loadFsrsStats();
                    })
                    .catch((err) => {
                        this.showSnackbar(err.response?.data?.message || '删除失败。', 'error');
                    })
                    .finally(() => {
                        this.deleteLoading = false;
                    });
            },
            // ==================== Session summary ====================
            endSession() {
                if (!this.hasReviewed) {
                    return;
                }
                this.showSessionSummary = true;
            },
            continueReview() {
                this.showSessionSummary = false;
            },
            exitReview() {
                window.location.href = '/review-cards/manage';
            },
            // ==================== Report dialogs ====================
            // All reports are orchestrated by SenseReviewReportCenter.
            // The parent only controls reportCenterOpen (boolean);
            // ReportCenter owns report selection, loading, GET, error,
            // async-race protection, and close.
            // No open*/close* methods needed here.
            // ==================== Snackbar ====================
            showSnackbar(text, color) {
                this.snackbar = { show: true, text, color };
            },
            // ==================== ADR-0009: Display helpers ====================
            // Map a rating value to a Vuetify color name for the action
            // drawer chips. Matches SenseReviewRatingPresentation colors.
            ratingColor(rating) {
                switch (rating) {
                    case 'again': return 'error';
                    case 'hard': return 'warning';
                    case 'good': return 'primary';
                    case 'easy': return 'success';
                    default: return 'foreground';
                }
            },
            // Format an ISO 8601 datetime for display in the action drawer.
            // Returns a short local-time string; empty string on null/invalid.
            formatTime(iso) {
                if (!iso) {
                    return '';
                }
                try {
                    const d = new Date(iso);
                    if (isNaN(d.getTime())) {
                        return '';
                    }
                    return d.toLocaleString('zh-CN', {
                        month: '2-digit',
                        day: '2-digit',
                        hour: '2-digit',
                        minute: '2-digit',
                    });
                } catch (e) {
                    return '';
                }
            },
            // Human-readable label for the undo source (where the undo was
            // triggered from). Used in the audit trail in the action drawer.
            undoSourceLabel(source) {
                switch (source) {
                    case 'sense_review_snackbar': return '评分提示';
                    case 'sense_review_history': return '操作历史';
                    case 'sense_review_hotkey': return '快捷键';
                    default: return source || '';
                }
            },
            // Human-readable label for blocked reasons. Used in the action
            // drawer to explain why a non-undoable action cannot be undone.
            blockedReasonLabel(reason) {
                switch (reason) {
                    case 'wrong_session': return '不属于当前会话';
                    case 'not_latest_action': return '不是最新操作';
                    case 'already_undone': return '已撤销';
                    case 'missing_snapshot': return '缺少快照（旧日志）';
                    case 'card_state_changed': return '卡片状态已变化';
                    case 'legacy_target': return '旧版卡片不支持撤销';
                    case 'sense_not_confirmed': return '词义未确认';
                    case 'card_archived': return '卡片已归档';
                    case 'unsupported_rating': return '不支持的评分类型';
                    case 'unsupported_source': return '不支持的来源';
                    default: return reason || '';
                }
            },
        }
    }
</script>

<style scoped>
    .sense-main {
        font-size: 24px;
        font-weight: 600;
    }
</style>
