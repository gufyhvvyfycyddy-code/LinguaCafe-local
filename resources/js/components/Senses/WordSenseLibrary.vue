<template>
    <div class="word-sense-library">
        <div class="mb-5">
            <h1 class="text-h5 font-weight-bold mb-2">生词</h1>
            <div class="body-2 text--secondary">
                这里是你已经保存并确认的词义。同一个词可以有多个词义。
            </div>
            <div v-if="state === 'success'" class="caption text--secondary mt-2">
                共 {{ pagination.total }} 个词义
            </div>
        </div>

        <v-alert dense text type="info" class="mb-5 word-sense-history-note">
            旧版按单词保存的复习历史会继续保留为只读历史；当前正式复习以词义为单位。
        </v-alert>

        <div class="word-sense-search mb-5">
            <v-text-field
                v-model="queryInput"
                outlined
                hide-details
                label="搜索单词、词性或释义"
                placeholder="搜索单词、词性或释义"
                @keyup.enter="submitSearch"
            ></v-text-field>
            <v-btn color="primary" depressed @click="submitSearch">搜索</v-btn>
        </div>

        <div v-if="state === 'loading'" class="py-2">
            <v-skeleton-loader type="list-item-three-line"></v-skeleton-loader>
            <div class="body-2 text--secondary mt-3">正在加载生词…</div>
        </div>

        <v-alert v-else-if="state === 'error'" type="error" outlined>
            生词加载失败，请重试。
            <div class="mt-3">
                <v-btn small outlined @click="retry">重试</v-btn>
            </div>
        </v-alert>

        <template v-else>
            <div v-if="pagination.total === 0 && appliedQuery" class="word-sense-state py-6">
                <div class="body-1 mb-3">没有找到匹配的生词。</div>
                <v-btn small outlined @click="clearSearch">清除搜索</v-btn>
            </div>

            <div v-else-if="pagination.total === 0" class="word-sense-state py-6 body-1">
                还没有保存的生词。你在阅读中保存并确认的词义会出现在这里。
            </div>

            <template v-else>
                <v-card
                    v-for="item in items"
                    :key="item.sense_id"
                    outlined
                    class="word-sense-item rounded-lg pa-4 mb-3"
                >
                    <div class="word-sense-heading">
                        <div class="text-h6 font-weight-bold word-sense-text">{{ item.lemma }}</div>
                        <v-chip small class="word-sense-pos">{{ displayPos(item.pos) }}</v-chip>
                    </div>

                    <div class="body-1 mt-3 word-sense-text">{{ item.sense_zh }}</div>
                    <div v-if="item.sense_en" class="body-2 text--secondary mt-2 word-sense-text">
                        {{ item.sense_en }}
                    </div>

                    <div class="mt-3">
                        <v-btn
                            small
                            text
                            color="primary"
                            :aria-expanded="expandedSenseId === item.sense_id ? 'true' : 'false'"
                            @click="toggleDetails(item.sense_id)"
                        >
                            {{ expandedSenseId === item.sense_id ? '收起' : '查看' }}
                        </v-btn>
                    </div>

                    <div
                        v-if="expandedSenseId === item.sense_id"
                        class="word-sense-details mt-3 pa-3"
                    >
                        <div class="word-sense-text"><strong>{{ item.lemma }}</strong></div>
                        <div class="caption text--secondary mt-1">{{ displayPos(item.pos) }}</div>
                        <div class="body-2 mt-2 word-sense-text">{{ item.sense_zh }}</div>
                        <div v-if="item.sense_en" class="body-2 text--secondary mt-2 word-sense-text">
                            {{ item.sense_en }}
                        </div>

                        <div v-if="item.aliases_zh && item.aliases_zh.length" class="word-sense-list-field mt-3">
                            <span class="body-2 text--secondary mr-2">近义译法：</span>
                            <v-chip
                                v-for="(alias, aliasIndex) in item.aliases_zh"
                                :key="`alias-${item.sense_id}-${aliasIndex}`"
                                x-small
                                class="mr-1 mb-1"
                            >
                                {{ alias }}
                            </v-chip>
                        </div>

                        <div v-if="item.collocations && item.collocations.length" class="word-sense-list-field mt-2">
                            <span class="body-2 text--secondary mr-2">搭配：</span>
                            <v-chip
                                v-for="(collocation, collocationIndex) in item.collocations"
                                :key="`collocation-${item.sense_id}-${collocationIndex}`"
                                x-small
                                outlined
                                class="mr-1 mb-1"
                            >
                                {{ collocation }}
                            </v-chip>
                        </div>

                        <div class="word-sense-detail-actions mt-3">
                            <v-btn
                                v-if="editingSenseId !== item.sense_id"
                                small
                                outlined
                                color="primary"
                                @click="startEdit(item)"
                            >
                                编辑词义
                            </v-btn>
                            <v-btn
                                small
                                text
                                color="primary"
                                :loading="sourceOverview.senseId === item.sense_id && sourceOverview.status === 'loading'"
                                @click="loadSources(item.sense_id)"
                            >
                                查看来源
                            </v-btn>
                        </div>

                        <manual-sense-form
                            v-if="editingSenseId === item.sense_id"
                            :value="editForm"
                            mode="edit"
                            :pos-options="posOptions"
                            :saving="saving"
                            :field-errors="editValidation.fieldErrors"
                            :general-error="editValidation.generalError"
                            @submit="onEditFormSubmit($event, item)"
                            @clear-error="clearValidationField($event)"
                            @cancel="cancelEdit"
                        />

                        <div
                            v-if="sourceOverview.senseId === item.sense_id && sourceOverview.status === 'error'"
                            class="body-2 text--secondary mt-3"
                        >
                            来源加载失败，请稍后再试。
                        </div>

                        <div
                            v-else-if="sourceOverview.senseId === item.sense_id && sourceOverview.status === 'success'"
                            class="word-sense-sources mt-3"
                        >
                            <template v-if="sourceOverview.sources.length">
                                <div
                                    v-for="(source, sourceIndex) in sourceOverview.sources"
                                    :key="`source-${item.sense_id}-${sourceIndex}`"
                                    class="word-sense-source-item rounded pa-2 mb-2"
                                >
                                    <div v-if="source.chapter_title" class="body-2 font-weight-medium word-sense-text">
                                        {{ source.chapter_title }}
                                    </div>
                                    <div class="caption text--secondary mt-1">
                                        {{ sourceKindLabel(source.source_kind) }}
                                    </div>
                                    <div v-if="source.fallback_message" class="body-2 mt-1 word-sense-text">
                                        {{ source.fallback_message }}
                                    </div>
                                    <div v-else-if="!source.source_available" class="body-2 mt-1 text--secondary">
                                        暂无可用原文位置
                                    </div>
                                </div>
                            </template>
                            <div v-else class="body-2 text--secondary">暂无可用原文位置</div>
                        </div>
                    </div>
                </v-card>

                <div v-if="pagination.last_page > 1" class="word-sense-pagination mt-5">
                    <v-btn
                        small
                        outlined
                        :disabled="pagination.current_page <= 1"
                        @click="changePage(pagination.current_page - 1)"
                    >
                        上一页
                    </v-btn>
                    <span class="caption text--secondary">
                        第 {{ pagination.current_page }} / {{ pagination.last_page }} 页
                    </span>
                    <v-btn
                        small
                        outlined
                        :disabled="pagination.current_page >= pagination.last_page"
                        @click="changePage(pagination.current_page + 1)"
                    >
                        下一页
                    </v-btn>
                </div>
            </template>
        </template>
    </div>
