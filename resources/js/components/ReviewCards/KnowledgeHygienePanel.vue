<template>
    <v-expansion-panels class="mb-3" flat>
        <v-expansion-panel>
            <v-expansion-panel-header>
                <div>
                    <strong>知识库整理</strong>
                    <span class="text-caption text--secondary ml-2">视图、批量替换、重复项与最近删除</span>
                </div>
            </v-expansion-panel-header>
            <v-expansion-panel-content>
                <v-alert v-if="error" type="error" dense dismissible @input="error = ''">{{ error }}</v-alert>

                <v-tabs v-model="tab" show-arrows>
                    <v-tab>视图</v-tab>
                    <v-tab>查找替换</v-tab>
                    <v-tab>重复项</v-tab>
                    <v-tab @click="loadRecentDeletes">最近删除</v-tab>
                </v-tabs>

                <v-tabs-items v-model="tab">
                    <v-tab-item>
                        <div class="pa-3">
                            <div class="text-subtitle-2 mb-2">当前显示列</div>
                            <div class="d-flex flex-wrap mb-3" style="gap: 4px 16px;">
                                <v-checkbox
                                    v-for="column in columnOptions"
                                    :key="column.key"
                                    v-model="draftColumns"
                                    :value="column.key"
                                    :label="column.label"
                                    :disabled="column.pinned"
                                    dense
                                    hide-details
                                    class="ma-0"
                                />
                            </div>
                            <div class="d-flex flex-wrap align-center" style="gap: 8px;">
                                <v-btn small color="primary" :loading="preferencesLoading" @click="saveColumns">
                                    保存列设置
                                </v-btn>
                                <v-text-field
                                    v-model.trim="viewName"
                                    label="保存当前筛选为视图"
                                    dense
                                    outlined
                                    hide-details
                                    maxlength="80"
                                    style="max-width: 260px;"
                                />
                                <v-btn small outlined :disabled="!viewName" :loading="preferencesLoading" @click="saveView">
                                    保存视图
                                </v-btn>
                            </div>
                            <v-list v-if="draftViews.length" dense class="mt-3">
                                <v-subheader>已保存视图</v-subheader>
                                <v-list-item v-for="(view, index) in draftViews" :key="view.name + index">
                                    <v-list-item-content>
                                        <v-list-item-title>{{ view.name }}</v-list-item-title>
                                    </v-list-item-content>
                                    <v-list-item-action class="d-flex flex-row">
                                        <v-btn x-small text color="primary" @click="applyView(view)">应用</v-btn>
                                        <v-btn x-small text color="error" @click="removeView(index)">删除</v-btn>
                                    </v-list-item-action>
                                </v-list-item>
                            </v-list>
                        </div>
                    </v-tab-item>

                    <v-tab-item>
                        <div class="pa-3">
                            <v-row dense>
                                <v-col cols="12" sm="3">
                                    <v-select v-model="replaceForm.field" :items="replaceFields" item-text="label" item-value="key" label="字段" dense outlined />
                                </v-col>
                                <v-col cols="12" sm="3">
                                    <v-text-field v-model="replaceForm.find" label="查找" dense outlined maxlength="200" />
                                </v-col>
                                <v-col cols="12" sm="3">
                                    <v-text-field v-model="replaceForm.replace" label="替换为（可留空）" dense outlined maxlength="500" />
                                </v-col>
                                <v-col cols="12" sm="3" class="d-flex align-start">
                                    <v-btn color="primary" outlined :loading="replaceLoading" @click="previewReplace">预览</v-btn>
                                </v-col>
                            </v-row>
                            <v-alert v-if="replacePreview" type="info" dense text>
                                当前查询范围内将修改 {{ replacePreview.affected }} 条；应用前会再次检查预览是否过期。
                            </v-alert>
                            <div v-if="replacePreview && replacePreview.rows.length" class="preview-table mb-3">
                                <table>
                                    <thead><tr><th>词条</th><th>修改前</th><th>修改后</th></tr></thead>
                                    <tbody>
                                        <tr v-for="row in replacePreview.rows" :key="row.word_sense_id">
                                            <td>{{ row.lemma }}</td><td>{{ row.before }}</td><td>{{ row.after }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <v-btn
                                v-if="replacePreview && replacePreview.affected"
                                color="primary"
                                :loading="replaceLoading"
                                @click="applyReplace"
                            >应用 {{ replacePreview.affected }} 条修改</v-btn>
                            <v-btn
                                v-if="lastOperationId"
                                text
                                color="warning"
                                :loading="undoLoading"
                                @click="undo(lastOperationId)"
                            >撤销上次整理操作</v-btn>
                        </div>
                    </v-tab-item>

                    <v-tab-item>
                        <div class="pa-3">
                            <div class="d-flex align-center mb-3" style="gap: 8px;">
                                <v-btn color="primary" outlined :loading="duplicatesLoading" @click="scanDuplicates">扫描当前查询</v-btn>
                                <span v-if="duplicatesScanned !== null" class="text-caption text--secondary">
                                    已扫描 {{ duplicatesScanned }} 张卡片
                                </span>
                            </div>
                            <v-alert v-if="duplicateItems.length === 0 && duplicatesScanned !== null" type="info" dense text>
                                当前查询中没有需要人工检查的同词同词性卡片。
                            </v-alert>
                            <v-card v-for="(pair, index) in duplicateItems" :key="index" outlined class="mb-2">
                                <v-card-text>
                                    <v-chip x-small label class="mb-2">{{ duplicateLabel(pair.classification) }}</v-chip>
                                    <div><strong>主卡候选 #{{ pair.primary_candidate.review_card_id }}</strong> {{ describeSense(pair.primary_candidate) }}</div>
                                    <div><strong>重复卡候选 #{{ pair.duplicate_candidate.review_card_id }}</strong> {{ describeSense(pair.duplicate_candidate) }}</div>
                                </v-card-text>
                                <v-card-actions>
                                    <v-btn small text color="primary" @click="previewMerge(pair)">检查合并影响</v-btn>
                                </v-card-actions>
                            </v-card>
                            <v-dialog v-model="mergeDialog" max-width="620">
                                <v-card>
                                    <v-card-title>确认词义合并</v-card-title>
                                    <v-card-text v-if="mergePreview">
                                        <p>主卡 #{{ mergePreview.primary.review_card_id }} 的 FSRS 调度会保留。</p>
                                        <p>将迁移 {{ mergePreview.impact.occurrences_rebound }} 条来源记录和 {{ mergePreview.impact.review_logs_rebound }} 条复习日志。</p>
                                        <v-alert type="warning" dense text>应用前会自动创建 M6 备份。合并不会自动判断语义，必须由你确认这两项确为重复词义。</v-alert>
                                        <v-checkbox v-model="mergeConfirmed" label="我已核对词义，并确认以主卡为准" />
                                    </v-card-text>
                                    <v-card-actions>
                                        <v-spacer />
                                        <v-btn text @click="mergeDialog = false">取消</v-btn>
                                        <v-btn color="primary" :disabled="!mergeConfirmed" :loading="mergeLoading" @click="applyMerge">备份并合并</v-btn>
                                    </v-card-actions>
                                </v-card>
                            </v-dialog>
                        </div>
                    </v-tab-item>

                    <v-tab-item>
                        <div class="pa-3">
                            <v-btn small text :loading="recentLoading" @click="loadRecentDeletes">刷新</v-btn>
                            <v-alert v-if="!recentLoading && recentDeletes.length === 0" type="info" dense text>最近 30 天没有可恢复的删除。</v-alert>
                            <v-list v-else dense>
                                <v-list-item v-for="item in recentDeletes" :key="item.operation_id">
                                    <v-list-item-content>
                                        <v-list-item-title>{{ item.lemma || '词义卡' }}</v-list-item-title>
                                        <v-list-item-subtitle>{{ item.deleted_at || item.created_at }} · 操作 {{ item.operation_id }}</v-list-item-subtitle>
                                    </v-list-item-content>
                                    <v-list-item-action>
                                        <v-btn
                                            small
                                            text
                                            color="primary"
                                            :disabled="!item.can_restore"
                                            :loading="undoLoading"
                                            @click="undo(item.operation_id)"
                                        >{{ item.can_restore ? '恢复' : '已恢复' }}</v-btn>
                                    </v-list-item-action>
                                </v-list-item>
                            </v-list>
                        </div>
                    </v-tab-item>
                </v-tabs-items>
            </v-expansion-panel-content>
        </v-expansion-panel>
    </v-expansion-panels>
</template>

<script>
import axios from 'axios';

export default {
    name: 'KnowledgeHygienePanel',
    props: {
        preferences: { type: Object, default: () => ({ columns: [], views: [] }) },
        filterState: { type: Object, required: true },
    },
    data() {
        return {
            tab: 0,
            error: '',
            preferencesLoading: false,
            draftColumns: [],
            draftViews: [],
            viewName: '',
            replaceLoading: false,
            replaceForm: { field: 'sense_zh', find: '', replace: '' },
            replacePreview: null,
            lastOperationId: null,
            undoLoading: false,
            duplicatesLoading: false,
            duplicatesScanned: null,
            duplicateItems: [],
            mergeDialog: false,
            mergeLoading: false,
            mergeConfirmed: false,
            mergePreview: null,
            recentLoading: false,
            recentDeletes: [],
            columnOptions: [
                { key: 'lemma', label: 'Lemma（固定）', pinned: true }, { key: 'surface_form', label: 'Surface' },
                { key: 'pos', label: 'POS' }, { key: 'sense_zh', label: '释义(中)' },
                { key: 'sense_en', label: '释义(英)' }, { key: 'example_sentence_en', label: '例句(英)' },
                { key: 'example_sentence_zh', label: '例句(中)' },
                { key: 'source_chapter_title', label: '溯源' }, { key: 'tags', label: '标签' },
                { key: 'marker', label: '标记（固定）', pinned: true }, { key: 'fsrs_state', label: 'FSRS 状态（固定）', pinned: true },
                { key: 'fsrs_due_at', label: '到期' }, { key: 'fsrs_stability', label: '稳定度' },
                { key: 'fsrs_difficulty', label: '难度' }, { key: 'fsrs_reps', label: '复习次数' },
                { key: 'fsrs_lapses', label: '遗忘次数' }, { key: 'lifecycle_state', label: '生命周期（固定）', pinned: true },
            ],
            replaceFields: [
                { key: 'sense_zh', label: '中文释义' }, { key: 'sense_en', label: '英文释义' },
                { key: 'example_sentence_zh', label: '中文例句' }, { key: 'example_sentence_en', label: '英文例句' },
            ],
        };
    },
    mounted() {
        this.loadPreferences();
    },
    watch: {
        preferences: {
            immediate: true,
            deep: true,
            handler(value) {
                this.draftColumns = Array.from(new Set([
                    ...(value.columns || []),
                    'lemma', 'sense_zh', 'marker', 'fsrs_state', 'lifecycle_state',
                ]));
                this.draftViews = (value.views || []).map(view => ({
                    ...view,
                    columns: [...(view.columns || [])],
                    filter_state: { ...(view.filter_state || {}) },
                }));
            },
        },
    },
    methods: {
        requestError(error, fallback) {
            const errors = error.response?.data?.errors;
            if (errors) return Object.values(errors).flat().join(' ');
            return error.response?.data?.message || fallback;
        },
        loadPreferences() {
            this.preferencesLoading = true;
            axios.get('/review-cards/knowledge-hygiene/preferences')
                .then((response) => {
                    this.$emit('preferences-updated', response.data);
                })
                .catch((error) => {
                    this.error = this.requestError(error, '列与视图偏好加载失败，当前页面仍可继续使用。');
                })
                .finally(() => {
                    this.preferencesLoading = false;
                });
        },
        persistColumns(columns) {
            if (!Array.isArray(columns) || columns.length === 0) return;
            this.draftColumns = [...columns];
            this.persistPreferences()
                .then(() => this.$emit('notify', '列设置已保存。', 'success'))
                .catch(() => {});
        },
        persistPreferences() {
            if (this.draftColumns.length === 0) {
                this.error = '至少保留一列。';
                return Promise.reject(new Error(this.error));
            }
            this.preferencesLoading = true;
            return axios.put('/review-cards/knowledge-hygiene/preferences', {
                columns: this.draftColumns,
                views: this.draftViews,
            }).then((response) => {
                this.$emit('preferences-updated', response.data);
                return response.data;
            }).catch((error) => {
                this.error = this.requestError(error, '保存偏好失败。');
                throw error;
            }).finally(() => {
                this.preferencesLoading = false;
            });
        },
        saveColumns() {
            this.persistPreferences().then(() => this.$emit('notify', '列设置已保存。', 'success')).catch(() => {});
        },
        saveView() {
            if (!this.viewName) return;
            const existing = this.draftViews.findIndex(view => view.name === this.viewName);
            const view = {
                name: this.viewName,
                filter_state: { ...this.filterState },
                columns: [...this.draftColumns],
            };
            if (existing >= 0) this.$set(this.draftViews, existing, view);
            else this.draftViews.push(view);
            this.persistPreferences().then(() => {
                this.viewName = '';
                this.$emit('notify', '视图已保存。', 'success');
            }).catch(() => {});
        },
        removeView(index) {
            this.draftViews.splice(index, 1);
            this.persistPreferences().catch(() => {});
        },
        applyView(view) {
            this.draftColumns = [...(view.columns || [])];
            this.$emit('apply-view', {
                filterState: { ...view.filter_state },
                columns: [...this.draftColumns],
            });
        },
        operationPayload(extra = {}) {
            return { ...this.filterState, ...extra };
        },
        previewReplace() {
            this.error = '';
            this.replaceLoading = true;
            axios.post('/review-cards/knowledge-hygiene/find-replace/preview', this.operationPayload(this.replaceForm))
                .then(response => { this.replacePreview = response.data; })
                .catch(error => { this.error = this.requestError(error, '预览失败。'); })
                .finally(() => { this.replaceLoading = false; });
        },
        applyReplace() {
            if (!this.replacePreview) return;
            this.replaceLoading = true;
            axios.post('/review-cards/knowledge-hygiene/find-replace/apply', this.operationPayload({
                ...this.replaceForm,
                preview_fingerprint: this.replacePreview.preview_fingerprint,
            })).then((response) => {
                this.lastOperationId = response.data.operation_id;
                this.replacePreview = null;
                this.$emit('refresh');
                this.$emit('notify', `已修改 ${response.data.affected} 条，可在本页撤销。`, 'success');
            }).catch(error => { this.error = this.requestError(error, '应用替换失败。'); })
                .finally(() => { this.replaceLoading = false; });
        },
        scanDuplicates() {
            this.duplicatesLoading = true;
            axios.post('/review-cards/knowledge-hygiene/duplicates', this.operationPayload())
                .then((response) => {
                    this.duplicateItems = response.data.items || [];
                    this.duplicatesScanned = response.data.scanned_cards;
                })
                .catch(error => { this.error = this.requestError(error, '重复项扫描失败。'); })
                .finally(() => { this.duplicatesLoading = false; });
        },
        previewMerge(pair) {
            this.mergeLoading = true;
            this.mergeConfirmed = false;
            axios.post('/review-cards/knowledge-hygiene/merge/preview', {
                primary_review_card_id: pair.primary_candidate.review_card_id,
                duplicate_review_card_id: pair.duplicate_candidate.review_card_id,
            }).then((response) => {
                this.mergePreview = response.data;
                this.mergeDialog = true;
            }).catch(error => { this.error = this.requestError(error, '合并预览失败。'); })
                .finally(() => { this.mergeLoading = false; });
        },
        applyMerge() {
            if (!this.mergePreview || !this.mergeConfirmed) return;
            this.mergeLoading = true;
            axios.post('/review-cards/knowledge-hygiene/merge/apply', {
                primary_review_card_id: this.mergePreview.primary.review_card_id,
                duplicate_review_card_id: this.mergePreview.duplicate.review_card_id,
                preview_fingerprint: this.mergePreview.preview_fingerprint,
                confirm: true,
            }).then((response) => {
                this.lastOperationId = response.data.operation_id;
                this.mergeDialog = false;
                this.mergePreview = null;
                this.scanDuplicates();
                this.$emit('refresh');
                this.$emit('notify', `已备份并合并；备份编号 ${response.data.backup_id}。`, 'success');
            }).catch(error => { this.error = this.requestError(error, '合并失败。'); })
                .finally(() => { this.mergeLoading = false; });
        },
        loadRecentDeletes() {
            this.recentLoading = true;
            axios.get('/review-cards/knowledge-hygiene/recent-deletes')
                .then(response => { this.recentDeletes = response.data.items || []; })
                .catch(error => { this.error = this.requestError(error, '最近删除加载失败。'); })
                .finally(() => { this.recentLoading = false; });
        },
        undo(operationId) {
            this.undoLoading = true;
            axios.post(`/review-cards/knowledge-hygiene/operations/${operationId}/undo`)
                .then(() => {
                    if (this.lastOperationId === operationId) this.lastOperationId = null;
                    this.loadRecentDeletes();
                    this.$emit('refresh');
                    this.$emit('notify', '整理操作已撤销。', 'success');
                })
                .catch(error => { this.error = this.requestError(error, '撤销失败。'); })
                .finally(() => { this.undoLoading = false; });
        },
        duplicateLabel(value) {
            return {
                exact_duplicate: '完全重复',
                needs_distinction: '需要补充区分',
                possible_merge: '可能可合并',
                keep_separate: '建议分别保留',
            }[value] || value;
        },
        describeSense(sense) {
            return `${sense.lemma || ''} · ${sense.pos || '—'} · ${sense.sense_zh || sense.sense_en || '无释义'}`;
        },
    },
};
</script>

<style scoped>
.preview-table {
    max-height: 320px;
    overflow: auto;
    border: 1px solid rgba(0, 0, 0, 0.12);
}
.preview-table table {
    width: 100%;
    border-collapse: collapse;
}
.preview-table th,
.preview-table td {
    padding: 8px;
    text-align: left;
    border-bottom: 1px solid rgba(0, 0, 0, 0.08);
    vertical-align: top;
}
</style>
