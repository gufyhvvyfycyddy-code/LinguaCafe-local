<template>
    <v-container fluid class="review-card-manage pa-4">
        <h2 class="mb-2">复习卡管理</h2>
        <p class="text--secondary mb-4">只显示词义复习卡。旧单词卡不会进入这里。</p>

        <!-- Filter bar -->
        <v-row class="mb-4" dense align="center">
            <v-col cols="12" sm="4" md="3">
                <v-text-field
                    v-model="searchQuery"
                    label="搜索"
                    prepend-inner-icon="mdi-magnify"
                    clearable
                    dense
                    hide-details
                    @keyup.enter="search"
                    @click:clear="search"
                />
            </v-col>
            <v-col cols="12" sm="8" md="5">
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

        <!-- Loading -->
        <v-progress-linear v-if="loading" indeterminate class="mb-3" />

        <!-- Error -->
        <v-alert v-if="error" type="error" dense class="mb-3">{{ error }}</v-alert>

        <!-- Cards table -->
        <v-card>
            <v-simple-table dense>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Lemma</th>
                        <th>Surface</th>
                        <th>POS</th>
                        <th>释义(中)</th>
                        <th>释义(英)</th>
                        <th>例句(英)</th>
                        <th>例句(中)</th>
                        <th>溯源</th>
                        <th>状态</th>
                        <th>到期</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in items" :key="item.review_card_id">
                        <td>{{ item.review_card_id }}</td>
                        <td>{{ item.lemma }}</td>
                        <td>{{ item.surface_form }}</td>
                        <td>
                            <template v-if="editingId === item.review_card_id">
                                <v-text-field v-model="editForm.pos" dense hide-details class="edit-field" />
                            </template>
                            <template v-else>{{ item.pos }}</template>
                        </td>
                        <td :class="{ 'text--secondary': item.missing_definition }">
                            <template v-if="editingId === item.review_card_id">
                                <v-text-field v-model="editForm.sense_zh" dense hide-details class="edit-field" />
                            </template>
                            <template v-else>{{ item.sense_zh || '—' }}</template>
                        </td>
                        <td :class="{ 'text--secondary': item.missing_definition }">
                            <template v-if="editingId === item.review_card_id">
                                <v-text-field v-model="editForm.sense_en" dense hide-details class="edit-field" />
                            </template>
                            <template v-else>{{ item.sense_en || '—' }}</template>
                        </td>
                        <td :class="{ 'text--secondary': item.missing_example }">
                            <template v-if="editingId === item.review_card_id">
                                <v-textarea v-model="editForm.example_sentence_en" dense hide-details rows="2" class="edit-field" />
                            </template>
                            <template v-else>{{ item.example_sentence_en || '—' }}</template>
                        </td>
                        <td>
                            <template v-if="editingId === item.review_card_id">
                                <v-text-field v-model="editForm.example_sentence_zh" dense hide-details class="edit-field" />
                            </template>
                            <template v-else>{{ item.example_sentence_zh || '—' }}</template>
                        </td>
                        <td :class="{ 'text--secondary': item.missing_source }">
                            {{ item.source_chapter_title || sourceKindLabel(item.source_kind) }}
                        </td>
                        <td>
                            <v-chip x-small :color="item.fsrs_enabled ? 'success' : 'grey'">
                                {{ item.fsrs_enabled ? '未归档' : '已归档' }}
                            </v-chip>
                            <span class="text-caption d-block">{{ item.fsrs_state }}</span>
                        </td>
                        <td>
                            <span class="text-caption">{{ formatDueAt(item.fsrs_due_at) }}</span>
                        </td>
                        <td>
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
                            </template>
                        </td>
                    </tr>
                    <tr v-if="!loading && items.length === 0">
                        <td colspan="12" class="text-center py-4 text--secondary">暂无词义复习卡。</td>
                    </tr>
                </tbody>
            </v-simple-table>
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
            snackbar: { show: false, text: '', color: 'success' },
        };
    },
    mounted() {
        this.loadData();
    },
    methods: {
        loadData() {
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

        search() {
            this.currentPage = 1;
            this.loadData();
        },

        applyFilter(filter) {
            this.activeFilter = filter;
            this.currentFilter = filter;
            this.currentPage = 1;
            this.loadData();
        },

        changePerPage() {
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
.review-card-manage .edit-field {
    min-width: 100px;
    font-size: 12px;
}
.review-card-manage .v-btn-toggle.flex-wrap {
    flex-wrap: wrap;
}
.review-card-manage table td {
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    vertical-align: middle;
}
</style>