</template>

<script>
    import ManualSenseForm from '../Text/ManualSenseForm.vue';
    import {
        manualSenseValidationState,
        normalizeWordSensePos,
        validateManualSenseForm,
    } from '../../services/ManualWordSenseFormService';

    const POS_OPTIONS = [
        { value: 'noun', label: '名词 noun' },
        { value: 'verb', label: '动词 verb' },
        { value: 'adjective', label: '形容词 adjective' },
        { value: 'adverb', label: '副词 adverb' },
        { value: 'preposition', label: '介词 preposition' },
        { value: 'conjunction', label: '连词 conjunction' },
        { value: 'phrase', label: '短语 phrase' },
        { value: 'other', label: '其他 other' },
    ];

    export default {
        components: {
            ManualSenseForm,
        },
        data: function() {
            return {
                state: 'loading',
                items: [],
                pagination: {
                    current_page: 1,
                    last_page: 1,
                    per_page: 20,
                    total: 0,
                },
                queryInput: '',
                appliedQuery: '',
                expandedSenseId: null,
                requestSequence: 0,
                posOptions: POS_OPTIONS,
                editingSenseId: null,
                editForm: this.emptyForm(),
                saving: false,
                editValidation: this.emptyValidationState(),
                sourceOverview: {
                    senseId: null,
                    status: 'unloaded',
                    sources: [],
                },
            };
        },
        mounted() {
            this.loadPage(1);
        },
        methods: {
            emptyForm() {
                return {
                    pos: 'other',
                    sense_zh: '',
                    sense_en: '',
                    aliases_zh: '',
                    collocations: '',
                };
            },
            emptyValidationState() {
                return {
                    fieldErrors: {
                        pos: '',
                        sense_zh: '',
                    },
                    generalError: '',
                };
            },
            hasValidationErrors(validation) {
                return Boolean(validation.fieldErrors.pos || validation.fieldErrors.sense_zh);
            },
            clearValidationField(field) {
                this.editValidation = {
                    ...this.editValidation,
                    generalError: '',
                    fieldErrors: {
                        ...this.editValidation.fieldErrors,
                        [field]: '',
                    },
                };
            },
            loadPage(page) {
                const requestSequence = ++this.requestSequence;
                const params = {
                    page: page,
                    per_page: 20,
                };

                if (this.appliedQuery) {
                    params.q = this.appliedQuery;
                }

                this.pagination.current_page = page;
                this.state = 'loading';

                axios.get('/word-senses/data', { params: params }).then((response) => {
                    if (requestSequence !== this.requestSequence) {
                        return;
                    }

                    this.items = response.data.data;
                    this.pagination = response.data.pagination;
                    this.state = 'success';
                }).catch(() => {
                    if (requestSequence !== this.requestSequence) {
                        return;
                    }

                    this.state = 'error';
                });
            },
            submitSearch() {
                this.appliedQuery = this.queryInput.trim();
                this.expandedSenseId = null;
                this.cancelEdit();
                this.loadPage(1);
            },
            clearSearch() {
                this.queryInput = '';
                this.appliedQuery = '';
                this.expandedSenseId = null;
                this.cancelEdit();
                this.loadPage(1);
            },
            retry() {
                this.loadPage(this.pagination.current_page || 1);
            },
            changePage(page) {
                if (page < 1 || page > this.pagination.last_page || page === this.pagination.current_page) {
                    return;
                }

                this.expandedSenseId = null;
                this.cancelEdit();
                this.loadPage(page);
            },
            toggleDetails(senseId) {
                if (this.expandedSenseId === senseId) {
                    this.expandedSenseId = null;
                    if (this.editingSenseId === senseId) {
                        this.cancelEdit();
                    }
                    return;
                }

                this.expandedSenseId = senseId;
            },
            displayPos(pos) {
                return pos && pos.trim() ? pos : '未标注';
            },
            listValue(value) {
                return Array.isArray(value) ? value.join(', ') : (value || '');
            },
            splitList(value) {
                return (value || '')
                    .split(',')
                    .map(item => item.trim())
                    .filter(item => item !== '');
            },
            startEdit(item) {
                this.editingSenseId = item.sense_id;
                this.editForm = {
                    pos: normalizeWordSensePos(item.pos) || 'other',
                    sense_zh: item.sense_zh || '',
                    sense_en: item.sense_en || '',
                    aliases_zh: this.listValue(item.aliases_zh),
                    collocations: this.listValue(item.collocations),
                };
                this.editValidation = this.emptyValidationState();
            },
            cancelEdit() {
                this.editingSenseId = null;
                this.editForm = this.emptyForm();
                this.editValidation = this.emptyValidationState();
            },
            onEditFormSubmit(formData, item) {
                this.editForm = {
                    ...this.editForm,
                    ...formData,
                };
                this.saveEdit(item);
            },
            saveEdit(item) {
                this.editValidation = validateManualSenseForm(this.editForm);
                if (this.hasValidationErrors(this.editValidation)) {
                    return;
                }

                this.saving = true;
                axios.put(`/senses/${item.sense_id}/manual`, {
                    pos: normalizeWordSensePos(this.editForm.pos) || this.editForm.pos,
                    sense_zh: this.editForm.sense_zh,
                    sense_en: this.editForm.sense_en,
                    aliases_zh: this.splitList(this.editForm.aliases_zh),
                    collocations: this.splitList(this.editForm.collocations),
                }).then(() => {
                    const currentPage = this.pagination.current_page || 1;
                    this.cancelEdit();
                    this.loadPage(currentPage);
                }).catch((error) => {
                    this.editValidation = manualSenseValidationState(error, '更新词义失败，请稍后重试。');
                }).finally(() => {
                    this.saving = false;
                });
            },
            loadSources(senseId) {
                if (this.sourceOverview.senseId === senseId && this.sourceOverview.status === 'loading') {
                    return;
                }

                this.sourceOverview = {
                    senseId: senseId,
                    status: 'loading',
                    sources: [],
                };

                axios.get(`/senses/${senseId}/source-context-list`, {
                    params: { read_only: 1 },
                }).then((response) => {
                    if (!response || !response.data || !Array.isArray(response.data.sources)) {
                        this.sourceOverview = {
                            senseId: senseId,
                            status: 'error',
                            sources: [],
                        };
                        return;
                    }

                    this.sourceOverview = {
                        senseId: senseId,
                        status: 'success',
                        sources: response.data.sources,
                    };
                }).catch(() => {
                    this.sourceOverview = {
                        senseId: senseId,
                        status: 'error',
                        sources: [],
                    };
                });
            },
            sourceKindLabel(sourceKind) {
                const labels = {
                    chapter: '阅读原文',
                    chapter_recovered: '已定位的阅读原文',
                    chapter_title: '章节标题',
                    chapter_fuzzy: '相近的阅读原文',
                    chapter_fuzzy_title: '相近的章节标题',
                    card_example: '保存的例句',
                };

                return labels[sourceKind] || '来源记录';
            },
        },
    };
