<template>
    <div id="admin-dashboard">
        <div class="d-flex subheader mt-4 mb-4 px-2">
            概览
            <v-spacer />
        </div>

        <v-card outlined class="rounded-lg" :loading="loading || backupCreationRequest.loading">
            <v-card-title>
                备份
                <v-spacer />
                <v-btn
                    icon
                    :disabled="loading || backupCreationRequest.loading"
                    aria-label="刷新备份列表"
                    @click="loadBackups"
                >
                    <v-icon>mdi-refresh</v-icon>
                </v-btn>
            </v-card-title>

            <v-card-text>
                备份会先在临时目录完成数据库导出、压缩与校验，成功后才加入列表。

                <v-alert
                    v-if="backupCreationRequest.success"
                    class="rounded-lg mt-4 mb-0"
                    color="success"
                    type="success"
                    border="left"
                    dark
                >
                    备份创建成功：{{ backupCreationRequest.fileName }}
                </v-alert>

                <v-alert
                    v-if="backupCreationRequest.error"
                    class="rounded-lg mt-4 mb-0"
                    color="error"
                    type="error"
                    border="left"
                >
                    {{ backupCreationRequest.error }}
                </v-alert>

                <v-alert
                    v-if="listError"
                    class="rounded-lg mt-4 mb-0"
                    color="error"
                    type="error"
                    border="left"
                >
                    {{ listError }}
                </v-alert>

                <v-alert
                    v-if="restoreRequest.error && !restoreDialog"
                    class="rounded-lg mt-4 mb-0"
                    color="error"
                    type="error"
                    border="left"
                >
                    {{ restoreRequest.error }}
                </v-alert>

                <v-list v-if="backups.length" class="mt-4" two-line>
                    <v-list-item
                        v-for="backup in backups"
                        :key="backup.backup_id"
                    >
                        <v-list-item-icon>
                            <v-icon color="success">mdi-database-check</v-icon>
                        </v-list-item-icon>
                        <v-list-item-content>
                            <v-list-item-title class="backup-name">
                                {{ backup.payload_file }}
                            </v-list-item-title>
                            <v-list-item-subtitle>
                                {{ formatDate(backup.created_at) }} ·
                                {{ formatBytes(backup.size_bytes) }}
                            </v-list-item-subtitle>
                        </v-list-item-content>
                        <v-list-item-action>
                            <v-btn
                                small
                                outlined
                                color="warning"
                                class="restore-action-btn"
                                :disabled="restoreRequest.submitting"
                                @click="openRestoreDialog(backup)"
                            >
                                恢复
                            </v-btn>
                        </v-list-item-action>
                    </v-list-item>
                </v-list>

                <div
                    v-else-if="!loading && !listError"
                    class="text-center grey--text py-8"
                >
                    还没有成功发布的备份。
                </div>
            </v-card-text>

            <v-card-actions>
                <v-spacer />
                <v-btn
                    rounded
                    depressed
                    color="primary"
                    :loading="backupCreationRequest.loading"
                    :disabled="loading || backupCreationRequest.loading"
                    @click="createBackup"
                >
                    <v-icon class="mr-2">mdi-database-export</v-icon>
                    创建备份
                </v-btn>
            </v-card-actions>
        </v-card>

        <v-dialog
            v-model="restoreDialog"
            persistent
            max-width="720"
            class="restore-dialog"
        >
            <v-card class="restore-dialog-card">
                <v-card-title class="restore-dialog-title">
                    恢复备份
                </v-card-title>
                <v-card-text class="restore-dialog-text">
                    <v-alert type="warning" outlined class="rounded-lg restore-risk-note">
                        恢复会用所选备份替换当前数据。恢复期间应用将暂时不可写；如果恢复失败，系统会尝试自动回滚。
                    </v-alert>

                    <div class="restore-target mb-4">
                        备份：{{ selectedBackupLabel }}
                    </div>

                    <v-text-field
                        v-model="restoreConfirmation"
                        label="输入 RESTORE 以确认"
                        autocomplete="off"
                        class="restore-confirmation-input"
                        :disabled="restoreRequest.submitting"
                        @keyup.enter="confirmRestore"
                    />

                    <div
                        v-if="restoreRequest.submitting"
                        class="restore-processing text-body-2"
                    >
                        正在检查备份并准备恢复……
                    </div>

                    <v-alert
                        v-if="restoreRequest.error"
                        type="error"
                        class="rounded-lg mb-0"
                    >
                        {{ restoreRequest.error }}
                    </v-alert>
                </v-card-text>
                <v-card-actions class="restore-dialog-actions">
                    <v-spacer />
                    <v-btn
                        text
                        class="restore-cancel-btn"
                        :disabled="restoreRequest.submitting"
                        @click="closeRestoreDialog"
                    >
                        取消
                    </v-btn>
                    <v-btn
                        color="error"
                        depressed
                        class="restore-confirm-btn"
                        :loading="restoreRequest.submitting"
                        :disabled="restoreConfirmation !== 'RESTORE' || restoreRequest.submitting"
                        @click="confirmRestore"
                    >
                        确认恢复
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <v-alert
            v-if="restoreRequest.operation"
            class="rounded-lg mt-4"
            :type="restoreOperationAlertType"
            border="left"
        >
            {{ restoreOperationLabel }}
        </v-alert>
    </div>
