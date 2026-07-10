<template>
    <v-card outlined class="rounded-lg pa-5">
        <div class="d-flex align-center mb-4">
            <div class="text-h5">近 7 天学习趋势</div>
            <v-spacer></v-spacer>
            <v-btn icon small @click="$emit('close')">
                <v-icon>mdi-close</v-icon>
            </v-btn>
        </div>

        <div class="caption text--secondary mb-4">
            {{ trend.start_day }} 至 {{ trend.end_day }}（{{ trend.timezone }}）
        </div>

        <div v-if="trend.summary.total_reviews === 0" class="body-1 text--secondary text-center py-6">
            近 7 天还没有完成词义卡复习。
        </div>

        <template v-else>
            <!-- 7 天总览 -->
            <div class="mb-5">
                <div class="text-h6 mb-2">7 天总览</div>
                <v-row dense>
                    <v-col cols="6" md="2">
                        <v-card outlined class="pa-3 text-center">
                            <div class="text-h5 primary--text">{{ trend.summary.total_reviews }}</div>
                            <div class="caption text--secondary">总复习次数</div>
                        </v-card>
                    </v-col>
                    <v-col cols="6" md="2">
                        <v-card outlined class="pa-3 text-center">
                            <div class="text-h5 info--text">{{ trend.summary.active_days }}</div>
                            <div class="caption text--secondary">活跃天数</div>
                        </v-card>
                    </v-col>
                    <v-col cols="6" md="2">
                        <v-card outlined class="pa-3 text-center">
                            <div class="text-h5 primary--text">{{ trend.summary.distinct_senses }}</div>
                            <div class="caption text--secondary">不同词义</div>
                        </v-card>
                    </v-col>
                    <v-col cols="6" md="2">
                        <v-card outlined class="pa-3 text-center">
                            <div class="text-h5 success--text">{{ averagePerActiveDayText }}</div>
                            <div class="caption text--secondary">日均复习</div>
                        </v-card>
                    </v-col>
                    <v-col cols="6" md="2">
                        <v-card outlined class="pa-3 text-center">
                            <div class="text-h5" :class="forgetRateClass">{{ forgetRateText }}</div>
                            <div class="caption text--secondary">7 天遗忘率</div>
                        </v-card>
                    </v-col>
                    <v-col cols="6" md="2">
                        <v-card outlined class="pa-3 text-center">
                            <div class="text-h5" :class="stabilityRateClass">{{ stabilityRateText }}</div>
                            <div class="caption text--secondary">7 天稳定率</div>
                        </v-card>
                    </v-col>
                </v-row>
                <div class="body-2 d-flex justify-space-around flex-wrap mt-2">
                    <div class="ma-2">
                        <span class="text--secondary">7 天评分分布：</span>
                        <span class="error--text font-weight-medium ml-2">忘 {{ trend.summary.distribution.again }}</span>
                        <span class="warning--text font-weight-medium ml-2">困难 {{ trend.summary.distribution.hard }}</span>
                        <span class="primary--text font-weight-medium ml-2">记得 {{ trend.summary.distribution.good }}</span>
                        <span class="success--text font-weight-medium ml-2">很熟 {{ trend.summary.distribution.easy }}</span>
                    </div>
                </div>
            </div>

            <v-divider class="mb-4" />

            <!-- 7 个日期行 -->
            <div class="mb-3">
                <div class="text-h6 mb-2">每日明细</div>
                <div
                    v-for="(day, i) in trend.days"
                    :key="i"
                    class="mb-3"
                >
                    <div class="d-flex align-center mb-1">
                        <div class="subtitle-2 font-weight-medium" :class="{ 'primary--text': isToday(day.day) }">
                            {{ day.day }}
                            <v-chip v-if="isToday(day.day)" x-small color="primary" class="ml-1">今天</v-chip>
                        </div>
                        <v-spacer></v-spacer>
                        <div class="caption text--secondary">
                            {{ day.total_reviews }} 次 · {{ day.distinct_senses }} 词义
                        </div>
                    </div>

                    <!-- 总数条形 -->
                    <div class="d-flex align-center mb-1">
                        <div style="width: 100%; height: 8px; background: #eee; border-radius: 4px; overflow: hidden;">
                            <div
                                :style="{ width: barWidth(day.total_reviews) + '%', height: '100%', background: barColor(day) }"
                            ></div>
                        </div>
                        <div class="caption text--secondary ml-2" style="min-width: 40px;">{{ day.total_reviews }}</div>
                    </div>

                    <!-- 评分分布 + 比率 -->
                    <div v-if="day.total_reviews > 0" class="body-2 d-flex flex-wrap align-center">
                        <v-chip x-small color="error" class="mr-1">忘 {{ day.distribution.again }}</v-chip>
                        <v-chip x-small color="warning" class="mr-1">困难 {{ day.distribution.hard }}</v-chip>
                        <v-chip x-small color="primary" class="mr-1">记得 {{ day.distribution.good }}</v-chip>
                        <v-chip x-small color="success" class="mr-1">很熟 {{ day.distribution.easy }}</v-chip>
                        <span class="text--secondary ml-2">
                            遗忘率 <span :class="dayRateClass(day.forget_rate)">{{ rateText(day.forget_rate) }}</span>
                        </span>
                        <span class="text--secondary ml-2">
                            稳定率 <span :class="dayRateClass(day.stability_rate)">{{ rateText(day.stability_rate) }}</span>
                        </span>
                    </div>
                    <div v-else class="body-2 text--secondary">
                        无记录
                    </div>
                </div>
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
    // SenseReviewSevenDayTrend-1000-1
    //
    // Presentational component for the SenseReview "近 7 天学习趋势"
    // (fixed rolling 7-day window: today + previous 6 natural days,
    // NOT a natural week). Pure render of backend data returned by the
    // GET /reviews/senses/seven-day-trend endpoint.
    //
    // Distinct from:
    //   - SenseReviewSessionSummary (本次复习总结, page-load scoped, frontend)
    //   - SenseReviewTodaySummary   (今日复习总结, simpler backend aggregate)
    //   - SenseReviewDailyReport    (今日学习日报, today-only four-block)
    //
    // Contract:
    //   - Props: trend (object from backend seven-day-trend endpoint).
    //   - Events: close (user wants to dismiss and continue reviewing).
    //   - Does NOT call any backend API itself (parent loads the data).
    //   - Does NOT write any review log or touch scheduling state.
    //   - Does NOT create/destroy cards.
    //   - Does NOT handle hotkeys (parent disables them while shown).
    //   - Empty state shows "近 7 天还没有完成词义卡复习。".
    //   - Empty days show 0 counts and "无记录" (never misleading "0%").
    //   - No chart library — uses Vuetify components + simple CSS bars.
    export default {
        name: 'SenseReviewSevenDayTrend',
        props: {
            trend: {
                type: Object,
                required: true,
            },
        },
        computed: {
            maxDayTotal() {
                if (!this.trend.days || this.trend.days.length === 0) return 1;
                return Math.max(1, ...this.trend.days.map(d => d.total_reviews));
            },
            averagePerActiveDayText() {
                const v = this.trend.summary.average_per_active_day;
                if (v === null || v === undefined) return '暂无';
                return v.toFixed(1);
            },
            forgetRateText() {
                const v = this.trend.summary.forget_rate;
                if (v === null || v === undefined) return '无记录';
                return (v * 100).toFixed(1) + '%';
            },
            forgetRateClass() {
                const v = this.trend.summary.forget_rate;
                if (v === null || v === undefined) return 'text--secondary';
                if (v >= 0.5) return 'error--text';
                if (v >= 0.25) return 'warning--text';
                return 'success--text';
            },
            stabilityRateText() {
                const v = this.trend.summary.stability_rate;
                if (v === null || v === undefined) return '无记录';
                return (v * 100).toFixed(1) + '%';
            },
            stabilityRateClass() {
                const v = this.trend.summary.stability_rate;
                if (v === null || v === undefined) return 'text--secondary';
                if (v >= 0.7) return 'success--text';
                if (v >= 0.4) return 'primary--text';
                return 'warning--text';
            },
        },
        methods: {
            isToday(dayStr) {
                return dayStr === this.trend.end_day;
            },
            barWidth(total) {
                if (total === 0) return 0;
                return Math.round((total / this.maxDayTotal) * 100);
            },
            barColor(day) {
                if (day.total_reviews === 0) return '#ddd';
                if (day.forget_rate !== null && day.forget_rate >= 0.5) return '#f56565';
                if (day.stability_rate !== null && day.stability_rate >= 0.7) return '#48bb78';
                return '#4299e1';
            },
            rateText(v) {
                if (v === null || v === undefined) return '无记录';
                return (v * 100).toFixed(1) + '%';
            },
            dayRateClass(v) {
                if (v === null || v === undefined) return 'text--secondary';
                if (v >= 0.5) return 'error--text font-weight-medium';
                if (v >= 0.25) return 'warning--text font-weight-medium';
                return 'success--text font-weight-medium';
            },
        },
    }
</script>
