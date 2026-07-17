<template>
    <v-container fluid class="review-card-manage pa-4">
        <!-- Deep link error (ADR-0007): shown when an invalid / not-found
             review_card_id was opened via /review-cards/manage?review_card_id=... -->
        <v-alert
            v-if="deepLink.error"
            type="warning"
            dense
            outlined
            dismissible
            class="mb-3"
            @input="closeDeepLinkError"
        >{{ deepLink.error }}</v-alert>
        <!-- Deep link loading indicator -->
        <v-alert
            v-if="deepLink.loading"
            type="info"
            dense
            outlined
            class="mb-3"
        >
            <v-progress-circular indeterminate size="16" class="mr-2"></v-progress-circular>
            正在从学习报告打开卡片详情…
        </v-alert>
        <!-- Header -->
        <div class="d-flex flex-wrap align-center mb-2">
            <div>
                <h2 class="mb-1">复习卡管理</h2>
                <p class="text--secondary mb-0">管理词义复习卡，可批量归档、恢复或彻底删除。</p>
            </div>
        </div>

        <!-- FSRS Stats Chips -->
        <v-alert v-if="statsError" type="warning" dense text class="mb-2">{{ statsError }}</v-alert>
        <div class="d-flex flex-wrap align-center mb-2" style="gap: 4px;">
            <span class="text-caption text--secondary mr-2">FSRS 总览：</span>
            <v-chip v-for="(chip, idx) in statsChips" :key="idx" x-small outlined class="mr-1 mb-1">
                {{ chip.label }} {{ chip.value }}
            </v-chip>
            <!-- ADR-0011: Leech summary chip. Shows the leech / struggling
                 counts from GET /review-cards/manage/leech-summary. Loaded
                 on mount and after bulk operations. -->
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
            <v-btn x-small text color="info" class="ml-1 mb-1" @click="openLifecycleStateHelp">
                <v-icon x-small left>mdi-help-circle-outline</v-icon>状态说明
            </v-btn>
        </div>

        <review-card-search-surface
            :filter-state="currentFilterState"
            :language="language"
            :initial-saved-search-id="deepLinkedSavedSearchId"
            :search-meta="searchMeta"
            :search-errors="searchErrors"
            @apply="applySearchFilterState"
        />

        <review-card-table-surface
            ref="tableSurface"
            :items="items"
            :pagination="pagination"
            :loading="loading"
            :error="error"
            :search-errors="searchErrors"
            :search-meta="searchMeta"
            :filter-state="currentFilterState"
            :per-page="perPage"
            :current-page="currentPage"
            :sort-by="sortBy"
            :sort-dir="sortDir"
            :editing-id="editingId"
            :saving-id="savingId"
            :edit-form="editForm"
            :lifecycle-loading="lifecycleSurfaceState.loading"
            :lifecycle-menu-id="lifecycleSurfaceState.menuId"
            :lifecycle-descriptor="lifecycleSurfaceState.descriptor"
            :available-lifecycle-actions="lifecycleSurfaceState.availableActions"
            :bulk-lifecycle-loading="lifecycleSurfaceState.bulkLoading"
            :bulk-rewrite-loading="bulkRewriteLoading"
            :bulk-leech-suspend-loading="bulkLeechSuspendLoading"
            @page-change="changePage"
            @per-page-change="changePerPage"
            @sort-change="changeSort"
            @edit-form-update="editForm = $event"
            @edit-start="startEdit"
            @edit-save="saveEdit"
            @edit-cancel="cancelEdit"
            @detail="openDetail"
            @lifecycle-menu-toggle="onLifecycleMenuToggle"
            @lifecycle-action="onLifecycleMenuClick"
            @due-now="confirmDueNow"
            @source="viewSource"
            @reset="confirmReset"
            @rewrite-package="openRewritePackageDialog"
            @suspend-leech="suspendLeechCard"
            @delete="confirmDelete"
            @bulk-lifecycle="confirmBulkLifecycle"
            @bulk-delete="confirmBulkDelete"
            @bulk-rewrite="openBulkRewritePackages"
            @bulk-leech-suspend="confirmBulkLeechSuspend"
            @notify="showSnackbar"
        />

        <!-- Source dialog -->
        <sense-example-dialog
            v-model="sourceDialog"
            :payload="sourcePayload"
            :language="language"
            :font-size="16"
        />

        <review-card-info-drawer
            v-model="detailDrawer"
            :review-card-id="detailReviewCardId"
            :deep-link-source="deepLink.active ? deepLink.source : null"
            @open-source="viewSource"
            @return-to-report="backToReport"
            @detail-loaded="onDetailLoaded"
            @detail-load-error="onDetailLoadError"
            @close="onDetailClosed"
        />

        <review-card-scheduling-mutation-surface
            ref="schedulingMutationSurface"
            @card-updated="onSchedulingCardUpdated"
            @refresh-list="loadData"
            @refresh-stats="loadFsrsStats"
            @notify="onSchedulingNotify"
            @error="onSchedulingError"
        />

        <review-card-lifecycle-mutation-surface
            ref="lifecycleMutationSurface"
            @state-change="onLifecycleStateChange"
            @clear-selection="clearTableSelection"
            @refresh-list="loadData"
            @refresh-stats="loadFsrsStats"
            @notify="showSnackbar"
            @error="onLifecycleError"
        />

        <review-card-delete-mutation-surface
            ref="deleteMutationSurface"
            @clear-selection="clearTableSelection"
            @refresh-list="loadData"
            @refresh-stats="loadFsrsStats"
            @notify="showSnackbar"
            @error="onDeleteError"
        />

        <!-- Archive confirmation dialog -->
        <v-dialog v-model="archiveDialog" max-width="480">
            <v-card>
                <v-card-title class="review-card-manage-archive-title">归档这张复习卡？</v-card-title>
                <v-card-text>
                    <p class="review-card-manage-archive-body">归档后，这张词义卡不会进入日常复习。</p>
                    <p class="review-card-manage-archive-note text--secondary">不会删除词义，不会删除复习历史，也不会改变阅读页中的来源记录。你之后可以在管理页恢复它。</p>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="archiveDialog = false">取消</v-btn>
                    <v-btn color="warning" class="review-card-manage-archive-confirm" @click="doArchive">确认归档</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Restore confirmation dialog -->
        <v-dialog v-model="restoreDialog" max-width="480">
            <v-card>
                <v-card-title class="review-card-manage-restore-title">恢复这张复习卡？</v-card-title>
                <v-card-text>
                    <p class="review-card-manage-restore-body">恢复后，这张词义卡会重新进入日常复习。</p>
                    <p class="review-card-manage-restore-note text--secondary">不会重置复习进度，也不会删除复习历史。</p>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="restoreDialog = false">取消</v-btn>
                    <v-btn color="success" class="review-card-manage-restore-confirm" @click="doRestore">确认恢复</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- ADR-0011: Single-card rewrite package dialog. Delegates to
             the SenseReviewLeechRewritePackageDialog sub-component which
             owns the POST fetch, loading state, copy buttons, and the
             "no AI" notice. -->
        <SenseReviewLeechRewritePackageDialog
            v-model="rewritePackageDialog"
            :review-card-id="rewritePackageCardId"
            :lemma="rewritePackageLemma"
            @copied="onRewriteCopied"
        />

        <!-- ADR-0011: Bulk rewrite packages dialog. Shows all packages
             generated by POST /review-cards/manage/bulk-leech-rewrite-packages.
             Each package has JSON + Markdown tabs; partial failures are
             listed per-item. No AI is called, nothing is auto-created. -->
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

        <!-- ADR-0011: Bulk leech suspend confirmation dialog. Calls the
             existing bulk-lifecycle endpoint with action=suspend and a
             dedicated source for audit. Per-item success/conflict display. -->
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
                    <v-btn text :disabled="bulkLeechSuspendLoading" @click="bulkLeechSuspendDialog = false">取消</v-btn>
                    <v-btn color="warning" :loading="bulkLeechSuspendLoading" @click="doBulkLeechSuspend">确认暂停</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Snackbar -->
        <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="3000" top>
            {{ snackbar.text }}
            <template #action="{ attrs }">
                <v-btn text v-bind="attrs" @click="snackbar.show = false">关闭</v-btn>
            </template>
        </v-snackbar>
    </v-container>
