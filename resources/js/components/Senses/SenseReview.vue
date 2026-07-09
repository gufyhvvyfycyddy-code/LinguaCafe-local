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
            <!-- SenseReview-SessionSummary-1000-1: explicit "end session"
                 button. Only visible after the user has rated at least one
                 card AND the summary is not already shown. Clicking it
                 does NOT write ReviewLog or touch FSRS. -->
            <div v-if="hasReviewed && !showSessionSummary" class="text-center mt-3">
                <v-btn small text color="primary" @click="endSession">结束本次复习</v-btn>
            </div>
        </v-card>

        <v-alert v-if="error" type="error" dense outlined>{{ error }}</v-alert>

        <!-- SenseReview-SessionSummary-1000-1: session summary view.
             Shown when the user explicitly ends the session OR when the
             queue naturally drains after at least one rating. Mutually
             exclusive with the review-card view. -->
        <SenseReviewSessionSummary
            v-if="showSummaryView"
            :stats="sessionStats"
            :has-more-cards="remainingCount > 0"
            @continue-review="continueReview"
            @exit-review="exitReview"
        />

        <v-card v-if="currentCard && !showSummaryView" outlined class="rounded-lg pa-5">
            <!-- Lemma / surface form / pos — always visible -->
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

            <!-- Question side (always visible, shows context) -->
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

            <!-- Show answer button (visible when showAnswer=false) -->
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

            <!-- Answer side (visible when showAnswer=true) -->
            <template v-if="showAnswer">
                <!-- Action buttons in More menu -->
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

                        <!-- Sense-level + occurrence-level merged understanding
                             aid (collapsible, read-only).
                             SenseReviewUnderstandingAid-1000-7 +
                             SenseReviewContextualUnderstanding-1000-10.
                             Default collapsed; user-initiated viewing only.
                             Does NOT trigger any network call, ReviewLog write,
                             or FSRS change. Only renders when at least one
                             sub-field has content. Occurrence-level evidence
                             (context_hint / judgment_basis / related_collocations)
                             overrides sense-level values for the same keys, so
                             the aid follows the currently-displayed occurrence. -->
                        <div v-if="hasUnderstandingAid" class="mt-4">
                            <div
                                class="caption text--secondary d-flex align-center"
                                style="cursor: pointer;"
                                @click="understandingAidOpen = !understandingAidOpen"
                            >
                                <v-icon small class="mr-1">{{ understandingAidOpen ? 'mdi-chevron-down' : 'mdi-chevron-right' }}</v-icon>
                                理解这个词义
                            </div>
                            <v-expand-transition>
                                <div v-if="understandingAidOpen" class="mt-2">
                                    <div v-if="understandingAid.explanation" class="body-2 mb-2">
                                        {{ understandingAid.explanation }}
                                    </div>
                                    <div v-if="understandingAid.meaning_boundary" class="mb-2">
                                        <span class="caption text--secondary">词义边界：</span>
                                        <span class="body-2">{{ understandingAid.meaning_boundary }}</span>
                                    </div>
                                    <div v-if="understandingAid.context_hint" class="mb-2">
                                        <span class="caption text--secondary">上下文提示：</span>
                                        <span class="body-2">{{ understandingAid.context_hint }}</span>
                                    </div>
                                    <div v-if="understandingAid.usage_keywords && understandingAid.usage_keywords.length">
                                        <span class="caption text--secondary">判断依据：</span>
                                        <div class="mt-1">
                                            <v-chip
                                                small
                                                class="mr-1 mb-1"
                                                v-for="kw in understandingAid.usage_keywords"
                                                :key="kw"
                                            >{{ kw }}</v-chip>
                                        </div>
                                    </div>
                                    <div v-if="understandingAid.related_collocations && understandingAid.related_collocations.length" class="mt-2">
                                        <span class="caption text--secondary">类似使用：</span>
                                        <div class="mt-1">
                                            <v-chip
                                                small
                                                outlined
                                                class="mr-1 mb-1"
                                                v-for="col in understandingAid.related_collocations"
                                                :key="col"
                                            >{{ col }}</v-chip>
                                        </div>
                                    </div>
                                </div>
                            </v-expand-transition>
                        </div>

                        <!-- SenseReview-LearningFeedback-1000-1: read-only
                             learning feedback (collapsible, default collapsed).
                             Shows total reviews, recent 5 performance breakdown,
                             current stability (reuses fsrs_stability), and a
                             factual "容易忘记" hint when 2+ of recent 5 were
                             'again'. Only states facts from ReviewLog; never
                             calls AI, never guesses causes. Does NOT trigger
                             any network call, ReviewLog write, or FSRS change.
                             Only renders when the card has >= 1 review log so
                             first reviews stay uncluttered. -->
                        <div v-if="hasLearningFeedback" class="mt-4">
                            <div
                                class="caption text--secondary d-flex align-center"
                                style="cursor: pointer;"
                                @click="learningFeedbackOpen = !learningFeedbackOpen"
                            >
                                <v-icon small class="mr-1">{{ learningFeedbackOpen ? 'mdi-chevron-down' : 'mdi-chevron-right' }}</v-icon>
                                学习状态
                            </div>
                            <v-expand-transition>
                                <div v-if="learningFeedbackOpen" class="mt-2">
                                    <div class="body-2 mb-2">
                                        已复习 {{ learningFeedback.total_reviews }} 次
                                    </div>
                                    <div v-if="learningFeedback.recent_reviews.length" class="mb-2">
                                        <span class="caption text--secondary">最近 {{ learningFeedback.recent_reviews.length }} 次表现：</span>
                                        <div class="mt-1">
                                            <v-chip
                                                small
                                                :color="r.rating === 'again' ? 'error' : (r.rating === 'hard' ? 'warning' : (r.rating === 'easy' ? 'success' : 'primary'))"
                                                class="mr-1 mb-1"
                                                v-for="(r, i) in learningFeedback.recent_reviews"
                                                :key="i"
                                            >{{ r.rating_label }}</v-chip>
                                        </div>
                                    </div>
                                    <div class="mb-2">
                                        <span class="caption text--secondary">当前稳定度：</span>
                                        <span class="body-2">{{ currentCard.fsrs_stability ? Math.round(currentCard.fsrs_stability) + ' 天' : '-' }}</span>
                                    </div>
                                    <div v-if="easyToForgetHint" class="body-2 warning--text">
                                        {{ easyToForgetHint }}
                                    </div>
                                </div>
                            </v-expand-transition>
                        </div>

                        <!-- SenseReview-ForgettingPattern-1000-3: read-only
                             forgetting-pattern block (collapsible, default
                             collapsed). Sits below the learning-status block.
                             Empty-state handling:
                               - 0 < total < 4 (trend='insufficient') → shows
                                 "复习次数较少,继续积累数据" instead of raw data,
                                 so a single 'again' never looks like "容易忘记".
                               - total >= 4 → shows recent review count, forget
                                 count, forget rate, and a factual trend label.
                             Read-only: opening it triggers no network call,
                             no ReviewLog write, no FSRS change. Does NOT affect
                             show-answer button, rating buttons, hotkeys, More
                             menu, or view-source. -->
                        <div class="mt-2">
                            <div
                                class="caption text--secondary d-flex align-center"
                                style="cursor: pointer;"
                                @click="forgettingPatternOpen = !forgettingPatternOpen"
                            >
                                <v-icon small class="mr-1">{{ forgettingPatternOpen ? 'mdi-chevron-down' : 'mdi-chevron-right' }}</v-icon>
                                遗忘情况
                            </div>
                            <v-expand-transition>
                                <div v-if="forgettingPatternOpen" class="mt-2">
                                    <div v-if="forgettingEmptyHint" class="body-2 text--secondary">
                                        {{ forgettingEmptyHint }}
                                    </div>
                                    <div v-else>
                                        <div class="body-2 mb-1">
                                            <span class="caption text--secondary">最近复习：</span>
                                            {{ learningFeedback.recent_reviews.length }} 次
                                        </div>
                                        <div class="body-2 mb-1">
                                            <span class="caption text--secondary">忘记：</span>
                                            {{ forgettingPattern.total_forget }} 次
                                        </div>
                                        <div class="body-2 mb-1">
                                            <span class="caption text--secondary">遗忘率：</span>
                                            {{ Math.round(forgettingPattern.forget_rate * 100) }}%
                                        </div>
                                        <div class="body-2">
                                            <span class="caption text--secondary">趋势：</span>
                                            <span :class="forgettingTrendColor">{{ forgettingTrendLabel }}</span>
                                        </div>
                                    </div>
                                </div>
                            </v-expand-transition>
                        </div>
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

                <div class="text-center caption grey--text mb-2">
                    快捷键：1 忘了 / 2 勉强 / 3 记得 / 4 很熟
                </div>

                <!-- Score buttons -->
                <div class="d-flex justify-center flex-wrap mt-6">
                    <v-btn depressed rounded color="error" class="ma-2" :disabled="rating || archiveLoading || deleteLoading || resetLoading" @click="rate('again')">忘了</v-btn>
                    <v-btn depressed rounded color="warning" class="ma-2" :disabled="rating || archiveLoading || deleteLoading || resetLoading" @click="rate('hard')">勉强记得</v-btn>
                    <v-btn depressed rounded color="primary" class="ma-2" :disabled="rating || archiveLoading || deleteLoading || resetLoading" @click="rate('good')">记得</v-btn>
                    <v-btn depressed rounded color="success" class="ma-2" :disabled="rating || archiveLoading || deleteLoading || resetLoading" @click="rate('easy')">很熟</v-btn>
                </div>
            </template>
        </v-card>

        <v-alert v-else-if="!loading && !showSummaryView" type="info" dense outlined>
            当前没有到期词义卡。
        </v-alert>

        <!-- Edit dialog -->
        <v-dialog v-model="editDialog" max-width="600">
            <v-card>
                <v-card-title>编辑词义卡片</v-card-title>
                <v-card-text>
                    <v-row dense>
                        <v-col cols="6">
                            <v-text-field
                                v-model="editForm.pos"
                                label="词性"
                                dense
                                hide-details="auto"
                                class="mb-3"
                            />
                        </v-col>
                        <v-col cols="6">
                            <v-text-field
                                v-model="editForm.sense_zh"
                                label="中文释义"
                                dense
                                hide-details="auto"
                                class="mb-3"
                            />
                        </v-col>
                        <v-col cols="12">
                            <v-text-field
                                v-model="editForm.sense_en"
                                label="英文释义"
                                dense
                                hide-details="auto"
                                class="mb-3"
                            />
                        </v-col>
                        <v-col cols="12">
                            <v-textarea
                                v-model="editForm.example_sentence_en"
                                label="英文例句"
                                dense
                                hide-details="auto"
                                rows="2"
                                class="mb-3"
                            />
                        </v-col>
                        <v-col cols="12">
                            <v-text-field
                                v-model="editForm.example_sentence_zh"
                                label="中文例句"
                                dense
                                hide-details="auto"
                                class="mb-3"
                            />
                        </v-col>
                        <v-col cols="12">
                            <v-text-field
                                v-model="editForm.aliases_zh_text"
                                label="近义译法（逗号分隔）"
                                dense
                                hide-details="auto"
                                class="mb-3"
                            />
                        </v-col>
                        <v-col cols="12">
                            <v-text-field
                                v-model="editForm.collocations_text"
                                label="搭配（逗号分隔）"
                                dense
                                hide-details="auto"
                                class="mb-3"
                            />
                        </v-col>
                    </v-row>
                    <v-alert v-if="editError" type="error" dense outlined class="mt-2">{{ editError }}</v-alert>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="cancelEdit">取消</v-btn>
                    <v-btn color="primary" :loading="editing" @click="saveEdit">保存</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

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
    import * as SessionTracker from './SenseReviewSessionTracker.js';

    export default {
        components: {
            SenseExampleDialog,
            SenseReviewSessionSummary,
        },
        data: function() {
            return {
                loading: false,
                rating: false,
                error: '',
                cards: [],
                summary: {},
                reviewedCount: 0,
                // Edit dialog
                editDialog: false,
                editing: false,
                editError: '',
                editForm: {
                    pos: '',
                    sense_zh: '',
                    sense_en: '',
                    example_sentence_en: '',
                    example_sentence_zh: '',
                    aliases_zh_text: '',
                    collocations_text: '',
                },
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
                // UI-Review-a
                statsDetailOpen: false,
                fsrsDetailOpen: false,
                // SenseReviewUnderstandingAid-1000-7: understanding aid collapse.
                // Default collapsed; reset on card change in loadCards().
                understandingAidOpen: false,
                // SenseReview-LearningFeedback-1000-1: learning feedback collapse.
                // Default collapsed; reset on card change in loadCards(). Read-only:
                // opening it triggers no network call, no ReviewLog write, no FSRS change.
                learningFeedbackOpen: false,
                // SenseReview-ForgettingPattern-1000-3: forgetting pattern collapse.
                // Default collapsed; reset on card change in loadCards(). Read-only.
                forgettingPatternOpen: false,
                showAnswer: false,
                // Whether the user is in "ignore daily limits" mode (over-limit review)
                ignoreDailyLimits: false,
                // SenseReview-SessionSummary-1000-1: this-session summary.
                // Tracks ratings completed on the CURRENT page load only.
                // Reset on page refresh (no persistence). Clicking "结束本次
                // 复习", viewing the summary, or expanding blocks never writes
                // ReviewLog and never touches FSRS. Only real user ratings
                // (via rate()) are recorded.
                session: SessionTracker.createSession(),
                showSessionSummary: false,
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
                // Defensive: never show a supplementary example that duplicates
                // the question example (backend guarantees this, but we guard
                // against regressions here too).
                if (supp.sentence_en === this.currentCard.example_sentence_en) {
                    return null;
                }
                return supp;
            },
            // SenseReviewUnderstandingAid-1000-7 +
            // SenseReviewContextualUnderstanding-1000-10: sense-level +
            // occurrence-level merged understanding aid. Backend always returns
            // a normalized structure (explanation, meaning_boundary,
            // context_hint, usage_keywords, related_collocations) with null/
            // empty defaults when the column is empty, so this is null-safe.
            // Occurrence-level evidence overrides sense-level values for
            // matching keys, so the aid follows the displayed occurrence.
            understandingAid() {
                if (!this.currentCard || !this.currentCard.understanding_aid) {
                    return null;
                }
                return this.currentCard.understanding_aid;
            },
            // Only render the collapsible block when at least one sub-field has
            // content. Empty sense+occurrence (all null/[]) hides the block.
            hasUnderstandingAid() {
                if (!this.understandingAid) {
                    return false;
                }
                return !!(
                    this.understandingAid.explanation ||
                    this.understandingAid.meaning_boundary ||
                    this.understandingAid.context_hint ||
                    (Array.isArray(this.understandingAid.usage_keywords) && this.understandingAid.usage_keywords.length) ||
                    (Array.isArray(this.understandingAid.related_collocations) && this.understandingAid.related_collocations.length)
                );
            },
            // SenseReview-LearningFeedback-1000-1: read-only learning feedback
            // aggregate from ReviewLog. Backend always returns a stable shape
            // (total_reviews / forget_count / hard_count / good_count /
            // easy_count / recent_reviews[] / recent_forget_count), so this
            // is null-safe. Opening this block does NOT trigger any network
            // call, ReviewLog write, or FSRS change.
            learningFeedback() {
                if (!this.currentCard || !this.currentCard.learning_feedback) {
                    return null;
                }
                return this.currentCard.learning_feedback;
            },
            // Only render the collapsible block when the card has at least one
            // review log. A brand-new card (total_reviews=0) hides the block
            // to avoid cluttering the first review.
            hasLearningFeedback() {
                if (!this.learningFeedback) {
                    return false;
                }
                return this.learningFeedback.total_reviews > 0;
            },
            // "容易忘记" hint: when 2+ of the recent 5 reviews were 'again',
            // surface a concise factual hint. Only states facts (counts),
            // never guesses causes and never calls AI.
            easyToForgetHint() {
                if (!this.hasLearningFeedback) {
                    return '';
                }
                const fb = this.learningFeedback;
                if (fb.recent_forget_count >= 2) {
                    return '过去 ' + fb.recent_reviews.length + ' 次复习中 ' + fb.recent_forget_count + ' 次选择 忘了';
                }
                return '';
            },
            // SenseReview-ForgettingPattern-1000-3: read-only forgetting-pattern
            // block. Backend always returns learning_feedback.forgetting_pattern
            // with a stable shape. Opening this block does NOT trigger any
            // network call, ReviewLog write, or FSRS change.
            forgettingPattern() {
                if (!this.learningFeedback || !this.learningFeedback.forgetting_pattern) {
                    return null;
                }
                return this.learningFeedback.forgetting_pattern;
            },
            // Empty-state hint shown INSTEAD of the full data block when there
            // is not enough data to render a meaningful forgetting analysis:
            //   - no reviews at all → "暂无复习记录"
            //   - reviews exist but trend is 'insufficient' (<4 reviews) →
            //     "复习次数较少,继续积累数据"
            // Returning '' means "data is sufficient, render the full block".
            forgettingEmptyHint() {
                const fb = this.learningFeedback;
                if (!fb || fb.total_reviews === 0) {
                    return '暂无复习记录';
                }
                const fp = this.forgettingPattern;
                if (!fp || fp.trend === 'insufficient') {
                    return '复习次数较少,继续积累数据';
                }
                return '';
            },
            hasForgettingData() {
                return this.forgettingEmptyHint === '' && !!this.forgettingPattern;
            },
            forgettingTrendLabel() {
                const t = this.forgettingPattern ? this.forgettingPattern.trend : '';
                return {
                    improving: '正在改善',
                    declining: '正在下降',
                    stable: '稳定',
                    insufficient: '数据不足',
                }[t] || '';
            },
            forgettingTrendColor() {
                const t = this.forgettingPattern ? this.forgettingPattern.trend : '';
                if (t === 'improving') {
                    return 'success--text';
                }
                if (t === 'declining') {
                    return 'error--text';
                }
                return 'text--secondary';
            },
            // SenseReview-SessionSummary-1000-1: session summary computed.
            // sessionStats is the aggregate of all ratings in this page
            // session. hasReviewed gates the summary (no fake "0 张" page).
            // showSummaryView gates the full-screen summary overlay.
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
                    this.fsrsDetailOpen = false;  // Reset FSRS collapse on card change
                    this.understandingAidOpen = false;  // SenseReviewUnderstandingAid-1000-7: reset aid collapse on card change
                    this.learningFeedbackOpen = false;  // SenseReview-LearningFeedback-1000-1: reset feedback collapse on card change
                    this.forgettingPatternOpen = false;  // SenseReview-ForgettingPattern-1000-3: reset forgetting collapse on card change
                    this.showAnswer = false;
                    // SenseReview-SessionSummary-1000-1: when the queue
                    // naturally drains AND the user has reviewed at least
                    // one card this session, auto-show the summary. When
                    // the user has not reviewed anything, keep the existing
                    // empty-state alert ("当前没有到期词义卡。") so we never
                    // show a fake "本次复习 0 张" summary.
                    if (this.cards.length === 0 && this.hasReviewed && !this.showSessionSummary) {
                        this.showSessionSummary = true;
                    }
                }).catch((error) => {
                    this.error = error.response?.data?.message || '词义复习队列加载失败。';
                }).finally(() => {
                    this.loading = false;
                });
            },
            rate(rating) {
                if (!this.currentCard) {
                    return;
                }

                this.rating = true;
                this.error = '';
                const payload = { rating: rating };
                if (this.ignoreDailyLimits) {
                    payload.ignoreDailyLimits = true;
                }
                // SenseReview-SessionSummary-1000-1: generate a unique
                // requestId per rate() call. The tracker dedupes by this
                // id so a double-click or accidental re-submit cannot
                // inflate the session stats. Only recorded AFTER the
                // backend confirms success.
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
                    // reviewed_card from the response carries the fresh
                    // forgetting_pattern (post-rating trend), which the
                    // tracker uses for the "declining" needs-attention rule.
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
            // Enter "ignore daily limits" mode so all due cards become visible
            continueOverLimit() {
                this.ignoreDailyLimits = true;
                this.loadCards();
            },
            // Return to the default daily-limit-enforced queue
            restoreLimits() {
                this.ignoreDailyLimits = false;
                this.loadCards();
            },
            // UI-Review-c: keyboard shortcuts
            handleHotkey(event) {
                // SenseReview-SessionSummary-1000-1: when the session summary
                // is shown, Space and 1/2/3/4 must NOT trigger show-answer or
                // rating. The user must explicitly close the summary first.
                if (this.showSessionSummary) {
                    return;
                }
                // Ignore when typing in input/textarea/select
                const tag = event.target?.tagName?.toLowerCase();
                if (['input', 'textarea', 'select'].includes(tag) || event.target?.isContentEditable) {
                    return;
                }
                // Ignore when dialogs are open
                if (this.editDialog || this.archiveDialog || this.resetDialog || this.deleteDialog || this.sourceDialog) {
                    return;
                }
                // Ignore when no card or loading
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
            startEdit() {
                if (!this.currentCard) {
                    return;
                }

                this.editError = '';
                this.editForm = {
                    pos: this.currentCard.pos || '',
                    sense_zh: this.currentCard.sense_zh || '',
                    sense_en: this.currentCard.sense_en || '',
                    example_sentence_en: this.currentCard.example_sentence_en || '',
                    example_sentence_zh: this.currentCard.example_sentence_zh || '',
                    aliases_zh_text: Array.isArray(this.currentCard.aliases_zh)
                        ? this.currentCard.aliases_zh.join(', ')
                        : '',
                    collocations_text: Array.isArray(this.currentCard.collocations)
                        ? this.currentCard.collocations.join(', ')
                        : '',
                };
                this.editDialog = true;
            },
            saveEdit() {
                if (!this.currentCard) {
                    return;
                }

                this.editing = true;
                this.editError = '';

                // Build payload: normalize comma-separated text fields to arrays
                const payload = {
                    pos: this.editForm.pos,
                    sense_zh: this.editForm.sense_zh,
                    sense_en: this.editForm.sense_en,
                    example_sentence_en: this.editForm.example_sentence_en,
                    example_sentence_zh: this.editForm.example_sentence_zh,
                    aliases_zh: this.editForm.aliases_zh_text
                        .split(',')
                        .map(s => s.trim())
                        .filter(s => s !== ''),
                    collocations: this.editForm.collocations_text
                        .split(',')
                        .map(s => s.trim())
                        .filter(s => s !== ''),
                };

                axios.patch(`/review-cards/manage/${this.currentCard.review_card_id}`, payload)
                    .then((response) => {
                        // Update current card with saved data
                        const saved = response.data;
                        this.cards[0].pos = saved.pos;
                        this.cards[0].sense_zh = saved.sense_zh;
                        this.cards[0].sense_en = saved.sense_en;
                        this.cards[0].example_sentence_en = saved.example_sentence_en;
                        this.cards[0].example_sentence_zh = saved.example_sentence_zh;
                        this.cards[0].aliases_zh = saved.aliases_zh || [];
                        this.cards[0].collocations = saved.collocations || [];
                        // Force reactivity
                        this.cards = [...this.cards];
                        this.editDialog = false;
                        this.showSnackbar('已保存词义卡片。', 'success');
                    })
                    .catch((err) => {
                        this.editError = err.response?.data?.message || '词义卡片保存失败。';
                    })
                    .finally(() => {
                        this.editing = false;
                    });
            },
            cancelEdit() {
                this.editDialog = false;
                this.editError = '';
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

                // SenseSourceContextFollowDisplayedOccurrence-1000-7:
                // Pass the occurrence currently shown on the review card so
                // the backend can place it at sources[0]. The id is strictly
                // validated server-side; on failure the backend falls back
                // to the original multi-source list and reports the outcome
                // via preferred_occurrence_status.
                const params = {};
                if (this.currentCard.displayed_occurrence_id) {
                    params.preferred_occurrence_id = this.currentCard.displayed_occurrence_id;
                }

                axios.get(`/senses/${this.currentCard.word_sense_id}/source-context-list`, { params: params })
                    .then((response) => {
                        const data = response.data || {};
                        const sources = Array.isArray(data.sources) ? data.sources : [];
                        // First source is the primary; older single-context shape
                        // is preserved as `context` for backward compatibility.
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
            // SenseReview-SessionSummary-1000-1: user explicitly ends the
            // session. Only allowed when at least one card has been rated
            // (no fake "0 张" summary). Does NOT write ReviewLog, does NOT
            // touch FSRS — only flips a local UI flag.
            endSession() {
                if (!this.hasReviewed) {
                    return;
                }
                this.showSessionSummary = true;
            },
            // Continue reviewing: close the summary and go back to the
            // remaining queue. Only meaningful when cards remain.
            continueReview() {
                this.showSessionSummary = false;
            },
            // Exit to the review-card management page. Uses a real
            // navigation so the page session is naturally discarded.
            exitReview() {
                window.location.href = '/review-cards/manage';
            },
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
