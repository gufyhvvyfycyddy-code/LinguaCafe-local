<template>
    <div class="d-inline-flex flex-wrap align-center">
        <v-chip
            v-if="summaryLoaded"
            x-small
            outlined
            color="error"
            class="mr-1 mb-1"
            :title="'高遗忘 ' + summary.counts.leech + ' · 需关注 ' + summary.counts.struggling"
        >
            高遗忘 {{ summary.counts.leech }}
        </v-chip>
        <v-chip
            v-if="summaryLoaded"
            x-small
            outlined
            color="warning"
            class="mr-1 mb-1"
        >
            需关注 {{ summary.counts.struggling }}
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
                                v-for="(fail, idx) in bulkRewriteFailed"
                                :key="'fail-' + idx"
                                class="text-body-2 mb-1"
                            >
                                <v-chip x-small outlined color="error" class="mr-1">#{{ fail.review_card_id }}</v-chip>
                                <span class="text--secondary">{{ fail.message || '生成失败' }}</span>
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

        <v-dialog v-model="bulkSuspendDialog" max-width="520">
            <v-card>
                <v-card-title>批量暂停选中的高遗忘卡？</v-card-title>
                <v-card-text>
                    <p class="review-card-manage-bulk-lifecycle-scope">
                        只会处理你当前勾选的 {{ bulkSuspendIds.length }} 张复习卡。
                    </p>
                    <p class="text--secondary">
                        通过生命周期接口暂停，保持学习进度。可在管理页恢复复习。
                    </p>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text :disabled="bulkSuspendLoading" @click="bulkSuspendDialog = false">取消</v-btn>
                    <v-btn color="warning" :loading="bulkSuspendLoading" @click="doBulkSuspend">确认暂停</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script>
import axios from 'axios';
import SenseReviewLeechRewritePackageDialog from '../Senses/SenseReviewLeechRewritePackageDialog.vue';

