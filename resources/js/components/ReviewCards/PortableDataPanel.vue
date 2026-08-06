<template>
    <v-expansion-panels flat class="mb-3 portable-data-panel">
        <v-expansion-panel>
            <v-expansion-panel-header>
                <span><v-icon small class="mr-2">mdi-package-variant-closed</v-icon>Portable Data 与 Anki 互操作</span>
            </v-expansion-panel-header>
            <v-expansion-panel-content>
                <v-row dense>
                    <v-col cols="12" md="6">
                        <div class="text-subtitle-2 mb-1">固定模板导出</div>
                        <p class="text-caption text--secondary mb-2">
                            一个 WordSense 对应一张 Sense Card；.apkg / JSON / CSV 默认不携带学习历史。
                        </p>
                        <v-checkbox
                            v-model="includeScheduling"
                            dense
                            hide-details
                            class="mt-0 mb-2"
                            label="显式为 .apkg / JSON / CSV 包含可映射的调度状态"
                        />
                        <v-checkbox
                            v-model="includeMedia"
                            dense
                            hide-details
                            class="mt-0 mb-2"
                            label="显式在全量 .lcpkg 中包含 MP3/M4A（默认不包含）"
                        />
                        <div class="d-flex flex-wrap" style="gap: 6px;">
                            <v-btn small outlined color="primary" :loading="loading === 'apkg'" @click="download('apkg')">
                                <v-icon small left>mdi-download</v-icon>.apkg
                            </v-btn>
                            <v-btn small outlined :loading="loading === 'json'" @click="download('json')">JSON</v-btn>
                            <v-btn small outlined :loading="loading === 'csv'" @click="download('csv')">CSV</v-btn>
                            <v-btn small outlined color="secondary" :loading="loading === 'full'" @click="download('full')">
                                全量 .lcpkg
                            </v-btn>
                            <v-btn
                                small
                                outlined
                                data-testid="check-media"
                                :loading="mediaCheckLoading"
                                @click="checkMedia"
                            >
                                <v-icon small left>mdi-folder-search-outline</v-icon>检查媒体
                            </v-btn>
                        </div>
                    </v-col>
                    <v-col cols="12" md="6">
                        <div class="text-subtitle-2 mb-1">受控回导</div>
                        <p class="text-caption text--secondary mb-2">
                            仅接受固定 LinguaCafe 格式；必须先预览，应用前自动创建恢复点。
                        </p>
                        <v-file-input
                            v-model="file"
                            dense
                            outlined
                            hide-details
                            accept=".apkg,.json,.csv,.lcpkg"
                            prepend-icon="mdi-file-upload-outline"
                            label="选择 .apkg / .json / .csv / .lcpkg"
                            :disabled="importLoading"
                            @change="clearPreview"
                        />
                        <v-btn
                            small
                            color="primary"
                            class="mt-2"
                            :disabled="!file"
                            :loading="importLoading && !preview"
                            @click="previewImport"
                        >健康检查并预览</v-btn>
                    </v-col>
                </v-row>

                <v-alert v-if="error" type="error" dense outlined class="mt-3 mb-0">{{ error }}</v-alert>
                <v-alert
                    v-if="mediaCheck"
                    :type="mediaCheckIsClean ? 'success' : 'warning'"
                    dense
                    outlined
                    data-testid="media-check-result"
                    class="mt-3 mb-0"
                >
                    检查媒体：缺失 {{ mediaCheck.missing.length }} · 孤立文件 {{ mediaCheck.orphaned.length }} ·
                    重复组 {{ mediaCheck.duplicates.length }} · 不兼容 {{ mediaCheck.incompatible.length }} ·
                    未登记文件 {{ mediaCheck.untracked_files.length }}
                </v-alert>
                <v-alert v-if="preview" :type="preview.can_apply ? 'info' : 'warning'" dense outlined class="mt-3 mb-0">
                    <div class="font-weight-medium">预览：{{ preview.source_kind.toUpperCase() }}</div>
                    <div class="portable-counts mt-1">
                        新建 {{ preview.counts.create }} · 更新 {{ preview.counts.update }} ·
                        跳过 {{ preview.counts.skip }} · 冲突 {{ preview.counts.conflict }}
                        <template v-if="preview.counts.articles || preview.counts.settings">
                            · 章节 {{ preview.counts.articles }} · 设置 {{ preview.counts.settings }}
                        </template>
                        <template v-if="preview.counts.history">
                            · 历史 {{ preview.counts.history }}
                        </template>
                        <template v-if="preview.counts.media_assets || preview.counts.media_references">
                            · 媒体 {{ preview.counts.media_assets }} 个文件 / {{ preview.counts.media_references }} 个引用
                        </template>
                    </div>
                    <template v-if="preview.can_apply">
                        <v-checkbox
                            v-model="confirmed"
                            dense
                            hide-details
                            class="mt-2"
                            label="我已核对预览，确认应用这些变更"
                        />
                        <v-btn
                            small
                            color="primary"
                            class="mt-2"
                            :disabled="!confirmed"
                            :loading="importLoading"
                            @click="applyImport"
                        >创建恢复点并应用</v-btn>
                    </template>
                    <div v-else class="mt-2">请修正冲突后重新导出或上传；系统不会自动覆盖。</div>
                </v-alert>
            </v-expansion-panel-content>
        </v-expansion-panel>
    </v-expansion-panels>
