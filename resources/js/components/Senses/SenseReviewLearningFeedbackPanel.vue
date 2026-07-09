<template>
    <!--
        SenseReviewLearningFeedbackPanel
        SenseReview-FeedbackPanel-1000-1

        Read-only display of the learning feedback aggregate for the
        current review card. Contains two collapsible blocks:
          1. 学习状态 — total reviews, recent 5 ratings, current stability,
             and a factual "容易忘记" hint when 2+ of recent 5 were 'again'.
          2. 遗忘情况 — recent review count, forget count, forget rate,
             and a factual trend label (improving / declining / stable /
             insufficient).

        Contract:
          - READ-ONLY: never calls any backend, never writes ReviewLog,
            never touches any FSRS field.
          - Only receives data via props; emits no events.
          - Owns its own open/close state (default collapsed).
          - The parent resets this component on card change by binding a
            :key to review_card_id, so the collapse state resets naturally.

        Empty-state handling:
          - total_reviews = 0 → the whole panel is hidden by the parent
            (hasLearningFeedback). The parent only renders this component
            when there is at least one review log.
          - 0 < total < 4 (trend='insufficient') → the 遗忘情况 block shows
            "复习次数较少,继续积累数据" instead of raw data, so a single
            'again' never looks like "容易忘记".
    -->
    <div class="mt-4" v-if="learningFeedback">
        <!-- 学习状态 (collapsible, default collapsed) -->
        <div>
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
                                :color="ratingColor(r.rating)"
                                class="mr-1 mb-1"
                                v-for="(r, i) in learningFeedback.recent_reviews"
                                :key="i"
                            >{{ r.rating_label }}</v-chip>
                        </div>
                    </div>
                    <div class="mb-2">
                        <span class="caption text--secondary">当前稳定度：</span>
                        <span class="body-2">{{ fsrsStability ? Math.round(fsrsStability) + ' 天' : '-' }}</span>
                    </div>
                    <div v-if="easyToForgetHint" class="body-2 warning--text">
                        {{ easyToForgetHint }}
                    </div>
                </div>
            </v-expand-transition>
        </div>

        <!-- 遗忘情况 (collapsible, default collapsed) -->
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
    </div>
</template>

<script>
    /**
     * SenseReviewLearningFeedbackPanel
     *
     * SenseReview-FeedbackPanel-1000-1
     *
     * Read-only presentational component extracted from SenseReview.vue.
     * Renders the learning feedback + forgetting pattern blocks that were
     * previously inline in the parent. Pure display: receives data via
     * props, emits no events, owns only its own collapse state.
     *
     * Why a separate component:
     *  - Keeps the feedback display logic (labels, colors, empty-state
     *    hints, trend mapping) in one place instead of bloating the parent.
     *  - The parent no longer needs ~80 lines of computed properties for
     *    feedback/trend display.
     *  - Makes it impossible for the display to accidentally trigger a
     *    network call or ReviewLog write (no axios, no emit to parent).
     */
    export default {
        name: 'SenseReviewLearningFeedbackPanel',
        props: {
            // The full learning_feedback object from the serialized card.
            // Shape: { total_reviews, forget_count, hard_count, good_count,
            //         easy_count, recent_reviews: [{rating, rating_label, date}],
            //         recent_forget_count, forgetting_pattern: {...} }
            // Can be null when the card has no learning_feedback; the parent
            // should gate rendering with v-if in that case.
            learningFeedback: {
                type: Object,
                default: null,
            },
            // Current card's fsrs_stability (number) used for the
            // "当前稳定度" display. Passed separately so this component
            // does not need to know about the full card structure.
            fsrsStability: {
                type: Number,
                default: null,
            },
        },
        data: function() {
            return {
                // Default collapsed; reset naturally because the parent
                // binds :key to review_card_id, recreating this component
                // on each card change.
                learningFeedbackOpen: false,
                forgettingPatternOpen: false,
            };
        },
        computed: {
            // forgetting_pattern sub-object, null-safe.
            forgettingPattern() {
                if (!this.learningFeedback || !this.learningFeedback.forgetting_pattern) {
                    return null;
                }
                return this.learningFeedback.forgetting_pattern;
            },
            // "容易忘记" hint: when 2+ of the recent 5 reviews were 'again',
            // surface a concise factual hint. Only states facts (counts),
            // never guesses causes and never calls AI.
            easyToForgetHint() {
                if (!this.learningFeedback) {
                    return '';
                }
                const fb = this.learningFeedback;
                if (fb.recent_forget_count >= 2) {
                    return '过去 ' + fb.recent_reviews.length + ' 次复习中 ' + fb.recent_forget_count + ' 次选择 忘了';
                }
                return '';
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
        },
        methods: {
            // Map a rating value to the chip color used in the recent
            // reviews list. Kept in sync with the rating buttons.
            ratingColor(rating) {
                if (rating === 'again') return 'error';
                if (rating === 'hard') return 'warning';
                if (rating === 'easy') return 'success';
                return 'primary';
            },
        },
    }
</script>
