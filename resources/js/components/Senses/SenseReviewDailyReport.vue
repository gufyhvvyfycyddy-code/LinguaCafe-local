<template>
    <v-card outlined class="rounded-lg pa-5">
        <div class="d-flex align-center mb-4">
            <div class="text-h5">今日学习日报</div>
            <v-spacer></v-spacer>
            <v-btn icon small @click="$emit('close')">
                <v-icon>mdi-close</v-icon>
            </v-btn>
        </div>

        <div class="caption text--secondary mb-4">
            今天（{{ report.day }}，{{ report.timezone }}）
        </div>

        <div v-if="report.overview.total_reviews === 0" class="body-1 text--secondary text-center py-6">
            今天还没有完成词义卡复习。
        </div>

        <template v-else>
            <!-- 第一块：今日复习概览 -->
            <div class="mb-5">
                <div class="text-h6 mb-2">今日复习概览</div>
                <v-row dense>
                    <v-col cols="6" md="2">
                        <v-card outlined class="pa-3 text-center">
                            <div class="text-h5 primary--text">{{ report.overview.total_reviews }}</div>
                            <div class="caption text--secondary">复习次数</div>
                        </v-card>
                    </v-col>
                    <v-col cols="6" md="2">
                        <v-card outlined class="pa-3 text-center">
                            <div class="text-h5 primary--text">{{ report.overview.distinct_senses }}</div>
                            <div class="caption text--secondary">不同词义</div>
                        </v-card>
                    </v-col>
                    <v-col cols="6" md="2">
                        <v-card outlined class="pa-3 text-center">
                            <div class="text-h5 info--text">{{ report.overview.first_review_senses }}</div>
                            <div class="caption text--secondary">首次复习</div>
                        </v-card>
                    </v-col>
                    <v-col cols="6" md="2">
                        <v-card outlined class="pa-3 text-center">
                            <div class="text-h5 info--text">{{ report.overview.review_again_senses }}</div>
                            <div class="caption text--secondary">再次复习</div>
                        </v-card>
                    </v-col>
                    <v-col cols="6" md="2">
                        <v-card outlined class="pa-3 text-center">
                            <div class="text-h5 success--text">{{ averageRatingText }}</div>
                            <div class="caption text--secondary">平均评分</div>
                        </v-card>
                    </v-col>
                </v-row>
            </div>

            <v-divider class="mb-4" />

            <!-- 第二块：今日学习质量 -->
            <div class="mb-5">
                <div class="text-h6 mb-2">今日学习质量</div>
                <v-row dense class="mb-2">
                    <v-col cols="6" md="3">
                        <v-card outlined class="pa-3 text-center">
                            <div class="text-h5 error--text">{{ report.quality.distribution.again }}</div>
                            <div class="caption text--secondary">忘了</div>
                        </v-card>
                    </v-col>
                    <v-col cols="6" md="3">
                        <v-card outlined class="pa-3 text-center">
                            <div class="text-h5 warning--text">{{ report.quality.distribution.hard }}</div>
                            <div class="caption text--secondary">勉强记得</div>
                        </v-card>
                    </v-col>
                    <v-col cols="6" md="3">
                        <v-card outlined class="pa-3 text-center">
                            <div class="text-h5 primary--text">{{ report.quality.distribution.good }}</div>
                            <div class="caption text--secondary">记得</div>
                        </v-card>
                    </v-col>
                    <v-col cols="6" md="3">
                        <v-card outlined class="pa-3 text-center">
                            <div class="text-h5 success--text">{{ report.quality.distribution.easy }}</div>
                            <div class="caption text--secondary">很熟</div>
                        </v-card>
                    </v-col>
                </v-row>
                <div class="body-2 d-flex justify-space-around flex-wrap">
                    <div class="ma-2">
                        <span class="text--secondary">今日遗忘率：</span>
                        <span class="font-weight-medium" :class="forgetRateClass">{{ forgetRateText }}</span>
                    </div>
                    <div class="ma-2">
                        <span class="text--secondary">今日稳定率：</span>
                        <span class="font-weight-medium" :class="stabilityRateClass">{{ stabilityRateText }}</span>
                    </div>
                </div>
            </div>

            <v-divider class="mb-4" />

            <!-- 第三块：今日重点词义 -->
            <div v-if="report.focus_senses.length" class="mb-5">
                <div class="text-h6 mb-2">今日重点词义</div>
                <v-list outlined dense class="rounded-lg">
                    <v-list-item
                        v-for="(item, i) in report.focus_senses"
                        :key="i"
                    >
                        <v-list-item-content>
                            <v-list-item-title>
                                <span class="font-weight-medium">{{ item.lemma }}</span>
                                <span class="text--secondary ml-2">{{ item.sense_zh }}</span>
                            </v-list-item-title>
                            <v-list-item-subtitle>
                                <v-chip
                                    x-small
                                    :color="ratingColor(item.last_rating)"
                                    class="mr-2"
                                >{{ ratingLabel(item.last_rating) }}</v-chip>
                                <span class="text-caption text--secondary">
                                    今日 {{ item.total }} 次
                                    <span v-if="item.again > 0" class="error--text">· 忘 {{ item.again }}</span>
                                    <span v-if="item.hard > 0" class="warning--text">· 勉强记得 {{ item.hard }}</span>
                                </span>
                            </v-list-item-subtitle>
                        </v-list-item-content>
                    </v-list-item>
                </v-list>
            </div>

            <v-divider v-if="report.focus_senses.length" class="mb-4" />

            <!-- 第四块：今日进步记录 -->
            <div v-if="report.progress_senses.length" class="mb-3">
                <div class="text-h6 mb-2">今日进步记录</div>
                <v-list outlined dense class="rounded-lg">
                    <v-list-item
                        v-for="(item, i) in report.progress_senses"
                        :key="i"
                    >
                        <v-list-item-content>
                            <v-list-item-title>
                                <span class="font-weight-medium">{{ item.lemma }}</span>
                                <span class="text--secondary ml-2">{{ item.sense_zh }}</span>
                            </v-list-item-title>
                            <v-list-item-subtitle>
                                <v-chip x-small :color="ratingColor(item.from_rating)" class="mr-1">{{ ratingLabel(item.from_rating) }}</v-chip>
                                <v-icon x-small class="mx-1">mdi-arrow-right</v-icon>
                                <v-chip x-small :color="ratingColor(item.to_rating)" class="ml-1">{{ ratingLabel(item.to_rating) }}</v-chip>
                            </v-list-item-subtitle>
                        </v-list-item-content>
                    </v-list-item>
                </v-list>
            </div>

            <div v-if="!report.progress_senses.length" class="body-2 text--secondary text-center pa-3">
                今天还没有出现明显的进步转变（如 忘了→记得、困难→很熟）。
            </div>

            <v-divider class="my-4" />

            <!-- 第五块：今日最近复习记录（默认展开，migrated from TodaySummary） -->
            <div class="mb-3">
                <div class="text-h6 mb-2">今日最近复习</div>
                <div v-if="report.recent_reviews.length === 0" class="body-2 text--secondary text-center pa-3">
                    今天还没有复习记录。
                </div>
                <v-list v-else outlined dense class="rounded-lg">
                    <v-list-item
                        v-for="(item, i) in report.recent_reviews"
                        :key="i"
                    >
                        <v-list-item-content>
                            <v-list-item-title>
                                <span class="font-weight-medium">{{ item.lemma }}</span>
                                <span class="text--secondary ml-2">{{ item.sense_zh }}</span>
                            </v-list-item-title>
                            <v-list-item-subtitle>
                                <v-chip
                                    x-small
                                    :color="ratingColor(item.rating)"
                                    class="mr-2"
                                >{{ item.rating_label }}</v-chip>
                                <span class="text-caption text--secondary">{{ formatTime(item.reviewed_at) }}</span>
                            </v-list-item-subtitle>
                        </v-list-item-content>
                    </v-list-item>
                </v-list>
            </div>

            <v-divider class="my-4" />

            <div class="d-flex justify-center flex-wrap">
                <v-btn
                    depressed
                    rounded
                    outlined
                    class="ma-2"
                    @click="$emit('close')"
                >关闭并继续复习</v-btn>
            </div>
        </template>
    </v-card>