</template>

<script>
import axios from 'axios';

export default {
    name: 'PortableDataPanel',
    props: {
        filterState: { type: Object, required: true },
    },
    data() {
        return {
            includeScheduling: false,
            includeMedia: false,
            loading: '',
            importLoading: false,
            file: null,
            preview: null,
            confirmed: false,
            error: '',
            mediaCheckLoading: false,
            mediaCheck: null,
        };
    },
    computed: {
        mediaCheckIsClean() {
            if (!this.mediaCheck) return false;
            return ['missing', 'orphaned', 'duplicates', 'incompatible', 'untracked_files']
                .every((key) => this.mediaCheck[key].length === 0);
        },
    },
    methods: {
        download(kind) {
            const endpoints = {
                apkg: '/review-cards/manage/portable/export-anki',
                json: '/review-cards/manage/portable/export-json',
                csv: '/review-cards/manage/portable/export-csv',
                full: '/review-cards/manage/portable/export-full',
            };
            const fallbacks = {
                apkg: 'linguacafe-wordsenses.apkg',
                json: 'linguacafe-wordsenses.json',
                csv: 'linguacafe-wordsenses.csv',
                full: 'linguacafe-portable-data.lcpkg',
            };
            this.loading = kind;
            this.error = '';
            axios.get(endpoints[kind], {
                params: {
                    ...this.filterState,
                    include_scheduling: kind !== 'full' && this.includeScheduling ? 1 : 0,
                    include_media: kind === 'full' && this.includeMedia ? 1 : 0,
                },
                responseType: 'blob',
            }).then((response) => {
                const disposition = response.headers['content-disposition'] || '';
                const match = disposition.match(/filename="?([^";]+)"?/i);
                const name = match ? match[1] : fallbacks[kind];
                const url = URL.createObjectURL(response.data);
                const link = document.createElement('a');
                link.href = url;
                link.download = name;
                document.body.appendChild(link);
                link.click();
                link.remove();
                URL.revokeObjectURL(url);
                this.$emit('notify', `已导出 ${name}。`, 'success');
            }).catch(this.handleError).finally(() => { this.loading = ''; });
        },
        clearPreview() {
            this.preview = null;
            this.confirmed = false;
            this.error = '';
        },
        checkMedia() {
            this.mediaCheckLoading = true;
            this.mediaCheck = null;
            this.error = '';
            axios.get('/media/check').then((response) => {
                this.mediaCheck = response.data;
            }).catch(this.handleError).finally(() => { this.mediaCheckLoading = false; });
        },
        previewImport() {
            if (!this.file) return;
            const data = new FormData();
            data.append('file', this.file);
            this.importLoading = true;
            this.error = '';
            axios.post('/review-cards/manage/portable/import-preview', data, {
                headers: { 'Content-Type': 'multipart/form-data' },
            }).then((response) => {
                this.preview = response.data;
                this.confirmed = false;
            }).catch(this.handleError).finally(() => { this.importLoading = false; });
        },
        applyImport() {
            if (!this.preview || !this.confirmed) return;
            this.importLoading = true;
            this.error = '';
            axios.post('/review-cards/manage/portable/import-apply', {
                preview_token: this.preview.preview_token,
                confirm: true,
            }).then((response) => {
                const data = response.data;
                this.$emit(
                    'notify',
                    `导入完成：新建 ${data.created}、更新 ${data.updated}、跳过 ${data.skipped}。恢复点 ${data.backup_id}`,
                    'success',
                );
                this.file = null;
                this.clearPreview();
                this.$emit('refresh');
            }).catch(this.handleError).finally(() => { this.importLoading = false; });
        },
        handleError(error) {
            const response = error.response;
            if (response && response.data instanceof Blob) {
                response.data.text().then((text) => {
                    try {
                        this.error = JSON.parse(text).message || '操作失败。';
                    } catch (_) {
                        this.error = '操作失败。';
                    }
                });
                return;
            }
            const errors = response?.data?.errors || {};
            this.error = Object.values(errors).flat()[0]
                || response?.data?.message
                || error.message
                || '操作失败。';
        },
    },
};
</script>

<style scoped>
.portable-data-panel {
    border: 1px solid rgba(127, 127, 127, 0.2);
    border-radius: 8px;
}
.portable-counts {
    overflow-wrap: anywhere;
}
</style>
