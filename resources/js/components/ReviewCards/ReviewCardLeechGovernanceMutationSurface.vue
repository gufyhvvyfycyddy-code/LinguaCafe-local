<template>
    <span class="review-card-leech-governance-mutation-surface">
        <v-chip
            v-if="leechSummaryLoaded"
            x-small
            outlined
            color="error"
            class="mr-1 mb-1"
            :title="'高遗忘 ' + leechSummary.counts.leech + ' · 需关注 ' + leechSummary.counts.struggling"
        >
            高遗忘 {{ leechSummary.counts.leech }}
        </v-chip>
        <v-chip
            v-if="leechSummaryLoaded"
            x-small
            outlined
            color="warning"
            class="mr-1 mb-1"
        >
            需关注 {{ leechSummary.counts.struggling }}
        </v-chip>

        <SenseReviewLeechRewritePackageDialog
            v-model="rewritePackageDialog"
            :review-card-id="rewritePackageCardId"
            :lemma="rewritePackageLemma"
            @copied="onRewriteCopied"
        />

        <v-dialog v-model="bulkRewriteDialog" max-width="900" scrollable>
            <v-card>
                <v-card-title class="d-flex align-center">
                    <v-icon small class="mr-2">mdi-package-variant-closed</v-icon>
                    批量重写提示包
                    <v-spacer />
                    <v-btn icon small @click="bulkRewriteDialog = false">
                        <v-icon small>mdi-close</v-icon>
                    </v-btn>
                </v-card-title>
                <v-alert type="warning" dense text border="left" class="mx-4 mb-0">
                    LinguaCafe 不会调用任何 AI。请手动复制以下内容到外部 AI 改写后再回到这里编辑词义。
                </v-alert>
                <v-card-text>
                    <div v-if="bulkRewriteLoading" class="d-flex align-center justify-center py-6">
                        <v-progress-circular indeterminate size="24" class="mr-3" />
                        <span class="text-body-2 text--secondary">正在生成 {{ bulkRewriteIds.length }} 个重写包…</span>
                    </div>
                    <div v-else-if="bulkRewriteError" class="text-body-2 error--text py-2">{{ bulkRewriteError }}</div>
                    <template v-else>
                        <div v-if="bulkRewritePackages.length" class="mb-2">
                            <div class="d-flex flex-wrap align-center mb-2" style="gap: 6px;">
                                <v-chip x-small outlined color="success">provider_called: false</v-chip>
                                <v-chip x-small outlined color="success">card_created: false</v-chip>
                                <v-chip x-small outlined color="success">review_log_created: false</v-chip>
                            </div>
                            <div class="text-caption text--secondary mb-2">
                                共生成 {{ bulkRewritePackages.length }} 个提示包（不调用 AI · 不创建学习卡 · 不写复习记录）
                            </div>
                            <v-expansion-panels v-model="bulkRewriteOpenPanel" accordion>
                                <v-expansion-panel
                                    v-for="(pkg, idx) in bulkRewritePackages"
                                    :key="idx"
                                >
                                    <v-expansion-panel-header class="py-1">
                                        <div class="d-flex align-center" style="gap: 6px;">
                                            <v-chip x-small outlined>#{{ pkg.review_card_id }}</v-chip>
                                            <span class="text-body-2">{{ pkg.lemma || '未命名' }}</span>
                                        </div>
                                    </v-expansion-panel-header>
                                    <v-expansion-panel-content>
                                        <div class="text-caption text--secondary mt-1 mb-1">JSON</div>
                                        <div class="d-flex justify-end mb-1">
                                            <v-btn x-small text color="primary" @click="copyBulkPackage(pkg, 'json')">
                                                <v-icon x-small left>mdi-content-copy</v-icon>复制 JSON
                                            </v-btn>
                                        </div>
                                        <pre class="bulk-rewrite-pre">{{ formatBulkPackage(pkg, 'json') }}</pre>
                                        <div class="text-caption text--secondary mt-3 mb-1">Markdown</div>
                                        <div class="d-flex justify-end mb-1">
                                            <v-btn x-small text color="primary" @click="copyBulkPackage(pkg, 'markdown')">
                                                <v-icon x-small left>mdi-content-copy</v-icon>复制 Markdown
                                            </v-btn>
                                        </div>
                                        <pre class="bulk-rewrite-pre">{{ formatBulkPackage(pkg, 'markdown') }}</pre>
                                    </v-expansion-panel-content>
                                </v-expansion-panel>
                            </v-expansion-panels>
                        </div>
                        <div v-if="bulkRewriteFailed.length" class="mt-3">
                            <div class="text-caption error--text mb-1">部分卡片生成失败（{{ bulkRewriteFailed.length }}）：</div>
                            <div
                                v-for="(failure, idx) in bulkRewriteFailed"
                                :key="'fail-' + idx"
                                class="text-body-2 mb-1"
                            >
                                <v-chip x-small outlined color="error" class="mr-1">#{{ failure.review_card_id }}</v-chip>
                                <span class="text--secondary">{{ failure.message || '生成失败' }}</span>
                            </div>
                        </div>
                    </template>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="bulkRewriteDialog = false">关闭</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <v-dialog v-model="bulkLeechSuspendDialog" max-width="520">
            <v-card>
                <v-card-title>批量暂停选中的高遗忘卡？</v-card-title>
                <v-card-text>
                    <p class="review-card-manage-bulk-lifecycle-scope">
                        只会处理你当前勾选的 {{ bulkSelectionIds.length }} 张复习卡。
                    </p>
                    <p class="text--secondary">
                        通过生命周期接口暂停，保持学习进度。可在管理页恢复复习。
                    </p>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text :disabled="bulkLeechSuspendLoading" @click="closeBulkSuspend">取消</v-btn>
                    <v-btn color="warning" :loading="bulkLeechSuspendLoading" @click="doBulkLeechSuspend">确认暂停</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </span>
