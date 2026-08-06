<template>
    <section class="statistics-v3">
        <div class="subheader subheader-margin-top d-flex align-center flex-wrap">
            <span>学习统计</span>
            <v-spacer></v-spacer>
            <v-btn text small :loading="exporting === 'csv'" @click="download('csv')">
                <v-icon left small>mdi-file-delimited-outline</v-icon>CSV
            </v-btn>
            <v-btn text small :loading="exporting === 'pdf'" @click="download('pdf')">
                <v-icon left small>mdi-file-pdf-box</v-icon>PDF
            </v-btn>
        </div>

        <v-card outlined class="pa-3 mb-4 rounded-lg">
            <div class="scope-controls">
                <v-select
                    v-model="periodDays"
                    :items="periodOptions"
                    item-text="label"
                    item-value="value"
                    label="统计周期"
                    dense
                    outlined
                    hide-details
                ></v-select>
                <v-text-field
                    v-model="query"
                    label="按统一查询缩小范围"
                    placeholder="例如 rated:again prop:lapses>2"
                    dense
                    outlined
                    clearable
                    hide-details
                    @keyup.enter="loadStatistics"
                ></v-text-field>
                <v-btn color="primary" :loading="loading" @click="loadStatistics">应用</v-btn>
            </div>
        </v-card>

        <v-alert v-if="error" type="error" text>{{ error }}</v-alert>
        <v-skeleton-loader v-if="loading && !report" type="card, card"></v-skeleton-loader>

        <template v-if="report">
            <div class="summary-grid mb-4">
                <v-card
                    v-for="metric in report.summary_cards"
                    :key="metric.key"
                    outlined
                    class="summary-card pa-4 rounded-lg"
                >
                    <div class="text-caption text--secondary">{{ metric.label }}</div>
                    <div class="summary-value">{{ display(metric.value) }}<small v-if="metric.unit"> {{ metric.unit }}</small></div>
                </v-card>
            </div>

            <v-alert v-if="report.scope.card_count === 0" type="info" text>
                当前查询范围没有可统计的确认词义卡片。
            </v-alert>

            <v-row>
                <v-col cols="12" md="6">
                    <statistics-mini-chart title="未来 30 天到期" :rows="futureRows" color="#42a5f5" />
                </v-col>
                <v-col cols="12" md="6">
                    <statistics-mini-chart title="Again / Hard / Good / Easy" :rows="ratingRows" color="#7e57c2" />
                </v-col>
                <v-col cols="12" md="6">
                    <statistics-mini-chart title="当前卡片状态" :rows="stateRows" color="#26a69a" />
                </v-col>
                <v-col cols="12" md="6">
                    <statistics-mini-chart title="当前间隔分布" :rows="report.interval_distribution.bins" color="#ffa726" />
                </v-col>
                <v-col cols="12" md="4">
                    <statistics-mini-chart title="Stability" :rows="report.fsrs.stability.bins" color="#66bb6a" />
                </v-col>
                <v-col cols="12" md="4">
                    <statistics-mini-chart title="Difficulty" :rows="report.fsrs.difficulty.bins" color="#ef5350" />
                </v-col>
                <v-col cols="12" md="4">
                    <statistics-mini-chart title="Retrievability" :rows="report.fsrs.retrievability.bins" color="#5c6bc0" />
                </v-col>
            </v-row>

            <v-card outlined class="pa-4 mb-4 rounded-lg">
                <div class="font-weight-medium mb-3">学习日历</div>
                <div class="heatmap" role="img" aria-label="每日正式复习次数热力图">
                    <div
                        v-for="day in report.calendar.days"
                        :key="day.date"
                        class="heat-cell"
                        :title="`${day.date}: ${day.count}`"
                        :style="{ opacity: heatOpacity(day.count) }"
                    ></div>
                </div>
                <div class="text-caption text--secondary mt-2">
                    {{ report.calendar.active_days }} 个活跃日；有计时的平均答题时间
                    {{ display(report.review_time.average_seconds) }} 秒。
                </div>
            </v-card>

            <v-row>
                <v-col cols="12" md="6">
                    <v-card outlined class="pa-4 rounded-lg fill-height">
                        <div class="font-weight-medium mb-2">真实保持率</div>
                        <div class="display-1">{{ display(report.true_retention.rate_percent) }}%</div>
                        <div class="text-caption text--secondary">
                            {{ report.true_retention.passed }} 通过 / {{ report.true_retention.total }} 次每日首次评分
                        </div>
                        <v-divider class="my-3"></v-divider>
                        <div>{{ report.definitions.true_retention }}</div>
                    </v-card>
                </v-col>
                <v-col cols="12" md="6">
                    <v-card outlined class="pa-4 rounded-lg fill-height">
                        <div class="font-weight-medium mb-2">阅读到词义卡转化</div>
                        <div>阅读词数：{{ report.reading_conversion.read_words }}</div>
                        <div>遇到词：{{ report.reading_conversion.encountered_words }}</div>
                        <div>确认词义：{{ report.reading_conversion.confirmed_senses }}</div>
                        <div>绑定来源：{{ report.reading_conversion.bound_source_occurrences }}</div>
                    </v-card>
                </v-col>
            </v-row>
        </template>
    </section>
