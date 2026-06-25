<template>
    <v-container fluid class="review-card-manage pa-4">
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
            <v-chip v-for="chip in statsChips" :key="chip.label" x-small outlined class="mr-1 mb-1">
                {{ chip.label }} {{ chip.value }}
            </v-chip>
        </div>

        <!-- Toolbar -->
        <v-row class="mb-3" dense align="center">
            <v-col cols="12" sm="5" md="4">
                <v-text-field
                    v-model="searchQuery"
                    label="搜索 Lemma / 释义 / 例句"
                    prepend-inner-icon="mdi-magnify"
                    clearable
                    dense
                    hide-details
                    @keyup.enter="search"
                    @click:clear="search"
                />
            </v-col>
            <v-col cols="12" sm="7" md="5">
                <v-btn-toggle v-model="activeFilter" mandatory dense class="flex-wrap">
                    <v-btn small value="all" @click="applyFilter('all')">全部</v-btn>
                    <v-btn small value="due" @click="applyFilter('due')">到期</v-btn>
                    <v-btn small value="future" @click="applyFilter('future')">未来到期</v-btn>
                    <v-btn small value="enabled" @click="applyFilter('enabled')">未归档</v-btn>
                    <v-btn small value="disabled" @click="applyFilter('disabled')">已归档</v-btn>
                    <v-btn small value="missing_definition" @click="applyFilter('missing_definition')">缺释义</v-btn>
                    <v-btn small value="missing_example" @click="applyFilter('missing_example')">缺例句</v-btn>
                    <v-btn small value="missing_source" @click="applyFilter('missing_source')">缺溯源</v-btn>
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
            <v-btn small color="warning" class="mr-2" @click="bulkArchive">批量归档</v-btn>
            <v-btn small color="success" class="mr-2" @click="bulkRestore">批量恢复</v-btn>
            <v-btn small color="error" class="mr-2" @click="confirmBulkDelete">批量彻底删除</v-btn>
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
                            <td class="col-source" :class="{ 'text--secondary': item.missing_source }" v-if="isColumnVisible('source')">
                                {{ item.source_chapter_title || sourceKindLabel(item.source_kind) }}
                            </td>
                            <td class="col-status">
                                <v-chip x-small :color="item.fsrs_enabled ? 'success' : 'grey'">
                                    {{ item.fsrs_enabled ? '未归档' : '已归档' }}
                                </v-chip>
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
                                    <v-btn v-if="item.fsrs_enabled" x-small text color="warning" @click="confirmArchive(item)">归档</v-btn>
                                    <v-btn v-else x-small text color="success" @click="toggleEnabled(item)">恢复</v-btn>
                                    <v-btn v-if="item.fsrs_enabled" x-small text @click="setDueNow(item)">立即到期</v-btn>
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
                                            <v-list-item @click="confirmDelete(item)">
                                                <v-list-item-title class="error--text">彻底删除</v-list-item-title>
                                            </v-list-item>
                                        </v-list>
                                    </v-menu>
                                </template>
                            </td>
                        </tr>
                        <tr v-if="!loading && items.length === 0">
                            <td :colspan="visibleColumnCount" class="text-center py-4 text--secondary">暂无词义复习卡。</td>
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
            <template v-if="detailTarget">
                <v-card flat>
                    <v-card-title class="d-flex align-center">
                        <span>复习卡详情</span>
                        <v-spacer />
                        <v-btn icon small @click="closeDetail">
                            <v-icon>mdi-close</v-icon>
                        </v-btn>
                    </v-card-title>
                    <v-card-subtitle class="pb-0">
                        {{ detailTarget.lemma }} / {{ detailTarget.surface_form }} / {{ detailTarget.pos }}
                    </v-card-subtitle>
                    <v-card-text class="detail-content">
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
                                    <v-chip x-small :color="detailTarget.fsrs_enabled ? 'success' : 'grey'">
                                        {{ detailTarget.fsrs_enabled ? '未归档' : '已归档' }}
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
                                <span class="detail-value" :class="{ 'text--secondary': detailTarget.missing_source }">
                                    {{ detailTarget.source_chapter_title || sourceKindLabel(detailTarget.source_kind) }}
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
                            <div class="detail-row">
                                <span class="detail-label">缺溯源</span>
                                <span class="detail-value">{{ detailTarget.missing_source ? '是' : '否' }}</span>
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

                        <!-- 最近复习记录 -->
                        <div class="detail-section">
                            <div class="detail-section-title">最近复习记录</div>
                            <div v-if="detailLogsLoading" class="text-caption text--secondary py-2">加载复习记录中...</div>
                            <div v-else-if="detailLogsError" class="text-caption error--text py-2">{{ detailLogsError }}</div>
                            <div v-else-if="detailLogs.length === 0" class="text-caption text--secondary py-2">暂无复习记录。</div>
                            <div v-else>
                                <div
                                    v-for="log in detailLogs"
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
                                </div>
                            </div>
                        </div>

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
                                <span class="detail-label">缺溯源</span>
                                <span class="detail-value">{{ detailTarget.missing_source ? '是' : '否' }}</span>
                            </div>
                        </div>
                    </v-card-text>
                    <v-card-actions>
                        <v-btn text @click="closeDetail">关闭</v-btn>
                        <v-spacer />
                        <v-btn text color="primary" @click="viewSource(detailTarget); closeDetail()">查看原文</v-btn>
                    </v-card-actions>
                </v-card>
            </template>
        </v-navigation-drawer>

        <!-- Archive confirmation dialog -->
        <v-dialog v-model="archiveDialog" max-width="480">
            <v-card>
                <v-card-title>确认归档</v-card-title>
                <v-card-text>
                    归档后，这张词义卡不会进入日常复习，但释义、例句、复习历史都会保留。确定归档吗？
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="archiveDialog = false">取消</v-btn>
                    <v-btn color="warning" @click="doArchive">归档</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Reset confirmation dialog -->
        <v-dialog v-model="resetDialog" max-width="500">
            <v-card>
                <v-card-title>重置为新学卡</v-card-title>
                <v-card-text>
                    <p>这会清空这张词义卡的 FSRS 记忆状态，并把它重新设为新学卡。</p>
                    <p>复习历史会保留，释义、例句和原文位置不会改变。</p>
                    <p>如果这张卡已归档，重置后会重新启用并进入复习队列。</p>
                    <p class="error--text">此操作不可恢复。重置后 FSRS 记忆状态将被清除。</p>
                    <p class="font-weight-bold">确定重置吗？</p>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="resetDialog = false" :disabled="resetLoading">取消</v-btn>
                    <v-btn color="primary" :loading="resetLoading" @click="doReset">确认重置</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Delete confirmation dialog -->
        <v-dialog v-model="deleteDialog" max-width="500">
            <v-card>
                <v-card-title class="error--text">彻底删除词义复习卡</v-card-title>
                <v-card-text>
                    <p>这会删除这张词义复习卡，并让该释义不再出现在阅读页点词结果中。</p>
                    <p class="font-weight-bold">阅读材料、原文位置和复习历史会保留。</p>
                    <p class="error--text">此操作不可恢复。确定删除吗？</p>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="deleteDialog = false">取消</v-btn>
                    <v-btn color="error" @click="doDelete">彻底删除</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Bulk delete confirmation dialog -->
        <v-dialog v-model="bulkDeleteDialog" max-width="520">
            <v-card>
                <v-card-title class="error--text">批量彻底删除词义复习卡</v-card-title>
                <v-card-text>
                    <p>将删除已选的 <strong>{{ selectedIds.length }}</strong> 张词义复习卡，并让对应释义不再出现在阅读页点词结果中。</p>
                    <p class="font-weight-bold">阅读材料、原文位置和复习历史会保留。</p>
                    <p class="error--text">此操作不可恢复。确定删除吗？</p>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="bulkDeleteDialog = false">取消</v-btn>
                    <v-btn color="error" @click="doBulkDelete">彻底删除 {{ selectedIds.length }} 张</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Bulk archive confirmation dialog -->
        <v-dialog v-model="bulkArchiveDialog" max-width="500">
            <v-card>
                <v-card-title>批量归档</v-card-title>
                <v-card-text>
                    <p>即将批量归档 <strong>{{ selectedIds.length }}</strong> 张复习卡。</p>
                    <p>操作只影响当前选中的 sense review cards。</p>
                    <p class="font-weight-bold">是否继续？</p>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="bulkArchiveDialog = false">取消</v-btn>
                    <v-btn color="warning" @click="doBulkArchive">确认归档</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Bulk restore confirmation dialog -->
        <v-dialog v-model="bulkRestoreDialog" max-width="500">
            <v-card>
                <v-card-title>批量恢复</v-card-title>
                <v-card-text>
                    <p>即将批量恢复 <strong>{{ selectedIds.length }}</strong> 张复习卡。</p>
                    <p>操作只影响当前选中的 sense review cards。</p>
                    <p class="font-weight-bold">是否继续？</p>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="bulkRestoreDialog = false">取消</v-btn>
                    <v-btn color="success" @click="doBulkRestore">确认恢复</v-btn>
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

