<template>
    <v-card outlined class="rounded-lg pa-5">
        <div class="d-flex align-center mb-4">
            <div class="text-h5">近 30 天复习日历</div>
            <v-spacer></v-spacer>
            <v-btn icon small @click="$emit('close')">
                <v-icon>mdi-close</v-icon>
            </v-btn>
        </div>

        <div class="caption text--secondary mb-4">
            {{ calendar.start_day }} 至 {{ calendar.end_day }}（{{ calendar.timezone }}）
        </div>

        <div v-if="calendar.summary.total_reviews === 0" class="body-1 text--secondary text-center py-6">
            近 30 天还没有完成词义卡复习。
        </div>

        <template v-else>
            <!-- 30 天总览 -->
            <div class="mb-5">
                <div class="text-h6 mb-2">30 天总览</div>
                <v-row dense>
                    <v-col cols="6" md="2">
                        <v-card outlined class="pa-3 text-center">
                            <div class="text-h5 primary--text">{{ calendar.summary.total_reviews }}</div>
                            <div class="caption text--secondary">总复习次数</div>
                        </v-card>
                    </v-col>
                    <v-col cols="6" md="2">
                        <v-card outlined class="pa-3 text-center">
                            <div class="text-h5 info--text">{{ calendar.summary.active_days }}</div>
                            <div class="caption text--secondary">活跃天数</div>
                        </v-card>
                    </v-col>
                    <v-col cols="6" md="2">
                        <v-card outlined class="pa-3 text-center">
                            <div class="text-h5 primary--text">{{ calendar.summary.distinct_senses }}</div>
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
                            <div class="caption text--secondary">遗忘率</div>
                        </v-card>
                    </v-col>
                    <v-col cols="6" md="2">
                        <v-card outlined class="pa-3 text-center">
                            <div class="text-h5" :class="stabilityRateClass">{{ stabilityRateText }}</div>
                            <div class="caption text--secondary">稳定率</div>
                        </v-card>
                    </v-col>
                </v-row>
            </div>

            <!-- 评分分布 -->
            <div class="mb-5">
                <div class="text-h6 mb-2">评分分布</div>
                <v-row dense>
                    <v-col cols="3">
                        <v-chip small color="error" outlined>忘了 {{ calendar.summary.distribution.again }}</v-chip>
                    </v-col>
                    <v-col cols="3">
                        <v-chip small color="warning" outlined>勉强记得 {{ calendar.summary.distribution.hard }}</v-chip>
                    </v-col>
                    <v-col cols="3">
                        <v-chip small color="success" outlined>记得 {{ calendar.summary.distribution.good }}</v-chip>
                    </v-col>
                    <v-col cols="3">
                        <v-chip small color="info" outlined>很熟 {{ calendar.summary.distribution.easy }}</v-chip>
                    </v-col>
                </v-row>
            </div>

            <!-- 30 天日历格 -->
            <div class="mb-3">
                <div class="text-h6 mb-2">日历</div>
                <div class="calendar-grid">
                    <div
                        v-for="(day, index) in calendar.days"
                        :key="day.day"
                        class="calendar-cell"
                        :class="[cellIntensityClass(day), { 'calendar-cell--selected': selectedIndex === index, 'calendar-cell--today': isToday(day.day) }]"
                        @click="selectDay(index)"
                    >
                        <div class="calendar-cell__date">{{ formatCellDate(day.day) }}</div>
                        <div class="calendar-cell__count">{{ day.total_reviews || '' }}</div>
                    </div>
                </div>
            </div>

            <!-- 选中日期详情 -->
            <div v-if="selectedDay" class="day-detail mt-4">
                <v-divider class="mb-3"></v-divider>
                <div class="text-h6 mb-2">{{ selectedDay.day }}{{ isToday(selectedDay.day) ? '（今天）' : '' }}</div>
                <div v-if="selectedDay.total_reviews === 0" class="body-2 text--secondary">
                    无记录。
                </div>
                <div v-else>
                    <v-row dense>
                        <v-col cols="6" md="3">
                            <div class="caption text--secondary">总复习</div>
                            <div class="body-1 font-weight-medium">{{ selectedDay.total_reviews }}</div>
                        </v-col>
                        <v-col cols="6" md="3">
                            <div class="caption text--secondary">不同词义</div>
                            <div class="body-1 font-weight-medium">{{ selectedDay.distinct_senses }}</div>
                        </v-col>
                        <v-col cols="6" md="3">
                            <div class="caption text--secondary">遗忘率</div>
                            <div class="body-1 font-weight-medium" :class="dayRateClass(selectedDay.forget_rate)">
                                {{ rateText(selectedDay.forget_rate) }}
                            </div>
                        </v-col>
                        <v-col cols="6" md="3">
                            <div class="caption text--secondary">稳定率</div>
                            <div class="body-1 font-weight-medium" :class="dayRateClass(selectedDay.stability_rate)">
                                {{ rateText(selectedDay.stability_rate) }}
                            </div>
                        </v-col>
                    </v-row>
                    <v-row dense class="mt-1">
                        <v-col cols="3">
                            <v-chip x-small color="error" outlined>忘了 {{ selectedDay.distribution.again }}</v-chip>
                        </v-col>
                        <v-col cols="3">
                            <v-chip x-small color="warning" outlined>勉强记得 {{ selectedDay.distribution.hard }}</v-chip>
                        </v-col>
                        <v-col cols="3">
                            <v-chip x-small color="success" outlined>记得 {{ selectedDay.distribution.good }}</v-chip>
                        </v-col>
                        <v-col cols="3">
                            <v-chip x-small color="info" outlined>很熟 {{ selectedDay.distribution.easy }}</v-chip>
                        </v-col>
                    </v-row>
                </div>
            </div>
        </template>
    </v-card>