</template>

<script>
import axios from 'axios';
import SenseReviewLeechRewritePackageDialog from '../Senses/SenseReviewLeechRewritePackageDialog.vue';

export default {
    name: 'ReviewCardLeechGovernanceMutationSurface',
    components: {
        SenseReviewLeechRewritePackageDialog,
    },
    props: {
        runLifecycleAction: { type: Function, required: true },
        runBulkLifecycle: { type: Function, required: true },
    },
    data() {
        return {
            leechSummary: {
                counts: { stable: 0, struggling: 0, leech: 0 },
                leech_card_ids: [],
                struggling_card_ids: [],
            },
            leechSummaryLoaded: false,
            rewritePackageDialog: false,
            rewritePackageCardId: 0,
            rewritePackageLemma: '',
            bulkRewriteDialog: false,
            bulkRewriteLoading: false,
            bulkRewriteError: '',
            bulkRewriteIds: [],
            bulkRewritePackages: [],
            bulkRewriteFailed: [],
            bulkRewriteOpenPanel: undefined,
            bulkLeechSuspendDialog: false,
            bulkLeechSuspendLoading: false,
            bulkSelectionIds: [],
        };
    },
    mounted() {
        this.loadSummary();
        this.publishState();
    },
    methods: {
        publishState() {
            this.$emit('state-change', {
                bulkRewriteLoading: this.bulkRewriteLoading,
                bulkLeechSuspendLoading: this.bulkLeechSuspendLoading,
            });
        },
        loadSummary() {
            return axios.get('/review-cards/manage/leech-summary')
                .then((response) => {
                    const data = response.data || {};
                    this.leechSummary = {
                        counts: data.counts || { stable: 0, struggling: 0, leech: 0 },
                        leech_card_ids: data.leech_card_ids || [],
                        struggling_card_ids: data.struggling_card_ids || [],
                    };
                    this.leechSummaryLoaded = true;
                })
                .catch(() => {
                    this.leechSummaryLoaded = false;
                });
        },
        openRewritePackage(item) {
            const reviewCardId = Number(item?.review_card_id);
            if (!Number.isInteger(reviewCardId) || reviewCardId <= 0) return;
            this.rewritePackageCardId = reviewCardId;
            this.rewritePackageLemma = item.lemma || '';
            this.rewritePackageDialog = true;
        },
        onRewriteCopied(payload) {
            if (!payload) return;
            this.$emit('notify', payload.text || '已复制。', 'success');
        },
        suspendLeech(item) {
            const reviewCardId = Number(item?.review_card_id);
            if (!Number.isInteger(reviewCardId) || reviewCardId <= 0) return;

            this.runLifecycleAction({
                action: 'suspend',
                item,
                expectedVersion: null,
                source: 'sense_review_leech',
            }).then((response) => {
                const alreadyApplied = response.data?.already_applied;
                this.$emit(
                    'notify',
                    alreadyApplied ? '该卡已暂停过。' : '已暂停该高遗忘卡。',
                    'success'
                );
                this.$emit('refresh-list');
                this.$emit('refresh-stats');
                this.loadSummary();
            }).catch((error) => {
                this.handleLifecycleError(error, false);
            });
        },
        openBulkRewrite(selection) {
            const ids = Array.isArray(selection?.ids)
                ? selection.ids.map(Number).filter(id => Number.isInteger(id) && id > 0)
                : [];
            if (ids.length === 0 || this.bulkRewriteLoading) return;

            this.bulkRewriteIds = [...ids];
            this.bulkRewritePackages = [];
            this.bulkRewriteFailed = [];
            this.bulkRewriteError = '';
            this.bulkRewriteOpenPanel = 0;
            this.bulkRewriteDialog = true;
            this.bulkRewriteLoading = true;
            this.publishState();

            axios.post('/review-cards/manage/bulk-leech-rewrite-packages', {
                ids: this.bulkRewriteIds,
            }).then((response) => {
                const data = response.data || {};
                this.bulkRewritePackages = Array.isArray(data.packages) ? data.packages : [];
                this.bulkRewriteFailed = Array.isArray(data.failed) ? data.failed : [];
            }).catch((error) => {
                this.bulkRewriteError = error.response?.data?.message
                    || '批量生成重写包失败，请稍后重试。';
            }).finally(() => {
                this.bulkRewriteLoading = false;
                this.publishState();
            });
        },
        formatBulkPackage(pkg, field) {
            if (!pkg) return '';
            if (field === 'markdown') {
                return typeof pkg.markdown === 'string' ? pkg.markdown : '';
            }
            if (typeof pkg.json === 'string') {
                return pkg.json;
            }
            return JSON.stringify(pkg.package || pkg.json || {}, null, 2);
        },
        copyBulkPackage(pkg, field) {
            const text = this.formatBulkPackage(pkg, field);
            if (!text) return;
            if (navigator && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                navigator.clipboard.writeText(text)
                    .then(() => this.$emit('notify', '已复制到剪贴板。', 'success'))
                    .catch(() => this.$emit('notify', '复制失败，请手动选择文本复制。', 'error'));
            } else {
                this.$emit('notify', '当前浏览器不支持自动复制，请手动选择文本复制。', 'warning');
            }
        },
        confirmBulkSuspend(selection) {
            const ids = Array.isArray(selection?.ids)
                ? selection.ids.map(Number).filter(id => Number.isInteger(id) && id > 0)
                : [];
            if (ids.length === 0 || this.bulkLeechSuspendLoading) return;
            this.bulkSelectionIds = [...ids];
            this.bulkLeechSuspendDialog = true;
        },
        closeBulkSuspend() {
            if (this.bulkLeechSuspendLoading) return;
            this.bulkLeechSuspendDialog = false;
            this.bulkSelectionIds = [];
        },
        doBulkLeechSuspend() {
            if (this.bulkLeechSuspendLoading || this.bulkSelectionIds.length === 0) return;
            const ids = [...this.bulkSelectionIds];
            this.bulkLeechSuspendLoading = true;
            this.publishState();

            this.runBulkLifecycle({
                ids,
                action: 'suspend',
                source: 'manage_bulk_leech_suspend',
            }).then((response) => {
                const data = response.data || {};
                let message = `已批量暂停：应用 ${data.applied ?? ids.length} 张`;
                if (data.skipped > 0) {
                    message += `，跳过 ${data.skipped} 张（当前状态不允许）`;
                }
                message += '。';
                this.bulkLeechSuspendDialog = false;
                this.bulkSelectionIds = [];
                this.$emit('clear-selection');
                this.$emit('notify', message, data.skipped > 0 ? 'warning' : 'success');
                this.$emit('refresh-list');
                this.$emit('refresh-stats');
                this.loadSummary();
            }).catch((error) => {
                this.handleLifecycleError(error, true);
            }).finally(() => {
                this.bulkLeechSuspendLoading = false;
                this.publishState();
            });
        },
        handleLifecycleError(error, bulk) {
            const status = error.response?.status;
            if (error.message === 'lifecycle_request_in_flight'
                || error.message === 'bulk_lifecycle_request_in_flight') {
                return;
            }
            if (error.message === 'lifecycle_surface_unavailable') {
                this.$emit('error', '生命周期操作暂不可用，请刷新页面后重试。');
                return;
            }
            if (status === 409) {
                this.$emit('notify', '卡片状态已在其他页面发生变化，已刷新。', 'warning');
                this.$emit('refresh-list');
                return;
            }
            if (status === 422) {
                this.$emit('notify', error.response?.data?.message || '该操作在当前状态下不可用。', 'error');
                return;
            }
            if (!error.response) {
                this.$emit('notify', '网络错误，请检查连接后重试。', 'error');
                return;
            }
            this.$emit('error', bulk
                ? '批量暂停失败：' + (error.response?.data?.message || error.message)
                : (error.response?.data?.message || '暂停失败。'));
        },
    },
};
</script>

<style scoped>
.bulk-rewrite-pre {
    background: #fafafa;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    padding: 10px;
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
    font-size: 11px;
    line-height: 1.45;
    max-height: 320px;
    overflow: auto;
    white-space: pre-wrap;
    word-break: break-word;
    margin: 0;
}
</style>
