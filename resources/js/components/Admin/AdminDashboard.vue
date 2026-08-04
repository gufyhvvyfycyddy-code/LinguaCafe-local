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

                <v-list v-if="backups.length" class="mt-4" two-line>
                    <v-list-item
                        v-for="backup in backups"
                        :key="backup.backup_id"
                    >
                        <v-list-item-icon>
                            <v-icon color="success">mdi-database-check</v-icon>
                        </v-list-item-icon>
                        <v-list-item-content>
                            <v-list-item-title>{{ backup.payload_file }}</v-list-item-title>
                            <v-list-item-subtitle>
                                {{ formatDate(backup.created_at) }} ·
                                {{ formatBytes(backup.size_bytes) }} ·
                                校验值 {{ backup.sha256.slice(0, 12) }}…
                            </v-list-item-subtitle>
                        </v-list-item-content>
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
    </div>
</template>

<script>
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
        };
    },
    mounted() {
        this.loadBackups();
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
