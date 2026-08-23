<template>
    <div>
        <v-card outlined class="rounded-lg">
            <v-card-title class="subtitle-1">重排已有卡片</v-card-title>
            <v-card-text>
                <div class="body-2">优化参数不会自动改变旧卡片。需要时先查看到期时间变化，再单独确认重排；预览不会修改任何卡片。</div>
                <v-btn class="mt-3" small outlined color="primary" :loading="previewLoading" :disabled="previewLoading" @click="previewReschedule">
                    看看重排后卡片到期日会怎么变
                </v-btn>
                <v-alert v-if="previewError" dense outlined type="error" class="mt-3">{{ previewError }}</v-alert>

                <div v-if="preview" class="mt-4">
                    <v-alert v-if="!preview.preview_available" dense outlined type="warning">
                        {{ firstWarning(preview) }}
                    </v-alert>
                    <div v-else>
                        <v-row dense>
                            <v-col cols="6" md="3"><v-sheet outlined rounded class="pa-3 text-center"><strong>{{ preview.total_candidates }}</strong><div class="caption">可预览</div></v-sheet></v-col>
                            <v-col cols="6" md="3"><v-sheet outlined rounded class="pa-3 text-center"><strong>{{ preview.total_changed }}</strong><div class="caption">会变化</div></v-sheet></v-col>
                            <v-col cols="6" md="3"><v-sheet outlined rounded class="pa-3 text-center"><strong>{{ preview.summary.will_move_earlier }}</strong><div class="caption">提前到期</div></v-sheet></v-col>
                            <v-col cols="6" md="3"><v-sheet outlined rounded class="pa-3 text-center"><strong>{{ preview.summary.will_move_later }}</strong><div class="caption">延后到期</div></v-sheet></v-col>
                        </v-row>
                        <v-alert v-if="preview.risk_assessment" dense outlined :type="riskAlertType" class="mt-3">
                            {{ preview.risk_assessment.label }}：{{ preview.risk_assessment.message }}
                        </v-alert>
                        <v-simple-table v-if="preview.samples && preview.samples.length" dense class="mt-3">
                            <thead><tr><th>词</th><th>释义</th><th>当前到期</th><th>预览到期</th><th>变化</th></tr></thead>
                            <tbody>
                                <tr v-for="(sample, index) in preview.samples" :key="index">
                                    <td>{{ sample.lemma }}</td>
                                    <td>{{ sample.sense_zh || sample.sense_en || '—' }}</td>
                                    <td>{{ formatDate(sample.current_due_at) }}</td>
                                    <td>{{ formatDate(sample.preview_due_at) }}</td>
                                    <td>{{ formatDaysChange(sample.days_change) }}</td>
                                </tr>
                            </tbody>
                        </v-simple-table>
                        <v-btn class="mt-4" color="warning" outlined :loading="confirmLoading" :disabled="confirmLoading || !!success" @click="openConfirmDialog">
                            确认重排这些卡片
                        </v-btn>
                    </div>
                </div>
                <v-alert v-if="error" dense outlined type="error" class="mt-3">{{ error }}</v-alert>
                <v-alert v-if="success" dense outlined type="success" class="mt-3">{{ success }}</v-alert>

                <v-divider class="my-4" />
                <v-btn small outlined color="secondary" :loading="undoLoading" :disabled="undoLoading || confirmLoading" @click="openUndoDialog">撤销上次重排</v-btn>
                <div class="caption grey--text mt-2">7 天内可撤销；重排后已经复习过的卡片不会恢复。</div>
                <v-alert v-if="undoStatus" dense outlined :type="undoError ? 'error' : 'success'" class="mt-3 mb-0">{{ undoStatus }}</v-alert>
            </v-card-text>
        </v-card>

        <v-dialog v-model="confirmDialog" max-width="480" persistent>
            <v-card>
                <v-card-title class="warning--text"><v-icon left color="warning">mdi-alert-circle-outline</v-icon>确认重排卡片</v-card-title>
                <v-card-text>
                    <p>这会修改卡片到期日，不会创建复习记录。</p>
                    <v-alert v-if="countdown > 0" dense outlined type="info">请等待 {{ countdown }} 秒后确认</v-alert>
                </v-card-text>
                <v-card-actions><v-spacer /><v-btn text @click="closeDialogs">取消</v-btn><v-btn color="warning" :disabled="countdown > 0 || confirmLoading" :loading="confirmLoading" @click="confirmReschedule">继续确认</v-btn></v-card-actions>
            </v-card>
        </v-dialog>

        <v-dialog v-model="riskDialog" max-width="480" persistent>
            <v-card>
                <v-card-title class="error--text"><v-icon left color="error">mdi-shield-alert</v-icon>高风险警告</v-card-title>
                <v-card-text>
                    <v-alert dense outlined type="error">{{ riskMessage }}</v-alert>
                    <v-alert v-if="countdown > 0" dense outlined type="info">请等待 {{ countdown }} 秒后确认</v-alert>
                </v-card-text>
                <v-card-actions><v-spacer /><v-btn text @click="closeDialogs">取消</v-btn><v-btn color="error" :disabled="countdown > 0 || confirmLoading" :loading="confirmLoading" @click="applyHighRiskReschedule">我知道风险，仍然重排</v-btn></v-card-actions>
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
import * as AdminReviewSettingsApi from '../services/AdminReviewSettingsApi';