export default {
    components: {
        SenseExampleDialog,
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
            activeFilter: 'enabled',
            currentFilter: 'enabled',
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
            detailLogs: [],
            detailLogsLoading: false,
            detailLogsError: '',
            archiveDialog: false,
            archiveTarget: null,
            resetDialog: false,
            resetTarget: null,
            resetLoading: false,
            deleteDialog: false,
            deleteTarget: null,
            bulkArchiveDialog: false,
            bulkRestoreDialog: false,
            bulkDeleteDialog: false,
            selectedIds: [],
            selectAll: false,
            snackbar: { show: false, text: '', color: 'success' },
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
                { key: 'missing_definition', label: '缺释义' },
                { key: 'missing_example', label: '缺例句' },
                { key: 'missing_source', label: '缺溯源' },
            ],
            exportFields: [],
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
                { label: '启用中', value: this.fsrsStats.enabled },
                { label: '已归档', value: this.fsrsStats.archived },
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
    },
    mounted() {
        this.loadColumnSettings();
        this.loadCompactMode();
        this.loadData();
        this.loadFsrsStats();
        this.initExportFields();
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
                this.error = '加载数据失败：' + (err.response?.data?.message || err.message);
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

        applyFilter(filter) {
            this.activeFilter = filter;
            this.currentFilter = filter;
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
                    this.showSnackbar(response.data.message || '已重置为新学卡。该卡会重新进入复习队列。', 'success');
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
                    this.showSnackbar(response.data.message || '已彻底删除词义复习卡。该释义不会再出现在阅读页。', 'success');
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
                    this.showSnackbar(response.data.message || '已彻底删除词义复习卡。', 'success');
                    this.loadData();
                    this.loadFsrsStats();
                })
                .catch((err) => {
                    this.error = '批量删除失败：' + (err.response?.data?.message || err.message);
                });
        },

        bulkArchive() {
            if (this.selectedIds.length === 0) return;
            this.bulkArchiveDialog = true;
        },

        doBulkArchive() {
            this.bulkArchiveDialog = false;
            const ids = [...this.selectedIds];

            axios.post('/review-cards/manage/bulk-enabled', { ids, enabled: false })
                .then((response) => {
                    this.clearSelection();
                    this.showSnackbar(response.data.message || '已批量归档。', 'warning');
                    this.loadData();
                    this.loadFsrsStats();
                })
                .catch((err) => {
                    this.error = '批量归档失败：' + (err.response?.data?.message || err.message);
                });
        },

        bulkRestore() {
            if (this.selectedIds.length === 0) return;
            this.bulkRestoreDialog = true;
        },

        doBulkRestore() {
            this.bulkRestoreDialog = false;
            const ids = [...this.selectedIds];

            axios.post('/review-cards/manage/bulk-enabled', { ids, enabled: true })
                .then((response) => {
                    this.clearSelection();
                    this.showSnackbar(response.data.message || '已批量恢复。', 'success');
                    this.loadData();
                    this.loadFsrsStats();
                })
                .catch((err) => {
                    this.error = '批量恢复失败：' + (err.response?.data?.message || err.message);
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
                })
                .catch(() => {
                    this.sourcePayload = { card: card, context: null, error: '获取原文失败。' };
                    this.sourceDialog = true;
                });
        },

        openDetail(item) {
            this.detailTarget = item;
            this.detailLogs = [];
            this.detailLogsLoading = false;
            this.detailLogsError = '';
            this.detailDrawer = true;
            this.loadDetailLogs(item);
        },

        closeDetail() {
            this.detailDrawer = false;
            this.detailTarget = null;
            this.detailLogs = [];
            this.detailLogsLoading = false;
            this.detailLogsError = '';
        },

        loadDetailLogs(item) {
            this.detailLogsLoading = true;
            this.detailLogsError = '';
            axios.get('/review-cards/manage/' + item.review_card_id + '/logs')
                .then((response) => {
                    this.detailLogs = response.data.items || [];
                })
                .catch((err) => {
                    this.detailLogsError = '加载复习记录失败：' + (err.response?.data?.message || err.message);
                })
                .finally(() => {
                    this.detailLogsLoading = false;
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
                    filter: this.currentFilter,
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
                    filter: this.currentFilter,
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
                    filter: this.currentFilter,
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
</style>
