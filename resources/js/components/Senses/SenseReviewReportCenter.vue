<template>
    <div>
        <!-- SenseReviewReportCenter — single orchestration component for
             all SenseReview report dialogs. Read-only: only axios.get,
             never POSTs ratings, never writes ReviewLog, never touches
             FSRS, never changes the card queue. SessionSummary is NOT
             managed here (page-load scoped). -->
        <v-dialog v-model="isOpen" :max-width="dialogMaxWidth" persistent scrollable>
            <v-card v-if="isOpen" class="pa-4">
                <!-- Loading -->
                <div v-if="loading" class="text-center py-8">
                    <v-progress-circular indeterminate color="primary"></v-progress-circular>
                    <div class="mt-2 text--secondary">{{ loadingText }}</div>
                </div>
                <!-- Error -->
                <div v-else-if="error" class="text-center py-8">
                    <v-alert type="error" dense outlined>{{ error }}</v-alert>
                    <div class="mt-2">
                        <v-btn small text color="primary" @click="backToList">返回报告列表</v-btn>
                        <v-btn small text @click="close">关闭</v-btn>
                    </div>
                </div>
                <!-- Report home page: catalog selection -->
                <div v-else-if="!selectedReportKey">
                    <div class="d-flex align-center mb-4">
                        <div class="text-h5">学习报告</div>
                        <v-spacer></v-spacer>
                        <v-btn icon small @click="close">
                            <v-icon>mdi-close</v-icon>
                        </v-btn>
                    </div>
                    <div class="body-2 text--secondary mb-4">选择要查看的报告：</div>
                    <v-row dense>
                        <v-col
                            v-for="report in catalog"
                            :key="report.key"
                            cols="12"
                            sm="6"
                        >
                            <v-card
                                outlined
                                hover
                                class="report-card pa-4 d-flex align-center"
                                @click="selectReport(report.key)"
                            >
                                <v-icon :color="report.color" class="mr-3">{{ report.icon }}</v-icon>
                                <div class="flex-grow-1">
                                    <div class="subtitle-1 font-weight-medium">{{ report.title }}</div>
                                    <div class="caption text--secondary">{{ report.description }}</div>
                                </div>
                                <v-icon color="grey lighten-1">mdi-chevron-right</v-icon>
                            </v-card>
                        </v-col>
                    </v-row>
                </div>
                <!-- Concrete report -->
                <div v-else>
                    <component
                        :is="currentComponent"
                        :[currentPayloadProp]="payload"
                        @close="close"
                        @back="backToList"
                    >
                    </component>
                    <div class="d-flex justify-center mt-3">
                        <v-btn small text color="primary" @click="backToList">
                            <v-icon left small>mdi-arrow-left</v-icon>
                            返回报告列表
                        </v-btn>
                    </div>
                </div>
            </v-card>
        </v-dialog>
    </div>
</template>

