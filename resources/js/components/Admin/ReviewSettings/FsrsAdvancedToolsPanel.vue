<template>
    <div>
        <v-expansion-panels flat class="mt-4">
            <v-expansion-panel>
                <v-expansion-panel-header>高级工具</v-expansion-panel-header>
                <v-expansion-panel-content>
                    <div class="caption grey--text mb-4">参数优化、旧卡重排和恢复默认参数属于低频操作，需要时再打开。</div>

                    <v-card outlined class="rounded-lg mb-4">
                        <v-card-title class="subtitle-1">自动优化参数</v-card-title>
                        <v-card-text>
                            <div class="body-2">根据正式词义复习记录计算更适合你的 FSRS 参数。</div>
                            <div class="caption grey--text mt-1">预览不会保存参数，也不会重排已有卡片。</div>
                            <v-btn class="mt-3" small outlined color="primary" :loading="optimizationLoading" :disabled="optimizationLoading" @click="runOptimizationPreview">
                                根据我的复习记录优化
                            </v-btn>

                            <v-alert v-if="optimizationMessage && !optimizationPreview" dense outlined :type="optimizationCanOptimize ? 'info' : 'warning'" class="mt-3 mb-0">
                                {{ optimizationMessage }}
                            </v-alert>

                            <div v-if="optimizationPreview && optimizationPreview.preview_available" class="mt-4">
                                <v-alert dense outlined type="success">{{ optimizationPreview.message }}</v-alert>
                                <v-row dense>
                                    <v-col cols="4"><v-sheet outlined rounded class="pa-3 text-center"><strong>{{ optimizationPreview.review_count }}</strong><div class="caption">复习记录</div></v-sheet></v-col>
                                    <v-col cols="4"><v-sheet outlined rounded class="pa-3 text-center"><strong>{{ optimizationPreview.card_count }}</strong><div class="caption">词义卡</div></v-sheet></v-col>
                                    <v-col cols="4"><v-sheet outlined rounded class="pa-3 text-center"><strong>{{ optimizationPreview.parameter_count }}</strong><div class="caption">参数</div></v-sheet></v-col>
                                </v-row>
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
                        </v-card-text>
                    </v-card>

                    <v-card outlined class="rounded-lg mb-4">
                        <v-card-title class="subtitle-1">参数来源与诊断</v-card-title>
                        <v-card-text>
                            <div class="mb-2"><strong>当前参数：</strong>{{ parameterSourceLabel }}</div>
                            <div class="caption grey--text">参数数量：{{ parameterCount }} 个</div>
                            <div v-if="parameterLastOptimizedAt" class="caption grey--text">最近优化：{{ formatDate(parameterLastOptimizedAt) }}</div>
                            <v-alert v-if="parameterWarning" dense outlined type="warning" class="mt-3">{{ parameterWarning }}</v-alert>

                            <div v-if="diagnostics" class="mt-4">
                                <v-divider class="mb-3" />
                                <div><strong>有效复习记录：</strong>{{ diagnostics.eligible_review_logs }} / {{ diagnostics.min_required }}</div>
                                <div><strong>可训练卡片：</strong>{{ diagnostics.trainable_cards }} 张</div>
                                <v-chip small outlined :color="diagnosisColor" class="mt-2">{{ diagnostics.diagnosis_message }}</v-chip>
                                <div v-if="diagnostics.excluded_review_logs > 0" class="caption grey--text mt-2">
                                    不参与计算的旧记录：{{ diagnostics.excluded_review_logs }} 条；其中 reset：{{ diagnostics.reset_review_logs }} 条。
                                </div>
                            </div>

                            <v-btn class="mt-4" small outlined color="secondary" :loading="restoreLoading" :disabled="restoreLoading" @click="restoreDefaultParameters">
                                恢复默认参数
                            </v-btn>
                            <v-alert v-if="restoreStatus" dense outlined type="success" class="mt-3 mb-0">{{ restoreStatus }}</v-alert>
                        </v-card-text>
                    </v-card>

                    <v-card outlined class="rounded-lg">
                        <v-card-title class="subtitle-1">重排已有卡片</v-card-title>
                        <v-card-text>
                            <div class="body-2">先查看到期时间变化，再单独确认。预览不会修改任何卡片。</div>
                            <v-btn class="mt-3" small outlined color="primary" :loading="reschedulePreviewLoading" :disabled="reschedulePreviewLoading" @click="previewReschedule">
                                看看重排后卡片到期日会怎么变
                            </v-btn>
                            <v-alert v-if="reschedulePreviewError" dense outlined type="error" class="mt-3">{{ reschedulePreviewError }}</v-alert>

                            <div v-if="reschedulePreview" class="mt-4">
                                <v-alert v-if="!reschedulePreview.preview_available" dense outlined type="warning">
                                    {{ firstWarning(reschedulePreview) }}
                                </v-alert>
                                <div v-else>
                                    <v-row dense>
                                        <v-col cols="6" md="3"><v-sheet outlined rounded class="pa-3 text-center"><strong>{{ reschedulePreview.total_candidates }}</strong><div class="caption">可预览</div></v-sheet></v-col>
                                        <v-col cols="6" md="3"><v-sheet outlined rounded class="pa-3 text-center"><strong>{{ reschedulePreview.total_changed }}</strong><div class="caption">会变化</div></v-sheet></v-col>
                                        <v-col cols="6" md="3"><v-sheet outlined rounded class="pa-3 text-center"><strong>{{ reschedulePreview.summary.will_move_earlier }}</strong><div class="caption">提前到期</div></v-sheet></v-col>
                                        <v-col cols="6" md="3"><v-sheet outlined rounded class="pa-3 text-center"><strong>{{ reschedulePreview.summary.will_move_later }}</strong><div class="caption">延后到期</div></v-sheet></v-col>
                                    </v-row>
                                    <v-alert v-if="reschedulePreview.risk_assessment" dense outlined :type="riskAlertType" class="mt-3">
                                        {{ reschedulePreview.risk_assessment.label }}：{{ reschedulePreview.risk_assessment.message }}
                                    </v-alert>
                                    <v-simple-table v-if="reschedulePreview.samples && reschedulePreview.samples.length" dense class="mt-3">
                                        <thead><tr><th>词</th><th>释义</th><th>当前到期</th><th>预览到期</th><th>变化</th></tr></thead>
                                        <tbody>
                                            <tr v-for="(sample, index) in reschedulePreview.samples" :key="index">
                                                <td>{{ sample.lemma }}</td>
                                                <td>{{ sample.sense_zh || sample.sense_en || '—' }}</td>
                                                <td>{{ formatDate(sample.current_due_at) }}</td>
                                                <td>{{ formatDate(sample.preview_due_at) }}</td>
                                                <td>{{ formatDaysChange(sample.days_change) }}</td>
                                            </tr>
                                        </tbody>
                                    </v-simple-table>
                                    <v-btn class="mt-4" color="warning" outlined :loading="rescheduleConfirmLoading" :disabled="rescheduleConfirmLoading || !!rescheduleSuccess" @click="openConfirmDialog">
                                        确认重排这些卡片
                                    </v-btn>
                                </div>
                            </div>
                            <v-alert v-if="rescheduleError" dense outlined type="error" class="mt-3">{{ rescheduleError }}</v-alert>
                            <v-alert v-if="rescheduleSuccess" dense outlined type="success" class="mt-3">{{ rescheduleSuccess }}</v-alert>

                            <v-divider class="my-4" />
                            <v-btn small outlined color="secondary" :loading="undoLoading" :disabled="undoLoading || rescheduleConfirmLoading" @click="openUndoDialog">撤销上次重排</v-btn>
                            <div class="caption grey--text mt-2">7 天内可撤销；重排后已经复习过的卡片不会恢复。</div>
                            <v-alert v-if="undoStatus" dense outlined :type="undoError ? 'error' : 'success'" class="mt-3 mb-0">{{ undoStatus }}</v-alert>
                        </v-card-text>
                    </v-card>

                    <v-card-actions class="px-0 mt-2"><v-btn small outlined color="primary" @click="goToManagePage">前往复习卡管理页</v-btn></v-card-actions>
                </v-expansion-panel-content>
            </v-expansion-panel>
        </v-expansion-panels>

        <v-dialog v-model="confirmDialog" max-width="480" persistent>
            <v-card>
                <v-card-title class="warning--text"><v-icon left color="warning">mdi-alert-circle-outline</v-icon>确认重排卡片</v-card-title>
                <v-card-text>
                    <p>这会修改卡片到期日，不会创建复习记录。</p>
                    <v-alert v-if="countdown > 0" dense outlined type="info">请等待 {{ countdown }} 秒后确认</v-alert>
                </v-card-text>
                <v-card-actions><v-spacer /><v-btn text @click="closeDialogs">取消</v-btn><v-btn color="warning" :disabled="countdown > 0 || rescheduleConfirmLoading" :loading="rescheduleConfirmLoading" @click="confirmReschedule">继续确认</v-btn></v-card-actions>
            </v-card>
        </v-dialog>

        <v-dialog v-model="riskDialog" max-width="480" persistent>
            <v-card>
                <v-card-title class="error--text"><v-icon left color="error">mdi-shield-alert</v-icon>高风险警告</v-card-title>
                <v-card-text>
                    <v-alert dense outlined type="error">{{ riskMessage }}</v-alert>
                    <v-alert v-if="countdown > 0" dense outlined type="info">请等待 {{ countdown }} 秒后确认</v-alert>
                </v-card-text>
                <v-card-actions><v-spacer /><v-btn text @click="closeDialogs">取消</v-btn><v-btn color="error" :disabled="countdown > 0 || rescheduleConfirmLoading" :loading="rescheduleConfirmLoading" @click="applyHighRiskReschedule">我知道风险，仍然重排</v-btn></v-card-actions>
            </v-card>
        </v-dialog>

        <v-dialog v-model="undoDialog" max-width="500" persistent>
            <v-card>
                <v-card-title>撤销上次重排？</v-card-title>
                <v-card-text>
                    <p>恢复上次重排前的到期安排。已复习卡片不会恢复，也不会修改复习历史。</p>
                    <v-alert v-if="undoCountdown > 0" dense outlined type="info">请等待 {{ undoCountdown }} 秒后确认</v-alert>
                </v-card-text>
                <v-card-actions><v-spacer /><v-btn text @click="closeUndoDialog">取消</v-btn><v-btn color="warning" :disabled="undoCountdown > 0 || undoLoading" :loading="undoLoading" @click="confirmUndo">确认撤销</v-btn></v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script>
