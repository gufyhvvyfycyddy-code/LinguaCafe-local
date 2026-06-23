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
        </v-row>

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
                <table class="manage-table">
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
                            <th class="col-id">ID</th>
                            <th class="col-lemma">Lemma</th>
                            <th class="col-surface">Surface</th>
                            <th class="col-pos">POS</th>
                            <th class="col-def">释义(中)</th>
                            <th class="col-def">释义(英)</th>
                            <th class="col-example">例句(英)</th>
                            <th class="col-example">例句(中)</th>
                            <th class="col-source">溯源</th>
                            <th class="col-status">状态</th>
                            <th class="col-due">到期</th>
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
                            <td class="col-id">{{ item.review_card_id }}</td>
                            <td class="col-lemma">{{ item.lemma }}</td>
                            <td class="col-surface">{{ item.surface_form }}</td>
                            <td class="col-pos">
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
                            <td class="col-def" :class="{ 'text--secondary': item.missing_definition }">
                                <template v-if="editingId === item.review_card_id">
                                    <v-text-field v-model="editForm.sense_en" dense hide-details class="edit-field" />
                                </template>
                                <template v-else>{{ item.sense_en || '—' }}</template>
                            </td>
                            <td class="col-example" :class="{ 'text--secondary': item.missing_example }">
                                <template v-if="editingId === item.review_card_id">
                                    <v-textarea v-model="editForm.example_sentence_en" dense hide-details rows="2" class="edit-field" />
                                </template>
                                <template v-else>{{ item.example_sentence_en || '—' }}</template>
                            </td>
                            <td class="col-example">
                                <template v-if="editingId === item.review_card_id">
                                    <v-text-field v-model="editForm.example_sentence_zh" dense hide-details class="edit-field" />
                                </template>
                                <template v-else>{{ item.example_sentence_zh || '—' }}</template>
                            </td>
                            <td class="col-source" :class="{ 'text--secondary': item.missing_source }">
                                {{ item.source_chapter_title || sourceKindLabel(item.source_kind) }}
                            </td>
                            <td class="col-status">
                                <v-chip x-small :color="item.fsrs_enabled ? 'success' : 'grey'">
                                    {{ item.fsrs_enabled ? '未归档' : '已归档' }}
                                </v-chip>
                                <span class="text-caption d-block">{{ item.fsrs_state }}</span>
                            </td>
                            <td class="col-due">
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
                                    <v-btn v-if="item.fsrs_enabled" x-small text color="warning" @click="confirmArchive(item)">归档</v-btn>
                                    <v-btn v-else x-small text color="success" @click="toggleEnabled(item)">恢复</v-btn>
                                    <v-btn v-if="item.fsrs_enabled" x-small text @click="setDueNow(item)">立即到期</v-btn>
                                    <v-btn x-small text color="info" @click="viewSource(item)">查看原文</v-btn>
                                    <v-btn x-small text color="error" @click="confirmDelete(item)">彻底删除</v-btn>
                                </template>
                            </td>
                        </tr>
                        <tr v-if="!loading && items.length === 0">
                            <td colspan="13" class="text-center py-4 text--secondary">暂无词义复习卡。</td>
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
            editingId: null,
            savingId: null,
            editForm: {},
            sourceDialog: false,
            sourcePayload: {},
            archiveDialog: false,
            archiveTarget: null,
            deleteDialog: false,
            deleteTarget: null,
            bulkDeleteDialog: false,
            selectedIds: [],
            selectAll: false,
            snackbar: { show: false, text: '', color: 'success' },
        };
    },
    computed: {
        selectIndeterminate() {
            if (this.selectedIds.length === 0) return false;
            if (this.items.length === 0) return false;
            return this.selectedIds.length < this.items.length;
        },
    },
    mounted() {
        this.loadData();
    },
    methods: {
        loadData(allowPageFallback = true) {
            this.loading = true;
            this.error = '';

            axios.get('/review-cards/manage/data', {
                params: {
                    q: this.searchQuery,
                    filter: this.currentFilter,
                    page: this.currentPage,
                    per_page: this.perPage,
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
            })
            .catch((err) => {
                this.error = '操作失败：' + (err.response?.data?.message || err.message);
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
                })
                .catch((err) => {
                    this.error = '批量删除失败：' + (err.response?.data?.message || err.message);
                });
        },

        bulkArchive() {
            if (this.selectedIds.length === 0) return;
            const ids = [...this.selectedIds];

            axios.post('/review-cards/manage/bulk-enabled', { ids, enabled: false })
                .then((response) => {
                    this.clearSelection();
                    this.showSnackbar(response.data.message || '已批量归档。', 'warning');
                    this.loadData();
                })
                .catch((err) => {
                    this.error = '批量归档失败：' + (err.response?.data?.message || err.message);
                });
        },

        bulkRestore() {
            if (this.selectedIds.length === 0) return;
            const ids = [...this.selectedIds];

            axios.post('/review-cards/manage/bulk-enabled', { ids, enabled: true })
                .then((response) => {
                    this.clearSelection();
                    this.showSnackbar(response.data.message || '已批量恢复。', 'success');
                    this.loadData();
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
    min-width: 1200px;
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

/* Sticky operations column */
.col-actions {
    min-width: 220px;
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

.edit-field {
    min-width: 90px;
    font-size: 11px;
}

.v-btn-toggle.flex-wrap {
    flex-wrap: wrap;
}
</style>
