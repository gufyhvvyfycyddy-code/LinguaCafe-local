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
            <v-btn x-small text color="info" class="ml-1 mb-1" @click="stateHelpDialog = true">
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
            :lifecycle-loading="lifecycleLoading"
            :lifecycle-menu-id="lifecycleMenuId"
            :lifecycle-descriptor="lifecycleDescriptor"
            :available-lifecycle-actions="availableLifecycleActions"
            :bulk-lifecycle-loading="bulkLifecycleLoading"
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

        <!-- ADR-0010: Lifecycle confirmation dialog (generic)
             Used for moderate lifecycle actions (suspend/archive/restore).
             Safe actions (bury/unbury/resume) execute immediately. -->
        <v-dialog v-model="lifecycleDialog" max-width="480">
            <v-card>
                <v-card-title>{{ lifecycleDialogTitle }}</v-card-title>
                <v-card-text>
                    <p>{{ lifecycleDialogHint }}</p>
                    <v-alert v-if="lifecycleConflict" type="error" dense text class="mt-2 mb-0">
                        {{ lifecycleConflict }}
                    </v-alert>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="lifecycleDialog = false" :disabled="lifecycleLoading">取消</v-btn>
                    <v-btn :color="lifecycleDialogColor" :loading="lifecycleLoading" @click="performLifecycleAction">确认</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- ADR-0010: Bulk lifecycle confirmation dialog.
             Replaces the legacy bulk-archive / bulk-restore dialogs.
             Shows the action label, hint, and selected count. -->
        <v-dialog v-model="bulkLifecycleDialog" max-width="520">
            <v-card>
                <v-card-title>{{ bulkLifecycleDialogTitle }}</v-card-title>
                <v-card-text>
                    <p class="review-card-manage-bulk-lifecycle-scope">
                        只会处理你当前勾选的 {{ bulkSelectionIds.length }} 张复习卡，不会按筛选条件全量操作。
                    </p>
                    <p class="review-card-manage-bulk-lifecycle-hint">{{ bulkLifecycleDialogHint }}</p>
                    <p class="review-card-manage-bulk-lifecycle-note text--secondary">
                        跳过当前状态不允许该操作的卡片；不删除词义或复习历史。
                    </p>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="bulkLifecycleDialog = false" :disabled="bulkLifecycleLoading">取消</v-btn>
                    <v-btn
                        :color="bulkLifecycleDialogColor"
                        :loading="bulkLifecycleLoading"
                        class="review-card-manage-bulk-lifecycle-confirm"
                        @click="doBulkLifecycle"
                    >确认</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Due now confirmation dialog -->
        <v-dialog v-model="dueNowDialog" max-width="480">
            <v-card>
                <v-card-title class="review-card-manage-due-now-title">让这张卡立即到期？</v-card-title>
                <v-card-text>
                    <p class="review-card-manage-due-now-body">确认后，这张卡会尽快出现在复习队列中。</p>
                    <p class="review-card-manage-due-now-note text--secondary">这不是一次复习评分，不会写入复习历史，也不会改变 FSRS 记忆。</p>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="dueNowDialog = false">取消</v-btn>
                    <v-btn color="primary" class="review-card-manage-due-now-confirm" @click="doDueNow">确认立即到期</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Reset confirmation dialog -->
        <v-dialog v-model="resetDialog" max-width="500">
            <v-card>
                <v-card-title class="review-card-manage-reset-title">重置这张复习卡的进度？</v-card-title>
                <v-card-text>
                    <p class="review-card-manage-reset-body">这会把这张词义卡恢复为新卡状态，并清空当前 FSRS 记忆。</p>
                    <p class="review-card-manage-reset-note text--secondary">旧复习历史会保留，并会新增一条 "reset" 记录。不会删除词义，也不会删除阅读来源。</p>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="resetDialog = false" :disabled="resetLoading">取消</v-btn>
                    <v-btn color="primary" :loading="resetLoading" class="review-card-manage-reset-confirm" @click="doReset">确认重置</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Delete confirmation dialog -->
        <v-dialog v-model="deleteDialog" max-width="500">
            <v-card>
                <v-card-title class="error--text review-card-manage-delete-title">彻底删除这张词义复习卡？</v-card-title>
                <v-card-text>
                    <p class="review-card-manage-delete-body">这会移除这张词义复习卡，并让该释义不再作为已确认词义出现在阅读页候选中。</p>
                    <p class="review-card-manage-delete-note text--secondary">复习历史会保留，阅读来源记录会保留。不会删除其他词义。</p>
                    <p class="review-card-manage-delete-last-sense text--secondary">如果这是该单词最后一个已确认词义，该单词会回到"新词"状态。</p>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="deleteDialog = false">取消</v-btn>
                    <v-btn color="error" class="review-card-manage-delete-confirm" @click="doDelete">确认彻底删除</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Bulk delete confirmation dialog -->
        <v-dialog v-model="bulkDeleteDialog" max-width="560">
            <v-card>
                <v-card-title class="error--text review-card-manage-bulk-delete-title">批量彻底删除选中的词义复习卡？</v-card-title>
                <v-card-text>
                    <p class="review-card-manage-bulk-delete-scope">只会处理你当前勾选的复习卡，不会按筛选条件全量删除。</p>
                    <div v-if="visibleBulkDeleteItems.length > 0" class="bulk-delete-list mb-3">
                        <div v-for="item in visibleBulkDeleteItems" :key="item.review_card_id" class="bulk-delete-item">
                            <span class="lemma">{{ item.lemma }}</span>
                            <span class="sense-zh">— {{ item.sense_zh || '无中文释义' }}</span>
                        </div>
                        <div v-if="hiddenBulkDeleteCount > 0" class="bulk-delete-more">
                            还有 {{ hiddenBulkDeleteCount }} 张未显示。
                        </div>
                    </div>
                    <p class="review-card-manage-bulk-delete-note text--secondary">对应释义会退出已确认词义候选，复习历史会保留，阅读来源记录会保留。</p>
                    <p class="review-card-manage-bulk-delete-last-sense text--secondary">如果某个单词没有其他已确认词义，它会回到"新词"状态。</p>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="bulkDeleteDialog = false">取消</v-btn>
                    <v-btn color="error" class="review-card-manage-bulk-delete-confirm" @click="doBulkDelete">确认批量彻底删除</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- ADR-0010: State explanation dialog.
             Shows the four lifecycle states with labels, colors, and
             one-line hints. Reset and delete are noted as separate
             operations (not lifecycle states). -->
        <v-dialog v-model="stateHelpDialog" max-width="560">
            <v-card>
                <v-card-title>复习卡生命周期状态说明</v-card-title>
                <v-card-text>
                    <v-list dense>
                        <v-list-item v-for="state in lifecycleStateHelpEntries" :key="state.key">
                            <v-list-item-icon>
                                <v-chip x-small :color="state.color">{{ state.label }}</v-chip>
                            </v-list-item-icon>
                            <v-list-item-content>
                                <v-list-item-subtitle>{{ state.hint }}</v-list-item-subtitle>
                            </v-list-item-content>
                        </v-list-item>
                    </v-list>
                    <v-divider class="my-3" />
                    <p class="text--secondary text-body-2 mb-1">
                        <strong>重置</strong>：清空 FSRS 调度进度，不影响生命周期状态。
                    </p>
                    <p class="text--secondary text-body-2 mb-0">
                        <strong>删除</strong>：永久移除复习卡，独立于生命周期状态。
                    </p>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="stateHelpDialog = false">关闭</v-btn>
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
import { parseReviewCardManageLocation } from '../../services/ReviewCardManageDeepLink.js';
import {
    actionLabel,
    actionHint,
    actionDangerLevel,
    actionColor,
    LIFECYCLE_PRESENTATION,
    LIFECYCLE_STATES,
} from '../../services/ReviewCardLifecyclePresentation.js';
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