</script>

<style scoped>
    .word-sense-library {
        width: 100%;
        max-width: 100%;
        overflow-x: hidden;
    }

    .word-sense-search {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        max-width: 720px;
    }

    .word-sense-search .v-input {
        flex: 1 1 auto;
        width: 100%;
        min-width: 0;
    }

    .word-sense-item,
    .word-sense-details,
    .word-sense-text,
    .word-sense-history-note,
    .word-sense-source-item {
        min-width: 0;
        max-width: 100%;
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    .word-sense-heading {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px;
        min-width: 0;
    }

    .word-sense-pos {
        flex: 0 0 auto;
    }

    .word-sense-details {
        border: 1px solid rgba(0, 0, 0, 0.12);
        border-radius: 8px;
    }

    .word-sense-detail-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .word-sense-source-item {
        border: 1px solid rgba(0, 0, 0, 0.12);
    }

    .word-sense-pagination {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: center;
        gap: 12px;
    }

    @media (max-width: 430px) {
        .word-sense-search {
            flex-direction: column;
        }

        .word-sense-search .v-btn {
            width: 100%;
            min-height: 44px;
        }

        .word-sense-detail-actions {
            flex-direction: column;
        }

        .word-sense-detail-actions .v-btn {
            width: 100%;
            min-height: 44px;
            margin-left: 0 !important;
        }

        .word-sense-item {
            padding: 12px !important;
        }
    }
</style>
