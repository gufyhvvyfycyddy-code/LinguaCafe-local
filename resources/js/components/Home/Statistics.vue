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
                <v-text-field v-model="dateFrom" type="date" label="开始学习日期（起）" dense outlined clearable hide-details></v-text-field>
                <v-text-field v-model="dateTo" type="date" label="开始学习日期（止）" dense outlined clearable hide-details></v-text-field>
                <v-btn color="primary" :loading="loading" @click="loadStatistics">应用</v-btn>
            </div>
            <div v-if="report && report.scope.learning_date_range" class="text-caption text--secondary mt-2">
                开始学习日期按学习时区 {{ report.scope.learning_date_range.timezone }} 计算。
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

            <v-card outlined class="pa-4 mb-4 rounded-lg">
                <div class="font-weight-medium mb-1">记忆持久度</div>
                <div class="text-caption text--secondary mb-3">
                    用当前复习证据解释词义记忆状态；证据不足的词义不会被标为“掌握稳定”。
                </div>
                <div class="memory-grid">
                    <v-sheet v-for="state in report.memory_durability.states" :key="state.key" outlined rounded class="pa-3">
                        <div class="text-caption text--secondary">{{ state.label }}</div>
                        <div class="memory-value">{{ state.count }}</div>
                    </v-sheet>
                </div>
                <div class="text-caption text--secondary mt-3">
                    {{ report.memory_durability.coverage.sufficient }} / {{ report.memory_durability.coverage.total }} 个词义有足够模型证据。
                </div>
            </v-card>

            <v-card outlined class="pa-4 mb-4 rounded-lg">
                <div class="font-weight-medium mb-1">未来复习压力</div>
                <div class="text-caption text--secondary mb-3">
                    这是只读预测，不会替你设定每日新学量，也不会修改卡片安排。
                </div>
                <div class="pressure-grid mb-4">
                    <v-sheet v-for="metric in pressureHorizonCards" :key="metric.key" outlined rounded class="pa-3">
                        <div class="text-caption text--secondary">{{ metric.label }}</div>
                        <div class="memory-value">{{ metric.value }}</div>
                    </v-sheet>
                </div>
                <statistics-mini-chart title="未来 90 天预计复习" :rows="pressureRows" color="#5468e7" />
                <v-alert v-for="warning in report.future_pressure.warnings" :key="warning" dense text type="info" class="mt-3 mb-0">{{ warning }}</v-alert>
            </v-card>

            <v-card outlined class="pa-4 mb-4 rounded-lg">
                <div class="d-flex align-center flex-wrap mb-1">
                    <div class="font-weight-medium">优化记忆模型</div>
                    <v-spacer></v-spacer>
                    <v-btn
                        color="primary"
                        :disabled="optimizationBusy !== '' || !optimizationStatus || !optimizationStatus.can_optimize"
                        :loading="optimizationBusy === 'preview'"
                        @click="previewMemoryOptimization"
                    >现在优化记忆模型</v-btn>
                </div>
                <div class="text-caption text--secondary mb-3">
                    使用你的正式词义复习记录改善之后的间隔计算。优化不会重排已有卡片。
                </div>
                <v-skeleton-loader v-if="optimizationBusy === 'status' && !optimizationStatus" type="text, text"></v-skeleton-loader>
                <template v-else-if="optimizationStatus">
                    <div>{{ optimizationStatus.parameters_source_label }}</div>
                    <div class="text-caption text--secondary">
                        已有 {{ optimizationStatus.review_count }} / {{ optimizationStatus.min_required }} 条可用复习记录。
                    </div>
                    <v-alert v-if="!optimizationStatus.can_optimize" dense text type="info" class="mt-3 mb-0">
                        {{ optimizationStatus.message }}
                    </v-alert>
                </template>
                <v-divider class="my-4"></v-divider>
                <div class="optimization-policy-grid">
                    <v-select
                        v-model="optimizationPolicyMode"
                        :items="optimizationPolicyOptions"
                        item-text="label"
                        item-value="value"
                        label="自动优化方式"
                        dense
                        outlined
                        hide-details
                        :disabled="!optimizationStatus"
                    ></v-select>
                    <v-text-field
                        v-if="optimizationPolicyMode === 'interval'"
                        v-model.number="optimizationIntervalDays"
                        type="number"
                        min="1"
                        max="365"
                        label="间隔天数"
                        dense
                        outlined
                        hide-details
                        :disabled="!optimizationStatus"
                    ></v-text-field>
                    <v-btn
                        outlined
                        color="primary"
                        :loading="optimizationBusy === 'policy'"
                        :disabled="optimizationBusy !== '' || !optimizationStatus"
                        @click="saveOptimizationPolicy"
                    >保存自动优化设置</v-btn>
                </div>
                <div v-if="optimizationStatus && optimizationStatus.optimization_policy" class="text-caption text--secondary mt-2">
                    <template v-if="optimizationStatus.optimization_policy.mode === 'manual'">当前仅在你明确操作时优化。</template>
                    <template v-else-if="optimizationStatus.optimization_policy.next_eligible_at">
                        下次最早自动优化：{{ formatOptimizationDate(optimizationStatus.optimization_policy.next_eligible_at) }}。
                    </template>
                    <template v-else>达到记录要求后，将在下一次每日检查时优化。</template>
                    自动优化也不会重排已有卡片。
                </div>
                <v-alert v-if="optimizationError" dense text type="error" class="mt-3 mb-0">{{ optimizationError }}</v-alert>
                <v-alert v-if="optimizationResult" dense text :type="optimizationResult.applied || optimizationResult.success ? 'success' : 'info'" class="mt-3 mb-0">
                    {{ optimizationResult.message }}
                </v-alert>
                <v-sheet v-if="optimizationPreview && optimizationPreview.preview_available" outlined rounded class="pa-3 mt-3">
                    <div class="font-weight-medium">优化结果已准备好</div>
                    <div class="text-caption text--secondary mt-1">{{ optimizationPreview.message }}</div>
                    <div class="d-flex justify-end flex-wrap mt-3">
                        <v-btn text :disabled="optimizationBusy === 'apply'" @click="optimizationPreview = null">取消</v-btn>
                        <v-btn color="primary" :loading="optimizationBusy === 'apply'" @click="applyMemoryOptimization">
                            确认保存优化结果
                        </v-btn>
                    </div>
                </v-sheet>
            </v-card>

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
import { applyOptimization, getOptimizationStatus, previewOptimization, updateOptimizationPolicy } from '../../services/AdminReviewSettingsApi';

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
            dateFrom: '',
            dateTo: '',
            optimizationStatus: null,
            optimizationPreview: null,
            optimizationResult: null,
            optimizationBusy: '',
            optimizationError: '',
            optimizationPolicyMode: 'manual',
            optimizationIntervalDays: 30,
            optimizationPolicyOptions: [
                { value: 'manual', label: '仅手动优化' },
                { value: 'interval', label: '每隔 N 天自动优化' },
            ],
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
            const payload = { period_days: this.periodDays, q: this.query || '' };
            if (this.dateFrom && this.dateTo) {
                payload.date_from = this.dateFrom;
                payload.date_to = this.dateTo;
            }
            return payload;
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
        pressureHorizonCards() {
            const horizons = this.report?.future_pressure?.horizons || {};
            return [
                { key: 'tomorrow', label: '明天', value: horizons.tomorrow || 0 },
                { key: '7', label: '未来 7 天', value: horizons['7'] || 0 },
                { key: '30', label: '未来 30 天', value: horizons['30'] || 0 },
                { key: '90', label: '未来 90 天', value: horizons['90'] || 0 },
            ];
        },
        pressureRows() {
            return (this.report?.future_pressure?.curve || [])
                .map(day => ({ label: day.date.slice(5), value: day.reviews }));
        },
    },
    mounted() {
        this.loadStatistics();
        this.loadOptimizationStatus();
    },
    methods: {
        async loadStatistics() {
            if (!this.validateLearningDateRange()) return;
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
        validateLearningDateRange() {
            if ((this.dateFrom && !this.dateTo) || (!this.dateFrom && this.dateTo)) {
                this.error = '请选择完整的开始学习日期范围。';
                return false;
            }
            if (this.dateFrom && this.dateTo && this.dateFrom > this.dateTo) {
                this.error = '开始学习日期的起始日不能晚于结束日。';
                return false;
            }
            return true;
        },
        async download(format) {
            if (!this.validateLearningDateRange()) return;
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
        async loadOptimizationStatus() {
            this.optimizationBusy = 'status';
            this.optimizationError = '';
            try {
                const response = await getOptimizationStatus();
                this.optimizationStatus = response.data;
                this.optimizationPolicyMode = response.data.optimization_policy.mode;
                this.optimizationIntervalDays = response.data.optimization_policy.interval_days;
            } catch (error) {
                this.optimizationError = error.response?.data?.message || '记忆模型状态加载失败，请稍后重试。';
            } finally {
                this.optimizationBusy = '';
            }
        },
        async previewMemoryOptimization() {
            this.optimizationBusy = 'preview';
            this.optimizationError = '';
            this.optimizationResult = null;
            try {
                const response = await previewOptimization();
                this.optimizationPreview = response.data.preview_available ? response.data : null;
                if (!response.data.preview_available) this.optimizationResult = response.data;
            } catch (error) {
                this.optimizationError = error.response?.data?.message || '记忆模型优化失败，请稍后重试。';
            } finally {
                this.optimizationBusy = '';
            }
        },
        async applyMemoryOptimization() {
            this.optimizationBusy = 'apply';
            this.optimizationError = '';
            try {
                const response = await applyOptimization();
                this.optimizationResult = response.data;
                this.optimizationPreview = null;
                await this.loadOptimizationStatus();
            } catch (error) {
                this.optimizationError = error.response?.data?.message || '优化结果保存失败，请稍后重试。';
            } finally {
                this.optimizationBusy = '';
            }
        },
        async saveOptimizationPolicy() {
            const intervalDays = Number(this.optimizationIntervalDays);
            if (!Number.isInteger(intervalDays) || intervalDays < 1 || intervalDays > 365) {
                this.optimizationError = '自动优化间隔必须是 1 到 365 天的整数。';
                return;
            }
            this.optimizationBusy = 'policy';
            this.optimizationError = '';
            this.optimizationResult = null;
            try {
                const response = await updateOptimizationPolicy({
                    mode: this.optimizationPolicyMode,
                    interval_days: intervalDays,
                });
                this.optimizationResult = { success: true, applied: false, message: response.data.message };
                await this.loadOptimizationStatus();
            } catch (error) {
                this.optimizationError = error.response?.data?.message || '自动优化设置保存失败，请稍后重试。';
            } finally {
                this.optimizationBusy = '';
            }
        },
        formatOptimizationDate(value) {
            return value ? new Date(value).toLocaleString() : '—';
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
    grid-template-columns: 150px minmax(220px, 1fr) 170px 170px auto;
    gap: 12px;
    align-items: center;
}
.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
}
.memory-grid, .pressure-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
}
.optimization-policy-grid {
    display: grid;
    grid-template-columns: minmax(180px, 1fr) minmax(140px, 180px) auto;
    gap: 12px;
    align-items: center;
}
.memory-value { margin-top: 4px; font-size: 24px; font-weight: 600; font-variant-numeric: tabular-nums; }
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
    .memory-grid, .pressure-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .optimization-policy-grid { grid-template-columns: 1fr; }
    .heatmap { grid-template-columns: repeat(15, minmax(6px, 1fr)); }
    .statistics-v3 { padding-left: 0; padding-right: 0; }
}
@media (min-width: 701px) and (max-width: 1100px) {
    .scope-controls { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>