</template>

<script>
import axios from 'axios';
import SenseExampleDialog from '../Review/SenseExampleDialog.vue';
import { parseReviewCardManageLocation, stripReviewCardManageDeepLinkQuery } from '../../services/ReviewCardManageDeepLink.js';
import {
    reasonLabel as leechReasonLabel,
    suggestionLabel as leechSuggestionLabel,
    severityText as leechSeverityText,
    severityColor as leechSeverityColor,
} from '../../services/SenseReviewLeechPresentation.js';
import SenseReviewLeechRewritePackageDialog from '../Senses/SenseReviewLeechRewritePackageDialog.vue';
import ReviewCardSearchSurface from './ReviewCardSearchSurface.vue';
import ReviewCardInfoDrawer from './ReviewCardInfoDrawer.vue';
import ReviewCardTableSurface from './ReviewCardTableSurface.vue';
import ReviewCardSchedulingMutationSurface from './ReviewCardSchedulingMutationSurface.vue';
import ReviewCardLifecycleMutationSurface from './ReviewCardLifecycleMutationSurface.vue';
import ReviewCardDeleteMutationSurface from './ReviewCardDeleteMutationSurface.vue';

export default {
    components: {
        SenseExampleDialog,
        SenseReviewLeechRewritePackageDialog,
        ReviewCardSearchSurface,
        ReviewCardInfoDrawer,
        ReviewCardTableSurface,
        ReviewCardSchedulingMutationSurface,
        ReviewCardLifecycleMutationSurface,
        ReviewCardDeleteMutationSurface,
    },
    props: {
        language: {
            type: String,
            default: 'english',
        },
    },
    data() {
        return {
            items: [],
            pagination: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
            loading: false,
            error: '',
            browserFilterState: {
                q: '',
                filter: 'active',
                sort_by: 'id',
                sort_dir: 'desc',
                fsrs_states: [],
                due_range: 'all',
                reps_min: null,
                lapses_min: null,
            },
            perPage: 20,
            currentPage: 1,
            sortBy: 'id',
            sortDir: 'desc',
            editingId: null,
            savingId: null,
            editForm: {},
            sourceDialog: false,
            sourcePayload: {},
            detailDrawer: false,
            detailReviewCardId: null,
            // Read-only projection published by ReviewCardLifecycleMutationSurface.
            // The child remains the sole request, target, lock and dialog owner.
            lifecycleSurfaceState: {
                menuId: null,
                descriptor: null,
                availableActions: [],
                loading: false,
                bulkLoading: false,
            },
            archiveDialog: false,
            archiveTarget: null,
            restoreDialog: false,
            restoreTarget: null,
            // ADR-0012: Server-authoritative search response state. The
            // dedicated search surface owns input/filter UI and presents it.
            searchMeta: null,
            searchErrors: [],
            // Snapshot of the current table selection while a parent-owned
            // bulk mutation dialog or request is active. Ongoing checkbox
            // selection remains owned by ReviewCardTableSurface.
            bulkSelectionIds: [],
            bulkSelectionItems: [],
            snackbar: { show: false, text: '', color: 'success' },
            // Deep link state (ADR-0007): when the page is opened via
            // /review-cards/manage?review_card_id=...&from=daily-report,
            // we load the exact card detail without depending on list
            // pagination/filters.
            deepLink: {
                active: false,
                loading: false,
                error: '',
                source: null,
                reviewCardId: null,
            },
            // FSRS stats
            statsLoading: false,
            statsError: '',
            fsrsStats: {
                total: 0,
                enabled: 0,
                archived: 0,
                due: 0,
                by_state: { new: 0, learning: 0, review: 0, relearning: 0 },
                average_stability: null,
                average_difficulty: null,
                lapses_total: 0,
                reviewed_today: 0,
                reset_count: 0,
            },
            // ADR-0011: Leech summary chip (top stats area).
            // Loaded on mount via GET /review-cards/manage/leech-summary.
            leechSummary: {
                counts: { stable: 0, struggling: 0, leech: 0 },
                leech_card_ids: [],
                struggling_card_ids: [],
            },
            leechSummaryLoaded: false,
            // ADR-0011: Single-card rewrite package dialog.
            rewritePackageDialog: false,
            rewritePackageCardId: 0,
            rewritePackageLemma: '',
            // ADR-0011: Bulk rewrite packages dialog.
            bulkRewriteDialog: false,
            bulkRewriteLoading: false,
            bulkRewriteError: '',
            bulkRewriteIds: [],
            bulkRewritePackages: [],
            bulkRewriteFailed: [],
            bulkRewriteOpenPanel: undefined,
            // ADR-0011: Bulk leech suspend dialog.
            bulkLeechSuspendDialog: false,
            bulkLeechSuspendLoading: false,
        };
    },
    computed: {
        deepLinkedSavedSearchId() {
            const value = Number(this.$route?.query?.saved_search_id);
            return Number.isInteger(value) && value > 0 ? value : null;
        },
        currentFilterState() {
            return {
                ...this.browserFilterState,
                sort_by: this.sortBy,
                sort_dir: this.sortDir,
                fsrs_states: [...(this.browserFilterState.fsrs_states || [])],
            };
        },
        statsChips() {
            return [
                { label: '总词义卡', value: this.fsrsStats.total },
                { label: '学习中', value: this.fsrsStats.active || 0 },
                { label: '已埋藏', value: this.fsrsStats.buried || 0 },
                { label: '已暂停', value: this.fsrsStats.suspended || 0 },
                { label: '已归档', value: this.fsrsStats.archived || 0 },
                { label: '当前到期', value: this.fsrsStats.due },
                { label: '新卡', value: this.fsrsStats.by_state.new },
                { label: '学习中', value: this.fsrsStats.by_state.learning },
                { label: '复习中', value: this.fsrsStats.by_state.review },
                { label: '重新学习', value: this.fsrsStats.by_state.relearning },
                { label: '今日已复习', value: this.fsrsStats.reviewed_today },
                { label: '今日重置', value: this.fsrsStats.reset_count },
            ];
        },
    },
    mounted() {
        this.loadData();
        this.loadFsrsStats();
        this.loadLeechSummary();
        this.handleDeepLink();
    },
    methods: {
        applySearchFilterState(filterState) {
            this.browserFilterState = {
                ...filterState,
                fsrs_states: [...(filterState.fsrs_states || [])],
            };
            this.sortBy = filterState.sort_by || 'id';
            this.sortDir = filterState.sort_dir || 'desc';
            this.currentPage = 1;
            this.clearTableSelection();
            this.loadData();
        },
        loadFsrsStats() {
            this.statsLoading = true;
            this.statsError = '';
            axios.get('/review-cards/stats')
                .then((response) => {
                    this.fsrsStats = response.data;
                })
                .catch(() => {
                    this.statsError = 'FSRS 统计加载失败。';
                })
                .finally(() => {
                    this.statsLoading = false;
                });
        },
        loadData(allowPageFallback = true) {
            this.loading = true;
            this.error = '';
            // ADR-0012: Clear previous search errors on new request.
            this.searchErrors = [];

            axios.get('/review-cards/manage/data', {
                params: {
                    ...this.currentFilterState,
                    page: this.currentPage,
                    per_page: this.perPage,
                },
            })
            .then((response) => {
                this.items = response.data.items;
                this.pagination = response.data.pagination;
                this.currentPage = response.data.pagination.current_page;

                // ADR-0012: Store search_meta for chip display.
                this.searchMeta = response.data.search_meta || null;

                // Fallback: if current page is empty but total data exists, go back one page
                if (allowPageFallback && this.items.length === 0 && this.currentPage > 1 && this.pagination.total > 0) {
                    this.currentPage--;
                    this.loadData(false);
                    return;
                }
            })
            .catch((err) => {
                // ADR-0012: Handle 422 grammar errors with specific per-token
                // detail. Do NOT replace with a generic "load failed" message.
                // Preserve the user's input in the search box.
                if (err.response && err.response.status === 422 && err.response.data && err.response.data.code === 'invalid_browser_search') {
                    this.searchErrors = err.response.data.errors || [];
                    // Do NOT set this.error — the specific errors are shown
                    // in the searchErrors alert. Keep existing items intact.
                } else {
                    this.error = '加载数据失败：' + (err.response?.data?.message || err.message);
                }
            })
            .finally(() => {
                this.loading = false;
                this.editingId = null;
                this.savingId = null;
            });
        },

        clearTableSelection() {
            if (this.$refs.tableSurface) {
                this.$refs.tableSurface.clearSelection();
            }
        },

        changePage(page) {
            this.currentPage = page;
            this.loadData();
        },

        changePerPage(perPage) {
            this.perPage = perPage;
            this.currentPage = 1;
            this.loadData();
        },

        changeSort({ sortBy, sortDir }) {
            this.sortBy = sortBy;
            this.sortDir = sortDir;
            this.currentPage = 1;
            this.loadData();
        },

        startEdit(item) {
            this.editingId = item.review_card_id;
            this.editForm = {
                pos: item.pos || '',
                sense_zh: item.sense_zh || '',
                sense_en: item.sense_en || '',
                example_sentence_en: item.example_sentence_en || '',
                example_sentence_zh: item.example_sentence_zh || '',
            };
        },

        cancelEdit() {
            this.editingId = null;
            this.editForm = {};
        },

        saveEdit(item) {
            this.savingId = item.review_card_id;

            axios.patch('/review-cards/manage/' + item.review_card_id, this.editForm)
                .then((response) => {
                    const idx = this.items.findIndex(i => i.review_card_id === item.review_card_id);
                    if (idx >= 0) {
                        this.$set(this.items, idx, response.data);
                    }
                    this.editingId = null;
                    this.savingId = null;
                })
                .catch((err) => {
                    this.error = '保存失败：' + (err.response?.data?.message || err.message);
                    this.savingId = null;
                });
        },

        toggleEnabled(item) {
            const newEnabled = !item.fsrs_enabled;
            axios.patch('/review-cards/manage/' + item.review_card_id + '/enabled', {
                enabled: newEnabled,
            })
            .then(() => {
                this.showSnackbar('已恢复。该卡会重新进入日常复习。', 'success');
                this.loadData();
                this.loadFsrsStats();
            })
            .catch((err) => {
                this.error = '操作失败：' + (err.response?.data?.message || err.message);
            });
        },

        confirmArchive(item) {
            this.archiveTarget = item;
            this.archiveDialog = true;
        },

        doArchive() {
            if (!this.archiveTarget) return;
            const item = this.archiveTarget;
            this.archiveDialog = false;
            this.archiveTarget = null;

            axios.patch('/review-cards/manage/' + item.review_card_id + '/enabled', {
                enabled: false,
            })
            .then(() => {
                this.showSnackbar('已归档。该卡不会进入日常复习。', 'warning');
                this.loadData();
                this.loadFsrsStats();
            })
            .catch((err) => {
                this.error = '操作失败：' + (err.response?.data?.message || err.message);
            });
        },

        confirmRestore(item) {
            this.restoreTarget = item;
            this.restoreDialog = true;
        },

        doRestore() {
            if (!this.restoreTarget) return;
            const item = this.restoreTarget;
            this.restoreDialog = false;
            this.restoreTarget = null;

            axios.patch('/review-cards/manage/' + item.review_card_id + '/enabled', {
                enabled: true,
            })
            .then(() => {
                this.showSnackbar('已恢复。该卡会重新进入日常复习。', 'success');
                this.loadData();
                this.loadFsrsStats();
            })
            .catch((err) => {
                this.error = '操作失败：' + (err.response?.data?.message || err.message);
            });
        },

        // ==================== Lifecycle (ADR-0010 / Phase 3C-2) ====================
        // The child owns descriptor reads, mutation requests, request locks,
        // confirmation state and lifecycle help. The parent only coordinates
        // table intents and cross-region refresh/notification effects.
        onLifecycleStateChange(state) {
            this.lifecycleSurfaceState = {
                menuId: state?.menuId ?? null,
                descriptor: state?.descriptor || null,
                availableActions: [...(state?.availableActions || [])],
                loading: Boolean(state?.loading),
                bulkLoading: Boolean(state?.bulkLoading),
            };
        },
        onLifecycleMenuToggle(isOpen, item) {
            this.$refs.lifecycleMutationSurface?.handleMenuToggle(isOpen, item);
        },
        onLifecycleMenuClick(action, item) {
            this.$refs.lifecycleMutationSurface?.handleAction(action, item);
        },
        confirmBulkLifecycle(selection) {
            this.$refs.lifecycleMutationSurface?.confirmBulk(selection);
        },
        openLifecycleStateHelp() {
            this.$refs.lifecycleMutationSurface?.openStateHelp();
        },
        onLifecycleError(message) {
            this.error = message;
        },

        confirmDueNow(item) {
            this.$refs.schedulingMutationSurface.confirmDueNow(item);
        },

        confirmReset(item) {
            this.$refs.schedulingMutationSurface.confirmReset(item);
        },

        onSchedulingCardUpdated(card) {
            const reviewCardId = Number(card?.review_card_id);
            const idx = this.items.findIndex(item => item.review_card_id === reviewCardId);
            if (idx >= 0) {
                this.$set(this.items, idx, card);
            }
        },

        onSchedulingNotify(text, color) {
            this.showSnackbar(text, color);
        },

        onSchedulingError(message) {
            this.error = message;
        },

        confirmDelete(item) {
            this.$refs.deleteMutationSurface?.confirmDelete(item);
        },

        confirmBulkDelete(selection) {
            this.$refs.deleteMutationSurface?.confirmBulk(selection);
        },

        onDeleteError(message) {
            this.error = message;
        },

        showSnackbar(text, color) {
            this.snackbar = { show: true, text, color };
        },

        viewSource(item) {
            const card = {
                lemma: item.lemma,
                surface_form: item.surface_form,
                sense_zh: item.sense_zh,
                sense_en: item.sense_en,
                example_sentence_en: item.example_sentence_en,
                example_sentence_zh: item.example_sentence_zh,
            };

            axios.get('/senses/' + item.word_sense_id + '/source-context')
                .then((response) => {
                    this.sourcePayload = { card: card, context: response.data };
                    this.sourceDialog = true;
                    // If the source-context API recovered source (wrote back chapter_id),
                    // refresh the list item so the outer display updates.
                    const ctx = response.data;
                    if (ctx && (ctx.source_kind === 'chapter_recovered' || ctx.source_kind === 'chapter_fuzzy' || ctx.source_kind === 'chapter_fuzzy_title')) {
                        this.loadData();
                    }
                })
                .catch(() => {
                    this.sourcePayload = { card: card, context: null, error: '获取原文失败。' };
                    this.sourceDialog = true;
                });
        },

        // ==================== ADR-0014: Card Info drawer seam ====================
        // The child owns the canonical request and all Card Info response
        // state. The parent chooses the current target and keeps deep-link
        // parsing, source-dialog orchestration, and every mutation.
        openDetail(item) {
            const reviewCardId = Number(item?.review_card_id);
            if (!Number.isInteger(reviewCardId) || reviewCardId <= 0) return;
            this.clearDeepLinkContext();
            this.detailReviewCardId = reviewCardId;
            this.detailDrawer = true;
        },

        // --- Deep link (ADR-0007) ---
        handleDeepLink() {
            const parsed = parseReviewCardManageLocation(this.$route ? this.$route.query : {});
            if (!parsed) return;
            this.deepLink.source = parsed.from;
            this.deepLink.reviewCardId = parsed.review_card_id;
            this.loadDeepLinkDetail(parsed.review_card_id);
        },

        loadDeepLinkDetail(reviewCardId) {
            this.deepLink.loading = true;
            this.deepLink.error = '';
            this.deepLink.active = false;
            this.detailReviewCardId = reviewCardId;
            this.detailDrawer = true;
        },

        clearDeepLinkContext(error = '', replaceRoute = true) {
            const routeQuery = this.$route && this.$route.query ? this.$route.query : {};
            const hasDeepLinkQuery = Object.prototype.hasOwnProperty.call(routeQuery, 'review_card_id')
                || Object.prototype.hasOwnProperty.call(routeQuery, 'from');
            this.deepLink.active = false;
            this.deepLink.loading = false;
            this.deepLink.error = error;
            this.deepLink.source = null;
            this.deepLink.reviewCardId = null;
            if (replaceRoute && hasDeepLinkQuery && this.$router && this.$route) {
                this.$router.replace({
                    path: this.$route.path,
                    query: stripReviewCardManageDeepLinkQuery(routeQuery),
                    hash: this.$route.hash,
                }, () => {}, () => {});
            }
        },

        syncTableCurrentCard(reviewCardId) {
            const tableSurface = this.$refs.tableSurface;
            if (tableSurface && typeof tableSurface.markCurrentCardById === 'function') {
                tableSurface.markCurrentCardById(reviewCardId);
            }
        },

        onDetailLoaded(reviewCardId) {
            this.syncTableCurrentCard(reviewCardId);
            if (reviewCardId !== this.deepLink.reviewCardId) return;
            this.deepLink.loading = false;
            this.deepLink.error = '';
            this.deepLink.active = true;
        },

        onDetailLoadError(reviewCardId) {
            if (reviewCardId !== this.deepLink.reviewCardId) return;
            this.clearDeepLinkContext('未找到可管理的词义复习卡，可能已删除、被拒绝或不属于当前语言。');
            this.detailDrawer = false;
            this.detailReviewCardId = null;
        },

        closeDeepLinkError() {
            this.deepLink.error = '';
        },

        backToReport() {
            if (window.history.length > 1) {
                window.history.back();
            } else {
                window.location.href = '/reviews/senses';
            }
        },

        onDetailClosed() {
            this.clearDeepLinkContext();
            this.detailDrawer = false;
            this.detailReviewCardId = null;
        },

        // ==================== ADR-0011: Leech governance ====================
        // Load the leech summary chip counts from
        // GET /review-cards/manage/leech-summary. Called on mount and
        // after bulk operations. Non-blocking: on failure, the chip is
        // hidden (leechSummaryLoaded stays false).
        loadLeechSummary() {
            axios.get('/review-cards/manage/leech-summary')
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
                    // Non-blocking: hide the chip on failure.
                    this.leechSummaryLoaded = false;
                });
        },

        // Thin wrappers exposing the pure presentation helpers to the
        // template. Vue 2 templates can only call functions registered
        // on the instance via methods.
        leechReasonLabel,
        leechSuggestionLabel,
        leechSeverityText,
        leechSeverityColor,

        // Open the single-card rewrite package dialog.
        openRewritePackageDialog(item) {
            if (!item || !item.review_card_id) {
                return;
            }
            this.rewritePackageCardId = item.review_card_id;
            this.rewritePackageLemma = item.lemma || '';
            this.rewritePackageDialog = true;
        },

        // Snackbar feedback when a copy operation completes.
        onRewriteCopied(payload) {
            if (!payload) {
                return;
            }
            this.showSnackbar(payload.text || '已复制。', 'success');
        },

        // Per-row leech governance remains in the parent, while the
        // lifecycle child is the sole owner of the underlying POST request.
        suspendLeechCard(item) {
            if (!item || !item.review_card_id) return;
            const surface = this.$refs.lifecycleMutationSurface;
            if (!surface || typeof surface.runLifecycleAction !== 'function') return;

            surface.runLifecycleAction({
                action: 'suspend',
                item,
                expectedVersion: null,
                source: 'sense_review_leech',
            }).then((response) => {
                const alreadyApplied = response.data?.already_applied;
                this.showSnackbar(
                    alreadyApplied ? '该卡已暂停过。' : '已暂停该高遗忘卡。',
                    'success'
                );
                this.loadData();
                this.loadFsrsStats();
                this.loadLeechSummary();
            }).catch((err) => {
                const status = err.response?.status;
                if (status === 409) {
                    this.showSnackbar('卡片状态已在其他页面发生变化，已刷新。', 'warning');
                    this.loadData();
                } else if (status === 422) {
                    this.showSnackbar(err.response?.data?.message || '该操作在当前状态下不可用。', 'error');
                } else if (err.message === 'lifecycle_request_in_flight') {
                    return;
                } else if (!err.response) {
                    this.showSnackbar('网络错误，请检查连接后重试。', 'error');
                } else {
                    this.showSnackbar(err.response?.data?.message || '暂停失败。', 'error');
                }
            });
        },

        // Open the bulk rewrite packages dialog. Calls
        // POST /review-cards/manage/bulk-leech-rewrite-packages with the
        // selected ids and displays all generated packages.
        openBulkRewritePackages(selection) {
            if (!selection || selection.ids.length === 0) {
                return;
            }
            this.bulkSelectionIds = [...selection.ids];
            this.bulkSelectionItems = [...selection.items];
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
                this.bulkRewriteError = (err.response && err.response.data && err.response.data.message)
                    || '批量生成重写包失败，请稍后重试。';
            }).finally(() => {
                this.bulkRewriteLoading = false;
            });
        },

        // Format a bulk package field for display.
        formatBulkPackage(pkg, field) {
            if (!pkg) {
                return '';
            }
            if (field === 'markdown') {
                return typeof pkg.markdown === 'string' ? pkg.markdown : '';
            }
            // json
            if (typeof pkg.json === 'string') {
                return pkg.json;
            }
            return JSON.stringify(pkg.package || pkg.json || {}, null, 2);
        },

        // Copy a bulk package field to the clipboard.
        copyBulkPackage(pkg, field) {
            const text = this.formatBulkPackage(pkg, field);
            if (!text) {
                return;
            }
            if (navigator && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                navigator.clipboard.writeText(text)
                    .then(() => {
                        this.showSnackbar('已复制到剪贴板。', 'success');
                    })
                    .catch(() => {
                        this.showSnackbar('复制失败，请手动选择文本复制。', 'error');
                    });
            } else {
                this.showSnackbar('当前浏览器不支持自动复制，请手动选择文本复制。', 'warning');
            }
        },

        // Open the bulk leech suspend confirmation dialog.
        confirmBulkLeechSuspend(selection) {
            if (!selection || selection.ids.length === 0) {
                return;
            }
            this.bulkSelectionIds = [...selection.ids];
            this.bulkSelectionItems = [...selection.items];
            this.bulkLeechSuspendDialog = true;
        },

        // Bulk leech governance keeps its own dialog, while the lifecycle
        // child owns the shared bulk-lifecycle request and lock.
        doBulkLeechSuspend() {
            const surface = this.$refs.lifecycleMutationSurface;
            if (!surface || typeof surface.runBulkLifecycle !== 'function') return;
            this.bulkLeechSuspendLoading = true;
            const ids = [...this.bulkSelectionIds];

            surface.runBulkLifecycle({
                ids,
                action: 'suspend',
                source: 'manage_bulk_leech_suspend',
            }).then((response) => {
                this.bulkLeechSuspendDialog = false;
                this.clearTableSelection();
                this.bulkSelectionIds = [];
                this.bulkSelectionItems = [];
                const data = response.data || {};
                let msg = `已批量暂停：应用 ${data.applied ?? ids.length} 张`;
                if (data.skipped > 0) {
                    msg += `，跳过 ${data.skipped} 张（当前状态不允许）`;
                }
                msg += '。';
                this.showSnackbar(msg, data.skipped > 0 ? 'warning' : 'success');
                this.loadData();
                this.loadFsrsStats();
                this.loadLeechSummary();
            }).catch((err) => {
                const status = err.response?.status;
                if (status === 422) {
                    this.showSnackbar(err.response?.data?.message || '请求参数有误。', 'error');
                } else if (err.message === 'bulk_lifecycle_request_in_flight') {
                    return;
                } else if (!err.response) {
                    this.showSnackbar('网络错误，请检查连接后重试。', 'error');
                } else {
                    this.showSnackbar(err.response?.data?.message || '批量暂停失败。', 'error');
                }
            }).finally(() => {
                this.bulkLeechSuspendLoading = false;
            });
        },

    },
};
</script>

<style scoped>
.review-card-manage {
    max-width: 100%;
}

/* ADR-0011: Bulk rewrite packages dialog <pre> blocks. */
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
