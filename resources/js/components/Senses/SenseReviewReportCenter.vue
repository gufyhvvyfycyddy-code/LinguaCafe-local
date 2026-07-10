<template>
    <div>
        <!-- Today summary dialog (cross-session daily aggregate). Read-only:
             the component only renders backend data and emits 'close'. It
             never writes ReviewLog, never touches FSRS, never creates cards. -->
        <v-dialog v-model="isOpen" :max-width="dialogMaxWidth" persistent>
            <v-card v-if="isOpen" class="pa-4">
                <div v-if="loading" class="text-center py-8">
                    <v-progress-circular indeterminate color="primary"></v-progress-circular>
                    <div class="mt-2 text--secondary">{{ loadingText }}</div>
                </div>
                <div v-else-if="error" class="text-center py-8">
                    <v-alert type="error" dense outlined>{{ error }}</v-alert>
                    <v-btn small text color="primary" @click="close">关闭</v-btn>
                </div>
                <div v-else>
                    <!-- Today summary -->
                    <SenseReviewTodaySummary
                        v-if="activeReport === 'today-summary'"
                        :summary="payload"
                        @close="close"
                    />
                    <!-- Daily report -->
                    <SenseReviewDailyReport
                        v-else-if="activeReport === 'daily-report'"
                        :report="payload"
                        @close="close"
                    />
                    <!-- Seven day trend -->
                    <SenseReviewSevenDayTrend
                        v-else-if="activeReport === 'seven-day-trend'"
                        :trend="payload"
                        @close="close"
                    />
                    <!-- Thirty day calendar (added in Task A) -->
                    <SenseReviewThirtyDayCalendar
                        v-else-if="activeReport === 'thirty-day-calendar'"
                        :calendar="payload"
                        @close="close"
                    />
                </div>
            </v-card>
        </v-dialog>
    </div>
</template>

<script>
    import axios from 'axios';
    import SenseReviewTodaySummary from './SenseReviewTodaySummary.vue';
    import SenseReviewDailyReport from './SenseReviewDailyReport.vue';
    import SenseReviewSevenDayTrend from './SenseReviewSevenDayTrend.vue';
    import SenseReviewThirtyDayCalendar from './SenseReviewThirtyDayCalendar.vue';

    /**
     * SenseReviewReportCenter
     *
     * Single orchestration component for all SenseReview report dialogs.
     * Replaces the three duplicated dialog/loading/payload/GET patterns
     * that previously lived in SenseReview.vue.
     *
     * Contract:
     *  - v-model / activeReport: null | 'today-summary' | 'daily-report' |
     *    'seven-day-trend' | 'thirty-day-calendar'.
     *  - Parent sets activeReport; ReportCenter handles dialog, loading,
     *    GET request, error state, and close (emits input null).
     *  - Only read-only GET requests. Never POSTs ratings.
     *  - Never writes ReviewLog. Never touches FSRS. Never changes the
     *    card queue.
     *  - SessionSummary is NOT managed here (it is page-load scoped, not
     *    a backend-report dialog).
     *
     * Endpoint map:
     *   today-summary       → GET /reviews/senses/today-summary
     *   daily-report        → GET /reviews/senses/daily-report
     *   seven-day-trend     → GET /reviews/senses/seven-day-trend
     *   thirty-day-calendar → GET /reviews/senses/thirty-day-calendar
     */
    export default {
        name: 'SenseReviewReportCenter',
        components: {
            SenseReviewTodaySummary,
            SenseReviewDailyReport,
            SenseReviewSevenDayTrend,
            SenseReviewThirtyDayCalendar,
        },
        model: {
            prop: 'activeReport',
            event: 'input',
        },
        props: {
            // null = closed; otherwise one of the report keys below.
            activeReport: {
                type: String,
                default: null,
            },
        },
        data() {
            return {
                loading: false,
                error: '',
                payload: {},
            };
        },
        computed: {
            isOpen: {
                get() {
                    return this.activeReport !== null;
                },
                set(value) {
                    if (!value) {
                        this.close();
                    }
                },
            },
            endpoint() {
                const map = {
                    'today-summary': '/reviews/senses/today-summary',
                    'daily-report': '/reviews/senses/daily-report',
                    'seven-day-trend': '/reviews/senses/seven-day-trend',
                    'thirty-day-calendar': '/reviews/senses/thirty-day-calendar',
                };
                return map[this.activeReport] || null;
            },
            dialogMaxWidth() {
                const map = {
                    'today-summary': 720,
                    'daily-report': 800,
                    'seven-day-trend': 820,
                    'thirty-day-calendar': 920,
                };
                return map[this.activeReport] || 800;
            },
            loadingText() {
                const map = {
                    'today-summary': '正在加载今日复习总结…',
                    'daily-report': '正在加载今日学习日报…',
                    'seven-day-trend': '正在加载近 7 天学习趋势…',
                    'thirty-day-calendar': '正在加载近 30 天复习日历…',
                };
                return map[this.activeReport] || '正在加载…';
            },
        },
        watch: {
            activeReport(newVal) {
                if (newVal) {
                    this.fetchReport();
                } else {
                    this.resetState();
                }
            },
        },
        methods: {
            fetchReport() {
                if (!this.endpoint) {
                    return;
                }
                this.loading = true;
                this.error = '';
                axios.get(this.endpoint)
                    .then((response) => {
                        this.payload = response.data;
                    })
                    .catch(() => {
                        this.error = '报表加载失败，请稍后重试。';
                    })
                    .finally(() => {
                        this.loading = false;
                    });
            },
            close() {
                this.$emit('input', null);
            },
            resetState() {
                this.loading = false;
                this.error = '';
                this.payload = {};
            },
        },
    }
</script>