<script>
    import axios from 'axios';
    import { REPORT_CATALOG, getReportByKey, isReportKey } from './SenseReviewReportCatalog.js';
    import SenseReviewDailyReport from './SenseReviewDailyReport.vue';
    import SenseReviewSevenDayTrend from './SenseReviewSevenDayTrend.vue';
    import SenseReviewThirtyDayCalendar from './SenseReviewThirtyDayCalendar.vue';

    /**
     * SenseReviewReportCenter
     *
     * SenseReview-ReportCenter-1000-2
     *
     * Single orchestration component for all SenseReview report dialogs.
     *
     * Contract (redesigned in 1000-2):
     *  - v-model = boolean (open state). Parent only controls open/close.
     *  - Internal state owned by ReportCenter:
     *      selectedReportKey: null = home page; otherwise a catalog key.
     *      loading / error / payload / requestSequence.
     *  - open=false: closed, internal state fully reset.
     *  - open=true, selectedReportKey=null: report home page (NO GET).
     *  - selectReport(key): set selectedReportKey, fire GET for that report.
     *  - backToList: selectedReportKey → null, clear error/payload.
     *  - close: emit input=false, fully reset.
     *  - Async race protection: a monotonic requestSequence guards against
     *    stale responses overwriting newer state (fast switch / close).
     *  - Same report already loading: no duplicate request.
     *  - Only read-only GET. Never POSTs. Never writes ReviewLog/FSRS.
     *  - SessionSummary is NOT managed here.
     */
    const COMPONENT_MAP = {
        'SenseReviewDailyReport': SenseReviewDailyReport,
        'SenseReviewSevenDayTrend': SenseReviewSevenDayTrend,
        'SenseReviewThirtyDayCalendar': SenseReviewThirtyDayCalendar,
    };

    export default {
        name: 'SenseReviewReportCenter',
        components: {
            SenseReviewDailyReport,
            SenseReviewSevenDayTrend,
            SenseReviewThirtyDayCalendar,
        },
        model: {
            prop: 'open',
            event: 'input',
        },
        props: {
            // Boolean open state. Parent only sets true/false.
            open: {
                type: Boolean,
                default: false,
            },
        },
        data() {
            return {
                catalog: REPORT_CATALOG,
                selectedReportKey: null,
                loading: false,
                error: '',
                payload: {},
                requestSequence: 0,
            };
        },
        computed: {
            isOpen: {
                get() {
                    return this.open;
                },
                set(value) {
                    if (!value) {
                        this.close();
                    }
                },
            },
            currentReport() {
                return this.selectedReportKey ? getReportByKey(this.selectedReportKey) : null;
            },
            currentComponent() {
                const report = this.currentReport;
                if (!report) return null;
                return COMPONENT_MAP[report.component] || null;
            },
            currentPayloadProp() {
                const report = this.currentReport;
                return report ? report.payloadProp : 'data';
            },
            dialogMaxWidth() {
                const report = this.currentReport;
                return report ? report.maxWidth : 760;
            },
            loadingText() {
                const report = this.currentReport;
                return report ? report.loadingText : '正在加载…';
            },
        },
        watch: {
            open(newVal) {
                if (!newVal) {
                    this.resetState();
                }
            },
        },
        methods: {
            selectReport(key) {
                if (!isReportKey(key)) {
                    return;
                }
                // Same report already loading: do not duplicate request.
                if (this.selectedReportKey === key && this.loading) {
                    return;
                }
                this.selectedReportKey = key;
                this.fetchReport();
            },
            fetchReport() {
                const report = this.currentReport;
                if (!report) {
                    return;
                }
                this.requestSequence += 1;
                const seq = this.requestSequence;
                this.loading = true;
                this.error = '';
                this.payload = {};
                axios.get(report.endpoint)
                    .then((response) => {
                        // Stale response guard: ignore if a newer request
                        // started, or the dialog was closed, or the user
                        // navigated back to the list / to another report.
                        if (seq !== this.requestSequence || !this.open || this.selectedReportKey !== report.key) {
                            return;
                        }
                        this.payload = response.data;
                    })
                    .catch(() => {
                        if (seq !== this.requestSequence || !this.open || this.selectedReportKey !== report.key) {
                            return;
                        }
                        this.error = '报表加载失败，请稍后重试。';
                    })
                    .finally(() => {
                        if (seq === this.requestSequence) {
                            this.loading = false;
                        }
                    });
            },
            backToList() {
                // Invalidate any in-flight request and clear payload/error.
                this.requestSequence += 1;
                this.selectedReportKey = null;
                this.loading = false;
                this.error = '';
                this.payload = {};
            },
            close() {
                this.requestSequence += 1;
                this.$emit('input', false);
                this.resetState();
            },
            resetState() {
                this.selectedReportKey = null;
                this.loading = false;
                this.error = '';
                this.payload = {};
            },
        },
    }
</script>

<style scoped>
.report-card {
    transition: border-color 0.15s, box-shadow 0.15s;
    min-height: 84px;
}
.report-card:hover {
    border-color: #1976d2;
    box-shadow: 0 2px 8px rgba(25, 118, 210, 0.15);
}
</style>
