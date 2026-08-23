<template>
    <v-container
        id="sense-review"
        class="sense-review-page"
        :class="{ 'review-high-contrast': experiencePreferences.highContrast, 'review-reduce-motion': experiencePreferences.reduceMotion }"
        data-testid="sense-review-page"
    >
        <v-card outlined class="sense-review-summary rounded-lg px-4 pb-4 my-4" :loading="loading">
            <div class="sense-review-summary-bar subheader my-4 d-flex align-center flex-wrap">
                词义复习
                <v-spacer></v-spacer>
                <v-chip class="mx-1" color="foreground">到期数量 {{ summary.due_count || 0 }}</v-chip>
                <v-chip class="mx-1" color="foreground">已复习 {{ reviewedCount }}</v-chip>
                <v-chip class="mx-1" color="foreground">剩余 {{ remainingCount }}</v-chip>
                <v-chip class="mx-1 my-1" small outlined>今日已复习 {{ fsrsStats.reviewed_today }}</v-chip>
                <v-btn small outlined color="primary" class="ml-2" @click="todayLimitsOpen = true">
                    <v-icon small left>mdi-calendar-edit</v-icon>今日学习设置
                </v-btn>
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
        <SenseReviewTodayLimitsDialog v-model="todayLimitsOpen" @changed="onTodayLimitsChanged" />

        <SenseReviewExperienceController
            v-if="currentCard && !showSummaryView"
            :experience="reviewExperience"
            :card="currentCard"
            :show-answer="showAnswer"
            :previous-available="previousNavigationAvailable"
            :forward-available="forwardNavigationAvailable"
            :busy="reviewExperienceBusy"
            :overlay-open="experienceOverlayOpen"
            @reveal-answer="showAnswer = true"
            @focus-rating="focusRatingControls"
            @card-started="onExperienceCardStarted"
            @preferences-change="experiencePreferences = $event"
            @previous-card="goPreviousCard('sense_review_history')"
            @next-card="goForwardCard"
            @view-source="viewSource"
        />

        <v-card v-if="currentCard && !showSummaryView" outlined class="sense-review-card rounded-lg pa-5">
            <SenseStudyCard
                ref="studyCard"
                :card="currentCard"
                :show-answer="showAnswer"
                :font-size="experiencePreferences.fontSize"
                @reveal="showAnswer = true"
                @view-source="viewSource"
            >
                <template #header-meta>
                    <SenseMediaControls
                        :card="currentCard"
                        @updated="onCurrentMediaUpdated"
                        @notify="showSnackbar"
                    />
                    <v-chip
                        v-if="currentCardIsInactive"
                        x-small
                        :color="stateColor(currentCardLifecycleState)"
                        class="mr-1"
                    >{{ stateLabel(currentCardLifecycleState) }}</v-chip>
                    <div v-if="buriedRemainingDisplay" class="caption warning--text ml-2">
                        {{ buriedRemainingDisplay }}
                    </div>
                </template>

                <template #reveal>
                    <v-btn
                        ref="revealButton"
                        depressed
                        rounded
                        color="primary"
                        large
                        class="mobile-reveal-button"
                        data-testid="show-sense-answer"
                        :disabled="rating || deleteLoading"
                        @click="showAnswer = true"
                    >显示答案</v-btn>
                </template>

                <template #answer-toolbar>
                    <v-menu offset-y left>
                        <template v-slot:activator="{ on, attrs }">
                            <v-btn small text v-bind="attrs" v-on="on">
                                <v-icon small left>mdi-dots-vertical</v-icon>更多
                            </v-btn>
                        </template>
                        <v-list dense>
                            <v-list-item @click="startEdit">
                                <v-list-item-icon><v-icon small>mdi-pencil</v-icon></v-list-item-icon>
                                <v-list-item-title>编辑</v-list-item-title>
                            </v-list-item>
                            <v-list-item @click="fsrsDetailOpen = true">
                                <v-list-item-icon><v-icon small>mdi-information-outline</v-icon></v-list-item-icon>
                                <v-list-item-title>复习信息</v-list-item-title>
                            </v-list-item>
                            <v-list-item v-if="previousCardSnapshot" @click="previousCardDialog = true">
                                <v-list-item-icon><v-icon small>mdi-card-search-outline</v-icon></v-list-item-icon>
                                <v-list-item-title>上一张信息</v-list-item-title>
                            </v-list-item>
                            <v-divider class="my-1" />
                            <v-list-item @click="openDeleteDialog">
                                <v-list-item-icon><v-icon small color="error">mdi-delete</v-icon></v-list-item-icon>
                                <v-list-item-title class="error--text">删除</v-list-item-title>
                            </v-list-item>
                        </v-list>
                    </v-menu>
                    <v-dialog v-model="fsrsDetailOpen" max-width="440">
                        <v-card>
                            <v-card-title>复习信息</v-card-title>
                            <v-card-text v-if="currentCard">
                                <v-simple-table dense class="no-hover border rounded-lg">
                                    <tbody>
                                        <tr><td>状态</td><td>{{ fsrsStateLabel(currentCard.fsrs_state) }}</td></tr>
                                        <tr><td>已复习</td><td>{{ currentCard.fsrs_reps }} 次</td></tr>
                                        <tr><td>下次到期</td><td>{{ currentCard.fsrs_due_at || '—' }}</td></tr>
                                        <tr><td>稳定度</td><td>{{ currentCard.fsrs_stability || '—' }}</td></tr>
                                        <tr><td>难度</td><td>{{ currentCard.fsrs_difficulty || '—' }}</td></tr>
                                        <tr><td>遗忘次数</td><td>{{ currentCard.fsrs_lapses }}</td></tr>
                                    </tbody>
                                </v-simple-table>
                            </v-card-text>
                            <v-card-actions>
                                <v-spacer />
                                <v-btn text @click="fsrsDetailOpen = false">关闭</v-btn>
                            </v-card-actions>
                        </v-card>
                    </v-dialog>
                </template>

                <template #answer-left-extra>
                    <SenseReviewUnderstandingAid :aid="understandingAid" />
                    <SenseReviewLearningFeedbackPanel
                        v-if="hasLearningFeedback"
                        :key="'feedback-' + currentCard.review_card_id"
                        :learning-feedback="learningFeedback"
                        :fsrs-stability="currentCard.fsrs_stability"
                    />
                    <SenseReviewLeechPanel
                        :key="'leech-' + currentCard.review_card_id"
                        :review-card-id="currentCard.review_card_id"
                        :show-answer="showAnswer"
                        @rewrite="leechRewriteDialog = true"
                        @edit="editDialog = true"
                        @history="sessionActionDrawerOpen = true"
                    />
                </template>

                <template #after-answer>
                    <SenseReviewRatingControls
                        ref="ratingControls"
                        :disabled="rating || deleteLoading"
                        :interval-previews="intervalPreviews"
                        :preview-loading="intervalPreviewLoading"
                        :preview-error="intervalPreviewError"
                        @rating="rate"
                    />
                </template>
            </SenseStudyCard>
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

        <!-- ADR-0011: Leech rewrite package dialog.
             Shows JSON + Markdown for the user to copy to an external AI.
             Does NOT call any AI provider. Does NOT create WordSense /
             ReviewCard / ReviewLog. -->
        <SenseReviewLeechRewritePackageDialog
            v-model="leechRewriteDialog"
            :review-card-id="currentCard ? currentCard.review_card_id : 0"
            :lemma="currentCard ? currentCard.lemma : ''"
        />

        <!-- Delete confirmation dialog -->
        <v-dialog v-model="deleteDialog" max-width="480">
            <v-card>
                <v-card-title>确认删除</v-card-title>
                <v-card-text>
                    这会将词义复习卡移入最近删除，并让该释义不再出现在阅读页点词结果中。阅读材料、原文位置和复习历史会保留；30 天内可从“我的 → 高级 → 最近删除”恢复。确定继续吗？
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="deleteDialog = false" :disabled="deleteLoading">取消</v-btn>
                    <v-btn color="error" :loading="deleteLoading" @click="deleteCard">移入最近删除</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Source context dialog -->
        <sense-example-dialog
            v-model="sourceDialog"
            :payload="sourcePayload"
            language="english"
            :font-size="experiencePreferences.fontSize"
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

        <SenseReviewSessionActionsSurface
            ref="sessionActionsSurface"
            v-model="sessionActionDrawerOpen"
            :review-session-id="reviewSessionId"
            @state-change="onSessionActionStateChange"
            @undone="onSessionActionUndone"
        />
        <SenseReviewPreviousCardDialog
            v-model="previousCardDialog"
            :snapshot="previousCardSnapshot"
            @undo="requestUndo($event, 'sense_review_history')"
        />
    </v-container>