export default {
    components: {
        SenseExampleDialog,
        SenseReviewLeechRewritePackageDialog,
        ReviewCardSearchSurface,
        ReviewCardInfoDrawer,
        ReviewCardTableSurface,
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
            // ADR-0010: Lifecycle state machine per-row actions
            // lifecycleMenuId: review_card_id whose lifecycle menu is open
            // lifecycleDescriptor: cached descriptor for the open menu
            // lifecycleLoading: true while a lifecycle POST is in flight
            // lifecycleDialog: generic confirmation dialog
            // lifecycleDialogAction: action being confirmed
            // lifecycleDialogTarget: card being acted on
            // lifecycleConflict: 409/422 error message in the dialog
            lifecycleMenuId: null,
            lifecycleDescriptor: null,
            lifecycleLoading: false,
            lifecycleDialog: false,
            lifecycleDialogAction: null,
            lifecycleDialogTarget: null,
            lifecycleConflict: '',
            archiveDialog: false,
            archiveTarget: null,
            restoreDialog: false,
            restoreTarget: null,
            dueNowDialog: false,
            dueNowTarget: null,
            resetDialog: false,
            resetTarget: null,
            resetLoading: false,
            deleteDialog: false,
            deleteTarget: null,
            bulkDeleteDialog: false,
            // ADR-0010: Bulk lifecycle operations
            bulkLifecycleLoading: false,
            bulkLifecycleDialog: false,
            bulkLifecycleAction: null,
            // ADR-0010: State explanation dialog
            stateHelpDialog: false,
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
        visibleBulkDeleteItems() {
            return this.bulkSelectionItems.slice(0, 20);
        },
        hiddenBulkDeleteCount() {
            return Math.max(this.bulkSelectionItems.length - 20, 0);
        },
        // ADR-0010: Available lifecycle actions for the open menu, from
        // the backend descriptor. Empty when descriptor not loaded.
        availableLifecycleActions() {
            if (!this.lifecycleDescriptor) {
                return [];
            }
            return this.lifecycleDescriptor.available_actions || [];
        },
        // Generic lifecycle dialog title.
        lifecycleDialogTitle() {
            if (!this.lifecycleDialogAction) {
                return '';
            }
            return '确认' + actionLabel(this.lifecycleDialogAction);
        },
        // Generic lifecycle dialog hint text.
        lifecycleDialogHint() {
            if (!this.lifecycleDialogAction) {
                return '';
            }
            return actionHint(this.lifecycleDialogAction);
        },
        // Generic lifecycle dialog button color.
        lifecycleDialogColor() {
            if (!this.lifecycleDialogAction) {
                return 'primary';
            }
            return actionColor(this.lifecycleDialogAction);
        },
        // Bulk lifecycle dialog title.
        bulkLifecycleDialogTitle() {
            if (!this.bulkLifecycleAction) {
                return '';
            }
            return '批量' + actionLabel(this.bulkLifecycleAction) + '选中的复习卡？';
        },
        // Bulk lifecycle dialog hint text.
        bulkLifecycleDialogHint() {
            if (!this.bulkLifecycleAction) {
                return '';
            }
            return actionHint(this.bulkLifecycleAction);
        },
        // Bulk lifecycle dialog button color.
        bulkLifecycleDialogColor() {
            if (!this.bulkLifecycleAction) {
                return 'primary';
            }
            return actionColor(this.bulkLifecycleAction);
        },
        // ADR-0010: State explanation entries for the help dialog.
        // Derived from the single source of truth in LIFECYCLE_PRESENTATION.
        lifecycleStateHelpEntries() {
            return LIFECYCLE_STATES.map((key) => ({
                key: key,
                label: LIFECYCLE_PRESENTATION[key].label,
                color: LIFECYCLE_PRESENTATION[key].color,
                hint: LIFECYCLE_PRESENTATION[key].hint,
            }));
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

        // ==================== Lifecycle (ADR-0010) ====================
        // Per-row lifecycle actions via POST /review-cards/{id}/lifecycle-actions.
        // The descriptor is fetched on menu open so the frontend never
        // replicates the state machine — the backend is the sole authority.
        // Thin wrappers exposing the pure presentation helpers to the template.
        // Vue 2 templates can only call functions registered on the instance.
        actionLabel,
        actionColor,
        // Menu open/close handler — fetches descriptor on open, clears on close.
        onLifecycleMenuToggle(isOpen, item) {
            if (isOpen) {
                this.lifecycleMenuId = item.review_card_id;
                this.lifecycleDescriptor = null;
                this.fetchLifecycleDescriptor(item.review_card_id);
            } else {
                // Delay clearing so the click handler can fire before the menu closes
                setTimeout(() => {
                    if (!this.lifecycleLoading) {
                        this.lifecycleMenuId = null;
                        this.lifecycleDescriptor = null;
                    }
                }, 200);
            }
        },
        fetchLifecycleDescriptor(cardId) {
            axios.get('/review-cards/' + cardId + '/lifecycle')
                .then((response) => {
                    this.lifecycleDescriptor = response.data.lifecycle || null;
                })
                .catch(() => {
                    this.lifecycleDescriptor = null;
                });
        },
        // Menu click handler: safe actions execute immediately,
        // moderate actions open the confirmation dialog.
        onLifecycleMenuClick(action, item) {
            const dangerLevel = actionDangerLevel(action);
            if (dangerLevel === 'safe') {
                this.executeLifecycleAction(action, item);
            } else {
                this.lifecycleDialogAction = action;
                this.lifecycleDialogTarget = item;
                this.lifecycleConflict = '';
                this.lifecycleDialog = true;
            }
        },
        // Execute a lifecycle action via POST.
        executeLifecycleAction(action, item) {
            const expectedVersion = this.lifecycleDescriptor
                ? this.lifecycleDescriptor.version
                : null;
            const requestId = (window.crypto && typeof window.crypto.randomUUID === 'function')
                ? window.crypto.randomUUID()
                : ('lc-' + Date.now() + '-' + Math.random().toString(36).slice(2));

            this.lifecycleLoading = true;
            axios.post('/review-cards/' + item.review_card_id + '/lifecycle-actions', {
                action: action,
                request_id: requestId,
                expected_version: expectedVersion,
                source: 'review_card_manage',
            }).then((response) => {
                this.lifecycleDialog = false;
                const label = actionLabel(action);
                const alreadyApplied = response.data?.already_applied;
                this.showSnackbar(
                    alreadyApplied ? label + '：该操作已应用过。' : '已' + label + '。',
                    'success'
                );
                this.loadData();
                this.loadFsrsStats();
            }).catch((err) => {
                const status = err.response?.status;
                if (status === 409) {
                    this.lifecycleConflict = '卡片状态已在其他页面发生变化，已刷新最新状态。';
                    this.fetchLifecycleDescriptor(item.review_card_id);
                } else if (status === 422) {
                    this.lifecycleConflict = err.response?.data?.message || '该操作在当前状态下不可用。';
                    this.fetchLifecycleDescriptor(item.review_card_id);
                } else if (!err.response) {
                    // Network error — keep dialog open for retry.
                    this.showSnackbar('网络错误，请检查连接后重试。', 'error');
                } else {
                    this.showSnackbar(err.response?.data?.message || '操作失败。', 'error');
                    this.lifecycleDialog = false;
                }
            }).finally(() => {
                this.lifecycleLoading = false;
            });
        },
        // Dialog confirm handler.
        performLifecycleAction() {
            if (!this.lifecycleDialogAction || !this.lifecycleDialogTarget) {
                return;
            }
            this.executeLifecycleAction(this.lifecycleDialogAction, this.lifecycleDialogTarget);
        },

        confirmDueNow(item) {
            this.dueNowTarget = item;
            this.dueNowDialog = true;
        },

        doDueNow() {
            if (!this.dueNowTarget) return;
            const item = this.dueNowTarget;
            this.dueNowDialog = false;
            this.dueNowTarget = null;

            axios.post('/review-cards/manage/' + item.review_card_id + '/due-now')
                .then((response) => {
                    const idx = this.items.findIndex(i => i.review_card_id === item.review_card_id);
                    if (idx >= 0) {
                        this.$set(this.items, idx, response.data);
                    }
                    this.showSnackbar('已设为立即到期。该卡会进入复习队列。', 'success');
                    this.loadFsrsStats();
                })
                .catch((err) => {
                    this.error = '操作失败：' + (err.response?.data?.message || err.message);
                });
        },

        confirmReset(item) {
            this.resetTarget = item;
            this.resetDialog = true;
        },

        doReset() {
            if (!this.resetTarget) return;
            const item = this.resetTarget;
            this.resetLoading = true;

            axios.post('/review-cards/manage/' + item.review_card_id + '/reset')
                .then((response) => {
                    this.resetDialog = false;
                    this.resetTarget = null;
                    this.showSnackbar(response.data.message || '已重置复习进度。该卡会重新进入复习队列。', 'success');
                    this.loadData();
                    this.loadFsrsStats();
                })
                .catch((err) => {
                    this.showSnackbar(err.response?.data?.message || '重置失败。', 'error');
                })
                .finally(() => {
                    this.resetLoading = false;
                });
        },

        confirmDelete(item) {
            this.deleteTarget = item;
            this.deleteDialog = true;
        },

        doDelete() {
            if (!this.deleteTarget) return;
            const item = this.deleteTarget;
            this.deleteDialog = false;
            this.deleteTarget = null;

            axios.delete('/review-cards/manage/' + item.review_card_id)
                .then((response) => {
                    this.showSnackbar(response.data.message || '已彻底删除词义复习卡。该释义不会再出现在阅读页，复习历史已保留。', 'success');
                    this.clearTableSelection();
                    this.loadData();
                    this.loadFsrsStats();
                })
                .catch((err) => {
                    this.error = '删除失败：' + (err.response?.data?.message || err.message);
                });
        },

        confirmBulkDelete(selection) {
            if (!selection || selection.ids.length === 0) return;
            this.bulkSelectionIds = [...selection.ids];
            this.bulkSelectionItems = [...selection.items];
            this.bulkDeleteDialog = true;
        },

        doBulkDelete() {
            this.bulkDeleteDialog = false;
            const ids = [...this.bulkSelectionIds];

            axios.post('/review-cards/manage/bulk-delete', { ids })
                .then((response) => {
                    this.clearTableSelection();
                    this.bulkSelectionIds = [];
                    this.bulkSelectionItems = [];
                    const data = response.data;
                    let msg = data.message || '已彻底删除词义复习卡，复习历史已保留。';
                    if (data.skipped > 0) {
                        msg += ' 其中有 ' + data.skipped + ' 张跳过处理。';
                    }
                    this.showSnackbar(msg, 'success');
                    this.loadData();
                    this.loadFsrsStats();
                })
                .catch((err) => {
                    this.error = '批量删除失败：' + (err.response?.data?.message || err.message);
                });
        },

        // ADR-0010: Open the bulk lifecycle confirmation dialog.
        // All five actions (suspend/resume/archive/restore/unbury) go
        // through the same dialog and the same bulk-lifecycle endpoint.
        confirmBulkLifecycle(selection) {
            if (!selection || selection.ids.length === 0) return;
            this.bulkSelectionIds = [...selection.ids];
            this.bulkSelectionItems = [...selection.items];
            this.bulkLifecycleAction = selection.action;
            this.bulkLifecycleDialog = true;
        },

        // ADR-0010: Execute the bulk lifecycle action via
        // POST /review-cards/manage/bulk-lifecycle.
        // The backend applies the action per-card and reports
        // applied/skipped counts; illegal transitions are skipped,
        // not raised as errors.
        doBulkLifecycle() {
            this.bulkLifecycleDialog = false;
            const ids = [...this.bulkSelectionIds];
            const action = this.bulkLifecycleAction;
            this.bulkLifecycleLoading = true;

            axios.post('/review-cards/manage/bulk-lifecycle', {
                ids: ids,
                action: action,
                source: 'review_card_manage_bulk',
            }).then((response) => {
                this.clearTableSelection();
                this.bulkSelectionIds = [];
                this.bulkSelectionItems = [];
                const data = response.data || {};
                const label = actionLabel(action);
                let msg = `已批量${label}：应用 ${data.applied ?? ids.length} 张`;
                if (data.skipped > 0) {
                    msg += `，跳过 ${data.skipped} 张（当前状态不允许）`;
                }
                msg += '。';
                this.showSnackbar(msg, data.skipped > 0 ? 'warning' : 'success');
                this.loadData();
                this.loadFsrsStats();
            }).catch((err) => {
                const status = err.response?.status;
                if (status === 422) {
                    this.showSnackbar(err.response?.data?.message || '请求参数有误。', 'error');
                } else if (!err.response) {
                    this.showSnackbar('网络错误，请检查连接后重试。', 'error');
                } else {
                    this.error = '批量操作失败：' + (err.response?.data?.message || err.message);
                }
            }).finally(() => {
                this.bulkLifecycleLoading = false;
            });
        },

        showSnackbar(text, color) {
            this.snackbar = { show: true, text, color };
        },

        setDueNow(item) {
            axios.post('/review-cards/manage/' + item.review_card_id + '/due-now')
                .then((response) => {
                    const idx = this.items.findIndex(i => i.review_card_id === item.review_card_id);
                    if (idx >= 0) {
                        this.$set(this.items, idx, response.data);
                    }
                    this.showSnackbar('已设为立即到期。该卡会进入复习队列。', 'success');
                    this.loadFsrsStats();
                })
                .catch((err) => {
                    this.error = '操作失败：' + (err.response?.data?.message || err.message);
                });
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

        onDetailLoaded(reviewCardId) {
            if (reviewCardId !== this.deepLink.reviewCardId) return;
            this.deepLink.loading = false;
            this.deepLink.error = '';
            this.deepLink.active = true;
        },

        onDetailLoadError(reviewCardId) {
            if (reviewCardId !== this.deepLink.reviewCardId) return;
            this.deepLink.loading = false;
            this.deepLink.error = '未找到可管理的词义复习卡，可能已删除、被拒绝或不属于当前语言。';
            this.deepLink.active = false;
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
            this.deepLink.loading = false;
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

        // Per-row suspend for leech cards. Calls the lifecycle endpoint
        // with action=suspend and a sense_review_leech source for audit.
        // Uses crypto.randomUUID() with a try/catch fallback.
        suspendLeechCard(item) {
            if (!item || !item.review_card_id) {
                return;
            }
            const requestId = this.generateRequestId('leech-suspend-');
            axios.post('/review-cards/' + item.review_card_id + '/lifecycle-actions', {
                action: 'suspend',
                request_id: requestId,
                expected_version: null,
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
                } else if (!err.response) {
                    this.showSnackbar('网络错误，请检查连接后重试。', 'error');
                } else {
                    this.showSnackbar(err.response?.data?.message || '暂停失败。', 'error');
                }
            });
        },

        // Generate a request_id with crypto.randomUUID() and a
        // Math.random fallback for older browsers.
        generateRequestId(prefix) {
            try {
                if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                    return window.crypto.randomUUID();
                }
            } catch (e) {
                // fall through to Math.random fallback
            }
            return (prefix || 'req-') + Date.now() + '-' + Math.random().toString(36).slice(2);
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

        // Execute the bulk leech suspend via the existing bulk-lifecycle
        // endpoint with action=suspend and a dedicated source.
        doBulkLeechSuspend() {
            this.bulkLeechSuspendLoading = true;
            const ids = [...this.bulkSelectionIds];
            axios.post('/review-cards/manage/bulk-lifecycle', {
                ids: ids,
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

.bulk-delete-list {
    max-height: 240px;
    overflow-y: auto;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    padding: 8px 12px;
    background: #fafafa;
}

.bulk-delete-item {
    padding: 4px 0;
    font-size: 0.9rem;
    line-height: 1.5;
}

.bulk-delete-item .lemma {
    font-weight: 600;
}

.bulk-delete-item .sense-zh {
    color: rgba(0, 0, 0, 0.66);
}

.bulk-delete-more {
    padding: 6px 0 2px;
    font-size: 0.85rem;
    color: rgba(0, 0, 0, 0.54);
    font-style: italic;
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
