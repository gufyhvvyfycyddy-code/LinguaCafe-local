<template>
    <div id="admin-dashboard">
        
        <!-- Title subheader -->
        <div class="d-flex subheader mt-4 mb-4 px-2 ">
            概览
            <v-spacer />
        </div>

        <!-- Admin dashboard -->
        <v-card outlined class="rounded-lg" :loading="backupCreationRequest.loading">
            <v-card-title>
                备份
            </v-card-title>
            <v-card-text>
                你可以在这里创建数据库的 .sql 备份。

                <!-- Success message -->
                <v-alert
                    v-if="!loading && backupCreationRequest.success"
                    class="rounded-lg mt-4 mb-0"
                    color="success"
                    type="success"
                    border="left"
                    dark
                >
                    数据库备份已成功创建。备份文件位于 "/storage/backup/{{ backupCreationRequest.fileName }}" 目录中。
                </v-alert>

                <!-- Error message -->
                <v-alert
                    v-if="!loading && backupCreationRequest.error"
                    class="rounded-lg mt-4 mb-0"
                    color="error"
                    type="error"
                    border="left"
                    dark
                >
                    导出数据库时发生错误。
                </v-alert>

            </v-card-text>
            <v-card-actions>
                <v-spacer />
                <v-btn 
                    rounded 
                    depressed 
                    color="primary" 
                    @click="createBackup"
                    :disabled="backupCreationRequest.loading"
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
        data: function() {
            return {
                loading: false,
                backupCreationRequest: {
                    loading: false,
                    error: false,
                    success: false,
                    fileName: '',
                }
            }
        },
        props: {
        },
        mounted() {
        },
        methods: {
            createBackup() {
                this.backupCreationRequest.error = false;
                this.backupCreationRequest.success = false;
                this.backupCreationRequest.fileName = '';
                this.backupCreationRequest.loading = true;

                axios.get('/backups/create').then((response) => {
                    
                    if (response.data.exitCode === 0) {
                        this.backupCreationRequest.loading = false;
                        this.backupCreationRequest.success = true;
                        this.backupCreationRequest.fileName = response.data.fileName;
                    } else {
                        this.backupCreationRequest.loading = false;
                        this.backupCreationRequest.error = true;
                        this.backupCreationRequest.fileName = response.data.fileName;
                    }
                }).catch((error) => {
                    this.backupCreationRequest.loading = false;
                    this.backupCreationRequest.error = true;
                });
            }
        }
    }
</script>
