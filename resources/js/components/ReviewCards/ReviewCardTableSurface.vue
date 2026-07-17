<template>
    <section class="review-card-table-surface">
        <div class="d-flex flex-wrap justify-end mb-2" style="gap: 12px;">
            <div class="stat-chip text-center px-4 py-2 rounded-lg">
                <div class="text-caption text--secondary">总计</div>
                <div class="text-h6 font-weight-bold">{{ pagination.total || 0 }}</div>
            </div>
            <div class="stat-chip text-center px-4 py-2 rounded-lg" :class="{ 'primary--text': selectedIds.length > 0 }">
                <div class="text-caption text--secondary">已选</div>
                <div class="text-h6 font-weight-bold">{{ selectedIds.length }}</div>
            </div>
        </div>

        <v-row class="mb-3" dense align="center">
            <v-col cols="6" sm="3" md="2">
                <v-select
                    :value="perPage"
                    :items="[20, 50, 100]"
                    label="每页"
                    dense
                    hide-details
                    @change="changePerPage"
                />
            </v-col>
            <v-col cols="6" sm="3" md="2" class="d-flex align-center justify-end">
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
                                :input-value="col.visible"
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

        <div v-if="selectedIds.length > 0" class="bulk-action-bar d-flex flex-wrap align-center pa-3 mb-3 rounded-lg">
            <review-card-marker-picker class="mr-2" :value="0" @change="emitBulkMarker" />
            <v-checkbox
                :input-value="selectAll"
                :indeterminate="selectIndeterminate"
                dense
                hide-details
                class="ma-0 mr-2"
                @change="toggleSelectAll"
            />
            <span class="mr-4 body-2">已选 <strong>{{ selectedIds.length }}</strong> 项</span>
            <v-menu offset-y>
                <template #activator="{ on, attrs }">
                    <v-btn small color="primary" class="mr-2" v-bind="attrs" v-on="on" :disabled="bulkLifecycleLoading">
                        <v-icon small left>mdi-state-machine</v-icon>批量生命周期
                        <v-icon small right>mdi-chevron-down</v-icon>
                    </v-btn>
                </template>
                <v-list dense>
                    <v-list-item
                        v-for="action in bulkLifecycleActions"
                        :key="action.key"
                        :disabled="bulkLifecycleLoading"
                        @click="emitBulkLifecycle(action.key)"
                    >
                        <v-list-item-icon><v-icon small>{{ action.icon }}</v-icon></v-list-item-icon>
                        <v-list-item-title>{{ action.label }}</v-list-item-title>
                    </v-list-item>
                </v-list>
            </v-menu>
            <v-btn small color="error" class="mr-2" @click="emitBulk('bulk-delete')">批量彻底删除</v-btn>
            <v-btn
                small
                color="primary"
                class="mr-2"
                :loading="bulkRewriteLoading"
                :disabled="bulkRewriteLoading"
                @click="emitBulk('bulk-rewrite')"
            >
                <v-icon small left>mdi-package-variant-closed</v-icon>批量生成重写包
            </v-btn>
            <v-btn
                small
                color="warning"
                class="mr-2"
                :loading="bulkLeechSuspendLoading"
                :disabled="bulkLeechSuspendLoading"
                @click="emitBulk('bulk-leech-suspend')"
            >
                <v-icon small left>mdi-pause-circle-outline</v-icon>批量暂停高遗忘卡
            </v-btn>
            <v-spacer />
            <v-btn small text @click="clearSelection">取消选择</v-btn>
        </div>

        <v-progress-linear v-if="loading" indeterminate class="mb-3" />
        <v-alert v-if="error" type="error" dense class="mb-3">{{ error }}</v-alert>

        <v-card class="manage-table-card">
            <div class="table-wrapper">
                <table class="manage-table" :class="{ 'table--compact': compactMode }" :style="{ minWidth: tableMinWidth }">
                    <thead>
                        <tr>
                            <th class="col-check">
                                <v-checkbox
                                    :input-value="selectAll"
                                    :indeterminate="selectIndeterminate"
                                    dense
                                    hide-details
                                    class="ma-0"
                                    @change="toggleSelectAll"
                                />
                            </th>
                            <th v-if="isColumnVisible('id')" class="col-id sortable" @click="toggleSort('id')">ID <span class="sort-icon">{{ sortIcon('id') }}</span></th>
                            <th class="col-lemma">Lemma</th>
                            <th v-if="isColumnVisible('surface_form')" class="col-surface">Surface</th>
                            <th v-if="isColumnVisible('pos')" class="col-pos">POS</th>
                            <th class="col-def">释义(中)</th>
                            <th v-if="isColumnVisible('sense_en')" class="col-def">释义(英)</th>
                            <th v-if="isColumnVisible('example_sentence_en')" class="col-example">例句(英)</th>
                            <th v-if="isColumnVisible('example_sentence_zh')" class="col-example">例句(中)</th>
                            <th v-if="isColumnVisible('source')" class="col-source">溯源</th>
                            <th class="col-status sortable" @click="toggleSort('fsrs_state')">状态 <span class="sort-icon">{{ sortIcon('fsrs_state') }}</span></th>
                            <th class="col-marker">标记</th>
                            <th v-if="isColumnVisible('fsrs_stability')" class="col-fsrs sortable" @click="toggleSort('fsrs_stability')">稳定度 <span class="sort-icon">{{ sortIcon('fsrs_stability') }}</span></th>
                            <th v-if="isColumnVisible('fsrs_difficulty')" class="col-fsrs sortable" @click="toggleSort('fsrs_difficulty')">难度 <span class="sort-icon">{{ sortIcon('fsrs_difficulty') }}</span></th>
                            <th v-if="isColumnVisible('fsrs_reps')" class="col-fsrs sortable" @click="toggleSort('fsrs_reps')">复习 <span class="sort-icon">{{ sortIcon('fsrs_reps') }}</span></th>
                            <th v-if="isColumnVisible('fsrs_lapses')" class="col-fsrs sortable" @click="toggleSort('fsrs_lapses')">遗忘 <span class="sort-icon">{{ sortIcon('fsrs_lapses') }}</span></th>
                            <th v-if="isColumnVisible('fsrs_last_reviewed_at')" class="col-last-review sortable" @click="toggleSort('fsrs_last_reviewed_at')">最近复习 <span class="sort-icon">{{ sortIcon('fsrs_last_reviewed_at') }}</span></th>
                            <th v-if="isColumnVisible('fsrs_due_at')" class="col-due sortable" @click="toggleSort('fsrs_due_at')">到期 <span class="sort-icon">{{ sortIcon('fsrs_due_at') }}</span></th>
                            <th class="col-actions">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="item in items"
                            :key="item.review_card_id"
                            :class="{
                                'selected-row': selectedIds.includes(item.review_card_id),
                                'current-row': currentCardId === item.review_card_id,
                            }"
                        >
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
                            <td v-if="isColumnVisible('id')" class="col-id">{{ item.review_card_id }}</td>
                            <td class="col-lemma">{{ item.lemma }}</td>
                            <td v-if="isColumnVisible('surface_form')" class="col-surface">{{ item.surface_form }}</td>
                            <td v-if="isColumnVisible('pos')" class="col-pos">
                                <template v-if="editingId === item.review_card_id">
                                    <v-text-field :value="editForm.pos" dense hide-details class="edit-field" @input="updateEditField('pos', $event)" />
                                </template>
                                <template v-else>{{ item.pos }}</template>
                            </td>
                            <td class="col-def" :class="{ 'text--secondary': item.missing_definition }">
                                <template v-if="editingId === item.review_card_id">
                                    <v-text-field :value="editForm.sense_zh" dense hide-details class="edit-field" @input="updateEditField('sense_zh', $event)" />
                                </template>
                                <template v-else>{{ item.sense_zh || '—' }}</template>
                            </td>
                            <td v-if="isColumnVisible('sense_en')" class="col-def" :class="{ 'text--secondary': item.missing_definition }">
                                <template v-if="editingId === item.review_card_id">
                                    <v-text-field :value="editForm.sense_en" dense hide-details class="edit-field" @input="updateEditField('sense_en', $event)" />
                                </template>
                                <template v-else>{{ item.sense_en || '—' }}</template>
                            </td>
                            <td v-if="isColumnVisible('example_sentence_en')" class="col-example" :class="{ 'text--secondary': item.missing_example }">
                                <template v-if="editingId === item.review_card_id">
                                    <v-textarea :value="editForm.example_sentence_en" dense hide-details rows="2" class="edit-field" @input="updateEditField('example_sentence_en', $event)" />
                                </template>
                                <template v-else>{{ item.example_sentence_en || '—' }}</template>
                            </td>
                            <td v-if="isColumnVisible('example_sentence_zh')" class="col-example">
                                <template v-if="editingId === item.review_card_id">
                                    <v-text-field :value="editForm.example_sentence_zh" dense hide-details class="edit-field" @input="updateEditField('example_sentence_zh', $event)" />
                                </template>
                                <template v-else>{{ item.example_sentence_zh || '—' }}</template>
                            </td>
                            <td v-if="isColumnVisible('source')" class="col-source" :class="sourceDisplayClass(item)">
                                {{ item.source_display_label || item.source_chapter_title || sourceKindLabel(item.source_kind) }}
                            </td>
                            <td class="col-status">
                                <v-chip x-small :color="stateColor(item.lifecycle_state)">{{ stateLabel(item.lifecycle_state) }}</v-chip>
                                <v-chip
                                    v-if="item.leech_status && item.leech_status !== 'stable'"
                                    x-small
                                    :color="leechStatusColor(item.leech_status)"
                                    text-color="white"
                                    class="ml-1"
                                >{{ leechStatusLabel(item.leech_status) }}</v-chip>
                                <span class="text-caption d-block">{{ item.fsrs_state }}</span>
                            </td>
                            <td class="col-marker">
                                <review-card-marker-picker :value="Number(item.marker || 0)" @change="$emit('marker-change', { item, marker: $event })" />
                            </td>
                            <td v-if="isColumnVisible('fsrs_stability')" class="col-fsrs text-center">{{ formatFsrsNumber(item.fsrs_stability) }}</td>
                            <td v-if="isColumnVisible('fsrs_difficulty')" class="col-fsrs text-center">{{ formatFsrsNumber(item.fsrs_difficulty) }}</td>
                            <td v-if="isColumnVisible('fsrs_reps')" class="col-fsrs text-center">{{ item.fsrs_reps || 0 }}</td>
                            <td v-if="isColumnVisible('fsrs_lapses')" class="col-fsrs text-center">{{ item.fsrs_lapses || 0 }}</td>
                            <td v-if="isColumnVisible('fsrs_last_reviewed_at')" class="col-last-review text-center">{{ formatLastReviewed(item.fsrs_last_reviewed_at) }}</td>
                            <td v-if="isColumnVisible('fsrs_due_at')" class="col-due"><span class="text-caption">{{ formatDueAt(item.fsrs_due_at) }}</span></td>
                            <td class="col-actions">
                                <template v-if="editingId === item.review_card_id">
                                    <v-btn x-small color="primary" :loading="savingId === item.review_card_id" @click="emitRow('edit-save', item)">保存</v-btn>
                                    <v-btn x-small text @click="emitRow('edit-cancel', item)">取消</v-btn>
                                </template>
                                <template v-else>
                                    <v-btn x-small text @click="emitRow('edit-start', item)">编辑</v-btn>
                                    <v-btn x-small text @click="emitRow('detail', item)">详情</v-btn>
                                    <v-menu offset-y @input="emitLifecycleMenuToggle($event, item)">
                                        <template #activator="{ on, attrs }">
                                            <v-btn x-small text v-bind="attrs" v-on="on" :disabled="lifecycleLoading">生命周期</v-btn>
                                        </template>
                                        <v-list dense>
                                            <div v-if="lifecycleMenuId === item.review_card_id && !lifecycleDescriptor" class="text-caption text--secondary pa-2">加载中...</div>
                                            <v-list-item
                                                v-for="lifecycleAction in availableLifecycleActions"
                                                :key="lifecycleAction"
                                                :disabled="lifecycleLoading"
                                                @click="emitLifecycleAction(lifecycleAction, item)"
                                            >
                                                <v-list-item-icon><v-icon small :color="actionColor(lifecycleAction)">{{ lifecycleActionIcon(lifecycleAction) }}</v-icon></v-list-item-icon>
                                                <v-list-item-title>{{ actionLabel(lifecycleAction) }}</v-list-item-title>
                                            </v-list-item>
                                            <div v-if="lifecycleMenuId === item.review_card_id && lifecycleDescriptor && availableLifecycleActions.length === 0" class="text-caption text--secondary pa-2">无可用操作</div>
                                        </v-list>
                                    </v-menu>
                                    <v-btn v-if="item.lifecycle_state === 'active'" x-small text @click="emitRow('due-now', item)">立即到期</v-btn>
                                    <v-menu offset-y>
                                        <template #activator="{ on, attrs }">
                                            <v-btn x-small text v-bind="attrs" v-on="on">更多</v-btn>
                                        </template>
                                        <v-list dense>
                                            <v-list-item @click="emitRow('source', item)"><v-list-item-title>查看原文</v-list-item-title></v-list-item>
                                            <v-list-item @click="emitRow('reset', item)"><v-list-item-title>重置</v-list-item-title></v-list-item>
                                            <v-list-item @click="emitRow('rewrite-package', item)"><v-list-item-title>生成重写包</v-list-item-title></v-list-item>
                                            <v-list-item v-if="item.leech_status === 'leech'" @click="emitRow('suspend-leech', item)"><v-list-item-title class="warning--text">暂停</v-list-item-title></v-list-item>
                                            <v-list-item @click="emitRow('delete', item)"><v-list-item-title class="error--text">彻底删除</v-list-item-title></v-list-item>
                                        </v-list>
                                    </v-menu>
                                </template>
                            </td>
                        </tr>
                        <tr v-if="!loading && items.length === 0">
                            <td :colspan="visibleColumnCount" class="text-center py-4 text--secondary">
                                <span v-if="searchErrors.length > 0">搜索语法有误，请修正后重试。</span>
                                <span v-else-if="filterState.q || (searchMeta && searchMeta.advanced)">当前搜索没有匹配结果。</span>
                                <span v-else>暂无词义复习卡。</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </v-card>

        <div class="d-flex justify-center mt-4">
            <v-pagination
                v-if="pagination.last_page > 1"
                :value="currentPage"
                :length="pagination.last_page"
                :total-visible="7"
                @input="changePage"
            />
        </div>
    </section>