export default {
    components: { SenseReviewLeechRewritePackageDialog },
    props: {
        runLifecycleAction: { type: Function, required: true },
        runBulkLifecycle: { type: Function, required: true },
    },
    data() {
        return {
            summary: { counts: { stable: 0, struggling: 0, leech: 0 } },
            summaryLoaded: false,
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
            bulkSuspendDialog: false,
            bulkSuspendLoading: false,
            bulkSuspendIds: [],
        };
    },
    watch: {
        bulkRewriteLoading() { this.emitState(); },
        bulkSuspendLoading() { this.emitState(); },
    },
    mounted() {
        this.loadSummary();
        this.emitState();
    },
    methods: {
        emitState() {
            this.$emit('state-change', {
                bulkRewriteLoading: this.bulkRewriteLoading,
                bulkSuspendLoading: this.bulkSuspendLoading,
            });
        },
        notify(text, color) {
            this.$emit('notify', text, color);
        },
        loadSummary() {
            axios.get('/review-cards/manage/leech-summary')
                .then((response) => {
                    const data = response.data || {};
                    this.summary = {
                        counts: data.counts || { stable: 0, struggling: 0, leech: 0 },
                        leech_card_ids: data.leech_card_ids || [],
                        struggling_card_ids: data.struggling_card_ids || [],
                    };
                    this.summaryLoaded = true;
                })
                .catch(() => {
                    this.summaryLoaded = false;
                });
        },
        openRewritePackageDialog(item) {
            if (!item?.review_card_id) return;
            this.rewritePackageCardId = item.review_card_id;
            this.rewritePackageLemma = item.lemma || '';
            this.rewritePackageDialog = true;
        },
        onRewriteCopied(payload) {
            if (payload) this.notify(payload.text || '已复制。', 'success');
        },
        suspendCard(item) {
            if (!item?.review_card_id) return;
            this.runLifecycleAction({
                action: 'suspend',
                item,
                expectedVersion: null,
                source: 'sense_review_leech',
            }).then((response) => {
                this.notify(response.data?.already_applied ? '该卡已暂停过。' : '已暂停该高遗忘卡。', 'success');
                this.$emit('refresh');
                this.$emit('refresh-stats');
                this.loadSummary();
            }).catch((err) => {
                const status = err.response?.status;
                if (status === 409) {
                    this.notify('卡片状态已在其他页面发生变化，已刷新。', 'warning');
                    this.$emit('refresh');
                } else if (status === 422) {
                    this.notify(err.response?.data?.message || '该操作在当前状态下不可用。', 'error');
                } else if (err.message !== 'lifecycle_request_in_flight') {
                    this.notify(!err.response ? '网络错误，请检查连接后重试。' : (err.response?.data?.message || '暂停失败。'), 'error');
                }
            });
        },
        openBulkRewritePackages(selection) {
            if (!selection?.ids?.length) return;
            this.bulkRewriteIds = [...selection.ids];
            this.bulkRewritePackages = [];
            this.bulkRewriteFailed = [];
            this.bulkRewriteError = '';
            this.bulkRewriteOpenPanel = 0;
            this.bulkRewriteDialog = true;
            this.bulkRewriteLoading = true;
            axios.post('/review-cards/manage/bulk-leech-rewrite-packages', {
                ids: this.bulkRewriteIds,
            }).then((response) => {
                const data = response.data || {};
                this.bulkRewritePackages = Array.isArray(data.packages) ? data.packages : [];
                this.bulkRewriteFailed = Array.isArray(data.failed) ? data.failed : [];
            }).catch((err) => {
                this.bulkRewriteError = err.response?.data?.message || '批量生成重写包失败，请稍后重试。';
            }).finally(() => {
                this.bulkRewriteLoading = false;
            });
        },
        formatBulkPackage(pkg, field) {
            if (!pkg) return '';
            if (field === 'markdown') return typeof pkg.markdown === 'string' ? pkg.markdown : '';
            if (typeof pkg.json === 'string') return pkg.json;
            return JSON.stringify(pkg.package || pkg.json || {}, null, 2);
        },
        copyBulkPackage(pkg, field) {
            const text = this.formatBulkPackage(pkg, field);
            if (!text) return;
            if (navigator?.clipboard?.writeText) {
                navigator.clipboard.writeText(text)
                    .then(() => this.notify('已复制到剪贴板。', 'success'))
                    .catch(() => this.notify('复制失败，请手动选择文本复制。', 'error'));
            } else {
                this.notify('当前浏览器不支持自动复制，请手动选择文本复制。', 'warning');
            }
        },
        confirmBulkSuspend(selection) {
            if (!selection?.ids?.length) return;
            this.bulkSuspendIds = [...selection.ids];
            this.bulkSuspendDialog = true;
        },
        doBulkSuspend() {
            const ids = [...this.bulkSuspendIds];
            if (!ids.length) return;
            this.bulkSuspendLoading = true;
            this.runBulkLifecycle({
                ids,
                action: 'suspend',
                source: 'manage_bulk_leech_suspend',
            }).then((response) => {
                this.bulkSuspendDialog = false;
                this.bulkSuspendIds = [];
                const data = response.data || {};
                let message = `已批量暂停：应用 ${data.applied ?? ids.length} 张`;
                if (data.skipped > 0) message += `，跳过 ${data.skipped} 张（当前状态不允许）`;
                this.notify(message + '。', data.skipped > 0 ? 'warning' : 'success');
                this.$emit('clear-selection');
                this.$emit('refresh');
                this.$emit('refresh-stats');
                this.loadSummary();
            }).catch((err) => {
                const status = err.response?.status;
                if (status === 422) {
                    this.notify(err.response?.data?.message || '请求参数有误。', 'error');
                } else if (err.message !== 'bulk_lifecycle_request_in_flight') {
                    this.notify(!err.response ? '网络错误，请检查连接后重试。' : (err.response?.data?.message || '批量暂停失败。'), 'error');
                }
            }).finally(() => {
                this.bulkSuspendLoading = false;
            });
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