</template>

<script>
import StatisticsMiniChart from './StatisticsMiniChart.vue';

export default {
    components: { StatisticsMiniChart },
    data() {
        return {
            report: null,
            loading: false,
            exporting: null,
            error: '',
            periodDays: 30,
            query: '',
            periodOptions: [
                { label: '最近 7 天', value: 7 },
                { label: '最近 30 天', value: 30 },
                { label: '最近 90 天', value: 90 },
                { label: '最近 365 天', value: 365 },
            ],
        };
    },
    computed: {
        requestPayload() {
            return { period_days: this.periodDays, q: this.query || '' };
        },
        futureRows() {
            return (this.report?.future_due?.daily || []).slice(0, 30)
                .map(day => ({ label: day.date.slice(5), value: day.count }));
        },
        ratingRows() {
            return (this.report?.ratings || []).map(row => ({ label: row.label, value: row.count }));
        },
        stateRows() {
            if (!this.report) return [];
            const labels = { new: '新卡', learning: '学习中', review: '复习', relearning: '重学' };
            return Object.keys(labels).map(key => ({ label: labels[key], value: this.report.card_states[key] || 0 }));
        },
    },
    mounted() {
        this.loadStatistics();
    },
    methods: {
        async loadStatistics() {
            this.loading = true;
            this.error = '';
            try {
                const response = await axios.post('/statistics/get', this.requestPayload);
                this.report = response.data;
            } catch (error) {
                this.error = error.response?.data?.message || '统计加载失败，请稍后重试。';
            } finally {
                this.loading = false;
            }
        },
        async download(format) {
            this.exporting = format;
            this.error = '';
            try {
                const response = await axios.post(`/statistics/export/${format}`, this.requestPayload, { responseType: 'blob' });
                const url = URL.createObjectURL(response.data);
                const anchor = document.createElement('a');
                anchor.href = url;
                anchor.download = `linguacafe-statistics.${format}`;
                anchor.click();
                URL.revokeObjectURL(url);
            } catch (error) {
                this.error = '导出失败，请稍后重试。';
            } finally {
                this.exporting = null;
            }
        },
        display(value) {
            return value === null || value === undefined ? '—' : value;
        },
        heatOpacity(count) {
            return count === 0 ? .12 : Math.min(1, .28 + (Math.log(count + 1) / 3));
        },
    },
};
</script>

<style scoped>
.statistics-v3 {
    width: 100%;
    max-width: 100%;
    min-width: 0;
    overflow-x: hidden;
}
.statistics-v3 > .row {
    margin-left: 0;
    margin-right: 0;
}
.statistics-v3 > .row > [class*="col-"] {
    min-width: 0;
    padding-left: 6px;
    padding-right: 6px;
}
.scope-controls {
    display: grid;
    grid-template-columns: 180px minmax(240px, 1fr) auto;
    gap: 12px;
    align-items: center;
}
.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
}
.summary-value {
    margin-top: 6px;
    font-size: 28px;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
}
.summary-value small { font-size: 13px; font-weight: 400; }
.heatmap {
    display: grid;
    grid-template-columns: repeat(30, minmax(5px, 1fr));
    gap: 3px;
}
.heat-cell {
    aspect-ratio: 1;
    min-height: 7px;
    border-radius: 2px;
    background: #26a69a;
}
@media (max-width: 700px) {
    .scope-controls { grid-template-columns: 1fr; }
    .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .heatmap { grid-template-columns: repeat(15, minmax(6px, 1fr)); }
    .statistics-v3 { padding-left: 0; padding-right: 0; }
}
</style>