</template>

<script>
import axios from 'axios';
import { DefaultLocalStorageManager } from '../../services/LocalStorageManagerService.js';
import {
    actionLabel,
    actionColor,
    stateLabel,
    stateColor,
} from '../../services/ReviewCardLifecyclePresentation.js';
import {
    statusLabel as leechStatusLabel,
    statusColor as leechStatusColor,
} from '../../services/SenseReviewLeechPresentation.js';
import ReviewCardMarkerPicker from './ReviewCardMarkerPicker.vue';

export default {
    components: { ReviewCardMarkerPicker },
    props: {
        items: { type: Array, default: () => [] },
        pagination: { type: Object, default: () => ({ current_page: 1, last_page: 1, total: 0 }) },
        loading: { type: Boolean, default: false },
        error: { type: String, default: '' },
        searchErrors: { type: Array, default: () => [] },
        searchMeta: { type: Object, default: null },
        filterState: { type: Object, required: true },
        perPage: { type: Number, default: 20 },
        currentPage: { type: Number, default: 1 },
        sortBy: { type: String, default: 'id' },
        sortDir: { type: String, default: 'desc' },
        editingId: { type: Number, default: null },
        savingId: { type: Number, default: null },
        editForm: { type: Object, default: () => ({}) },
        lifecycleLoading: { type: Boolean, default: false },
        lifecycleMenuId: { type: Number, default: null },
        lifecycleDescriptor: { type: Object, default: null },
        availableLifecycleActions: { type: Array, default: () => [] },
        bulkLifecycleLoading: { type: Boolean, default: false },
        bulkRewriteLoading: { type: Boolean, default: false },
        bulkLeechSuspendLoading: { type: Boolean, default: false },
    },
    data() {
        return {
            selectedIds: [],
            selectAll: false,
            currentCardId: null,
            columnSettings: {},
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
            pinnedColumnKeys: ['checkbox', 'lemma', 'sense_zh', 'fsrs_state', 'marker', 'actions'],
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
            compactMode: false,
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
        };
    },
    computed: {
        selectedItems() {
            return this.items.filter(item => this.selectedIds.includes(item.review_card_id));
        },
        selectIndeterminate() {
            if (this.selectedIds.length === 0 || this.items.length === 0) return false;
            return this.selectedIds.length < this.items.length;
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
        configurableColumns() {
            return this.configurableColumnDefs.map(def => ({ ...def, visible: this.isColumnVisible(def.key) }));
        },
        hasHiddenColumns() {
            return this.configurableColumns.some(column => !column.visible);
        },
        visibleColumnCount() {
            return this.pinnedColumnKeys.length + Object.values(this.columnSettings).filter(Boolean).length;
        },
        tableMinWidth() {
            let width = this.compactMode ? 1480 : 1640;
            if (!this.isColumnVisible('sense_en')) width -= 100;
            if (!this.isColumnVisible('example_sentence_en')) width -= 140;
            if (!this.isColumnVisible('example_sentence_zh')) width -= 140;
            return width + 'px';
        },
        bulkLifecycleActions() {
            return [
                { key: 'suspend', label: '批量暂停', icon: 'mdi-pause-circle-outline' },
                { key: 'resume', label: '批量恢复复习', icon: 'mdi-play-circle-outline' },
                { key: 'archive', label: '批量归档', icon: 'mdi-archive' },
                { key: 'restore', label: '批量恢复归档', icon: 'mdi-archive-arrow-up' },
                { key: 'unbury', label: '批量解除埋藏', icon: 'mdi-alarm-check' },
            ];
        },
    },
    watch: {
        items: {
            immediate: true,
            handler() {
                const visibleIds = this.items.map(item => item.review_card_id);
                this.selectedIds = this.selectedIds.filter(id => visibleIds.includes(id));
                if (this.currentCardId !== null && !visibleIds.includes(this.currentCardId)) {
                    this.currentCardId = null;
                }
                this.updateSelectAllState();
            },
        },
    },
    mounted() {
        this.loadColumnSettings();
        this.loadCompactMode();
        this.initExportFields();
    },
    methods: {
        stateLabel,
        stateColor,
        actionLabel,
        actionColor,
        leechStatusLabel,
        leechStatusColor,
        markCurrentCard(item) {
            this.markCurrentCardById(item && item.review_card_id);
        },
        markCurrentCardById(reviewCardId) {
            const normalizedId = Number(reviewCardId);
            this.currentCardId = Number.isInteger(normalizedId) && normalizedId > 0
                ? normalizedId
                : null;
        },
        emitRow(eventName, item) {
            this.markCurrentCard(item);
            this.$emit(eventName, item);
        },
        emitLifecycleMenuToggle(isOpen, item) {
            if (isOpen) this.markCurrentCard(item);
            this.$emit('lifecycle-menu-toggle', isOpen, item);
        },
        emitLifecycleAction(action, item) {
            this.markCurrentCard(item);
            this.$emit('lifecycle-action', action, item);
        },
        updateEditField(field, value) {
            this.$emit('edit-form-update', { ...this.editForm, [field]: value });
        },
        emitBulk(eventName) {
            if (this.selectedIds.length === 0) return;
            this.$emit(eventName, { ids: [...this.selectedIds], items: [...this.selectedItems] });
        },
        emitBulkLifecycle(action) {
            if (this.selectedIds.length === 0) return;
            this.$emit('bulk-lifecycle', { action, ids: [...this.selectedIds], items: [...this.selectedItems] });
        },
        emitBulkMarker(marker) {
            if (this.selectedIds.length === 0) return;
            this.$emit('bulk-marker', { ids: [...this.selectedIds], items: [...this.selectedItems], marker });
        },
        updateSelectAllState() {
            if (this.items.length === 0) {
                this.selectAll = false;
                return;
            }
            const visibleIds = this.items.map(item => item.review_card_id);
            this.selectAll = visibleIds.every(id => this.selectedIds.includes(id));
        },
        toggleSelectAll() {
            const visibleIds = this.items.map(item => item.review_card_id);
            const allSelected = visibleIds.length > 0 && visibleIds.every(id => this.selectedIds.includes(id));
            if (allSelected) {
                this.selectedIds = this.selectedIds.filter(id => !visibleIds.includes(id));
            } else {
                visibleIds.forEach((id) => {
                    if (!this.selectedIds.includes(id)) this.selectedIds.push(id);
                });
            }
            this.updateSelectAllState();
        },
        toggleItem(id) {
            const index = this.selectedIds.indexOf(id);
            if (index >= 0) this.selectedIds.splice(index, 1);
            else this.selectedIds.push(id);
            this.updateSelectAllState();
        },
        clearSelection() {
            this.selectedIds = [];
            this.selectAll = false;
        },
        changePerPage(value) {
            this.clearSelection();
            this.$emit('per-page-change', Number(value));
        },
        changePage(value) {
            this.clearSelection();
            this.$emit('page-change', Number(value));
        },
        toggleSort(column) {
            if (!this.sortableColumns.includes(column)) return;
            const nextDirection = this.sortBy === column
                ? (this.sortDir === 'asc' ? 'desc' : 'asc')
                : (this.columnDefaultDir[column] || 'asc');
            this.clearSelection();
            this.$emit('sort-change', { sortBy: column, sortDir: nextDirection });
        },
        sortIcon(column) {
            if (this.sortBy !== column) return '↕';
            return this.sortDir === 'asc' ? '↑' : '↓';
        },
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
            if (item.source_display_status === 'missing') return 'error--text';
            if (item.source_display_status === 'card_example_only') return 'warning--text text--darken-1';
            return '';
        },
        formatDueAt(isoString) {
            if (!isoString) return '—';
            return new Date(isoString).toLocaleString('zh-CN', { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
        },
        formatLastReviewed(isoString) {
            if (!isoString) return '—';
            return new Date(isoString).toLocaleString('zh-CN', { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
        },
        formatFsrsNumber(value) {
            if (value === null || value === undefined || value === '') return '—';
            const number = Number(value);
            return Number.isNaN(number) ? '—' : number.toFixed(2);
        },
        isColumnVisible(key) {
            if (this.pinnedColumnKeys.includes(key)) return true;
            if (Object.prototype.hasOwnProperty.call(this.columnSettings, key)) return this.columnSettings[key];
            return Object.prototype.hasOwnProperty.call(this.columnDefaults, key) ? this.columnDefaults[key] : true;
        },
        loadColumnSettings() {
            try {
                const saved = DefaultLocalStorageManager.loadSetting('reviewCardManageColumnSettings');
                this.columnSettings = saved && typeof saved === 'object'
                    ? { ...this.columnDefaults, ...saved }
                    : { ...this.columnDefaults };
            } catch (error) {
                this.columnSettings = { ...this.columnDefaults };
            }
            this.ensureVisibleSortColumn();
        },
        saveColumnSettings() {
            try {
                DefaultLocalStorageManager.saveSetting('reviewCardManageColumnSettings', this.columnSettings);
            } catch (error) {
                // Local preferences are optional; keep the page usable when storage is unavailable.
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
            this.compactMode = false;
            this.saveColumnSettings();
            this.saveCompactMode();
            this.ensureVisibleSortColumn();
        },
        showAllColumns() {
            Object.keys(this.columnDefaults).forEach(key => this.$set(this.columnSettings, key, true));
            this.saveColumnSettings();
        },
        ensureVisibleSortColumn() {
            if (!this.isColumnVisible(this.sortBy)) {
                this.$emit('sort-change', { sortBy: 'id', sortDir: 'desc' });
            }
        },
        loadCompactMode() {
            try {
                this.compactMode = DefaultLocalStorageManager.loadSetting('reviewCardManageCompactMode') === true;
            } catch (error) {
                this.compactMode = false;
            }
        },
        saveCompactMode() {
            try {
                DefaultLocalStorageManager.saveSetting('reviewCardManageCompactMode', this.compactMode);
            } catch (error) {
                // Local preferences are optional; keep the page usable when storage is unavailable.
            }
        },
        initExportFields() {
            this.exportFields = this.exportFieldOptions.map(option => option.key);
        },
        selectAllExportFields() {
            this.exportFields = this.exportFieldOptions.map(option => option.key);
        },
        resetExportFields() {
            this.initExportFields();
        },
        exportCurrentFilter() {
            if (this.exportFields.length === 0) {
                this.$emit('notify', '请至少选择一个导出字段。', 'error');
                return;
            }
            this.exportLoading = true;
            axios.get('/review-cards/manage/export', {
                params: { ...this.filterState, fields: this.exportFields },
                responseType: 'blob',
            }).then(response => this.downloadBlob(response, 'review-cards-export.json', '已导出当前筛选结果。'))
                .catch(error => this.handleExportError(error))
                .finally(() => { this.exportLoading = false; });
        },
        exportAnkiTsv() {
            this.ankiExportLoading = true;
            axios.get('/review-cards/manage/export-anki-tsv', {
                params: { ...this.filterState },
                responseType: 'blob',
            }).then(response => this.downloadBlob(response, 'review-cards-anki.tsv', '已导出 Anki TSV。'))
                .catch(error => this.handleExportError(error))
                .finally(() => { this.ankiExportLoading = false; });
        },
        exportCsv() {
            if (this.exportFields.length === 0) {
                this.$emit('notify', '请至少选择一个导出字段。', 'error');
                return;
            }
            this.csvExportLoading = true;
            axios.get('/review-cards/manage/export-csv', {
                params: { ...this.filterState, fields: this.exportFields },
                responseType: 'blob',
            }).then(response => this.downloadBlob(response, 'review-cards.csv', '已导出 CSV。'))
                .catch(error => this.handleExportError(error))
                .finally(() => { this.csvExportLoading = false; });
        },
        downloadBlob(response, fallbackName, successMessage) {
            const url = window.URL.createObjectURL(new Blob([response.data]));
            const link = document.createElement('a');
            const disposition = response.headers['content-disposition'] || '';
            const match = disposition.match(/filename="?(.+?)"?$/);
            link.href = url;
            link.setAttribute('download', match ? match[1] : fallbackName);
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.URL.revokeObjectURL(url);
            this.$emit('notify', successMessage, 'success');
        },
        handleExportError(error) {
            if (error.response && error.response.status === 422 && error.response.data && typeof error.response.data.text === 'function') {
                error.response.data.text().then((text) => {
                    try {
                        const parsed = JSON.parse(text);
                        this.$emit('notify', parsed.message || '导出失败。', 'error');
                    } catch (parseError) {
                        this.$emit('notify', '导出失败：结果超过上限。', 'error');
                    }
                });
                return;
            }
            this.$emit('notify', '导出失败：' + (error.response?.data?.message || error.message || '未知错误'), 'error');
        },
    },
};
</script>

<style scoped>
.review-card-table-surface,
.manage-table-card,
.table-wrapper {
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

.manage-table-card { overflow: hidden; }
.table-wrapper { overflow-x: auto; width: 100%; }
.manage-table { width: 100%; border-collapse: collapse; table-layout: auto; }
.manage-table thead { background: #fafafa; }
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
.manage-table .selected-row { background: #e3f2fd; }
.manage-table .current-row td {
    box-shadow: inset 0 1px 0 #1976d2, inset 0 -1px 0 #1976d2;
}
.manage-table .current-row td:first-child { box-shadow: inset 1px 0 0 #1976d2, inset 0 1px 0 #1976d2, inset 0 -1px 0 #1976d2; }
.manage-table .current-row td:last-child { box-shadow: inset -1px 0 0 #1976d2, inset 0 1px 0 #1976d2, inset 0 -1px 0 #1976d2; }
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
.col-actions {
    min-width: 160px;
    white-space: nowrap;
    position: sticky;
    right: 0;
    background: #fff;
    z-index: 1;
    box-shadow: -2px 0 4px rgba(0, 0, 0, 0.05);
}
.manage-table .selected-row .col-actions { background: #e3f2fd; }
.manage-table thead .col-actions { z-index: 2; }
.sortable { cursor: pointer; user-select: none; }
.sortable:hover { background: #e0e0e0; }
.sort-icon {
    display: inline-block;
    width: 14px;
    text-align: center;
    font-size: 0.7rem;
    color: #999;
    margin-left: 2px;
}
.sortable .sort-icon { color: #1976d2; }
.edit-field { min-width: 90px; font-size: 11px; }
.manage-table.table--compact th { padding: 6px 4px; font-size: 0.72rem; }
.manage-table.table--compact td { padding: 4px; font-size: 0.75rem; }
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
.manage-table.table--compact .col-actions { min-width: 145px; }
</style>