</template>

<script>
    /**
     * SenseReviewThirtyDayCalendar — presentational component for the
     * fixed rolling 30-day SenseReview calendar.
     *
     * Distinct from SevenDayTrend (short-term continuous change) — this
     * shows historical date distribution across 30 days.
     *
     * Props: calendar (Object from GET /reviews/senses/thirty-day-calendar).
     * Events: close.
     * Constraints: pure presentational. No API calls, no ReviewLog writes,
     * no FSRS modifications. No chart library. Uses Vuetify + CSS only.
     */
    export default {
        name: 'SenseReviewThirtyDayCalendar',
        props: {
            calendar: {
                type: Object,
                required: true,
            },
        },
        data() {
            return {
                selectedIndex: -1,
            };
        },
        computed: {
            selectedDay() {
                if (this.selectedIndex < 0 || !this.calendar.days) {
                    return null;
                }
                return this.calendar.days[this.selectedIndex] || null;
            },
            averagePerActiveDayText() {
                const v = this.calendar.summary.average_per_active_day;
                return v !== null && v !== undefined ? String(v) : '—';
            },
            forgetRateText() {
                return this.rateText(this.calendar.summary.forget_rate);
            },
            forgetRateClass() {
                return this.rateClass(this.calendar.summary.forget_rate);
            },
            stabilityRateText() {
                return this.rateText(this.calendar.summary.stability_rate);
            },
            stabilityRateClass() {
                return this.rateClass(this.calendar.summary.stability_rate);
            },
            maxDayTotal() {
                if (!this.calendar.days) return 1;
                return Math.max(1, ...this.calendar.days.map(d => d.total_reviews));
            },
        },
        methods: {
            selectDay(index) {
                this.selectedIndex = this.selectedIndex === index ? -1 : index;
            },
            isToday(dayStr) {
                const today = new Date();
                const y = today.getFullYear();
                const m = String(today.getMonth() + 1).padStart(2, '0');
                const d = String(today.getDate()).padStart(2, '0');
                return dayStr === `${y}-${m}-${d}`;
            },
            formatCellDate(dayStr) {
                // Show MM-DD only for compactness.
                return dayStr ? dayStr.substring(5) : '';
            },
            cellIntensityClass(day) {
                if (day.total_reviews === 0) {
                    return 'calendar-cell--empty';
                }
                const ratio = day.total_reviews / this.maxDayTotal;
                if (ratio >= 0.75) return 'calendar-cell--high';
                if (ratio >= 0.5) return 'calendar-cell--mid';
                if (ratio >= 0.25) return 'calendar-cell--low';
                return 'calendar-cell--verylow';
            },
            rateText(rate) {
                if (rate === null || rate === undefined) return '—';
                return Math.round(rate * 100) + '%';
            },
            rateClass(rate) {
                if (rate === null || rate === undefined) return 'text--secondary';
                if (rate >= 0.5) return 'error--text';
                if (rate >= 0.25) return 'warning--text';
                return 'success--text';
            },
            dayRateClass(rate) {
                return this.rateClass(rate);
            },
        },
    }
</script>

<style scoped>
.calendar-grid {
    display: grid;
    grid-template-columns: repeat(10, 1fr);
    gap: 4px;
}
.calendar-cell {
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    padding: 4px 2px;
    text-align: center;
    cursor: pointer;
    min-height: 44px;
    transition: background-color 0.15s;
}
.calendar-cell:hover {
    border-color: #1976d2;
}
.calendar-cell--empty {
    background-color: #fafafa;
    color: #bbb;
}
.calendar-cell--verylow {
    background-color: #e8f5e9;
}
.calendar-cell--low {
    background-color: #c8e6c9;
}
.calendar-cell--mid {
    background-color: #81c784;
}
.calendar-cell--high {
    background-color: #43a047;
    color: #fff;
}
.calendar-cell--selected {
    border: 2px solid #1976d2;
    box-shadow: 0 0 4px rgba(25, 118, 210, 0.4);
}
.calendar-cell--today {
    border-color: #1976d2;
    border-width: 2px;
}
.calendar-cell__date {
    font-size: 10px;
    line-height: 1.2;
}
.calendar-cell__count {
    font-size: 14px;
    font-weight: 600;
    line-height: 1.4;
}
@media (max-width: 960px) {
    .calendar-grid {
        grid-template-columns: repeat(7, 1fr);
    }
}
</style>