export default {
    data() {
        return {
            previewLoading: false,
            preview: null,
            previewError: '',
            confirmLoading: false,
            error: '',
            success: '',
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
        riskAlertType() {
            const level = this.preview?.risk_assessment?.level;
            if (level === 'medium') return 'warning';
            if (level === 'high' || level === 'blocked') return 'error';
            return 'success';
        },
    },
    beforeDestroy() {
        this.stopCountdown();
        this.stopUndoCountdown();
    },
    methods: {
        previewReschedule() {
            this.previewLoading = true;
            this.previewError = '';
            this.preview = null;
            this.success = '';
            AdminReviewSettingsApi.previewReschedule().then(response => { this.preview = response.data; })
                .catch(() => { this.previewError = '重排预览加载失败，请稍后再试。'; })
                .finally(() => { this.previewLoading = false; });
        },
        openConfirmDialog() {
            if (!this.preview?.preview_available) return;
            this.error = '';
            this.confirmDialog = true;
            this.startCountdown();
        },
        confirmReschedule() {
            this.confirmLoading = true;
            AdminReviewSettingsApi.confirmReschedule({ preview_hash: this.preview.preview_hash, confirm: true })
                .then(() => this.applyReschedule(false))
                .catch(error => this.handlePreflightError(error));
        },
        applyReschedule(riskConfirm) {
            this.confirmLoading = true;
            AdminReviewSettingsApi.confirmReschedule({
                preview_hash: this.preview.preview_hash,
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
            this.confirmLoading = false;
            if (error.response?.status === 409) return this.previewExpired();
            if (error.response?.status === 422 && data.risk_level === 'high' && data.requires_risk_confirm) {
                this.confirmDialog = false;
                this.riskMessage = data.message || '重排将导致复习量显著增加。';
                this.riskDialog = true;
                this.startCountdown();
                return;
            }
            this.closeDialogs();
            this.error = data.message || '重排检查未通过。';
        },
        handleRescheduleError(error) {
            this.confirmLoading = false;
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
            this.error = data.message || '重排失败，请重新预览后再试。';
        },
        rescheduleSucceeded(data) {
            this.success = data.message || '重排完成。';
            this.error = '';
            this.closeDialogs();
            this.$emit('stats-changed');
            this.previewReschedule();
        },
        previewExpired() {
            this.closeDialogs();
            this.preview = null;
            this.error = '预览已过期，请重新生成预览。';
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
            this.confirmLoading = false;
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
                this.preview = null;
                this.success = '';
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
    },
};
</script>