</template>

<script>
    import SenseExampleDialog from '../Review/SenseExampleDialog.vue';
    import SenseReviewSessionSummary from './SenseReviewSessionSummary.vue';
    import SenseReviewSessionActionsSurface from './SenseReviewSessionActionsSurface.vue';
    import SenseReviewLearningFeedbackPanel from './SenseReviewLearningFeedbackPanel.vue';
    import SenseReviewRatingControls from './SenseReviewRatingControls.vue';
    import SenseReviewUnderstandingAid from './SenseReviewUnderstandingAid.vue';
    import SenseReviewEditDialog from './SenseReviewEditDialog.vue';
    import SenseReviewReportCenter from './SenseReviewReportCenter.vue';
    import SenseReviewTodayLimitsDialog from './SenseReviewTodayLimitsDialog.vue';
    import SenseReviewLeechPanel from './SenseReviewLeechPanel.vue';
    import SenseReviewLeechRewritePackageDialog from './SenseReviewLeechRewritePackageDialog.vue';
    import SenseReviewExperienceController from './SenseReviewExperienceController.vue';
    import SenseReviewPreviousCardDialog from './SenseReviewPreviousCardDialog.vue';
    import SenseMediaControls from './SenseMediaControls.vue';
    import SenseStudyCard from './SenseStudyCard.vue';
    import * as SessionTracker from './SenseReviewSessionTracker.js';
    import { getOrCreateReviewSessionId } from './SenseReviewSessionIdentity.js';
    import {
        emptyReviewNavigationHistory,
        loadReviewNavigationHistory,
        moveNavigationBack,
        moveNavigationForward,
        recordRatedCard,
        saveReviewNavigationHistory,
        setNavigationCurrentCard,
    } from './SenseReviewNavigationHistory.js';
    import { normalizeIntervalPreview } from './SenseReviewIntervalPresentation.js';
    import { createReviewApiClient } from '../Review/ReviewApiClient.js';
    import { createReviewRatingTransaction } from '../Review/ReviewRatingTransaction.js';
    import { createTracker, pause as pauseDuration, resume as resumeDuration, durationMs } from '../Review/ReviewDurationTracker.js';
    import {
        stateLabel,
        stateColor,
        buriedRemainingText,
    } from '../../services/ReviewCardLifecyclePresentation.js';

    const reviewApi = createReviewApiClient();

    /**
     * SenseReview.vue — page container (refactored).
     *
     * Responsibilities (after extraction of sub-components):
     *  - Load the due-card queue and FSRS stats.
     *  - Track the current card index and show-answer state.
     *  - Call the rating API and record ratings into the page session.
     *  - Coordinate dialogs (edit / delete / source).
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
            SenseReviewSessionActionsSurface,
            SenseReviewLearningFeedbackPanel,
            SenseReviewRatingControls,
            SenseReviewUnderstandingAid,
            SenseReviewEditDialog,
            SenseReviewReportCenter,
            SenseReviewTodayLimitsDialog,
            SenseReviewLeechPanel,
            SenseReviewLeechRewritePackageDialog,
            SenseReviewExperienceController,
            SenseReviewPreviousCardDialog,
            SenseMediaControls,
            SenseStudyCard,
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
                // Delete dialog
                deleteDialog: false,
                deleteLoading: false,
                // Read-only lifecycle descriptor (ADR-0010).
                // lifecycleDescriptor: cached descriptor from GET /review-cards/{id}/lifecycle.
                //   Ordinary review consumes only effective_state and buried_until.
                lifecycleDescriptor: null,
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
                todayLimitsOpen: false,
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
                // DEV-QO-7: loadCards stale-response protection.
                // Incremented on every loadCards() call and on every rate()
                // start. A stale loadCards() response (seq mismatch) is
                // discarded so it cannot overwrite a newer rating result or
                // a newer queue state.
                loadCardsRequestSequence: 0,
                ratingTransaction: createReviewRatingTransaction(),
                reviewDurationTracker: createTracker(undefined, document.visibilityState !== 'hidden'),
                reviewSessionId: '',
                sessionActionDrawerOpen: false,
                sessionActionProjection: {
                    latestUndoableAction: null,
                    activeCount: 0,
                    undoLoadingReviewLogId: null,
                },
                undoSnackbar: {
                    show: false,
                    text: '',
                    action: null,
                },
                // ADR-0011: Leech governance — rewrite package dialog.
                // leechRewriteDialog: dialog visibility (v-model).
                // The dialog fetches its own data on open.
                leechRewriteDialog: false,
                reviewExperience: {},
                experiencePreferences: { fontSize: 20, highContrast: false, reduceMotion: false },
                previousCardSnapshot: null,
                previousCardDialog: false,
                navigationHistory: emptyReviewNavigationHistory(''),
            }
        },
        computed: {
            reviewExperienceBusy() {
                return this.loading || this.rating || this.deleteLoading
                    || this.undoLoadingReviewLogId !== null;
            },
            experienceOverlayOpen() {
                return this.editDialog || this.deleteDialog || this.sourceDialog || this.reportCenterOpen
                    || this.todayLimitsOpen || this.sessionActionDrawerOpen
                    || this.previousCardDialog || this.showSessionSummary || this.leechRewriteDialog;
            },
            currentCard() {
                return this.cards.length ? this.cards[0] : null;
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
            remainingCount() {
                return this.cards.length;
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
            latestUndoableAction() {
                return this.sessionActionProjection.latestUndoableAction;
            },
            previousNavigationTargetId() {
                const ids = this.navigationHistory.backCardIds || [];
                return ids.length ? ids[ids.length - 1] : null;
            },
            forwardNavigationTargetId() {
                const ids = this.navigationHistory.forwardCardIds || [];
                return ids.length ? ids[ids.length - 1] : null;
            },
            previousNavigationAvailable() {
                if (!this.previousNavigationTargetId) return false;
                if (this.cards.some(card => card.review_card_id === this.previousNavigationTargetId)) return true;
                return this.latestUndoableAction?.review_card_id === this.previousNavigationTargetId;
            },
            forwardNavigationAvailable() {
                if (!this.currentCard || !this.forwardNavigationTargetId) return false;
                return this.forwardNavigationTargetId !== this.currentCard.review_card_id
                    && this.cards.some(card => card.review_card_id === this.forwardNavigationTargetId);
            },
            activeSessionActionCount() {
                return this.sessionActionProjection.activeCount;
            },
            undoLoadingReviewLogId() {
                return this.sessionActionProjection.undoLoadingReviewLogId;
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
                    this.$nextTick(this.focusRatingControls);
                }
            },
            // When the current card changes (new card loaded after rating,
            // or queue refreshed), discard any stale preview and bump the
            // request sequence so any in-flight response is ignored.
            currentCard(newCard, oldCard) {
                const newId = newCard ? newCard.review_card_id : null;
                const oldId = oldCard ? oldCard.review_card_id : null;
                if (newId !== oldId) {
                    this.reviewDurationTracker = createTracker(undefined, document.visibilityState !== 'hidden');
                    this.intervalPreviews = null;
                    this.intervalPreviewError = '';
                    this.intervalPreviewLoading = false;
                    this.intervalPreviewRequestSequence++;
                    // Invalidate the read-only lifecycle descriptor on card change.
                    this.lifecycleDescriptor = null;
                }
            },
        },
        beforeDestroy() {
            window.removeEventListener('keyup', this.handleHotkey);
            document.removeEventListener('visibilitychange', this.handleReviewVisibility);
            this.ratingTransaction.invalidate();
        },
        mounted() {
            // ADR-0009: Create or restore the per-tab review session ID.
            // Uses sessionStorage (per-tab, refresh-persistent, not shared
            // across tabs). This ID is sent with every rating POST and used
            // to scope the session-action timeline and stack-undo.
            this.reviewSessionId = getOrCreateReviewSessionId();
            this.navigationHistory = loadReviewNavigationHistory(this.reviewSessionId);
            this.loadCards();
            this.loadFsrsStats();
            this.$nextTick(() => {
                this.$refs.sessionActionsSurface.reload();
            });
            window.addEventListener('keyup', this.handleHotkey);
            document.addEventListener('visibilitychange', this.handleReviewVisibility);
        },
        methods: {
            handleReviewVisibility() {
                if (document.visibilityState === 'hidden') pauseDuration(this.reviewDurationTracker);
                else resumeDuration(this.reviewDurationTracker);
            },
            focusRevealButton() {
                const target = this.$refs.revealButton;
                const element = target?.$el || target;
                if (element && typeof element.focus === 'function') element.focus();
                else this.$refs.studyCard?.focusCard();
            },
            focusRatingControls() {
                this.$refs.ratingControls?.focusFirst();
            },
            onExperienceCardStarted() {
                this.$nextTick(this.focusRevealButton);
            },
            onCurrentMediaUpdated(media) {
                if (!this.currentCard) return;
                this.currentCard.media = Array.isArray(media) ? media : [];
                this.cards = [...this.cards];
            },
            onTodayLimitsChanged(limits) {
                this.summary = Object.assign({}, this.summary, limits);
                this.ignoreDailyLimits = false;
                this.loadCards();
            },
            loadFsrsStats() {
                this.statsLoading = true;
                this.statsError = '';
                axios.get('/review-cards/stats')
                    .then((response) => {
                        this.fsrsStats = response.data;
                    })
                    .catch(() => {
                        this.statsError = '复习统计加载失败。';
                    })
                    .finally(() => {
                        this.statsLoading = false;
                    });
            },
            loadCards() {
                this.ratingTransaction.invalidate();
                this.loading = true;
                this.error = '';
                // DEV-QO-7: capture sequence so stale loadCards() responses
                // (e.g. from a previous rating cycle) are discarded.
                this.loadCardsRequestSequence++;
                const seq = this.loadCardsRequestSequence;
                const params = {};
                if (this.ignoreDailyLimits) {
                    params.ignoreDailyLimits = true;
                }
                return reviewApi.loadSenseQueue(params).then((response) => {
                    // DEV-QO-7: drop stale responses so a slow loadCards()
                    // cannot overwrite a newer rating result or queue state.
                    if (seq !== this.loadCardsRequestSequence) {
                        return;
                    }
                    const cards = Array.isArray(response.data.cards) ? [...response.data.cards] : [];
                    const preferredCardId = this.navigationHistory.currentCardId;
                    if (preferredCardId) {
                        const preferredIndex = cards.findIndex(card => card.review_card_id === preferredCardId);
                        if (preferredIndex > 0) {
                            const [preferredCard] = cards.splice(preferredIndex, 1);
                            cards.unshift(preferredCard);
                        }
                    }
                    this.cards = cards;
                    this.navigationHistory = saveReviewNavigationHistory(setNavigationCurrentCard(
                        this.navigationHistory,
                        this.currentCard ? this.currentCard.review_card_id : null,
                    ));
                    this.summary = response.data.summary;
                    this.reviewExperience = response.data.experience || {};
                    this.fsrsDetailOpen = false;
                    this.showAnswer = false;
                    // When the queue naturally drains AND the user has
                    // reviewed at least one card, auto-show the summary.
                    // When no reviews yet, keep the empty-state alert.
                    if (this.cards.length === 0 && this.hasReviewed && !this.showSessionSummary) {
                        this.showSessionSummary = true;
                    }
                }).catch((error) => {
                    if (seq !== this.loadCardsRequestSequence) {
                        return;
                    }
                    this.error = error.response?.data?.message || '词义复习队列加载失败。';
                }).finally(() => {
                    if (seq === this.loadCardsRequestSequence) {
                        this.loading = false;
                    }
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
                reviewApi.loadSenseIntervalPreview(cardId).then((response) => {
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

                // DEV-QO-7: prevent double-rating from hotkeys or
                // programmatic calls. The rating buttons are already
                // disabled via :disabled="rating || ...", but this guard
                // ensures programmatic calls are also blocked.
                if (this.rating) {
                    return;
                }

                this.rating = true;
                const seq = this.ratingTransaction.begin();
                this.error = '';
                // Invalidate the interval preview immediately so the old
                // card's predicted intervals cannot bleed into the next
                // card. The requestSequence bump also discards any
                // in-flight preview response.
                this.intervalPreviews = null;
                this.intervalPreviewError = '';
                this.intervalPreviewLoading = false;
                this.intervalPreviewRequestSequence++;
                // DEV-QO-7: invalidate any in-flight loadCards() so its
                // stale response cannot overwrite the state after this
                // rating completes and triggers a fresh loadCards().
                this.loadCardsRequestSequence++;
                const payload = { rating: rating };
                payload.review_duration_ms = durationMs(this.reviewDurationTracker);
                if (this.currentCard.question_example_key) {
                    payload.question_example_key = this.currentCard.question_example_key;
                }
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
                reviewApi.rateSenseCard(this.currentCard.review_card_id, payload).then((response) => {
                    if (!this.ratingTransaction.isCurrent(seq)) {
                        return;
                    }
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
                    // A new successful rating creates a new navigation branch.
                    // Only card IDs are remembered here; ReviewLog/FSRS remains
                    // the learning authority.
                    this.navigationHistory = saveReviewNavigationHistory(recordRatedCard(
                        this.navigationHistory,
                        cardSnapshot.review_card_id,
                    ));
                    this.loadCards();
                    this.loadFsrsStats();
                    this.$refs.sessionActionsSurface.reload();
                    // ADR-0009: Show the undo snackbar with the real action
                    // metadata from the backend (review_log_id, rating_label,
                    // undoable). Do NOT fake the review_log_id on the frontend.
                    const action = response.data.action;
                    this.previousCardSnapshot = {
                        card: reviewedCard || cardSnapshot,
                        action: action || { rating, rating_label: rating, undoable: false },
                        durationMs: payload.review_duration_ms,
                    };
                    if (action && action.undoable) {
                        this.showUndoSnackbar(action, reviewedCard);
                    }
                    // DEV-QO-3 (Task 2000-12): Server confirmed the rating.
                    // Clear any persistent rating-recovery error from a
                    // previous failed attempt, then unlock the buttons.
                    this.error = '';
                    this.rating = false;
                }).catch((error) => {
                    if (!this.ratingTransaction.isCurrent(seq)) {
                        return;
                    }
                    // DEV-RECOVERY-1 (Task 2000-13): delegate the recovery
                    // orchestration to the pure JS helper. The helper keeps
                    // this.rating=true (buttons disabled), calls loadCards()
                    // which returns a Promise, waits for it to settle, then
                    // unlocks. The helper does NOT touch statistics,
                    // ReviewLog, FSRS, or lifecycle.
                    this.ratingTransaction.recover({
                        reloadQueue: () => this.loadCards(),
                        lockRating: () => { this.rating = true; },
                        unlockRating: () => { this.rating = false; },
                        setRecoveryMessage: () => {
                            this.error = '评分结果状态不确定，正在重新加载词义复习队列，请不要重复评分。';
                        },
                        preserveLoadError: () => !!this.error,
                    });
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
            requestUndo(action, source) {
                if (this.$refs.sessionActionsSurface) {
                    return this.$refs.sessionActionsSurface.requestUndo(action, source);
                }
                return Promise.resolve(null);
            },
            activateQueuedCard(cardId) {
                const index = this.cards.findIndex(card => card.review_card_id === cardId);
                if (index < 0) return false;
                if (index > 0) {
                    const [card] = this.cards.splice(index, 1);
                    this.cards.unshift(card);
                    this.cards = [...this.cards];
                }
                this.undoSnackbar.show = false;
                this.fsrsDetailOpen = false;
                this.showAnswer = false;
                this.$nextTick(this.focusRevealButton);
                return true;
            },
            goPreviousCard(source = 'sense_review_history') {
                if (!this.currentCard || !this.previousNavigationAvailable || this.reviewExperienceBusy) {
                    return Promise.resolve(null);
                }
                const targetCardId = this.previousNavigationTargetId;
                const currentCardId = this.currentCard.review_card_id;

                if (this.cards.some(card => card.review_card_id === targetCardId)) {
                    this.navigationHistory = saveReviewNavigationHistory(moveNavigationBack(
                        this.navigationHistory,
                        targetCardId,
                        currentCardId,
                    ));
                    this.activateQueuedCard(targetCardId);
                    return Promise.resolve({ success: true, local_navigation: true });
                }

                if (this.latestUndoableAction?.review_card_id !== targetCardId) {
                    return Promise.resolve(null);
                }
                return this.requestUndo(this.latestUndoableAction, source);
            },
            goForwardCard() {
                if (!this.currentCard || !this.forwardNavigationAvailable || this.reviewExperienceBusy) return;
                const targetCardId = this.forwardNavigationTargetId;
                const currentCardId = this.currentCard.review_card_id;
                if (!this.activateQueuedCard(targetCardId)) return;
                this.navigationHistory = saveReviewNavigationHistory(moveNavigationForward(
                    this.navigationHistory,
                    targetCardId,
                    currentCardId,
                ));
            },
            onSessionActionStateChange(projection) {
                this.sessionActionProjection = projection;
            },
            onSessionActionUndone(data) {
                this.undoSnackbar.show = false;
                const undoneReviewLogId = data.review_log_id || data.action?.review_log_id || null;
                if (this.previousCardSnapshot?.action?.review_log_id === undoneReviewLogId) {
                    this.previousCardSnapshot.action.undoable = false;
                    this.previousCardSnapshot = { ...this.previousCardSnapshot };
                }
                const restoredCardId = data.restored_card ? data.restored_card.review_card_id : null;
                if (restoredCardId) {
                    const currentCardId = this.currentCard ? this.currentCard.review_card_id : null;
                    const nextHistory = this.previousNavigationTargetId === restoredCardId
                        ? moveNavigationBack(this.navigationHistory, restoredCardId, currentCardId)
                        : setNavigationCurrentCard(this.navigationHistory, restoredCardId);
                    this.navigationHistory = saveReviewNavigationHistory(nextHistory);
                }
                this.loadCards().then(() => {
                    if (restoredCardId) {
                        const idx = this.cards.findIndex((card) => card.review_card_id === restoredCardId);
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
                if (restoredCardId) {
                    this.session = SessionTracker.removeRating(this.session, restoredCardId);
                    if (this.reviewedCount > 0) {
                        this.reviewedCount--;
                    }
                }
                this.loadFsrsStats();
                this.showSnackbar('已撤销上一次评分，可以重新作答。', 'info');
            },
            handleHotkey(event) {
                // Ctrl/Cmd+Z follows the same back path as the visible
                // 返回 button. Ctrl/Cmd+Shift+Z follows the client-only
                // 前进 path; it never re-applies a rating.
                if ((event.ctrlKey || event.metaKey) && (event.key === 'z' || event.key === 'Z')) {
                    const tag = event.target?.tagName?.toLowerCase();
                    if (['input', 'textarea', 'select'].includes(tag) || event.target?.isContentEditable) {
                        return;
                    }
                    if (this.editDialog || this.deleteDialog || this.sourceDialog) {
                        return;
                    }
                    if (this.showSessionSummary || this.reviewExperienceBusy) {
                        return;
                    }
                    if (event.shiftKey) {
                        if (!this.forwardNavigationAvailable) return;
                        event.preventDefault();
                        this.goForwardCard();
                        return;
                    }
                    if (!this.previousNavigationAvailable) return;
                    event.preventDefault();
                    this.goPreviousCard('sense_review_hotkey');
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
                if (this.editDialog || this.deleteDialog || this.sourceDialog) {
                    return;
                }
                if (!this.currentCard || this.loading || this.rating || this.deleteLoading) {
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
            // ==================== Lifecycle state (ADR-0010) ====================
            // Ordinary review keeps only the read-only lifecycle indicator.
            // Thin wrappers exposing the pure presentation helpers to the template.
            // Vue 2 templates can only call functions registered on the instance.
            stateColor,
            stateLabel,
            fsrsStateLabel(state) {
                return {
                    new: '新卡',
                    learning: '学习中',
                    review: '复习中',
                    relearning: '重新学习',
                }[state] || state || '—';
            },
            // Fetch the lifecycle descriptor for the current card.
            // Called when the answer is revealed. Non-blocking: on failure,
            // the read-only state indicator simply stays hidden.
            fetchLifecycleDescriptor() {
                if (!this.currentCard) {
                    return;
                }
                axios.get(`/review-cards/${this.currentCard.review_card_id}/lifecycle`)
                    .then((response) => {
                        this.lifecycleDescriptor = response.data.lifecycle || null;
                    })
                    .catch(() => {
                        this.lifecycleDescriptor = null;
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
                        const message = response.data?.message || '已移入最近删除，30 天内可以恢复。';
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
                window.location.href = '/learning-history';
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
        }
    }
</script>

<style scoped>
    .sense-review-summary-bar {
        gap: 6px;
    }

    @media (max-width: 600px) {
        .sense-review-page {
            padding: 8px 8px calc(12px + env(safe-area-inset-bottom, 0px));
        }
        .sense-review-summary {
            margin-top: 4px !important;
            padding-right: 10px !important;
            padding-left: 10px !important;
        }
        .sense-review-summary-bar {
            align-items: flex-start !important;
        }
        .sense-review-summary-bar > .spacer {
            display: none;
        }
        .sense-review-summary-bar .v-chip {
            margin: 2px !important;
        }
        .sense-review-summary .v-btn {
            min-height: 44px;
            min-width: 44px;
        }
        .sense-review-card {
            padding: 14px 12px calc(14px + env(safe-area-inset-bottom, 0px)) !important;
            overflow: visible;
        }
        .mobile-reveal-button {
            width: 100%;
            min-height: 52px;
        }
    }
</style>
