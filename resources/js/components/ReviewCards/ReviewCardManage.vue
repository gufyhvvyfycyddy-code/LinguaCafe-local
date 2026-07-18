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
            <review-card-leech-governance-mutation-surface
                ref="leechGovernanceMutationSurface"
                :run-lifecycle-action="runLeechLifecycleAction"
                :run-bulk-lifecycle="runLeechBulkLifecycle"
                @state-change="onLeechGovernanceStateChange"
                @clear-selection="clearTableSelection"
                @refresh-list="loadData"
                @refresh-stats="loadFsrsStats"
                @notify="showSnackbar"
                @error="onLeechGovernanceError"
            />
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
            :bulk-rewrite-loading="leechGovernanceSurfaceState.bulkRewriteLoading"
            :bulk-leech-suspend-loading="leechGovernanceSurfaceState.bulkLeechSuspendLoading"
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
import ReviewCardSearchSurface from './ReviewCardSearchSurface.vue';
import ReviewCardInfoDrawer from './ReviewCardInfoDrawer.vue';
import ReviewCardTableSurface from './ReviewCardTableSurface.vue';
import ReviewCardSchedulingMutationSurface from './ReviewCardSchedulingMutationSurface.vue';
import ReviewCardLifecycleMutationSurface from './ReviewCardLifecycleMutationSurface.vue';
import ReviewCardDeleteMutationSurface from './ReviewCardDeleteMutationSurface.vue';
import ReviewCardLeechGovernanceMutationSurface from './ReviewCardLeechGovernanceMutationSurface.vue';

export default {
    components: {
        SenseExampleDialog,
        ReviewCardSearchSurface,
        ReviewCardInfoDrawer,
        ReviewCardTableSurface,
        ReviewCardSchedulingMutationSurface,
        ReviewCardLifecycleMutationSurface,
        ReviewCardDeleteMutationSurface,
        ReviewCardLeechGovernanceMutationSurface,
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
            // Read-only loading projection published by the Leech governance owner.
            leechGovernanceSurfaceState: {
                bulkRewriteLoading: false,
                bulkLeechSuspendLoading: false,
            },
            archiveDialog: false,
            archiveTarget: null,
            restoreDialog: false,
            restoreTarget: null,
            // ADR-0012: Server-authoritative search response state. The
            // dedicated search surface owns input/filter UI and presents it.
            searchMeta: null,
            searchErrors: [],
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

        // ==================== ADR-0011 / Phase 3C-4: Leech governance ====================
        // The child owns Leech summary, rewrite-package requests, dialogs and
        // orchestration. The parent only forwards table intents and bridges
        // lifecycle writes to the existing lifecycle request owner.
        onLeechGovernanceStateChange(state) {
            this.leechGovernanceSurfaceState = {
                bulkRewriteLoading: Boolean(state?.bulkRewriteLoading),
                bulkLeechSuspendLoading: Boolean(state?.bulkLeechSuspendLoading),
            };
        },
        openRewritePackageDialog(item) {
            this.$refs.leechGovernanceMutationSurface?.openRewritePackage(item);
        },
        suspendLeechCard(item) {
            this.$refs.leechGovernanceMutationSurface?.suspendLeech(item);
        },
        openBulkRewritePackages(selection) {
            this.$refs.leechGovernanceMutationSurface?.openBulkRewrite(selection);
        },
        confirmBulkLeechSuspend(selection) {
            this.$refs.leechGovernanceMutationSurface?.confirmBulkSuspend(selection);
        },
        runLeechLifecycleAction(options) {
            const surface = this.$refs.lifecycleMutationSurface;
            if (!surface || typeof surface.runLifecycleAction !== 'function') {
                return Promise.reject(new Error('lifecycle_surface_unavailable'));
            }
            return surface.runLifecycleAction(options);
        },
        runLeechBulkLifecycle(options) {
            const surface = this.$refs.lifecycleMutationSurface;
            if (!surface || typeof surface.runBulkLifecycle !== 'function') {
                return Promise.reject(new Error('lifecycle_surface_unavailable'));
            }
            return surface.runBulkLifecycle(options);
        },
        onLeechGovernanceError(message) {
            this.error = message;
        },

    },
};
</script>

<style scoped>
.review-card-manage {
    max-width: 100%;
}
</style>
