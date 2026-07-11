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
                <v-chip class="mr-1">{{ currentCard.fsrs_state }}</v-chip>
                <v-chip>{{ currentCard.fsrs_reps }} 次</v-chip>
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
                    :disabled="rating || archiveLoading || deleteLoading || resetLoading"
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
                            <v-list-item @click="openArchiveDialog">
                                <v-list-item-icon><v-icon small color="warning">mdi-archive</v-icon></v-list-item-icon>
                                <v-list-item-title>归档</v-list-item-title>
                            </v-list-item>
                            <v-list-item @click="openResetDialog">
                                <v-list-item-icon><v-icon small>mdi-restore</v-icon></v-list-item-icon>
                                <v-list-item-title>重置</v-list-item-title>
                            </v-list-item>
                            <v-divider class="my-1" />
                            <v-list-item @click="openDeleteDialog">
                                <v-list-item-icon><v-icon small color="error">mdi-delete</v-icon></v-list-item-icon>
                                <v-list-item-title class="error--text">彻底删除</v-list-item-title>
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
                    :disabled="rating || archiveLoading || deleteLoading || resetLoading"
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

        <!-- Archive confirmation dialog -->
        <v-dialog v-model="archiveDialog" max-width="480">
            <v-card>
                <v-card-title>确认归档</v-card-title>
                <v-card-text>
                    归档后，这张词义卡不会进入日常复习，但释义、例句、复习历史都会保留。确定归档吗？
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="archiveDialog = false" :disabled="archiveLoading">取消</v-btn>
                    <v-btn color="warning" :loading="archiveLoading" @click="archiveCard">确认归档</v-btn>
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
                    <p>重置后，这张卡会立即重新进入复习队列。</p>
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
    import { normalizeIntervalPreview } from './SenseReviewIntervalPresentation.js';

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
                // Archive dialog
                archiveDialog: false,
                archiveLoading: false,
                // Reset dialog
                resetDialog: false,
                resetLoading: false,
                // Delete dialog
                deleteDialog: false,
                deleteLoading: false,
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
            }
        },
        computed: {
            currentCard() {
                return this.cards.length ? this.cards[0] : null;
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
        },
        watch: {
            // When the answer is revealed, fetch the interval preview for
            // the current card. Preview is NEVER fetched before the answer
            // is shown. Preview failure does not block rating.
            showAnswer(val) {
                if (val && this.currentCard) {
                    this.loadIntervalPreview();
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
                }
            },
        },
        beforeDestroy() {
            window.removeEventListener('keyup', this.handleHotkey);
        },
        mounted() {
            this.loadCards();
            this.loadFsrsStats();
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
                axios.get('/reviews/senses', { params: params }).then((response) => {
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
            handleHotkey(event) {
                // When the session summary is shown, Space and 1/2/3/4
                // must NOT trigger show-answer or rating.
                if (this.showSessionSummary) {
                    return;
                }
                const tag = event.target?.tagName?.toLowerCase();
                if (['input', 'textarea', 'select'].includes(tag) || event.target?.isContentEditable) {
                    return;
                }
                if (this.editDialog || this.archiveDialog || this.resetDialog || this.deleteDialog || this.sourceDialog) {
                    return;
                }
                if (!this.currentCard || this.loading || this.rating || this.archiveLoading || this.resetLoading || this.deleteLoading) {
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
            // ==================== Archive ====================
            openArchiveDialog() {
                if (!this.currentCard) {
                    return;
                }
                this.archiveDialog = true;
            },
            archiveCard() {
                if (!this.currentCard) {
                    return;
                }

                this.archiveLoading = true;
                axios.patch(`/review-cards/manage/${this.currentCard.review_card_id}/enabled`, {
                    enabled: false,
                }).then(() => {
                    this.archiveDialog = false;
                    this.showSnackbar('已归档。该卡不会进入日常复习。', 'success');
                    this.loadCards();
                    this.loadFsrsStats();
                }).catch((err) => {
                    this.showSnackbar(err.response?.data?.message || '归档失败。', 'error');
                }).finally(() => {
                    this.archiveLoading = false;
                });
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
        }
    }
</script>

<style scoped>
    .sense-main {
        font-size: 24px;
        font-weight: 600;
    }
</style>