</template>

<script>
const RESTORE_OPERATION_STORAGE_KEY = 'linguacafe.restore.operation_id';

export default {
    data() {
        return {
            loading: false,
            listError: '',
            backups: [],
            backupCreationRequest: {
                loading: false,
                error: '',
                success: false,
                fileName: '',
            },
            restoreDialog: false,
            restoreConfirmation: '',
            selectedBackup: null,
            restorePollTimer: null,
            restoreRequest: {
                submitting: false,
                error: '',
                operation: null,
            },
        };
    },
    computed: {
        selectedBackupLabel() {
            const backup = this.selectedBackup;
            if (!backup) {
                return '';
            }

            return backup.payload_file || this.formatDate(backup.created_at);
        },
        restoreOperationLabel() {
            const labels = {
                queued: '等待执行',
                dispatch_failed: '排队失败，可重新确认重试',
                running: '正在恢复',
                succeeded: '恢复成功',
                rolled_back: '恢复失败并已回滚',
                failed: '恢复失败',
                failed_manual_recovery: '恢复失败，需要人工处理',
            };

            return labels[this.restoreRequest.operation?.status] || '状态未知';
        },
        restoreOperationAlertType() {
            const status = this.restoreRequest.operation?.status;
            if (status === 'succeeded') {
                return 'success';
            }
            if (['rolled_back', 'failed', 'failed_manual_recovery', 'dispatch_failed'].includes(status)) {
                return 'error';
            }

            return 'info';
        },
    },
    mounted() {
        this.loadBackups();
        this.resumeRestorePolling();
    },
    beforeDestroy() {
        this.stopRestorePolling();
    },
    methods: {
        async loadBackups() {
            this.loading = true;
            this.listError = '';

            try {
                const response = await axios.get('/backups');
                this.backups = response.data.backups || [];
            } catch (error) {
                this.listError = '无法加载备份列表，请稍后重试。';
            } finally {
                this.loading = false;
            }
        },
        async createBackup() {
            this.backupCreationRequest.error = '';
            this.backupCreationRequest.success = false;
            this.backupCreationRequest.fileName = '';
            this.backupCreationRequest.loading = true;

            try {
                const response = await axios.post('/backups');
                const backup = response.data.backup;
                this.backupCreationRequest.success = true;
                this.backupCreationRequest.fileName = backup.payload_file;
                await this.loadBackups();
            } catch (error) {
                this.backupCreationRequest.error =
                    error.response?.data?.error?.message ||
                    '创建备份失败，已有成功备份不会被删除。';
            } finally {
                this.backupCreationRequest.loading = false;
            }
        },
        openRestoreDialog(backup) {
            this.selectedBackup = backup;
            this.restoreConfirmation = '';
            this.restoreRequest.error = '';
            this.restoreDialog = true;
        },
        closeRestoreDialog() {
            this.restoreDialog = false;
            this.restoreConfirmation = '';
            this.selectedBackup = null;
            this.restoreRequest.error = '';
        },
        async confirmRestore() {
            const backup = this.selectedBackup;
            if (!backup || this.restoreConfirmation !== 'RESTORE') {
                return;
            }

            this.restoreRequest.submitting = true;
            this.restoreRequest.error = '';

            try {
                const response = await axios.post(
                    `/backups/${backup.backup_id}/restore`,
                    {
                        confirmation: this.restoreConfirmation,
                    },
                );
                this.restoreRequest.operation = response.data.restore_operation;
                this.restoreDialog = false;
                this.startRestorePolling();
            } catch (error) {
                this.restoreRequest.error =
                    error.response?.data?.error?.message ||
                    '恢复任务无法提交，请稍后重试。';
            } finally {
                this.restoreRequest.submitting = false;
            }
        },
        startRestorePolling() {
            this.stopRestorePolling();
            this.persistRestoreOperation();
            this.pollRestoreStatus();
            this.restorePollTimer = window.setInterval(
                this.pollRestoreStatus,
                2000,
            );
        },
        stopRestorePolling() {
            if (this.restorePollTimer !== null) {
                window.clearInterval(this.restorePollTimer);
                this.restorePollTimer = null;
            }
        },
        persistRestoreOperation() {
            const operation = this.restoreRequest.operation;
            if (!operation) {
                return;
            }

            try {
                window.localStorage.setItem(
                    RESTORE_OPERATION_STORAGE_KEY,
                    operation.operation_id,
                );
            } catch (error) {
                // Storage may be unavailable; polling still works for this page session.
            }
        },
        clearRestoreOperation() {
            try {
                window.localStorage.removeItem(RESTORE_OPERATION_STORAGE_KEY);
            } catch (error) {
                // Storage may be unavailable.
            }
        },
        resumeRestorePolling() {
            let operationId = '';
            try {
                operationId = window.localStorage.getItem(RESTORE_OPERATION_STORAGE_KEY) || '';
            } catch (error) {
                return;
            }

            if (!operationId) {
                return;
            }

            this.restoreRequest.operation = { operation_id: operationId };
            this.startRestorePolling();
        },
        async pollRestoreStatus() {
            const operation = this.restoreRequest.operation;
            if (!operation) {
                return;
            }

            try {
                const response = await axios.get(
                    `/backup-restores/${operation.operation_id}`,
                );
                this.restoreRequest.operation = response.data.restore_operation;
                if (['succeeded', 'rolled_back', 'failed', 'failed_manual_recovery'].includes(
                    this.restoreRequest.operation.status,
                )) {
                    this.stopRestorePolling();
                    this.clearRestoreOperation();
                    await this.loadBackups();
                }
            } catch (error) {
                this.stopRestorePolling();
                if (error.response?.status === 404) {
                    // The operation no longer exists (expired or unknown); drop it.
                    this.clearRestoreOperation();
                }
                // Network or maintenance-window errors keep the stored operation id
                // so a later page refresh can resume polling.
            }
        },
        formatDate(value) {
            return new Date(value).toLocaleString();
        },
        formatBytes(value) {
            if (!Number.isFinite(value) || value < 1) {
                return '0 B';
            }

            const units = ['B', 'KB', 'MB', 'GB'];
            const index = Math.min(
                Math.floor(Math.log(value) / Math.log(1024)),
                units.length - 1,
            );

            return `${(value / (1024 ** index)).toFixed(index === 0 ? 0 : 1)} ${units[index]}`;
        },
    },
};
</script>

<style scoped>
.restore-dialog-card {
    max-height: 90vh;
    overflow-y: auto;
}

.restore-dialog-text {
    padding-top: 8px;
}

.restore-risk-note {
    word-break: break-word;
}

.restore-target {
    word-break: break-word;
}

.backup-name {
    word-break: break-word;
}

@media (max-width: 600px) {
    .restore-dialog >>> .v-dialog {
        max-width: 95vw !important;
    }

    .restore-dialog-actions {
        flex-wrap: wrap;
        padding-bottom: 12px;
    }

    .restore-confirm-btn,
    .restore-cancel-btn {
        min-width: 120px;
        min-height: 44px;
    }

    .restore-action-btn {
        min-width: 72px;
        min-height: 40px;
    }
}
</style>