import * as AdminReviewSettingsApi from '../../../services/AdminReviewSettingsApi';

export default {
    data() {
        return {
            optimizationLoading: false,
            optimizationApplyLoading: false,
            optimizationCanOptimize: false,
            optimizationMessage: '',
            optimizationPreview: null,
            optimizationSuccess: '',
            parameterSource: 'default',
            parameterSourceLabel: '当前使用默认参数',
            parameterLastOptimizedAt: null,
            parameterCount: 19,
            parameterWarning: '',
            diagnostics: null,
            restoreLoading: false,
            restoreStatus: '',
            reschedulePreviewLoading: false,
            reschedulePreview: null,
            reschedulePreviewError: '',
            rescheduleConfirmLoading: false,
            rescheduleError: '',
            rescheduleSuccess: '',
            confirmDialog: false,
            riskDialog: false,
            riskMessage: '',
            countdown: 0,
            countdownTimer: null,
            undoDialog: false,
            undoLoading: false,
            undoCountdown: 0,
            undoTimer: null,
            undoStatus: '',
            undoError: false,
        };
    },
    computed: {
        diagnosisColor() {
            if (!this.diagnostics) return 'grey';
            if (this.diagnostics.diagnosis_level === 'ready') return 'success';
            if (['insufficient', 'needs_more_card_history'].includes(this.diagnostics.diagnosis_level)) return 'warning';
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
        riskAlertType() {
            const level = this.reschedulePreview?.risk_assessment?.level;
            if (level === 'medium') return 'warning';
            if (level === 'high' || level === 'blocked') return 'error';
            return 'success';
        },
    },
    mounted() {
        this.loadOptimizationStatus();
    },
    beforeDestroy() {
        this.stopCountdown();
        this.stopUndoCountdown();
    },
    methods: {
        loadOptimizationStatus() {
            AdminReviewSettingsApi.getOptimizationStatus().then(response => {
                const data = response.data;
                this.optimizationCanOptimize = data.can_optimize;
                this.optimizationMessage = data.message;
                this.parameterSource = data.parameters_source || 'default';
                this.parameterSourceLabel = data.parameters_source_label || '当前使用默认参数';
                this.parameterLastOptimizedAt = data.last_optimized_at || null;
                this.parameterCount = data.parameters_count ?? 19;
                this.parameterWarning = data.parameters_warning || '';
                this.diagnostics = data.diagnostics || null;
            }).catch(() => {
                this.optimizationCanOptimize = false;
                this.optimizationMessage = '自动优化状态加载失败，请稍后再试。';
            });
        },
        runOptimizationPreview() {
            this.optimizationLoading = true;
            this.optimizationPreview = null;
            this.optimizationSuccess = '';
            AdminReviewSettingsApi.previewOptimization().then(response => {
                this.optimizationCanOptimize = response.data.can_optimize;
                if (response.data.preview_available) this.optimizationPreview = response.data;
                else this.optimizationMessage = response.data.message || '复习记录还不够，先继续复习一段时间再来优化。';
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
        previewReschedule() {
            this.reschedulePreviewLoading = true;
            this.reschedulePreviewError = '';
            this.reschedulePreview = null;
            this.rescheduleSuccess = '';
            AdminReviewSettingsApi.previewReschedule().then(response => { this.reschedulePreview = response.data; })
                .catch(() => { this.reschedulePreviewError = '重排预览加载失败，请稍后再试。'; })
                .finally(() => { this.reschedulePreviewLoading = false; });
        },
        openConfirmDialog() {
            if (!this.reschedulePreview?.preview_available) return;
            this.rescheduleError = '';
            this.confirmDialog = true;
            this.startCountdown();
        },
        confirmReschedule() {
            this.rescheduleConfirmLoading = true;
            AdminReviewSettingsApi.confirmReschedule({ preview_hash: this.reschedulePreview.preview_hash, confirm: true })
                .then(() => this.applyReschedule(false))
                .catch(error => this.handlePreflightError(error));
        },
        applyReschedule(riskConfirm) {
            this.rescheduleConfirmLoading = true;
            AdminReviewSettingsApi.confirmReschedule({
                preview_hash: this.reschedulePreview.preview_hash,
                confirm: true,
                apply: true,
                risk_confirm: riskConfirm || undefined,
            }).then(response => this.rescheduleSucceeded(response.data))
                .catch(error => this.handleRescheduleError(error));
        },
        applyHighRiskReschedule() {
            this.applyReschedule(true);
        },
        handlePreflightError(error) {
            const data = error.response?.data || {};
            this.rescheduleConfirmLoading = false;
            if (error.response?.status === 409) return this.previewExpired();
            if (error.response?.status === 422 && data.risk_level === 'high' && data.requires_risk_confirm) {
                this.confirmDialog = false;
                this.riskMessage = data.message || '重排将导致复习量显著增加。';
                this.riskDialog = true;
                this.startCountdown();
                return;
            }
            this.closeDialogs();
            this.rescheduleError = data.message || '重排检查未通过。';
        },
        handleRescheduleError(error) {
            this.rescheduleConfirmLoading = false;
            if (error.response?.status === 409) return this.previewExpired();
            const data = error.response?.data || {};
            if (error.response?.status === 422 && data.risk_level === 'high') {
                this.confirmDialog = false;
                this.riskMessage = data.message || '重排将导致复习量显著增加。';
                this.riskDialog = true;
                this.startCountdown();
                return;
            }
            this.closeDialogs();
            this.rescheduleError = data.message || '重排失败，请重新预览后再试。';
        },
        rescheduleSucceeded(data) {
            this.rescheduleSuccess = data.message || '重排完成。';
            this.rescheduleError = '';
            this.closeDialogs();
            this.$emit('stats-changed');
            this.previewReschedule();
        },
        previewExpired() {
            this.closeDialogs();
            this.reschedulePreview = null;
            this.rescheduleError = '预览已过期，请重新生成预览。';
        },
        startCountdown() {
            this.stopCountdown();
            this.countdown = 3;
            this.countdownTimer = window.setInterval(() => {
                if (this.countdown > 0) this.countdown -= 1;
                else this.stopCountdown();
            }, 1000);
        },
        stopCountdown() {
            if (this.countdownTimer) window.clearInterval(this.countdownTimer);
            this.countdownTimer = null;
        },
        closeDialogs() {
            this.stopCountdown();
            this.confirmDialog = false;
            this.riskDialog = false;
            this.countdown = 0;
            this.rescheduleConfirmLoading = false;
        },
        openUndoDialog() {
            this.undoStatus = '';
            this.undoError = false;
            this.undoDialog = true;
            this.startUndoCountdown();
        },
        startUndoCountdown() {
            this.stopUndoCountdown();
            this.undoCountdown = 3;
            this.undoTimer = window.setInterval(() => {
                if (this.undoCountdown > 0) this.undoCountdown -= 1;
                else this.stopUndoCountdown();
            }, 1000);
        },
        stopUndoCountdown() {
            if (this.undoTimer) window.clearInterval(this.undoTimer);
            this.undoTimer = null;
        },
        closeUndoDialog() {
            this.stopUndoCountdown();
            this.undoDialog = false;
            this.undoCountdown = 0;
        },
        confirmUndo() {
            this.undoLoading = true;
            AdminReviewSettingsApi.undoReschedule().then(response => {
                this.undoError = false;
                this.undoStatus = response.data.message || '撤销成功。';
                this.closeUndoDialog();
                this.reschedulePreview = null;
                this.rescheduleSuccess = '';
                this.$emit('stats-changed');
            }).catch(error => {
                this.undoError = true;
                this.undoStatus = error.response?.data?.message || '撤销请求失败，请稍后重试。';
            }).finally(() => { this.undoLoading = false; });
        },
        firstWarning(payload) {
            return payload.warnings?.[0] || '当前没有符合条件的旧卡片可预览。';
        },
        formatDate(value) {
            if (!value) return '—';
            const date = new Date(value);
            if (Number.isNaN(date.getTime())) return '—';
            return date.toLocaleString();
        },
        formatDaysChange(days) {
            if (days === null || days === undefined) return '—';
            if (days < 0) return `提前 ${Math.abs(days)} 天`;
            if (days > 0) return `延后 ${days} 天`;
            return '不变';
        },
        goToManagePage() {
            window.location.href = '/review-cards/manage';
        },
    },
};
</script>
