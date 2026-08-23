<template>
    <div>
        <v-expansion-panels flat class="mt-4">
            <v-expansion-panel>
                <v-expansion-panel-header>高级工具</v-expansion-panel-header>
                <v-expansion-panel-content>
                    <div class="caption grey--text mb-4">参数优化、旧卡重排和恢复默认参数属于低频操作，需要时再打开。</div>

                    <v-card outlined class="rounded-lg mb-4">
                        <v-card-title class="subtitle-1">参数优化</v-card-title>
                        <v-card-text aria-live="polite">
                            <v-progress-linear v-if="advancedToolsView.dataState === 'loading'" indeterminate color="primary" class="mb-3" />
                            <v-alert dense outlined :type="stateAlertType" class="mb-3">
                                {{ advancedToolsView.primaryMessage }}
                            </v-alert>

                            <v-progress-linear
                                v-if="['insufficient', 'ready'].includes(advancedToolsView.dataState)"
                                :value="advancedToolsView.progressPercent"
                                height="8"
                                rounded
                                color="primary"
                                class="mb-3"
                                aria-hidden="true"
                            />

                            <v-btn
                                v-if="!['loading', 'error'].includes(advancedToolsView.dataState)"
                                small
                                outlined
                                color="primary"
                                :loading="optimizationLoading"
                                :disabled="optimizationLoading || !advancedToolsView.canPreviewOptimization"
                                @click="runOptimizationPreview"
                            >
                                {{ advancedToolsView.previewButtonLabel }}
                            </v-btn>
                            <v-btn v-else-if="advancedToolsView.dataState === 'error'" small outlined color="primary" @click="loadOptimizationStatus">重新加载诊断</v-btn>

                            <div v-if="!advancedToolsView.canPreviewOptimization && ['empty', 'insufficient'].includes(advancedToolsView.dataState)" class="caption grey--text mt-2">
                                有效记录达到 300 条后，预览按钮才会启用。
                            </div>
                            <v-alert v-if="optimizationMessage" dense outlined type="error" class="mt-3 mb-0">{{ optimizationMessage }}</v-alert>

                            <div v-if="advancedToolsView.dataState !== 'error' && optimizationPreview && optimizationPreview.preview_available" class="mt-4">
                                <v-alert dense outlined type="success">{{ optimizationPreview.message }}</v-alert>
                                <v-expansion-panels flat class="mt-3">
                                    <v-expansion-panel>
                                        <v-expansion-panel-header class="body-2">查看参数明细</v-expansion-panel-header>
                                        <v-expansion-panel-content>
                                            <v-simple-table dense>
                                                <thead><tr><th>参数</th><th class="text-right">当前值</th><th class="text-right">优化后</th><th class="text-right">变化</th></tr></thead>
                                                <tbody>
                                                    <tr v-for="row in parameterRows" :key="row.label">
                                                        <td>{{ row.label }}</td>
                                                        <td class="text-right">{{ row.currentText }}</td>
                                                        <td class="text-right">{{ row.optimizedText }}</td>
                                                        <td class="text-right">{{ row.diffText }}</td>
                                                    </tr>
                                                </tbody>
                                            </v-simple-table>
                                        </v-expansion-panel-content>
                                    </v-expansion-panel>
                                </v-expansion-panels>
                                <v-alert dense outlined type="info" class="mt-3">应用后只影响之后的新评分，不会自动重排已有卡片。</v-alert>
                                <v-btn color="success" :loading="optimizationApplyLoading" :disabled="optimizationApplyLoading || !!optimizationSuccess" @click="confirmApplyOptimization">
                                    确认应用优化参数
                                </v-btn>
                            </div>
                            <v-alert v-if="optimizationSuccess" dense outlined type="success" class="mt-3 mb-0">{{ optimizationSuccess }}</v-alert>

                            <template v-if="!['loading', 'error'].includes(advancedToolsView.dataState)">
                                <v-divider class="my-4" />
                                <div class="d-flex flex-wrap align-center">
                                    <v-chip small outlined :color="parameterStateColor" class="mr-2 mb-2">{{ advancedToolsView.parameterLabel }}</v-chip>
                                    <span class="body-2 mb-2">{{ advancedToolsView.parameterDescription }}</span>
                                </div>
                                <div class="caption grey--text">参数数量：{{ advancedToolsView.parameterCount }} 个</div>
                                <div v-if="advancedToolsView.lastOptimizedAt" class="caption grey--text">最近优化：{{ formatDate(advancedToolsView.lastOptimizedAt) }}</div>
                                <v-alert
                                    v-for="warning in healthWarnings"
                                    :key="warning.code"
                                    dense
                                    outlined
                                    :type="warning.severity === 'warning' ? 'warning' : 'info'"
                                    class="mt-3 mb-0"
                                >
                                    {{ warning.message }}
                                </v-alert>

                                <v-btn
                                    class="mt-3"
                                    small
                                    outlined
                                    color="secondary"
                                    :loading="restoreLoading"
                                    :disabled="restoreLoading || !advancedToolsView.canRestoreDefaults"
                                    @click="restoreDefaultParameters"
                                >
                                    {{ advancedToolsView.restoreButtonLabel }}
                                </v-btn>
                                <v-alert v-if="restoreStatus" dense outlined type="success" class="mt-3 mb-0">{{ restoreStatus }}</v-alert>

                                <v-expansion-panels v-if="advancedToolsView.showDiagnosticDetails" v-model="diagnosticPanels" flat class="mt-4">
                                    <v-expansion-panel>
                                        <v-expansion-panel-header class="body-2">查看诊断详情</v-expansion-panel-header>
                                        <v-expansion-panel-content>
                                            <div class="caption">符合条件的卡片：{{ advancedToolsView.diagnosticDetails.eligibleCards }} 张</div>
                                            <div class="caption">不参与计算的记录：{{ advancedToolsView.diagnosticDetails.excludedReviewLogs }} 条</div>
                                            <div class="caption">其中 reset 记录：{{ advancedToolsView.diagnosticDetails.resetReviewLogs }} 条</div>
                                            <div class="caption">已确认词义卡：{{ advancedToolsView.diagnosticDetails.confirmedSenseCards }} 张</div>
                                            <div class="caption">已拒绝词义：{{ advancedToolsView.diagnosticDetails.rejectedWordSenses }} 条</div>
                                        </v-expansion-panel-content>
                                    </v-expansion-panel>
                                </v-expansion-panels>
                            </template>
                        </v-card-text>
                    </v-card>

                    <fsrs-reschedule-panel @stats-changed="$emit('stats-changed')" />
                </v-expansion-panel-content>
            </v-expansion-panel>
        </v-expansion-panels>

    </div>