</template>

<script>
    // SenseReviewDailyReport-1000-1 (consolidated in 1000-3 / ADR-0006)
    //
    // Presentational component for the SenseReview "今日学习日报" (daily
    // learning report). Pure render of backend data returned by the
    // GET /reviews/senses/daily-report endpoint.
    //
    // This is the SINGLE today-report component after ADR-0006 — the former
    // SenseReviewTodaySummary.vue was merged into this component. The
    // recent_reviews section was migrated from that component.
    //
    // Distinct from:
    //   - SenseReviewSessionSummary (本次复习总结, page-load scoped, frontend)
    //
    // Contract:
    //   - Props: report (object from backend daily-report endpoint).
    //   - Events: close (user wants to dismiss and continue reviewing).
    //   - Does NOT call any backend API itself (parent loads the data).
    //   - Does NOT write any review log or touch scheduling state.
    //   - Does NOT create/destroy cards.
    //   - Does NOT handle hotkeys (parent disables them while shown).
    //   - Empty state shows "今天还没有完成词义卡复习。" with no fake charts.
    //   - average_rating null → "暂无数据".
    //   - Five sections: overview, quality, focus_senses, progress_senses,
    //     recent_reviews (additive, migrated from TodaySummary in ADR-0006).
    export default {
        name: 'SenseReviewDailyReport',
        props: {
            report: {
                type: Object,
                required: true,
            },
        },
        computed: {
            averageRatingText() {
                const v = this.report.overview.average_rating;
                if (v === null || v === undefined) return '暂无数据';
                return v.toFixed(2);
            },
            forgetRateText() {
                const v = this.report.quality.forget_rate;
                if (v === null || v === undefined) return '无记录';
                return (v * 100).toFixed(1) + '%';
            },
            forgetRateClass() {
                const v = this.report.quality.forget_rate;
                if (v === null || v === undefined) return 'text--secondary';
                if (v >= 0.5) return 'error--text';
                if (v >= 0.25) return 'warning--text';
                return 'success--text';
            },
            stabilityRateText() {
                const v = this.report.quality.stability_rate;
                if (v === null || v === undefined) return '无记录';
                return (v * 100).toFixed(1) + '%';
            },
            stabilityRateClass() {
                const v = this.report.quality.stability_rate;
                if (v === null || v === undefined) return 'text--secondary';
                if (v >= 0.7) return 'success--text';
                if (v >= 0.4) return 'primary--text';
                return 'warning--text';
            },
        },
        methods: {
            ratingLabel(rating) {
                return {
                    again: '忘了',
                    hard: '勉强记得',
                    good: '记得',
                    easy: '很熟',
                }[rating] || rating;
            },
            ratingColor(rating) {
                return {
                    again: 'error',
                    hard: 'warning',
                    good: 'primary',
                    easy: 'success',
                }[rating] || 'default';
            },
            formatTime(iso) {
                if (!iso) return '';
                try {
                    const d = new Date(iso);
                    return d.toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit' });
                } catch (e) {
                    return iso;
                }
            },
        },
    }
</script>
