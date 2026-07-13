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
            <v-spacer />
            <div class="d-flex flex-wrap" style="gap: 12px;">
                <div class="stat-chip text-center px-4 py-2 rounded-lg">
                    <div class="text-caption text--secondary">总计</div>
                    <div class="text-h6 font-weight-bold">{{ pagination.total }}</div>
                </div>
                <div class="stat-chip text-center px-4 py-2 rounded-lg" :class="{ 'primary--text': selectedIds.length > 0 }">
                    <div class="text-caption text--secondary">已选</div>
                    <div class="text-h6 font-weight-bold">{{ selectedIds.length }}</div>
                </div>
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

        <!-- Toolbar -->
        <v-row class="mb-3" dense align="center">
            <v-col cols="12" sm="5" md="4">
                <v-text-field
                    v-model="searchQuery"
                    label="搜索词义，或输入高级语法"
                    prepend-inner-icon="mdi-magnify"
                    clearable
                    dense
                    hide-details
                    @keyup.enter="search"
                    @click:clear="search"
                />
                <div class="d-flex align-center mt-1" style="gap: 4px;">
                    <v-btn x-small text color="info" @click="searchHelpDialog = true">
                        <v-icon x-small left>mdi-help-circle-outline</v-icon>高级搜索语法
                    </v-btn>
                </div>
                <!-- ADR-0012: Token chips from server-side search_meta -->
                <div v-if="searchMeta && searchMeta.tokens && searchMeta.tokens.length > 0" class="d-flex flex-wrap align-center mt-1" style="gap: 4px;">
                    <v-chip
                        v-for="token in searchMeta.tokens"
                        :key="token"
                        x-small
                        close
                        color="primary"
                        text-color="white"
                        @click:close="removeToken(token)"
                    >{{ token }}</v-chip>
                </div>
                <!-- ADR-0012: Specific grammar error display (422) -->
                <v-alert
                    v-if="searchErrors.length > 0"
                    type="error"
                    dense
                    text
                    class="mt-2 mb-0"
                >
                    <div class="text-caption">高级搜索语法有误：</div>
                    <div v-for="(err, idx) in searchErrors" :key="idx" class="text-caption">
                        <strong>{{ err.token }}</strong> — {{ err.reason }}
                        <span v-if="err.example" class="text--secondary">（示例：{{ err.example }}）</span>
                    </div>
                </v-alert>
            </v-col>
            <v-col cols="12" sm="7" md="5">
                <v-btn-toggle v-model="activeFilter" mandatory dense class="flex-wrap">
                    <v-btn small value="all" @click="applyFilter('all')">全部</v-btn>
                    <v-btn small value="due" @click="applyFilter('due')">到期</v-btn>
                    <v-btn small value="future" @click="applyFilter('future')">未来到期</v-btn>
                    <v-btn small value="active" @click="applyFilter('active')">学习中</v-btn>
                    <v-btn small value="buried" @click="applyFilter('buried')">已埋藏</v-btn>
                    <v-btn small value="suspended" @click="applyFilter('suspended')">已暂停</v-btn>
                    <v-btn small value="archived" @click="applyFilter('archived')">已归档</v-btn>
                    <v-btn small value="missing_definition" @click="applyFilter('missing_definition')">缺释义</v-btn>
                    <v-btn small value="missing_example" @click="applyFilter('missing_example')">缺例句</v-btn>
                    <v-btn small value="missing_source" @click="applyFilter('missing_source')">缺溯源</v-btn>
                    <!-- ADR-0011: Leech governance filters. Backend injects
                         leech_status per item when filter=leech|struggling
                         and include_leech=true is set automatically. -->
                    <v-btn small value="leech" @click="applyFilter('leech')" class="error--text">高遗忘</v-btn>
                    <v-btn small value="struggling" @click="applyFilter('struggling')" class="warning--text">需关注</v-btn>
                </v-btn-toggle>
            </v-col>
            <v-col cols="6" sm="3" md="2">
                <v-select
                    v-model="perPage"
                    :items="[20, 50, 100]"
                    label="每页"
                    dense
                    hide-details
                    @change="changePerPage"
                />
            </v-col>
            <v-col cols="6" sm="3" md="1" class="d-flex align-center justify-end">
                <v-menu offset-y :close-on-content-click="false" max-height="500">
                    <template #activator="{ on, attrs }">
                        <v-btn small text :loading="exportLoading" v-bind="attrs" v-on="on" class="mr-1">
                            <v-icon small left>mdi-download</v-icon>导出
                        </v-btn>
                    </template>
                    <v-card min-width="240">
                        <v-card-title class="text-subtitle-2 pa-3 pb-0">选择导出字段</v-card-title>
                        <v-card-text class="pa-2" style="max-height: 360px; overflow-y: auto;">
                            <v-checkbox
                                v-for="opt in exportFieldOptions"
                                :key="opt.key"
                                v-model="exportFields"
                                :value="opt.key"
                                :label="opt.label"
                                dense
                                hide-details
                                class="ma-0 py-1"
                            />
                        </v-card-text>
                        <v-divider />
                        <v-card-actions class="pa-2">
                            <v-btn x-small text @click="selectAllExportFields">全选</v-btn>
                            <v-btn x-small text @click="resetExportFields">恢复默认</v-btn>
                            <v-spacer />
                            <v-btn x-small color="primary" @click="exportCurrentFilter">导出 JSON</v-btn>
                        </v-card-actions>
                        <v-divider />
                        <v-card-actions class="pa-2">
                            <v-btn x-small color="primary" :loading="ankiExportLoading" @click="exportAnkiTsv">导出 Anki TSV</v-btn>
                        </v-card-actions>
                        <v-divider />
                        <v-card-actions class="pa-2">
                            <v-btn x-small color="primary" :loading="csvExportLoading" @click="exportCsv">导出 CSV</v-btn>
                        </v-card-actions>
                    </v-card>
                </v-menu>
                <v-menu offset-y :close-on-content-click="false">
                    <template #activator="{ on, attrs }">
                        <v-btn small text v-bind="attrs" v-on="on">
                            <v-icon small left>mdi-cog</v-icon>列设置
                            <v-chip v-if="hasHiddenColumns" x-small color="primary" class="ml-1" label>已隐藏</v-chip>
                        </v-btn>
                    </template>
                    <v-card min-width="220">
                        <v-card-text class="pa-2">
                            <div class="text-caption text--secondary mb-2">选择要显示的列：</div>
                            <v-checkbox
                                v-for="col in configurableColumns"
                                :key="col.key"
                                v-model="col.visible"
                                :label="col.label"
                                dense
                                hide-details
                                class="ma-0 py-1"
                                @change="toggleColumnVisibility(col.key)"
                            />
                            <v-divider class="my-2" />
                            <v-switch
                                v-model="compactMode"
                                label="紧凑模式"
                                dense
                                hide-details
                                class="ma-0 pa-0"
                                @change="saveCompactMode"
                            />
                            <v-divider class="my-2" />
                            <div class="d-flex" style="gap: 4px;">
                                <v-btn x-small text @click="resetColumnDefaults">恢复默认</v-btn>
                                <v-btn x-small text @click="showAllColumns">全部显示</v-btn>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-menu>
            </v-col>
        </v-row>

        <!-- Advanced Filter Panel -->
        <v-expansion-panels v-model="advancedPanelOpen" flat class="mb-3">
            <v-expansion-panel>
                <v-expansion-panel-header class="font-weight-medium">
                    高级筛选
                    <v-chip v-if="hasAdvancedFilter" x-small color="primary" class="ml-2" label>已启用</v-chip>
                </v-expansion-panel-header>
                <v-expansion-panel-content>
                    <v-row dense>
                        <!-- FSRS States multi-select -->
                        <v-col cols="12" sm="6" md="3">
                            <div class="text-caption text--secondary mb-1">FSRS 状态</div>
                            <div class="d-flex flex-wrap" style="gap: 4px;">
                                <v-chip
                                    v-for="state in fsrsStateOptions"
                                    :key="state.value"
                                    small
                                    :color="advancedFilters.fsrsStates.includes(state.value) ? 'primary' : ''"
                                    :outlined="!advancedFilters.fsrsStates.includes(state.value)"
                                    @click="toggleFsrsState(state.value)"
                                    style="cursor: pointer;"
                                >
                                    {{ state.label }}
                                </v-chip>
                            </div>
                        </v-col>

                        <!-- Due Range -->
                        <v-col cols="12" sm="6" md="3">
                            <v-select
                                v-model="advancedFilters.dueRange"
                                :items="dueRangeOptions"
                                label="到期范围"
                                dense
                                hide-details
                            />
                        </v-col>

                        <!-- Reps Min -->
                        <v-col cols="6" sm="3" md="2">
                            <v-text-field
                                v-model="advancedFilters.repsMin"
                                label="最少复习次数"
                                type="number"
                                min="0"
                                dense
                                hide-details
                            />
                        </v-col>

                        <!-- Lapses Min -->
                        <v-col cols="6" sm="3" md="2">
                            <v-text-field
                                v-model="advancedFilters.lapsesMin"
                                label="最少遗忘次数"
                                type="number"
                                min="0"
                                dense
                                hide-details
                            />
                        </v-col>

                        <!-- Actions -->
                        <v-col cols="12" sm="6" md="2" class="d-flex align-end" style="gap: 8px;">
                            <v-btn small color="primary" @click="applyAdvancedFilter">应用筛选</v-btn>
                            <v-btn small text @click="clearAdvancedFilter">清空高级筛选</v-btn>
                        </v-col>
                    </v-row>
                </v-expansion-panel-content>
            </v-expansion-panel>
        </v-expansion-panels>

        <!-- Bulk action bar -->
        <div v-if="selectedIds.length > 0" class="bulk-action-bar d-flex flex-wrap align-center pa-3 mb-3 rounded-lg">
            <v-checkbox
                v-model="selectAll"
                :indeterminate="selectIndeterminate"
                dense
                hide-details
                class="ma-0 mr-2"
                @change="toggleSelectAll"
            />
            <span class="mr-4 body-2">已选 <strong>{{ selectedIds.length }}</strong> 项</span>
            <!-- ADR-0010: Batch lifecycle operations. Uses
                 POST /review-cards/manage/bulk-lifecycle endpoint.
                 Bulk bury/reset/delete are NOT supported. -->
            <v-menu offset-y>
                <template #activator="{ on, attrs }">
                    <v-btn small color="primary" class="mr-2" v-bind="attrs" v-on="on" :disabled="bulkLifecycleLoading">
                        <v-icon small left>mdi-state-machine</v-icon>批量生命周期
                        <v-icon small right>mdi-chevron-down</v-icon>
                    </v-btn>
                </template>
                <v-list dense>
                    <v-list-item :disabled="bulkLifecycleLoading" @click="confirmBulkLifecycle('suspend')">
                        <v-list-item-icon><v-icon small>mdi-pause-circle-outline</v-icon></v-list-item-icon>
                        <v-list-item-title>批量暂停</v-list-item-title>
                    </v-list-item>
                    <v-list-item :disabled="bulkLifecycleLoading" @click="confirmBulkLifecycle('resume')">
                        <v-list-item-icon><v-icon small>mdi-play-circle-outline</v-icon></v-list-item-icon>
                        <v-list-item-title>批量恢复复习</v-list-item-title>
                    </v-list-item>
                    <v-list-item :disabled="bulkLifecycleLoading" @click="confirmBulkLifecycle('archive')">
                        <v-list-item-icon><v-icon small>mdi-archive</v-icon></v-list-item-icon>
                        <v-list-item-title>批量归档</v-list-item-title>
                    </v-list-item>
                    <v-list-item :disabled="bulkLifecycleLoading" @click="confirmBulkLifecycle('restore')">
                        <v-list-item-icon><v-icon small>mdi-archive-arrow-up</v-icon></v-list-item-icon>
                        <v-list-item-title>批量恢复归档</v-list-item-title>
                    </v-list-item>
                    <v-list-item :disabled="bulkLifecycleLoading" @click="confirmBulkLifecycle('unbury')">
                        <v-list-item-icon><v-icon small>mdi-alarm-check</v-icon></v-list-item-icon>
                        <v-list-item-title>批量解除埋藏</v-list-item-title>
                    </v-list-item>
                </v-list>
            </v-menu>
            <v-btn small color="error" class="mr-2" @click="confirmBulkDelete">批量彻底删除</v-btn>
            <!-- ADR-0011: Leech batch governance.
                 批量生成重写包 calls bulk-leech-rewrite-packages and opens
                 a dialog showing all generated packages.
                 批量暂停高遗忘卡 calls bulk-lifecycle with action=suspend and
                 a dedicated source tag for audit. -->
            <v-btn
                small
                color="primary"
                class="mr-2"
                :loading="bulkRewriteLoading"
                :disabled="bulkRewriteLoading"
                @click="openBulkRewritePackages"
            >
                <v-icon small left>mdi-package-variant-closed</v-icon>批量生成重写包
            </v-btn>
            <v-btn
                small
                color="warning"
                class="mr-2"
                :loading="bulkLeechSuspendLoading"
                :disabled="bulkLeechSuspendLoading"
                @click="confirmBulkLeechSuspend"
            >
                <v-icon small left>mdi-pause-circle-outline</v-icon>批量暂停高遗忘卡
            </v-btn>
            <v-spacer />
            <v-btn small text @click="clearSelection">取消选择</v-btn>
        </div>

        <!-- Loading -->
        <v-progress-linear v-if="loading" indeterminate class="mb-3" />

        <!-- Error -->
        <v-alert v-if="error" type="error" dense class="mb-3">{{ error }}</v-alert>

        <!-- Cards table -->
        <v-card class="manage-table-card">
            <div class="table-wrapper">
                <table class="manage-table" :class="{ 'table--compact': compactMode }" :style="{ minWidth: tableMinWidth }">
                    <thead>
                        <tr>
                            <th class="col-check">
                                <v-checkbox
                                    v-model="selectAll"
                                    :indeterminate="selectIndeterminate"
                                    dense
                                    hide-details
                                    class="ma-0"
                                    @change="toggleSelectAll"
                                />
                            </th>
                            <th class="col-id sortable" @click="toggleSort('id')" v-if="isColumnVisible('id')">ID <span class="sort-icon">{{ sortIcon('id') }}</span></th>
                            <th class="col-lemma">Lemma</th>
                            <th class="col-surface" v-if="isColumnVisible('surface_form')">Surface</th>
                            <th class="col-pos" v-if="isColumnVisible('pos')">POS</th>
                            <th class="col-def">释义(中)</th>
                            <th class="col-def" v-if="isColumnVisible('sense_en')">释义(英)</th>
                            <th class="col-example" v-if="isColumnVisible('example_sentence_en')">例句(英)</th>
                            <th class="col-example" v-if="isColumnVisible('example_sentence_zh')">例句(中)</th>
                            <th class="col-source" v-if="isColumnVisible('source')">溯源</th>
                            <th class="col-status sortable" @click="toggleSort('fsrs_state')">状态 <span class="sort-icon">{{ sortIcon('fsrs_state') }}</span></th>
                            <th class="col-fsrs sortable" @click="toggleSort('fsrs_stability')" v-if="isColumnVisible('fsrs_stability')">稳定度 <span class="sort-icon">{{ sortIcon('fsrs_stability') }}</span></th>
                            <th class="col-fsrs sortable" @click="toggleSort('fsrs_difficulty')" v-if="isColumnVisible('fsrs_difficulty')">难度 <span class="sort-icon">{{ sortIcon('fsrs_difficulty') }}</span></th>
                            <th class="col-fsrs sortable" @click="toggleSort('fsrs_reps')" v-if="isColumnVisible('fsrs_reps')">复习 <span class="sort-icon">{{ sortIcon('fsrs_reps') }}</span></th>
                            <th class="col-fsrs sortable" @click="toggleSort('fsrs_lapses')" v-if="isColumnVisible('fsrs_lapses')">遗忘 <span class="sort-icon">{{ sortIcon('fsrs_lapses') }}</span></th>
                            <th class="col-last-review sortable" @click="toggleSort('fsrs_last_reviewed_at')" v-if="isColumnVisible('fsrs_last_reviewed_at')">最近复习 <span class="sort-icon">{{ sortIcon('fsrs_last_reviewed_at') }}</span></th>
                            <th class="col-due sortable" @click="toggleSort('fsrs_due_at')" v-if="isColumnVisible('fsrs_due_at')">到期 <span class="sort-icon">{{ sortIcon('fsrs_due_at') }}</span></th>
                            <th class="col-actions">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in items" :key="item.review_card_id" :class="{ 'selected-row': selectedIds.includes(item.review_card_id) }">
                            <td class="col-check">
                                <v-checkbox
                                    :value="item.review_card_id"
                                    :input-value="selectedIds.includes(item.review_card_id)"
                                    dense
                                    hide-details
                                    class="ma-0"
                                    @change="toggleItem(item.review_card_id)"
                                />
                            </td>
                            <td class="col-id" v-if="isColumnVisible('id')">{{ item.review_card_id }}</td>
                            <td class="col-lemma">{{ item.lemma }}</td>
                            <td class="col-surface" v-if="isColumnVisible('surface_form')">{{ item.surface_form }}</td>
                            <td class="col-pos" v-if="isColumnVisible('pos')">
                                <template v-if="editingId === item.review_card_id">
                                    <v-text-field v-model="editForm.pos" dense hide-details class="edit-field" />
                                </template>
                                <template v-else>{{ item.pos }}</template>
                            </td>
                            <td class="col-def" :class="{ 'text--secondary': item.missing_definition }">
                                <template v-if="editingId === item.review_card_id">
                                    <v-text-field v-model="editForm.sense_zh" dense hide-details class="edit-field" />
                                </template>
                                <template v-else>{{ item.sense_zh || '—' }}</template>
                            </td>
                            <td class="col-def" :class="{ 'text--secondary': item.missing_definition }" v-if="isColumnVisible('sense_en')">
                                <template v-if="editingId === item.review_card_id">
                                    <v-text-field v-model="editForm.sense_en" dense hide-details class="edit-field" />
                                </template>
                                <template v-else>{{ item.sense_en || '—' }}</template>
                            </td>
                            <td class="col-example" :class="{ 'text--secondary': item.missing_example }" v-if="isColumnVisible('example_sentence_en')">
                                <template v-if="editingId === item.review_card_id">
                                    <v-textarea v-model="editForm.example_sentence_en" dense hide-details rows="2" class="edit-field" />
                                </template>
                                <template v-else>{{ item.example_sentence_en || '—' }}</template>
                            </td>
                            <td class="col-example" v-if="isColumnVisible('example_sentence_zh')">
                                <template v-if="editingId === item.review_card_id">
                                    <v-text-field v-model="editForm.example_sentence_zh" dense hide-details class="edit-field" />
                                </template>
                                <template v-else>{{ item.example_sentence_zh || '—' }}</template>
                            </td>
                            <td class="col-source" :class="sourceDisplayClass(item)" v-if="isColumnVisible('source')">
                                {{ item.source_display_label || item.source_chapter_title || sourceKindLabel(item.source_kind) }}
                            </td>
                            <td class="col-status">
                                <v-chip x-small :color="stateColor(item.lifecycle_state)">
                                    {{ stateLabel(item.lifecycle_state) }}
                                </v-chip>
                                <!-- ADR-0011: Leech badge. Shown when the backend
                                     injected a non-stable leech_status (only when
                                     include_leech=true is sent, e.g. filter=leech). -->
                                <v-chip
                                    v-if="item.leech_status && item.leech_status !== 'stable'"
                                    x-small
                                    :color="leechStatusColor(item.leech_status)"
                                    text-color="white"
                                    class="ml-1"
                                >{{ leechStatusLabel(item.leech_status) }}</v-chip>
                                <span class="text-caption d-block">{{ item.fsrs_state }}</span>
                            </td>
                            <td class="col-fsrs text-center" v-if="isColumnVisible('fsrs_stability')">{{ formatFsrsNumber(item.fsrs_stability) }}</td>
                            <td class="col-fsrs text-center" v-if="isColumnVisible('fsrs_difficulty')">{{ formatFsrsNumber(item.fsrs_difficulty) }}</td>
                            <td class="col-fsrs text-center" v-if="isColumnVisible('fsrs_reps')">{{ item.fsrs_reps || 0 }}</td>
                            <td class="col-fsrs text-center" v-if="isColumnVisible('fsrs_lapses')">{{ item.fsrs_lapses || 0 }}</td>
                            <td class="col-last-review text-center" v-if="isColumnVisible('fsrs_last_reviewed_at')">{{ formatLastReviewed(item.fsrs_last_reviewed_at) }}</td>
                            <td class="col-due" v-if="isColumnVisible('fsrs_due_at')">
                                <span class="text-caption">{{ formatDueAt(item.fsrs_due_at) }}</span>
                            </td>
                            <td class="col-actions">
                                <template v-if="editingId === item.review_card_id">
                                    <v-btn x-small color="primary" :loading="savingId === item.review_card_id" @click="saveEdit(item)">
                                        保存
                                    </v-btn>
                                    <v-btn x-small text @click="cancelEdit">取消</v-btn>
                                </template>
                                <template v-else>
                                    <v-btn x-small text @click="startEdit(item)">编辑</v-btn>
                                    <v-btn x-small text @click="openDetail(item)">详情</v-btn>
                                    <!-- ADR-0010: Lifecycle action menu. Fetches the
                                         descriptor on open to show only available
                                         actions. The backend validates all transitions. -->
                                    <v-menu offset-y @input="onLifecycleMenuToggle($event, item)">
                                        <template #activator="{ on, attrs }">
                                            <v-btn x-small text v-bind="attrs" v-on="on" :disabled="lifecycleLoading">
                                                生命周期
                                            </v-btn>
                                        </template>
                                        <v-list dense>
                                            <div v-if="lifecycleMenuId === item.review_card_id && !lifecycleDescriptor" class="text-caption text--secondary pa-2">
                                                加载中...
                                            </div>
                                            <v-list-item
                                                v-for="lifecycleAction in availableLifecycleActions"
                                                :key="lifecycleAction"
                                                :disabled="lifecycleLoading"
                                                @click="onLifecycleMenuClick(lifecycleAction, item)"
                                            >
                                                <v-list-item-icon>
                                                    <v-icon small :color="actionColor(lifecycleAction)">{{ lifecycleActionIcon(lifecycleAction) }}</v-icon>
                                                </v-list-item-icon>
                                                <v-list-item-title>{{ actionLabel(lifecycleAction) }}</v-list-item-title>
                                            </v-list-item>
                                            <div v-if="lifecycleMenuId === item.review_card_id && lifecycleDescriptor && availableLifecycleActions.length === 0" class="text-caption text--secondary pa-2">
                                                无可用操作
                                            </div>
                                        </v-list>
                                    </v-menu>
                                    <v-btn v-if="item.lifecycle_state === 'active'" x-small text @click="confirmDueNow(item)">立即到期</v-btn>
                                    <v-menu offset-y>
                                        <template #activator="{ on, attrs }">
                                            <v-btn x-small text v-bind="attrs" v-on="on">更多</v-btn>
                                        </template>
                                        <v-list dense>
                                            <v-list-item @click="viewSource(item)">
                                                <v-list-item-title>查看原文</v-list-item-title>
                                            </v-list-item>
                                            <v-list-item @click="confirmReset(item)">
                                                <v-list-item-title>重置</v-list-item-title>
                                            </v-list-item>
                                            <!-- ADR-0011: Leech governance per-row actions.
                                                 生成重写包 opens the rewrite-package dialog;
                                                 暂停 calls the lifecycle suspend endpoint. -->
                                            <v-list-item @click="openRewritePackageDialog(item)">
                                                <v-list-item-title>生成重写包</v-list-item-title>
                                            </v-list-item>
                                            <v-list-item
                                                v-if="item.leech_status === 'leech'"
                                                @click="suspendLeechCard(item)"
                                            >
                                                <v-list-item-title class="warning--text">暂停</v-list-item-title>
                                            </v-list-item>
                                            <v-list-item @click="confirmDelete(item)">
                                                <v-list-item-title class="error--text">彻底删除</v-list-item-title>
                                            </v-list-item>
                                        </v-list>
                                    </v-menu>
                                </template>
                            </td>
                        </tr>
                        <tr v-if="!loading && items.length === 0">
                            <td :colspan="visibleColumnCount" class="text-center py-4 text--secondary">
                                <span v-if="searchErrors.length > 0">搜索语法有误，请修正后重试。</span>
                                <span v-else-if="searchQuery || (searchMeta && searchMeta.advanced)">当前搜索没有匹配结果。</span>
                                <span v-else>暂无词义复习卡。</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </v-card>

        <!-- Pagination -->
        <div class="d-flex justify-center mt-4">
            <v-pagination
                v-if="pagination.last_page > 1"
                v-model="currentPage"
                :length="pagination.last_page"
                :total-visible="7"
                @input="loadData"
            />
        </div>

        <!-- Source dialog -->
        <sense-example-dialog
            v-model="sourceDialog"
            :payload="sourcePayload"
            :language="language"
            :font-size="16"
        />

        <!-- Detail drawer -->
        <v-navigation-drawer
            v-model="detailDrawer"
            right
            temporary
            fixed
            width="420"
            class="detail-drawer"
        >
            <!-- ADR-0014: Drawer-level loading / error / empty states.
                 detailLoading without detailTarget means we are fetching
                 via deep link (no optimistic row). detailError without
                 detailTarget means the fetch failed entirely. The monotonic
                 detailRequestSeq guard ensures stale responses never
                 overwrite the current drawer state. -->
            <div v-if="detailLoading && !detailTarget" class="d-flex align-center justify-center" style="min-height: 240px;">
                <div class="text-center">
                    <v-progress-circular indeterminate color="primary" size="32" />
                    <div class="text-caption text--secondary mt-3">加载卡片详情中...</div>
                </div>
            </div>
            <div v-else-if="detailError && !detailTarget" class="d-flex align-center justify-center pa-4" style="min-height: 240px;">
                <div class="text-center">
                    <v-icon color="error" large>mdi-alert-circle-outline</v-icon>
                    <div class="error--text mt-2 text-body-2">{{ detailError }}</div>
                    <v-btn text color="primary" @click="closeDetail" class="mt-2">关闭</v-btn>
                </div>
            </div>
            <template v-else-if="detailTarget">
                <v-card flat>
                    <v-card-title class="d-flex align-center">
                        <span>复习卡详情</span>
                        <v-spacer />
                        <v-btn icon small @click="closeDetail">
                            <v-icon>mdi-close</v-icon>
                        </v-btn>
                    </v-card-title>
                    <!-- Deep link hint (ADR-0007) -->
                    <v-alert
                        v-if="deepLink.active"
                        type="info"
                        dense
                        text
                        class="mx-4 mb-0"
                        icon="mdi-information-outline"
                    >从今日学习日报打开。</v-alert>
                    <!-- ADR-0014: Drawer-level loading / error banners shown
                         alongside optimistic detailTarget content. -->
                    <v-alert
                        v-if="detailLoading"
                        type="info"
                        dense
                        text
                        class="mx-4 mb-0"
                        icon="mdi-loading mdi-spin"
                    >正在加载最新详情...</v-alert>
                    <v-alert
                        v-if="detailError"
                        type="error"
                        dense
                        text
                        class="mx-4 mb-0"
                        icon="mdi-alert-circle-outline"
                    >{{ detailError }}</v-alert>
                    <v-card-subtitle class="pb-0">
                        {{ detailTarget.lemma }} / {{ detailTarget.surface_form }} / {{ detailTarget.pos }}
                    </v-card-subtitle>
                    <v-card-text class="detail-content">
                        <!-- ADR-0014: Card Info V1 tab structure — 概览 / 历史 / 诊断.
                             All data comes from the single canonical detail
                             request. No additional /logs, /lifecycle-events,
                             or /leech requests are fired when the drawer opens. -->
                        <v-tabs v-model="detailTab" grow class="mb-2">
                            <v-tab href="#overview">概览</v-tab>
                            <v-tab href="#history">历史</v-tab>
                            <v-tab href="#diagnosis">诊断</v-tab>
                        </v-tabs>
                        <v-tabs-items v-model="detailTab">
                            <v-tab-item value="overview">
                        <!-- 基本信息 -->
                        <div class="detail-section">
                            <div class="detail-section-title">基本信息</div>
                            <div class="detail-row">
                                <span class="detail-label">ReviewCard ID</span>
                                <span class="detail-value">{{ detailTarget.review_card_id }}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">WordSense ID</span>
                                <span class="detail-value">{{ detailTarget.word_sense_id }}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Lemma</span>
                                <span class="detail-value">{{ detailTarget.lemma }}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Surface</span>
                                <span class="detail-value">{{ displayValue(detailTarget.surface_form) }}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">POS</span>
                                <span class="detail-value">{{ displayValue(detailTarget.pos) }}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">状态</span>
                                <span class="detail-value">
                                    <v-chip x-small :color="stateColor(detailTarget.lifecycle_state)">
                                        {{ stateLabel(detailTarget.lifecycle_state) }}
                                    </v-chip>
                                </span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">FSRS State</span>
                                <span class="detail-value">{{ displayValue(detailTarget.fsrs_state) }}</span>
                            </div>
                        </div>

                        <v-divider class="my-3" />

                        <!-- 释义信息 -->
                        <div class="detail-section">
                            <div class="detail-section-title">释义信息</div>
                            <div class="detail-row">
                                <span class="detail-label">中文释义</span>
                                <span class="detail-value" :class="{ 'text--secondary': detailTarget.missing_definition }">{{ displayValue(detailTarget.sense_zh) }}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">英文释义</span>
                                <span class="detail-value" :class="{ 'text--secondary': detailTarget.missing_definition }">{{ displayValue(detailTarget.sense_en) }}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">英文例句</span>
                                <span class="detail-value" :class="{ 'text--secondary': detailTarget.missing_example }">{{ displayValue(detailTarget.example_sentence_en) }}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">中文例句</span>
                                <span class="detail-value">{{ displayValue(detailTarget.example_sentence_zh) }}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">近义译法</span>
                                <span class="detail-value">
                                    <template v-if="Array.isArray(detailTarget.aliases_zh) && detailTarget.aliases_zh.length > 0">
                                        <v-chip v-for="(alias, i) in detailTarget.aliases_zh" :key="i" x-small class="mr-1">{{ alias }}</v-chip>
                                    </template>
                                    <template v-else>—</template>
                                </span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">搭配</span>
                                <span class="detail-value">
                                    <template v-if="Array.isArray(detailTarget.collocations) && detailTarget.collocations.length > 0">
                                        <v-chip v-for="(coll, i) in detailTarget.collocations" :key="i" x-small class="mr-1">{{ coll }}</v-chip>
                                    </template>
                                    <template v-else>—</template>
                                </span>
                            </div>
                        </div>

                        <v-divider class="my-3" />

                        <!-- 溯源信息 -->
                        <div class="detail-section">
                            <div class="detail-section-title">溯源信息</div>
                            <div class="detail-row">
                                <span class="detail-label">来源</span>
                            <span class="detail-value" :class="detailSourceClass(detailTarget)">
                                {{ detailTarget.source_display_label || detailTarget.source_chapter_title || sourceKindLabel(detailTarget.source_kind) }}
                            </span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">来源类型</span>
                                <span class="detail-value">{{ sourceKindLabel(detailTarget.source_kind) }}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">来源章节 ID</span>
                                <span class="detail-value">{{ displayValue(detailTarget.source_chapter_id) }}</span>
                            </div>
                        </div>

                        <v-divider class="my-3" />

                        <!-- FSRS 信息 -->
                        <div class="detail-section">
                            <div class="detail-section-title">FSRS 信息</div>
                            <div class="detail-row">
                                <span class="detail-label">到期时间</span>
                                <span class="detail-value">{{ formatDueAt(detailTarget.fsrs_due_at) }}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">最近复习</span>
                                <span class="detail-value">{{ formatLastReviewed(detailTarget.fsrs_last_reviewed_at) }}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">稳定度</span>
                                <span class="detail-value">{{ formatFsrsNumber(detailTarget.fsrs_stability) }}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">难度</span>
                                <span class="detail-value">{{ formatFsrsNumber(detailTarget.fsrs_difficulty) }}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">复习次数</span>
                                <span class="detail-value">{{ displayValue(detailTarget.fsrs_reps, 0) }}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">遗忘次数</span>
                                <span class="detail-value">{{ displayValue(detailTarget.fsrs_lapses, 0) }}</span>
                            </div>
                        </div>

                        <v-divider class="my-3" />

                        <!-- ADR-0010: 生命周期信息 -->
                        <div class="detail-section">
                            <div class="detail-section-title">生命周期</div>
                            <div class="detail-row">
                                <span class="detail-label">当前状态</span>
                                <span class="detail-value">
                                    <v-chip x-small :color="stateColor(detailTarget.lifecycle_state)">
                                        {{ stateLabel(detailTarget.lifecycle_state) }}
                                    </v-chip>
                                </span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">埋藏到期</span>
                                <span class="detail-value">{{ detailTarget.buried_until ? formatDateTime(detailTarget.buried_until) : '—' }}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">状态变更时间</span>
                                <span class="detail-value">{{ detailTarget.lifecycle_changed_at ? formatDateTime(detailTarget.lifecycle_changed_at) : '—' }}</span>
                            </div>
                        </div>

                        <!-- ADR-0014: 缺失状态 moved into the overview tab
                             (previously positioned after the diagnosis
                             section). The overview tab closes after this
                             section, then the history and diagnosis tabs
                             follow. -->
                        <v-divider class="my-3" />

                        <!-- 缺失状态 -->
                        <div class="detail-section">
                            <div class="detail-section-title">缺失状态</div>
                            <div class="detail-row">
                                <span class="detail-label">缺释义</span>
                                <span class="detail-value">{{ detailTarget.missing_definition ? '是' : '否' }}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">缺例句</span>
                                <span class="detail-value">{{ detailTarget.missing_example ? '是' : '否' }}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">溯源状态</span>
                                <span class="detail-value" :class="detailSourceClass(detailTarget)">{{ detailTarget.source_display_status === 'missing' ? '是（无例句无原文）' : (detailTarget.source_display_status === 'card_example_only' ? '仅保存例句（未定位原章节）' : '否（已定位原文）') }}</span>
                            </div>
                        </div>
                            </v-tab-item>

                            <!-- ADR-0014: 历史 tab — uses card_info payload
                                 from the single canonical detail request.
                                 No additional /logs or /lifecycle-events
                                 requests are fired. Sections are labeled
                                 "最近 N 条" to make the cap explicit. -->
                            <v-tab-item value="history">
                                <!-- ADR-0010: 生命周期记录（最近 N 条） -->
                                <div class="detail-section">
                                    <div class="detail-section-title">生命周期记录（最近 {{ cardInfoLifecycleEventsLimit() }} 条）</div>
                                    <div v-if="!cardInfo" class="text-caption text--secondary py-2">暂无生命周期记录。</div>
                                    <div v-else-if="cardInfoLifecycleEvents().length === 0" class="text-caption text--secondary py-2">暂无生命周期记录。</div>
                                    <div v-else>
                                        <div
                                            v-for="event in cardInfoLifecycleEvents()"
                                            :key="event.id"
                                            class="log-entry mb-2 pa-2"
                                        >
                                            <div class="d-flex align-center" style="gap: 6px;">
                                                <v-chip x-small :color="actionColor(event.action)">{{ actionLabel(event.action) }}</v-chip>
                                                <span class="text-caption">| {{ event.source }}</span>
                                                <v-spacer />
                                                <span class="text-caption text--secondary">{{ formatDateTime(event.created_at) }}</span>
                                            </div>
                                            <div class="text-caption mt-1">
                                                <span :class="event.previous_state ? '' : 'text--secondary'">{{ stateLabel(event.previous_state) }}</span>
                                                <span class="mx-1">→</span>
                                                <span :class="event.new_state ? '' : 'text--secondary'">{{ stateLabel(event.new_state) }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <v-divider class="my-3" />

                                <!-- ADR-0009: 最近复习记录（最近 N 条）— undone
                                     audit trail retained. Original rating
                                     preserved, log not hidden, no undo button. -->
                                <div class="detail-section">
                                    <div class="detail-section-title">最近复习记录（最近 {{ cardInfoReviewLogsLimit() }} 条）</div>
                                    <div v-if="!cardInfo" class="text-caption text--secondary py-2">暂无复习记录。</div>
                                    <div v-else-if="cardInfoReviewLogs().length === 0" class="text-caption text--secondary py-2">暂无复习记录。</div>
                                    <div v-else>
                                        <div
                                            v-for="log in cardInfoReviewLogs()"
                                            :key="log.id"
                                            class="log-entry mb-2 pa-2"
                                        >
                                            <div class="d-flex align-center" style="gap: 6px;">
                                                <v-chip x-small :color="logRatingColor(log.rating)">{{ log.rating }}</v-chip>
                                                <span class="text-caption">| {{ log.source }}</span>
                                                <v-spacer />
                                                <span class="text-caption text--secondary">{{ formatDateTime(log.reviewed_at) }}</span>
                                            </div>
                                            <div class="text-caption mt-1">
                                                <span :class="log.previous_state ? '' : 'text--secondary'">{{ log.previous_state || '—' }}</span>
                                                <span class="mx-1">→</span>
                                                <span :class="log.new_state ? '' : 'text--secondary'">{{ log.new_state || '—' }}</span>
                                            </div>
                                            <div class="text-caption text--secondary mt-1">
                                                S: {{ formatFsrsNumber(log.previous_stability) }} → {{ formatFsrsNumber(log.new_stability) }}
                                                &nbsp;|&nbsp;
                                                D: {{ formatFsrsNumber(log.previous_difficulty) }} → {{ formatFsrsNumber(log.new_difficulty) }}
                                            </div>
                                            <div class="text-caption text--secondary">
                                                到期: {{ formatDueAt(log.previous_due_at) }} → {{ formatDueAt(log.new_due_at) }}
                                            </div>
                                            <!-- ADR-0009: Undo audit trail. The original
                                                 rating is preserved (not changed to undo),
                                                 the log is not hidden, and no undo button
                                                 is provided on this page. -->
                                            <div v-if="log.undone" class="text-caption mt-1 d-flex align-center" style="gap: 4px;">
                                                <v-chip x-small color="grey">已撤销</v-chip>
                                                <span class="text--secondary">
                                                    撤销时间: {{ formatDateTime(log.undone_at) }}
                                                    <span v-if="log.undo_source"> · 来源: {{ log.undo_source }}</span>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </v-tab-item>

                            <!-- ADR-0014: 诊断 tab — uses card_info.leech
                                 from the single canonical detail request.
                                 No additional /leech request is fired. -->
                            <v-tab-item value="diagnosis">
                                <!-- ADR-0011: 遗忘诊断 (leech diagnostics).
                                     For suspended/archived cards, a note
                                     explains that leech status is still
                                     computed but the card is not in the
                                     review queue. -->
                                <div class="detail-section">
                                    <div class="detail-section-title">遗忘诊断</div>
                                    <template v-if="cardInfoLeech()">
                                        <div class="detail-row">
                                            <span class="detail-label">遗忘状态</span>
                                            <span class="detail-value">
                                                <v-chip x-small :color="leechStatusColor(cardInfoLeech().status)" text-color="white">
                                                    {{ leechStatusLabel(cardInfoLeech().status) }}
                                                </v-chip>
                                                <v-chip
                                                    v-if="cardInfoLeech().status !== 'stable'"
                                                    x-small
                                                    :color="leechSeverityColor(cardInfoLeech().severity)"
                                                    outlined
                                                    class="ml-1"
                                                >严重度：{{ leechSeverityText(cardInfoLeech().severity) }}</v-chip>
                                            </span>
                                        </div>
                                        <div v-if="cardInfoLeech().reasons && cardInfoLeech().reasons.length" class="detail-row">
                                            <span class="detail-label">原因</span>
                                            <span class="detail-value">
                                                <v-chip
                                                    v-for="reason in cardInfoLeech().reasons"
                                                    :key="reason"
                                                    x-small
                                                    color="error"
                                                    outlined
                                                    class="mr-1 mb-1"
                                                >{{ leechReasonLabel(reason) }}</v-chip>
                                            </span>
                                        </div>
                                        <div v-if="cardInfoLeech().suggestions && cardInfoLeech().suggestions.length" class="detail-row">
                                            <span class="detail-label">建议</span>
                                            <span class="detail-value">
                                                <ul class="pl-4 mb-0">
                                                    <li v-for="suggestion in cardInfoLeech().suggestions" :key="suggestion" class="text-body-2">
                                                        {{ leechSuggestionLabel(suggestion) }}
                                                    </li>
                                                </ul>
                                            </span>
                                        </div>
                                        <v-alert
                                            v-if="detailTarget.lifecycle_state === 'suspended' || detailTarget.lifecycle_state === 'archived'"
                                            type="info"
                                            dense
                                            text
                                            class="mt-2 mb-0"
                                            border="left"
                                        >
                                            该卡当前为「{{ stateLabel(detailTarget.lifecycle_state) }}」状态，不在复习队列中。遗忘诊断仍会基于历史数据计算，但不会出现在日常复习中。
                                        </v-alert>
                                    </template>
                                    <div v-else class="text-caption text--secondary py-2">暂无遗忘诊断数据。</div>
                                </div>
                            </v-tab-item>
                        </v-tabs-items>
                    </v-card-text>
                    <v-card-actions>
                        <v-btn text @click="closeDetail">关闭</v-btn>
                        <v-btn
                            v-if="deepLink.active"
                            text
                            color="primary"
                            @click="backToReport"
                        >
                            <v-icon left small>mdi-arrow-left</v-icon>
                            返回学习报告
                        </v-btn>
                        <v-spacer />
                        <v-btn text color="primary" @click="viewSource(detailTarget); closeDetail()">查看原文</v-btn>
                    </v-card-actions>
                </v-card>
            </template>
            <div v-else class="d-flex align-center justify-center" style="min-height: 240px;">
                <div class="text-caption text--secondary">请从列表中选择一张卡片查看详情。</div>
            </div>
        </v-navigation-drawer>

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
                        只会处理你当前勾选的 {{ selectedIds.length }} 张复习卡，不会按筛选条件全量操作。
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

        <!-- ADR-0012: Advanced search syntax help dialog -->
        <v-dialog v-model="searchHelpDialog" max-width="560">
            <v-card>
                <v-card-title>高级搜索语法</v-card-title>
                <v-card-text>
                    <p class="text-body-2 mb-3">
                        在搜索框中输入以下 token 可以精确筛选复习卡。所有条件使用 AND 语义组合。普通文本仍会搜索 Lemma / 释义 / 例句。
                    </p>
                    <v-list dense class="mb-2">
                        <v-list-item>
                            <v-list-item-content>
                                <v-list-item-title><code>is:leech</code></v-list-item-title>
                                <v-list-item-subtitle>只显示高遗忘（Leech）的卡片</v-list-item-subtitle>
                            </v-list-item-content>
                        </v-list-item>
                        <v-list-item>
                            <v-list-item-content>
                                <v-list-item-title><code>is:struggling</code></v-list-item-title>
                                <v-list-item-subtitle>只显示需关注的卡片</v-list-item-subtitle>
                            </v-list-item-content>
                        </v-list-item>
                        <v-list-item>
                            <v-list-item-content>
                                <v-list-item-title><code>is:active</code> / <code>is:buried</code> / <code>is:suspended</code> / <code>is:archived</code></v-list-item-title>
                                <v-list-item-subtitle>按生命周期状态筛选（最多一个）</v-list-item-subtitle>
                            </v-list-item-content>
                        </v-list-item>
                        <v-list-item>
                            <v-list-item-content>
                                <v-list-item-title><code>rated:again</code> / <code>rated:hard</code></v-list-item-title>
                                <v-list-item-subtitle>只显示有对应评分记录的卡片</v-list-item-subtitle>
                            </v-list-item-content>
                        </v-list-item>
                        <v-list-item>
                            <v-list-item-content>
                                <v-list-item-title><code>prop:lapses&gt;=2</code></v-list-item-title>
                                <v-list-item-subtitle>按遗忘次数筛选，支持 =, &gt;, &gt;=, &lt;, &lt;=</v-list-item-subtitle>
                            </v-list-item-content>
                        </v-list-item>
                    </v-list>
                    <v-divider class="my-2" />
                    <p class="text-body-2 mb-1"><strong>组合示例：</strong></p>
                    <p class="text-body-2 mb-1"><code>charge is:leech</code> — 搜索 charge 且是 Leech</p>
                    <p class="text-body-2 mb-1"><code>is:leech is:suspended</code> — Leech 且已暂停</p>
                    <p class="text-body-2 mb-1"><code>rated:again prop:lapses&gt;=2</code> — 有 Again 记录且遗忘 ≥ 2</p>
                    <p class="text-body-2 mb-0"><code>charge rated:again prop:lapses&gt;=2</code> — 全部组合</p>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="searchHelpDialog = false">关闭</v-btn>
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
                        只会处理你当前勾选的 {{ selectedIds.length }} 张复习卡。
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
import { DefaultLocalStorageManager } from '../../services/LocalStorageManagerService.js';
import { parseReviewCardManageLocation } from '../../services/ReviewCardManageDeepLink.js';
import {
    actionLabel,
    actionHint,
    actionDangerLevel,
    actionColor,
    stateLabel,
    stateColor,
    LIFECYCLE_PRESENTATION,
    LIFECYCLE_STATES,
} from '../../services/ReviewCardLifecyclePresentation.js';
import {
    statusLabel as leechStatusLabel,
    statusColor as leechStatusColor,
    reasonLabel as leechReasonLabel,
    suggestionLabel as leechSuggestionLabel,
    severityText as leechSeverityText,
    severityColor as leechSeverityColor,
} from '../../services/SenseReviewLeechPresentation.js';
import SenseReviewLeechRewritePackageDialog from '../Senses/SenseReviewLeechRewritePackageDialog.vue';

export default {
    components: {
        SenseExampleDialog,
        SenseReviewLeechRewritePackageDialog,
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
            searchQuery: '',
            activeFilter: 'active',
            currentFilter: 'active',
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
            detailTarget: null,
            // ADR-0014: Card Info read model — single canonical detail request
            // replaces the former three parallel sub-requests (logs, lifecycle-
            // events, leech). cardInfo holds the additive `card_info` payload
            // (review_logs / lifecycle_events / leech). detailLoading and
            // detailError drive the drawer-level loading/error states.
            // detailRequestSeq is a monotonic guard against stale responses
            // when the user rapidly switches cards.
            cardInfo: null,
            detailLoading: false,
            detailError: '',
            detailRequestSeq: 0,
            detailTab: 'overview',
            // Legacy fields retained for backward-compat with any code path
            // that still reads them; the drawer no longer mutates them after
            // ADR-0014, but tests/refs may inspect them.
            detailLogs: [],
            detailLogsLoading: false,
            detailLogsError: '',
            // ADR-0010: Lifecycle state machine per-row actions
            // lifecycleMenuId: review_card_id whose lifecycle menu is open
            // lifecycleDescriptor: cached descriptor for the open menu
            // lifecycleLoading: true while a lifecycle POST is in flight
            // lifecycleDialog: generic confirmation dialog
            // lifecycleDialogAction: action being confirmed
            // lifecycleDialogTarget: card being acted on
            // lifecycleConflict: 409/422 error message in the dialog
            // detailEvents: lifecycle state events for the detail drawer
            // detailEventsLoading / detailEventsError
            lifecycleMenuId: null,
            lifecycleDescriptor: null,
            lifecycleLoading: false,
            lifecycleDialog: false,
            lifecycleDialogAction: null,
            lifecycleDialogTarget: null,
            lifecycleConflict: '',
            detailEvents: [],
            detailEventsLoading: false,
            detailEventsError: '',
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
            // ADR-0012: Advanced browser search state
            // searchHelpDialog: help dialog for advanced syntax
            // searchMeta: {raw_query, text_query, tokens, advanced} from server
            // searchErrors: per-token error list from 422 responses
            searchHelpDialog: false,
            searchMeta: null,
            searchErrors: [],
            selectedIds: [],
            selectAll: false,
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
            // Advanced filters
            advancedPanelOpen: undefined,
            advancedFilters: {
                fsrsStates: [],
                dueRange: 'all',
                repsMin: null,
                lapsesMin: null,
            },
            fsrsStateOptions: [
                { label: '新卡', value: 'new' },
                { label: '学习中', value: 'learning' },
                { label: '复习中', value: 'review' },
                { label: '重新学习', value: 'relearning' },
            ],
            dueRangeOptions: [
                { text: '全部', value: 'all' },
                { text: '已逾期', value: 'overdue' },
                { text: '今天', value: 'today' },
                { text: '未来 7 天', value: 'next7' },
                { text: '未来', value: 'future' },
                { text: '无到期', value: 'none' },
            ],
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
            // Column visibility settings
            columnSettings: {},
            columnSettingsLoaded: false,
            columnDefaults: {
                id: true,
                surface_form: true,
                pos: true,
                sense_en: false,
                example_sentence_en: false,
                example_sentence_zh: false,
                source: true,
                fsrs_stability: true,
                fsrs_difficulty: true,
                fsrs_reps: true,
                fsrs_lapses: true,
                fsrs_last_reviewed_at: true,
                fsrs_due_at: true,
            },
            pinnedColumnKeys: [
                'checkbox',
                'lemma',
                'sense_zh',
                'fsrs_state',
                'actions',
            ],
            configurableColumnDefs: [
                { key: 'id', label: 'ID' },
                { key: 'surface_form', label: 'Surface' },
                { key: 'pos', label: 'POS' },
                { key: 'sense_en', label: '释义(英)' },
                { key: 'example_sentence_en', label: '例句(英)' },
                { key: 'example_sentence_zh', label: '例句(中)' },
                { key: 'source', label: '溯源' },
                { key: 'fsrs_stability', label: '稳定度' },
                { key: 'fsrs_difficulty', label: '难度' },
                { key: 'fsrs_reps', label: '复习' },
                { key: 'fsrs_lapses', label: '遗忘' },
                { key: 'fsrs_last_reviewed_at', label: '最近复习' },
                { key: 'fsrs_due_at', label: '到期' },
            ],
            // Compact mode
            compactMode: false,
            // Export
            exportLoading: false,
            ankiExportLoading: false,
            csvExportLoading: false,
            exportFieldOptions: [
                { key: 'review_card_id', label: 'ReviewCard ID' },
                { key: 'word_sense_id', label: 'WordSense ID' },
                { key: 'lemma', label: 'Lemma' },
                { key: 'surface_form', label: 'Surface' },
                { key: 'pos', label: 'POS' },
                { key: 'sense_zh', label: '中文释义' },
                { key: 'sense_en', label: '英文释义' },
                { key: 'example_sentence_en', label: '英文例句' },
                { key: 'example_sentence_zh', label: '中文例句' },
                { key: 'aliases_zh', label: '近义译法' },
                { key: 'collocations', label: '搭配' },
                { key: 'source_chapter_title', label: '来源章节' },
                { key: 'source_kind', label: '来源类型' },
                { key: 'fsrs_state', label: 'FSRS 状态' },
                { key: 'fsrs_due_at', label: '到期时间' },
                { key: 'fsrs_stability', label: '稳定度' },
                { key: 'fsrs_difficulty', label: '难度' },
                { key: 'fsrs_reps', label: '复习次数' },
                { key: 'fsrs_lapses', label: '遗忘次数' },
                { key: 'fsrs_last_reviewed_at', label: '最近复习' },
                { key: 'fsrs_enabled', label: '是否启用' },
                { key: 'lifecycle_state', label: '生命周期状态' },
                { key: 'buried_until', label: '埋藏到期' },
                { key: 'lifecycle_changed_at', label: '状态变更时间' },
                { key: 'missing_definition', label: '缺释义' },
                { key: 'missing_example', label: '缺例句' },
                { key: 'missing_source', label: '缺溯源' },
            ],
            exportFields: [],
            // ADR-0011: Leech summary chip (top stats area).
            // Loaded on mount via GET /review-cards/manage/leech-summary.
            leechSummary: {
                counts: { stable: 0, struggling: 0, leech: 0 },
                leech_card_ids: [],
                struggling_card_ids: [],
            },
            leechSummaryLoaded: false,
            // ADR-0011 / ADR-0014: Detail drawer leech diagnostics.
            // After ADR-0014, the leech descriptor arrives as
            // cardInfo.leech from the single canonical detail request.
            // These legacy fields are kept for backward-compat with any
            // external reader but are no longer populated by the drawer.
            detailLeech: null,
            detailLeechLoading: false,
            detailLeechError: '',
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
        selectIndeterminate() {
            if (this.selectedIds.length === 0) return false;
            if (this.items.length === 0) return false;
            return this.selectedIds.length < this.items.length;
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
        sortableColumns() {
            return ['id', 'fsrs_state', 'fsrs_stability', 'fsrs_difficulty', 'fsrs_reps', 'fsrs_lapses', 'fsrs_last_reviewed_at', 'fsrs_due_at'];
        },
        columnDefaultDir() {
            return {
                id: 'desc',
                fsrs_state: 'asc',
                fsrs_stability: 'asc',
                fsrs_difficulty: 'desc',
                fsrs_reps: 'desc',
                fsrs_lapses: 'desc',
                fsrs_last_reviewed_at: 'desc',
                fsrs_due_at: 'asc',
            };
        },
        hasAdvancedFilter() {
            return this.advancedFilters.fsrsStates.length > 0
                || this.advancedFilters.dueRange !== 'all'
                || this.advancedFilters.repsMin !== null
                || this.advancedFilters.lapsesMin !== null;
        },
        configurableColumns() {
            return this.configurableColumnDefs.map(def => ({
                ...def,
                visible: this.isColumnVisible(def.key),
            }));
        },
        hasHiddenColumns() {
            return this.configurableColumns.some(col => !col.visible);
        },
        visibleColumnCount() {
            const pinnedVisible = this.pinnedColumnKeys.length;
            const configVisible = Object.values(this.columnSettings).filter(Boolean).length;
            return pinnedVisible + configVisible;
        },
        tableMinWidth() {
            let width = this.compactMode ? 1480 : 1640;
            if (!this.isColumnVisible('sense_en')) width -= 100;
            if (!this.isColumnVisible('example_sentence_en')) width -= 140;
            if (!this.isColumnVisible('example_sentence_zh')) width -= 140;
            return width + 'px';
        },
        selectedBulkDeleteItems() {
            return this.items.filter(item => this.selectedIds.includes(item.review_card_id));
        },
        visibleBulkDeleteItems() {
            return this.selectedBulkDeleteItems.slice(0, 20);
        },
        hiddenBulkDeleteCount() {
            return Math.max(this.selectedBulkDeleteItems.length - 20, 0);
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
        this.loadColumnSettings();
        this.loadCompactMode();
        this.loadData();
        this.loadFsrsStats();
        this.loadLeechSummary();
        this.initExportFields();
        this.handleDeepLink();
    },
    methods: {
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

            // ADR-0012 §6: When the search query contains advanced tokens,
            // auto-switch the filter button to "全部" (filter=all) so that
            // lifecycle tokens like is:suspended are not pre-filtered out.
            // This must happen BEFORE the request is sent.
            if (this.detectAdvancedTokens(this.searchQuery)) {
                if (this.currentFilter !== 'all') {
                    this.currentFilter = 'all';
                    this.activeFilter = 'all';
                }
            }

            axios.get('/review-cards/manage/data', {
                params: {
                    q: this.searchQuery,
                    filter: this.currentFilter,
                    page: this.currentPage,
                    per_page: this.perPage,
                    sort_by: this.sortBy,
                    sort_dir: this.sortDir,
                    fsrs_states: this.advancedFilters.fsrsStates,
                    due_range: this.advancedFilters.dueRange,
                    reps_min: this.advancedFilters.repsMin,
                    lapses_min: this.advancedFilters.lapsesMin,
                },
            })
            .then((response) => {
                this.items = response.data.items;
                this.pagination = response.data.pagination;
                this.currentPage = response.data.pagination.current_page;

                // ADR-0012: Store search_meta for chip display.
                this.searchMeta = response.data.search_meta || null;

                // Clean up selectedIds — remove ids not in current page
                const currentIds = this.items.map(i => i.review_card_id);
                this.selectedIds = this.selectedIds.filter(id => currentIds.includes(id));
                this.updateSelectAllState();

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

        updateSelectAllState() {
            if (this.items.length === 0) {
                this.selectAll = false;
                return;
            }
            const currentIds = this.items.map(i => i.review_card_id);
            this.selectAll = currentIds.every(id => this.selectedIds.includes(id));
        },

        toggleSelectAll() {
            const currentIds = this.items.map(i => i.review_card_id);
            const allSelected = currentIds.every(id => this.selectedIds.includes(id));

            if (allSelected) {
                // Deselect all on current page
                this.selectedIds = this.selectedIds.filter(id => !currentIds.includes(id));
            } else {
                // Select all on current page
                for (const id of currentIds) {
                    if (!this.selectedIds.includes(id)) {
                        this.selectedIds.push(id);
                    }
                }
            }
            this.updateSelectAllState();
        },

        toggleItem(id) {
            const index = this.selectedIds.indexOf(id);
            if (index >= 0) {
                this.selectedIds.splice(index, 1);
            } else {
                this.selectedIds.push(id);
            }
            this.updateSelectAllState();
        },

        clearSelection() {
            this.selectedIds = [];
            this.selectAll = false;
        },

        search() {
            this.currentPage = 1;
            this.clearSelection();
            this.loadData();
        },

        // ADR-0012: Frontend helper functions for token display and removal.
        // These are SIMPLE string helpers — they do NOT replicate the backend
        // parser. Validation is server-side only. The frontend only needs to:
        //   (1) detect if advanced tokens are present (to auto-switch filter),
        //   (2) strip is: tokens when a filter button is clicked,
        //   (3) remove a specific token when a chip's close button is clicked.
        detectAdvancedTokens(query) {
            if (!query) return false;
            // A segment "looks like an advanced token" if it contains a colon
            // AND the prefix (before the first colon) is is/rated/prop (case-
            // insensitive). This mirrors the backend's token detection rule.
            const segments = query.trim().split(/\s+/);
            return segments.some(seg => {
                const colonPos = seg.indexOf(':');
                if (colonPos <= 0) return false;
                const prefix = seg.substring(0, colonPos).toLowerCase();
                return prefix === 'is' || prefix === 'rated' || prefix === 'prop';
            });
        },

        stripIsTokens(query) {
            if (!query) return '';
            const segments = query.trim().split(/\s+/);
            const kept = segments.filter(seg => {
                const colonPos = seg.indexOf(':');
                if (colonPos <= 0) return true; // plain text — keep
                const prefix = seg.substring(0, colonPos).toLowerCase();
                return prefix !== 'is'; // remove only is: tokens
            });
            return kept.join(' ').trim();
        },

        removeToken(token) {
            if (!this.searchQuery || !token) return;
            const segments = this.searchQuery.trim().split(/\s+/);
            // Remove the first segment that matches the token (case-insensitive).
            const tokenLower = token.toLowerCase();
            let removed = false;
            const kept = segments.filter(seg => {
                if (removed) return true;
                if (seg.toLowerCase() === tokenLower) {
                    removed = true;
                    return false;
                }
                return true;
            });
            this.searchQuery = kept.join(' ').trim();
            this.currentPage = 1;
            this.clearSelection();
            this.loadData();
        },

        // ADR-0012: Returns the effective filter for API requests.
        // When advanced tokens are present, returns 'all' to avoid the
        // default filter pre-filtering out lifecycle tokens. Used by
        // loadData() and all three export methods.
        effectiveFilter() {
            if (this.detectAdvancedTokens(this.searchQuery)) {
                return 'all';
            }
            return this.currentFilter;
        },

        applyFilter(filter) {
            this.activeFilter = filter;
            this.currentFilter = filter;
            // ADR-0012 §6: When the user clicks a filter button, clear is:
            // tokens from the search box but preserve plain text, rated:,
            // and prop: tokens. This makes filter buttons and is: tokens
            // mutually exclusive (as specified).
            if (this.searchQuery) {
                this.searchQuery = this.stripIsTokens(this.searchQuery);
            }
            this.currentPage = 1;
            this.clearSelection();
            this.loadData();
        },

        changePerPage() {
            this.currentPage = 1;
            this.clearSelection();
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
        stateColor,
        stateLabel,
        actionLabel,
        actionColor,
        // Map a lifecycle action to an MDI icon name.
        lifecycleActionIcon(action) {
            const icons = {
                bury: 'mdi-alarm-snooze',
                unbury: 'mdi-alarm-check',
                suspend: 'mdi-pause-circle-outline',
                resume: 'mdi-play-circle-outline',
                archive: 'mdi-archive',
                restore: 'mdi-archive-arrow-up',
            };
            return icons[action] || 'mdi-circle-medium';
        },
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
                    this.selectedIds = this.selectedIds.filter(id => id !== item.review_card_id);
                    this.loadData();
                    this.loadFsrsStats();
                })
                .catch((err) => {
                    this.error = '删除失败：' + (err.response?.data?.message || err.message);
                });
        },

        confirmBulkDelete() {
            if (this.selectedIds.length === 0) return;
            this.bulkDeleteDialog = true;
        },

        doBulkDelete() {
            this.bulkDeleteDialog = false;
            const ids = [...this.selectedIds];

            axios.post('/review-cards/manage/bulk-delete', { ids })
                .then((response) => {
                    this.clearSelection();
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
        confirmBulkLifecycle(action) {
            if (this.selectedIds.length === 0) return;
            this.bulkLifecycleAction = action;
            this.bulkLifecycleDialog = true;
        },

        // ADR-0010: Execute the bulk lifecycle action via
        // POST /review-cards/manage/bulk-lifecycle.
        // The backend applies the action per-card and reports
        // applied/skipped counts; illegal transitions are skipped,
        // not raised as errors.
        doBulkLifecycle() {
            this.bulkLifecycleDialog = false;
            const ids = [...this.selectedIds];
            const action = this.bulkLifecycleAction;
            this.bulkLifecycleLoading = true;

            axios.post('/review-cards/manage/bulk-lifecycle', {
                ids: ids,
                action: action,
                source: 'review_card_manage_bulk',
            }).then((response) => {
                this.clearSelection();
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

        // ==================== ADR-0014: Card Info read model ====================
        // The detail drawer now opens with a SINGLE canonical request to
        // GET /review-cards/manage/{reviewCard}/detail, which returns the
        // existing top-level fields PLUS an additive `card_info` object
        // (review_logs / lifecycle_events / leech). The former three parallel
        // sub-requests (logs / lifecycle-events / leech) are no longer fired
        // by the drawer. Both the list-row entry and the daily-report deep-
        // link entry share the same loadCardInfo() path.
        openDetail(item) {
            // ADR-0014: Always fetch the canonical detail payload (do not
            // trust the possibly-stale list-row snapshot). The response
            // carries both the top-level card fields and card_info.
            this.detailDrawer = true;
            this.detailTab = 'overview';
            this.detailTarget = item; // optimistic placeholder until response arrives
            this.cardInfo = null;
            this.detailError = '';
            this.detailLoading = true;
            this.loadCardInfo(item.review_card_id);
        },

        // Single canonical detail request shared by list-row and deep-link.
        // Uses a monotonic sequence number to guard against stale responses
        // when the user rapidly switches cards (or closes the drawer while a
        // request is in flight).
        loadCardInfo(reviewCardId) {
            const seq = ++this.detailRequestSeq;
            axios.get('/review-cards/manage/' + reviewCardId + '/detail')
                .then((response) => {
                    // Stale-response guard: if a newer open/close happened,
                    // discard this response.
                    if (seq !== this.detailRequestSeq) {
                        return;
                    }
                    const data = response.data || {};
                    this.detailTarget = data;
                    this.cardInfo = data.card_info || null;
                    this.detailError = '';
                })
                .catch((err) => {
                    if (seq !== this.detailRequestSeq) {
                        return;
                    }
                    this.detailError = '加载卡片详情失败：' + (err.response?.data?.message || err.message);
                    this.cardInfo = null;
                })
                .finally(() => {
                    if (seq !== this.detailRequestSeq) {
                        return;
                    }
                    this.detailLoading = false;
                });
        },

        // --- Deep link (ADR-0007) ---
        handleDeepLink() {
            const parsed = parseReviewCardManageLocation(this.$route ? this.$route.query : {});
            if (!parsed) {
                return;
            }
            this.deepLink.source = parsed.from;
            this.deepLink.reviewCardId = parsed.review_card_id;
            this.loadDeepLinkDetail(parsed.review_card_id);
        },

        loadDeepLinkDetail(reviewCardId) {
            this.deepLink.loading = true;
            this.deepLink.error = '';
            this.deepLink.active = false;
            // ADR-0014: Use the same monotonic sequence guard as
            // loadCardInfo so a rapid list-row click cannot be overwritten
            // by a late deep-link response.
            const seq = ++this.detailRequestSeq;
            this.detailLoading = true;
            axios.get('/review-cards/manage/' + reviewCardId + '/detail')
                .then((response) => {
                    if (seq !== this.detailRequestSeq) {
                        return;
                    }
                    const data = response.data || {};
                    this.deepLink.active = true;
                    // ADR-0014: Open the drawer and populate from the single
                    // canonical response. Do NOT re-fetch via openDetail()
                    // because the response already contains card_info.
                    this.detailDrawer = true;
                    this.detailTab = 'overview';
                    this.detailTarget = data;
                    this.cardInfo = data.card_info || null;
                    this.detailError = '';
                    this.detailLoading = false;
                })
                .catch(() => {
                    if (seq !== this.detailRequestSeq) {
                        return;
                    }
                    this.deepLink.error = '未找到可管理的词义复习卡，可能已删除、被拒绝或不属于当前语言。';
                    this.deepLink.active = false;
                    this.detailLoading = false;
                })
                .finally(() => {
                    this.deepLink.loading = false;
                });
        },

        closeDeepLinkError() {
            this.deepLink.error = '';
        },

        backToReport() {
            // Prefer history back (returns to /reviews/senses where the
            // report dialog was). Fall back to the sense review page if
            // there is no usable history.
            if (window.history.length > 1) {
                window.history.back();
            } else {
                window.location.href = '/reviews/senses';
            }
        },

        closeDetail() {
            // ADR-0014: Bump sequence to invalidate any in-flight request,
            // then clear all drawer state.
            this.detailRequestSeq++;
            this.detailDrawer = false;
            this.detailTarget = null;
            this.cardInfo = null;
            this.detailLoading = false;
            this.detailError = '';
            this.detailTab = 'overview';
            // Legacy field cleanup (no longer populated by the drawer, but
            // cleared for cleanliness).
            this.detailLogs = [];
            this.detailLogsLoading = false;
            this.detailLogsError = '';
            this.detailEvents = [];
            this.detailEventsLoading = false;
            this.detailEventsError = '';
            this.detailLeech = null;
            this.detailLeechLoading = false;
            this.detailLeechError = '';
        },

        // Computed-style helpers for the template. Vue 2 data() cannot
        // expose computed properties that depend on cardInfo without
        // declaring them in `computed`, so we use methods referenced from
        // the template instead.
        cardInfoReviewLogs() {
            return this.cardInfo?.review_logs?.items || [];
        },
        cardInfoReviewLogsLimit() {
            return this.cardInfo?.review_logs?.limit || 20;
        },
        cardInfoLifecycleEvents() {
            return this.cardInfo?.lifecycle_events?.items || [];
        },
        cardInfoLifecycleEventsLimit() {
            return this.cardInfo?.lifecycle_events?.limit || 20;
        },
        cardInfoLeech() {
            return this.cardInfo?.leech || null;
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
        leechStatusLabel,
        leechStatusColor,
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
        openBulkRewritePackages() {
            if (this.selectedIds.length === 0) {
                return;
            }
            this.bulkRewriteIds = [...this.selectedIds];
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
        confirmBulkLeechSuspend() {
            if (this.selectedIds.length === 0) {
                return;
            }
            this.bulkLeechSuspendDialog = true;
        },

        // Execute the bulk leech suspend via the existing bulk-lifecycle
        // endpoint with action=suspend and a dedicated source.
        doBulkLeechSuspend() {
            this.bulkLeechSuspendLoading = true;
            const ids = [...this.selectedIds];
            axios.post('/review-cards/manage/bulk-lifecycle', {
                ids: ids,
                action: 'suspend',
                source: 'manage_bulk_leech_suspend',
            }).then((response) => {
                this.bulkLeechSuspendDialog = false;
                this.clearSelection();
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

        initExportFields() {
            this.exportFields = this.exportFieldOptions.map(o => o.key);
        },

        selectAllExportFields() {
            this.exportFields = this.exportFieldOptions.map(o => o.key);
        },

        resetExportFields() {
            this.initExportFields();
        },

        exportCurrentFilter() {
            if (!this.exportFields || this.exportFields.length === 0) {
                this.snackbar = { show: true, text: '请至少选择一个导出字段。', color: 'error' };
                return;
            }
            this.exportLoading = true;
            axios.get('/review-cards/manage/export', {
                params: {
                    q: this.searchQuery,
                    filter: this.effectiveFilter(),
                    sort_by: this.sortBy,
                    sort_dir: this.sortDir,
                    fsrs_states: this.advancedFilters.fsrsStates,
                    due_range: this.advancedFilters.dueRange,
                    reps_min: this.advancedFilters.repsMin,
                    lapses_min: this.advancedFilters.lapsesMin,
                    fields: this.exportFields,
                },
                responseType: 'blob',
            })
            .then((response) => {
                const url = window.URL.createObjectURL(new Blob([response.data]));
                const link = document.createElement('a');
                link.href = url;
                const disposition = response.headers['content-disposition'] || '';
                const match = disposition.match(/filename="?(.+?)"?$/);
                link.setAttribute('download', match ? match[1] : 'review-cards-export.json');
                document.body.appendChild(link);
                link.click();
                link.remove();
                window.URL.revokeObjectURL(url);
                this.snackbar = { show: true, text: '已导出当前筛选结果。', color: 'success' };
            })
            .catch((err) => {
                if (err.response && err.response.status === 422) {
                    // Parse the JSON error from blob
                    err.response.data.text().then((text) => {
                        try {
                            const parsed = JSON.parse(text);
                            this.snackbar = { show: true, text: parsed.message || '导出失败。', color: 'error' };
                        } catch (e) {
                            this.snackbar = { show: true, text: '导出失败：结果超过上限。', color: 'error' };
                        }
                    });
                } else {
                    this.snackbar = { show: true, text: '导出失败：' + (err.response?.data?.message || err.message), color: 'error' };
                }
            })
            .finally(() => {
                this.exportLoading = false;
            });
        },

        exportAnkiTsv() {
            this.ankiExportLoading = true;
            axios.get('/review-cards/manage/export-anki-tsv', {
                params: {
                    q: this.searchQuery,
                    filter: this.effectiveFilter(),
                    sort_by: this.sortBy,
                    sort_dir: this.sortDir,
                    fsrs_states: this.advancedFilters.fsrsStates,
                    due_range: this.advancedFilters.dueRange,
                    reps_min: this.advancedFilters.repsMin,
                    lapses_min: this.advancedFilters.lapsesMin,
                },
                responseType: 'blob',
            })
            .then((response) => {
                const url = window.URL.createObjectURL(new Blob([response.data]));
                const link = document.createElement('a');
                link.href = url;
                const disposition = response.headers['content-disposition'] || '';
                const match = disposition.match(/filename="?(.+?)"?$/);
                link.setAttribute('download', match ? match[1] : 'review-cards-anki.tsv');
                document.body.appendChild(link);
                link.click();
                link.remove();
                window.URL.revokeObjectURL(url);
                this.snackbar = { show: true, text: '已导出 Anki TSV。', color: 'success' };
            })
            .catch((err) => {
                if (err.response && err.response.status === 422) {
                    err.response.data.text().then((text) => {
                        try {
                            const parsed = JSON.parse(text);
                            this.snackbar = { show: true, text: parsed.message || '导出失败。', color: 'error' };
                        } catch (e) {
                            this.snackbar = { show: true, text: '导出失败：结果超过上限。', color: 'error' };
                        }
                    });
                } else {
                    this.snackbar = { show: true, text: '导出失败：' + (err.message || '未知错误'), color: 'error' };
                }
            })
            .finally(() => {
                this.ankiExportLoading = false;
            });
        },

        exportCsv() {
            this.csvExportLoading = true;
            axios.get('/review-cards/manage/export-csv', {
                params: {
                    q: this.searchQuery,
                    filter: this.effectiveFilter(),
                    sort_by: this.sortBy,
                    sort_dir: this.sortDir,
                    fsrs_states: this.advancedFilters.fsrsStates,
                    due_range: this.advancedFilters.dueRange,
                    reps_min: this.advancedFilters.repsMin,
                    lapses_min: this.advancedFilters.lapsesMin,
                    fields: this.exportFields,
                },
                responseType: 'blob',
            })
            .then((response) => {
                const url = window.URL.createObjectURL(new Blob([response.data]));
                const link = document.createElement('a');
                link.href = url;
                const disposition = response.headers['content-disposition'] || '';
                const match = disposition.match(/filename="?(.+?)"?$/);
                link.setAttribute('download', match ? match[1] : 'review-cards.csv');
                document.body.appendChild(link);
                link.click();
                link.remove();
                window.URL.revokeObjectURL(url);
                this.snackbar = { show: true, text: '已导出 CSV。', color: 'success' };
            })
            .catch((err) => {
                if (err.response && err.response.status === 422) {
                    err.response.data.text().then((text) => {
                        try {
                            const parsed = JSON.parse(text);
                            this.snackbar = { show: true, text: parsed.message || '导出失败。', color: 'error' };
                        } catch (e) {
                            this.snackbar = { show: true, text: '导出失败：结果超过上限。', color: 'error' };
                        }
                    });
                } else {
                    this.snackbar = { show: true, text: '导出失败：' + (err.message || '未知错误'), color: 'error' };
                }
            })
            .finally(() => {
                this.csvExportLoading = false;
            });
        },

        displayValue(value, fallback = '—') {
            if (value === null || value === undefined || value === '') return fallback;
            return value;
        },

        sourceKindLabel(kind) {
            const labels = {
                chapter: '章节原文',
                occurrence_chapter: '出现章节',
                card_example: '仅有例句',
                missing: '缺失',
            };
            return labels[kind] || kind;
        },

        sourceDisplayClass(item) {
            if (!item) return '';
            const s = item.source_display_status;
            if (s === 'missing') return 'error--text';
            if (s === 'card_example_only') return 'warning--text text--darken-1';
            return '';
        },

        detailSourceClass(item) {
            if (!item) return '';
            const s = item.source_display_status;
            if (s === 'missing') return 'error--text';
            if (s === 'card_example_only') return 'warning--text text--darken-1';
            return '';
        },

        formatDueAt(isoString) {
            if (!isoString) return '—';
            const d = new Date(isoString);
            return d.toLocaleString('zh-CN', { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
        },

        formatLastReviewed(isoString) {
            if (!isoString) return '—';
            const d = new Date(isoString);
            return d.toLocaleString('zh-CN', { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
        },

        formatFsrsNumber(value) {
            if (value === null || value === undefined || value === '') {
                return '—';
            }
            const number = Number(value);
            if (Number.isNaN(number)) {
                return '—';
            }
            return number.toFixed(2);
        },

        formatDateTime(isoString) {
            if (!isoString) return '—';
            const d = new Date(isoString);
            return d.toLocaleString('zh-CN', { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
        },

        logRatingColor(rating) {
            const colors = {
                again: 'red',
                hard: 'orange',
                good: 'green',
                easy: 'blue',
                reset: 'grey',
            };
            return colors[rating] || '';
        },

        isSortedBy(column) {
            return this.sortBy === column;
        },

        sortIcon(column) {
            if (this.sortBy !== column) {
                return '↕';
            }
            return this.sortDir === 'asc' ? '↑' : '↓';
        },

        toggleSort(column) {
            if (!this.sortableColumns.includes(column)) {
                return;
            }
            if (this.sortBy === column) {
                // Toggle direction
                this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                // New column — use its default direction
                this.sortBy = column;
                this.sortDir = (this.columnDefaultDir[column] || 'asc');
            }
            this.currentPage = 1;
            this.clearSelection();
            this.loadData();
        },

        // Advanced filter methods
        toggleFsrsState(value) {
            const index = this.advancedFilters.fsrsStates.indexOf(value);
            if (index >= 0) {
                this.advancedFilters.fsrsStates.splice(index, 1);
            } else {
                this.advancedFilters.fsrsStates.push(value);
            }
        },

        applyAdvancedFilter() {
            this.currentPage = 1;
            this.clearSelection();
            this.loadData();
        },

        clearAdvancedFilter() {
            this.advancedFilters = {
                fsrsStates: [],
                dueRange: 'all',
                repsMin: null,
                lapsesMin: null,
            };
            this.currentPage = 1;
            this.clearSelection();
            this.loadData();
        },

        // Column visibility methods
        isColumnVisible(key) {
            // Pinned columns are always visible
            if (this.pinnedColumnKeys.includes(key)) return true;
            // Configurable columns: check settings, default to visible if not yet loaded
            if (this.columnSettings.hasOwnProperty(key)) {
                return this.columnSettings[key];
            }
            // Fallback: use default
            return this.columnDefaults.hasOwnProperty(key) ? this.columnDefaults[key] : true;
        },

        loadColumnSettings() {
            try {
                const saved = DefaultLocalStorageManager.loadSetting('reviewCardManageColumnSettings');
                if (saved && typeof saved === 'object') {
                    // Merge with defaults — any missing key gets the default value
                    this.columnSettings = { ...this.columnDefaults, ...saved };
                } else {
                    this.columnSettings = { ...this.columnDefaults };
                }
            } catch (e) {
                // Corrupted localStorage — fall back to defaults
                this.columnSettings = { ...this.columnDefaults };
            }
            this.columnSettingsLoaded = true;
            this.ensureVisibleSortColumn();
        },

        saveColumnSettings() {
            try {
                DefaultLocalStorageManager.saveSetting('reviewCardManageColumnSettings', this.columnSettings);
            } catch (e) {
                // localStorage full or unavailable — silently ignore
            }
        },

        toggleColumnVisibility(key) {
            if (this.pinnedColumnKeys.includes(key)) return;
            this.$set(this.columnSettings, key, !this.isColumnVisible(key));
            this.saveColumnSettings();
            this.ensureVisibleSortColumn();
        },

        resetColumnDefaults() {
            this.columnSettings = { ...this.columnDefaults };
            this.saveColumnSettings();
            this.ensureVisibleSortColumn();
            // Also disable compact mode
            this.compactMode = false;
            this.saveCompactMode();
        },

        showAllColumns() {
            for (const key of Object.keys(this.columnSettings)) {
                this.$set(this.columnSettings, key, true);
            }
            this.saveColumnSettings();
        },

        ensureVisibleSortColumn() {
            if (!this.isColumnVisible(this.sortBy)) {
                this.sortBy = 'id';
                this.sortDir = 'desc';
            }
        },

        // Compact mode methods
        loadCompactMode() {
            try {
                const saved = DefaultLocalStorageManager.loadSetting('reviewCardManageCompactMode');
                this.compactMode = saved === true;
            } catch (e) {
                this.compactMode = false;
            }
        },

        saveCompactMode() {
            try {
                DefaultLocalStorageManager.saveSetting('reviewCardManageCompactMode', this.compactMode);
            } catch (e) {
                // localStorage full or unavailable — silently ignore
            }
        },
    },
};
</script>

<style scoped>
.review-card-manage {
    max-width: 100%;
}

.stat-chip {
    background: #f5f5f5;
    border: 1px solid #e0e0e0;
    min-width: 80px;
}

.bulk-action-bar {
    background: #e3f2fd;
    border: 1px solid #90caf9;
}

.manage-table-card {
    overflow: hidden;
}

.table-wrapper {
    overflow-x: auto;
    width: 100%;
}

.manage-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: auto;
}

.manage-table thead {
    background: #fafafa;
}

.manage-table th {
    padding: 8px 6px;
    font-size: 0.75rem;
    font-weight: 600;
    color: rgba(0, 0, 0, 0.6);
    white-space: nowrap;
    border-bottom: 2px solid #e0e0e0;
    position: sticky;
    top: 0;
    background: #fafafa;
    z-index: 1;
}

.manage-table td {
    padding: 6px;
    font-size: 0.8rem;
    vertical-align: middle;
    border-bottom: 1px solid #eee;
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
}

.manage-table .selected-row {
    background: #e3f2fd;
}

/* Column widths */
.col-check { width: 40px; text-align: center; }
.col-id { width: 50px; }
.col-lemma { min-width: 90px; }
.col-surface { min-width: 80px; }
.col-pos { width: 70px; }
.col-def { min-width: 100px; }
.col-example { min-width: 140px; }
.col-source { min-width: 90px; }
.col-status { width: 80px; }
.col-due { width: 90px; }
.col-fsrs { width: 70px; text-align: center; }
.col-last-review { width: 90px; text-align: center; }

/* Sticky operations column */
.col-actions {
    min-width: 160px;
    white-space: nowrap;
    position: sticky;
    right: 0;
    background: #fff;
    z-index: 1;
    box-shadow: -2px 0 4px rgba(0, 0, 0, 0.05);
}

.manage-table .selected-row .col-actions {
    background: #e3f2fd;
}

.manage-table thead .col-actions {
    z-index: 2;
}

.sortable {
    cursor: pointer;
    user-select: none;
}

.sortable:hover {
    background: #e0e0e0;
}

.sort-icon {
    display: inline-block;
    width: 14px;
    text-align: center;
    font-size: 0.7rem;
    color: #999;
    margin-left: 2px;
}

.sortable .sort-icon {
    color: #1976d2;
}

.edit-field {
    min-width: 90px;
    font-size: 11px;
}

.v-btn-toggle.flex-wrap {
    flex-wrap: wrap;
}

/* Compact mode */
.manage-table.table--compact th {
    padding: 6px 4px;
    font-size: 0.72rem;
}

.manage-table.table--compact td {
    padding: 4px;
    font-size: 0.75rem;
}

.manage-table.table--compact .col-check { width: 34px; }
.manage-table.table--compact .col-id { width: 44px; }
.manage-table.table--compact .col-lemma { min-width: 80px; }
.manage-table.table--compact .col-surface { min-width: 70px; }
.manage-table.table--compact .col-pos { width: 60px; }
.manage-table.table--compact .col-def { min-width: 90px; }
.manage-table.table--compact .col-example { min-width: 120px; }
.manage-table.table--compact .col-source { min-width: 78px; }
.manage-table.table--compact .col-status { width: 70px; }
.manage-table.table--compact .col-due { width: 78px; }
.manage-table.table--compact .col-fsrs { width: 58px; }
.manage-table.table--compact .col-last-review { width: 78px; }
.manage-table.table--compact .col-actions {
    min-width: 145px;
}

/* Detail drawer */
.detail-drawer .detail-content {
    padding-top: 4px;
}

.detail-section {
    margin-bottom: 4px;
}

.detail-section-title {
    font-size: 0.75rem;
    font-weight: 700;
    color: rgba(0, 0, 0, 0.54);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
}

.detail-row {
    display: flex;
    align-items: baseline;
    padding: 4px 0;
    border-bottom: 1px solid #f5f5f5;
}

.detail-label {
    flex: 0 0 100px;
    font-size: 0.8rem;
    color: rgba(0, 0, 0, 0.54);
    white-space: nowrap;
}

.detail-value {
    flex: 1;
    font-size: 0.85rem;
    color: rgba(0, 0, 0, 0.87);
    word-break: break-word;
}

.log-entry {
    background: #fafafa;
    border-radius: 4px;
    border: 1px solid #f0f0f0;
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