</template>

<script>
import * as AdminReviewSettingsApi from '../../../services/AdminReviewSettingsApi';
import { buildFsrsAdvancedToolsPresentation } from '../../../services/FsrsAdvancedToolsPresentation';
import FsrsReschedulePanel from '../../FsrsReschedulePanel.vue';

export default {
    components: { FsrsReschedulePanel },
    data() {
        return {
            optimizationStatus: null,
            optimizationStatusLoading: true,
            optimizationStatusError: false,
            optimizationLoading: false,
            optimizationApplyLoading: false,
            optimizationMessage: '',
            optimizationPreview: null,
            optimizationSuccess: '',
            diagnosticPanels: [],
            restoreLoading: false,
            restoreStatus: '',
        };
    },
    computed: {
        advancedToolsView() {
            return buildFsrsAdvancedToolsPresentation(this.optimizationStatus, {
                loading: this.optimizationStatusLoading,
                error: this.optimizationStatusError,
            });
        },
        stateAlertType() {
            if (this.advancedToolsView.dataState === 'error') return 'error';
            if (this.advancedToolsView.dataState === 'ready') return 'success';
            if (this.advancedToolsView.dataState === 'insufficient') return 'warning';
            return 'info';
        },
        parameterStateColor() {
            if (this.advancedToolsView.parameterState === 'optimized') return 'success';
            if (this.advancedToolsView.parameterState === 'unknown') return 'warning';
            return 'grey';
        },
        parameterRows() {
            if (!this.optimizationPreview) return [];
            const current = this.optimizationPreview.current_parameters || [];
            const optimized = this.optimizationPreview.optimized_parameters || [];
            return Array.from({ length: Math.max(current.length, optimized.length) }, (_, index) => {
                const before = current[index] ?? null;
                const after = optimized[index] ?? null;
                const diff = before !== null && after !== null ? after - before : null;
                return {
                    label: `参数 ${index + 1}`,
                    currentText: before === null ? '—' : Number(before).toFixed(4),
                    optimizedText: after === null ? '—' : Number(after).toFixed(4),
                    diffText: diff === null ? '—' : `${diff >= 0 ? '+' : ''}${diff.toFixed(4)}`,
                };
            });
        },
        healthWarnings() {
            return this.optimizationStatus?.diagnostics?.health_warnings || [];
        },
    },
    mounted() {
        this.loadOptimizationStatus();
    },
    methods: {
        loadOptimizationStatus() {
            this.optimizationStatusLoading = true;
            this.optimizationStatusError = false;
            this.optimizationMessage = '';
            this.optimizationPreview = null;
            this.optimizationSuccess = '';
            AdminReviewSettingsApi.getOptimizationStatus().then(response => {
                this.optimizationStatus = response.data;
                this.diagnosticPanels = [];
            }).catch(() => {
                this.optimizationStatus = null;
                this.optimizationStatusError = true;
            }).finally(() => {
                this.optimizationStatusLoading = false;
            });
        },
        runOptimizationPreview() {
            if (!this.advancedToolsView.canPreviewOptimization) return;
            this.optimizationLoading = true;
            this.optimizationMessage = '';
            this.optimizationPreview = null;
            this.optimizationSuccess = '';
            AdminReviewSettingsApi.previewOptimization().then(response => {
                if (response.data.preview_available) this.optimizationPreview = response.data;
                else this.optimizationMessage = response.data.message || '暂时无法生成优化预览，请重新加载诊断。';
            }).catch(() => { this.optimizationMessage = '检查失败了，请稍后再试。'; })
                .finally(() => { this.optimizationLoading = false; });
        },
        confirmApplyOptimization() {
            if (!window.confirm('确认应用这组优化参数吗？已有卡片不会自动重排。')) return;
            this.optimizationApplyLoading = true;
            AdminReviewSettingsApi.applyOptimization().then(response => {
                this.optimizationSuccess = response.data.message || '优化参数已保存。';
                this.optimizationPreview = null;
                this.loadOptimizationStatus();
            }).catch(error => {
                this.optimizationMessage = error.response?.data?.message || '参数保存失败，请稍后再试。';
            }).finally(() => { this.optimizationApplyLoading = false; });
        },
        restoreDefaultParameters() {
            if (!this.advancedToolsView.canRestoreDefaults) return;
            if (!window.confirm('这只会恢复 FSRS 默认参数，不会删除学习数据，也不会自动重排已有卡片。')) return;
            this.restoreLoading = true;
            this.restoreStatus = '';
            AdminReviewSettingsApi.restoreDefaultParameters().then(response => {
                this.restoreStatus = response.data.message || '已恢复 FSRS 默认参数。';
                this.optimizationPreview = null;
                this.loadOptimizationStatus();
            }).catch(() => { window.alert('恢复默认参数失败，请稍后再试。'); })
                .finally(() => { this.restoreLoading = false; });
        },
        formatDate(value) {
            if (!value) return '—';
            const date = new Date(value);
            if (Number.isNaN(date.getTime())) return '—';
            return date.toLocaleString();
        },
    },
};
</script>
