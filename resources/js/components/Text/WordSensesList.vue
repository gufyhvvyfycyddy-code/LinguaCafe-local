<template>
    <div class="word-senses-section mt-4">
        <div class="sense-section-header d-flex align-center mb-1">
            <div>
                <div class="vocab-box-subheader d-flex mb-0">词元释义</div>
                <div class="text-caption text--secondary">
                    以下释义都属于词元 <strong>{{ effectiveLemma || '未识别' }}</strong>，可按词性分组管理。
                </div>
            </div>
            <v-spacer />
            <v-btn small rounded depressed color="primary" @click="openAddForm()" :disabled="!effectiveLemma">
                + 添加新释义
            </v-btn>
        </div>

        <div class="lemma-surface-card rounded pa-2 mb-3">
            <div class="text-caption text--secondary">当前词形</div>
            <div class="d-flex align-center">
                <strong class="default-font mr-2">{{ surfaceWord || '未选择' }}</strong>
                <span class="text--secondary mr-1">词元：</span>
                <strong class="default-font">{{ effectiveLemma || '未识别' }}</strong>
            </div>
            <div v-if="legacyTranslation" class="legacy-translation mt-2">
                <span class="text--secondary">旧词条释义：</span>{{ legacyTranslation }}
            </div>
        </div>

        <div v-if="loading" class="search-result disabled">
            <div class="search-result-definition rounded pr-2">
                正在查询 <v-progress-circular indeterminate class="ml-1" size="16" width="2" color="primary" />
            </div>
        </div>

        <v-alert v-else-if="error" dense text type="error" class="mb-2">
            词义查询失败，请稍后重试。
        </v-alert>

        <v-alert v-if="message" dense text type="success" class="mt-2 mb-2">{{ message }}</v-alert>
        <v-alert v-if="saveError" dense text type="error" class="mt-2 mb-2">{{ saveError }}</v-alert>

        <v-expansion-panels v-if="!loading && !error" v-model="openPanels" multiple class="sense-groups">
            <v-expansion-panel v-for="(group, groupIndex) in senseGroups" :key="group.pos" class="sense-group-panel">
                <v-expansion-panel-header>
                    <div class="d-flex align-center w-full">
                        <strong>{{ group.label }}</strong>
                        <v-chip x-small class="ml-2">{{ group.senses.length }}</v-chip>
                        <v-spacer />
                    </div>
                </v-expansion-panel-header>
                <v-expansion-panel-content>
                    <div v-if="!group.senses.length" class="empty-pos rounded pa-3 mb-2">
                        当前还没有{{ group.shortLabel }}释义。
                        <v-btn x-small text color="primary" class="ml-1" @click="openAddForm(group.pos)">
                            + 在{{ group.shortLabel }}下添加释义
                        </v-btn>
                    </div>

                    <div
                        v-for="sense in group.senses"
                        :key="sense.sense_id"
                        class="sense-item rounded mb-2 pa-2"
                    >
                        <div class="d-flex align-center mb-1">
                            <v-chip x-small color="success" class="mr-1">已保存</v-chip>
                            <v-chip x-small outlined class="mr-1">{{ group.label }}</v-chip>
                            <v-chip v-if="sense.review_card_id" x-small outlined color="primary">FSRS</v-chip>
                            <v-spacer />
                            <v-btn v-if="editingSenseId !== sense.sense_id" x-small outlined color="primary" @click="startEdit(sense)">
                                编辑该释义
                            </v-btn>
                        </div>

                        <template v-if="editingSenseId === sense.sense_id">
                            <div class="edit-form rounded pa-2 mt-2">
                                <v-select
                                    dense
                                    filled
                                    rounded
                                    hide-details
                                    class="mb-2"
                                    label="词性"
                                    :items="posOptions"
                                    item-text="label"
                                    item-value="value"
                                    v-model="editForm.pos"
                                />
                                <v-textarea
                                    dense
                                    filled
                                    rounded
                                    hide-details
                                    no-resize
                                    class="mb-2"
                                    height="70"
                                    label="中文释义"
                                    v-model="editForm.sense_zh"
                                />
                                <v-textarea
                                    dense
                                    filled
                                    rounded
                                    hide-details
                                    no-resize
                                    class="mb-2"
                                    height="70"
                                    label="英文解释（可选）"
                                    v-model="editForm.sense_en"
                                />
                                <v-text-field dense filled rounded hide-details class="mb-2" label="近义译法，用逗号分隔" v-model="editForm.aliases_zh" />
                                <v-text-field dense filled rounded hide-details class="mb-2" label="搭配，用逗号分隔" v-model="editForm.collocations" />
                                <div class="d-flex">
                                    <v-spacer />
                                    <v-btn x-small text class="mr-2" @click="cancelEdit">取消</v-btn>
                                    <v-btn x-small color="success" :loading="saving" @click="saveEdit(sense)">保存释义</v-btn>
                                </div>
                            </div>
                        </template>

                        <template v-else>
                            <div v-if="sense.sense_zh" class="sense-zh mb-1">
                                <strong>{{ sense.sense_zh }}</strong>
                            </div>
                            <div v-if="sense.sense_en" class="sense-en mb-1 text--secondary">
                                {{ sense.sense_en }}
                            </div>

                            <div class="sense-fsrs-row text-caption mb-1">
                                <span>下次复习：<strong>{{ dueText(sense) }}</strong></span>
                                <span class="ml-3">状态：<strong>{{ fsrsStatusText(sense) }}</strong></span>
                            </div>

                            <div v-if="sense.aliases_zh && sense.aliases_zh.length" class="sense-aliases mb-1">
                                <span class="text--secondary">近义译法：</span>
                                <v-chip v-for="(alias, i) in sense.aliases_zh" :key="i" x-small class="mr-1 mb-1">{{ alias }}</v-chip>
                            </div>

                            <div v-if="sense.collocations && sense.collocations.length" class="sense-collocations mb-1">
                                <span class="text--secondary">搭配：</span>
                                <v-chip v-for="(col, i) in sense.collocations" :key="i" x-small outlined class="mr-1 mb-1">{{ col }}</v-chip>
                            </div>

                            <div class="d-flex mt-2">
                                <v-btn x-small text disabled>查看例句</v-btn>
                                <v-spacer />
                                <span class="text-caption text--secondary" v-if="sense.fsrs_reps !== null && sense.fsrs_reps !== undefined">
                                    已复习 {{ sense.fsrs_reps }} 次
                                </span>
                            </div>
                        </template>
                    </div>
                </v-expansion-panel-content>
            </v-expansion-panel>
        </v-expansion-panels>

        <div v-if="showAddForm" class="sense-form rounded pa-3 mt-3">
            <div class="d-flex align-center mb-2">
                <strong>添加新释义</strong>
                <span v-if="prefillSource" class="text-caption text--secondary ml-2">来自词典结果预填</span>
                <v-spacer />
                <v-btn icon small @click="closeAddForm"><v-icon small>mdi-close</v-icon></v-btn>
            </div>

            <v-select
                dense
                filled
                rounded
                hide-details
                class="mb-2"
                label="词性"
                :items="posOptions"
                item-text="label"
                item-value="value"
                v-model="newForm.pos"
            />
            <v-textarea
                dense
                filled
                rounded
                hide-details
                no-resize
                class="mb-2"
                height="70"
                label="中文释义"
                placeholder="例如：落下；掉下"
                v-model="newForm.sense_zh"
            />
            <v-textarea
                dense
                filled
                rounded
                hide-details
                no-resize
                class="mb-2"
                height="70"
                label="英文解释（可选）"
                placeholder="例如：to fall"
                v-model="newForm.sense_en"
            />
            <v-text-field dense filled rounded hide-details class="mb-2" label="例句（可选）" v-model="newForm.example_sentence_en" />
            <v-text-field dense filled rounded hide-details class="mb-2" label="近义译法，用逗号分隔" v-model="newForm.aliases_zh" />
            <v-text-field dense filled rounded hide-details class="mb-2" label="搭配，用逗号分隔" v-model="newForm.collocations" />
            <div class="d-flex">
                <v-spacer />
                <v-btn small text class="mr-2" @click="closeAddForm">取消</v-btn>
                <v-btn small rounded color="success" :loading="saving" @click="createSense">保存新释义</v-btn>
            </div>
        </div>
    </div>
</template>

<script>
import { mapState } from 'vuex';

const POS_OPTIONS = [
    { value: 'noun', label: '名词 noun', shortLabel: '名词' },
    { value: 'verb', label: '动词 verb', shortLabel: '动词' },
    { value: 'adjective', label: '形容词 adjective', shortLabel: '形容词' },
    { value: 'adverb', label: '副词 adverb', shortLabel: '副词' },
    { value: 'preposition', label: '介词 preposition', shortLabel: '介词' },
    { value: 'conjunction', label: '连词 conjunction', shortLabel: '连词' },
    { value: 'phrase', label: '短语 phrase', shortLabel: '短语' },
    { value: 'other', label: '其他 other', shortLabel: '其他' },
];

export default {
    props: {
        lemma: {
            type: String,
            required: true,
        },
        surface: {
            type: String,
            default: '',
        },
        language: {
            type: String,
            default: 'english',
        },
        legacyTranslation: {
            type: String,
            default: '',
        },
    },
    computed: {
        ...mapState({
            chapterId: state => state.vocabularyBox.chapterId,
            sentenceIndex: state => state.vocabularyBox.sentenceIndex,
            sentenceText: state => state.vocabularyBox.sentenceText,
        }),
        effectiveLemma() {
            return (this.lemma || this.surface || '').trim().toLowerCase();
        },
        surfaceWord() {
            return (this.surface || this.lemma || '').trim();
        },
        senseGroups() {
            return POS_OPTIONS.map(option => {
                const senses = this.senses.filter(sense => (sense.pos || 'other') === option.value);
                return {
                    pos: option.value,
                    label: option.label,
                    shortLabel: option.shortLabel,
                    senses: senses,
                };
            });
        },
    },
    watch: {
        effectiveLemma: {
            immediate: true,
            handler() {
                this.fetchSenses();
            },
        },
        language() {
            this.fetchSenses();
        },
    },
    data() {
        return {
            senses: [],
            loading: false,
            error: false,
            saving: false,
            showAddForm: false,
            editingSenseId: null,
            message: '',
            saveError: '',
            prefillSource: '',
            openPanels: [],
            posOptions: POS_OPTIONS,
            newForm: this.emptyForm(),
            editForm: this.emptyForm(),
        };
    },
    methods: {
        emptyForm() {
            return {
                pos: 'verb',
                sense_zh: '',
                sense_en: '',
                aliases_zh: '',
                collocations: '',
                example_sentence_en: '',
            };
        },
        fetchSenses() {
            if (!this.effectiveLemma) {
                this.senses = [];
                this.loading = false;
                this.error = false;
                this.openPanels = [];
                return;
            }

            this.loading = true;
            this.error = false;
            this.senses = [];

            axios.get('/senses/candidates', {
                params: {
                    lemma: this.effectiveLemma,
                    language: this.language,
                },
            })
                .then((response) => {
                    this.senses = response.data || [];
                    this.openPanels = this.senseGroups
                        .map((group, index) => group.senses.length ? index : null)
                        .filter(index => index !== null);
                })
                .catch(() => {
                    this.error = true;
                })
                .finally(() => {
                    this.loading = false;
                });
        },
        listValue(value) {
            if (Array.isArray(value)) {
                return value.join(', ');
            }

            return value || '';
        },
        splitList(value) {
            return (value || '')
                .split(',')
                .map(item => item.trim())
                .filter(item => item !== '');
        },
        cleanDictionaryDefinition(definition) {
            return (definition || '')
                .replace(/^(vt\.|vi\.|v\.|n\.|adj\.|a\.|adv\.|prep\.|conj\.)\s*/i, '')
                .trim();
        },
        openAddForm(pos = 'verb', prefill = null) {
            this.showAddForm = true;
            this.saveError = '';
            this.message = '';
            this.prefillSource = '';
            this.newForm = this.emptyForm();
            this.newForm.pos = pos || 'verb';

            if (prefill) {
                this.prefillSource = prefill.dictionary || '词典';
                this.newForm.pos = prefill.pos || this.newForm.pos;
                this.newForm.sense_zh = this.cleanDictionaryDefinition(prefill.definition);
            }
        },
        openAddFormFromDictionary(payload) {
            this.openAddForm(payload && payload.pos ? payload.pos : 'other', payload || {});
            this.$nextTick(() => {
                const element = this.$el.querySelector('.sense-form');
                if (element && element.scrollIntoView) {
                    element.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            });
        },
        closeAddForm() {
            this.showAddForm = false;
            this.prefillSource = '';
            this.newForm = this.emptyForm();
        },
        createPayload(form) {
            return {
                lemma: this.effectiveLemma,
                surface_form: this.surfaceWord,
                pos: form.pos,
                sense_zh: form.sense_zh,
                sense_en: form.sense_en,
                aliases_zh: this.splitList(form.aliases_zh),
                collocations: this.splitList(form.collocations),
                chapter_id: this.chapterId,
                sentence_id: this.sentenceIndex !== null && this.sentenceIndex !== undefined ? String(this.sentenceIndex) : null,
                sentence_en: form.example_sentence_en || this.sentenceText,
            };
        },
        createSense() {
            if (!this.newForm.sense_zh.trim()) {
                this.saveError = '请先填写中文释义。';
                return;
            }

            this.saving = true;
            this.saveError = '';
            this.message = '';

            axios.post('/senses/manual', this.createPayload(this.newForm))
                .then(() => {
                    this.message = '已保存新词义，并已创建词义复习卡。';
                    const pos = this.newForm.pos;
                    this.closeAddForm();
                    this.fetchSenses();
                    const index = POS_OPTIONS.findIndex(option => option.value === pos);
                    if (index >= 0 && this.openPanels.indexOf(index) === -1) {
                        this.openPanels.push(index);
                    }
                })
                .catch(() => {
                    this.saveError = '保存词义失败，请稍后重试。';
                })
                .finally(() => {
                    this.saving = false;
                });
        },
        startEdit(sense) {
            this.editingSenseId = sense.sense_id;
            this.editForm = {
                pos: sense.pos || 'other',
                sense_zh: sense.sense_zh || '',
                sense_en: sense.sense_en || '',
                aliases_zh: this.listValue(sense.aliases_zh),
                collocations: this.listValue(sense.collocations),
                example_sentence_en: '',
            };
            this.saveError = '';
            this.message = '';
        },
        cancelEdit() {
            this.editingSenseId = null;
            this.editForm = this.emptyForm();
        },
        saveEdit(sense) {
            if (!this.editForm.sense_zh.trim()) {
                this.saveError = '请先填写中文释义。';
                return;
            }

            this.saving = true;
            this.saveError = '';
            this.message = '';

            axios.put(`/senses/${sense.sense_id}/manual`, {
                pos: this.editForm.pos,
                sense_zh: this.editForm.sense_zh,
                sense_en: this.editForm.sense_en,
                aliases_zh: this.splitList(this.editForm.aliases_zh),
                collocations: this.splitList(this.editForm.collocations),
            })
                .then(() => {
                    this.message = '已更新词义。';
                    this.cancelEdit();
                    this.fetchSenses();
                })
                .catch(() => {
                    this.saveError = '更新词义失败，请稍后重试。';
                })
                .finally(() => {
                    this.saving = false;
                });
        },
        dueText(sense) {
            if (!sense.review_card_id || !sense.fsrs_enabled) {
                return '已暂停';
            }

            if (!sense.fsrs_due_at) {
                return '今天';
            }

            const due = new Date(sense.fsrs_due_at);
            const now = new Date();
            const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
            const dueDay = new Date(due.getFullYear(), due.getMonth(), due.getDate());
            const diffDays = Math.ceil((dueDay - today) / 86400000);

            if (diffDays <= 0) {
                return '今天';
            }

            if (diffDays <= 7) {
                return `${diffDays}天后`;
            }

            return `${due.getFullYear()}-${String(due.getMonth() + 1).padStart(2, '0')}-${String(due.getDate()).padStart(2, '0')}`;
        },
        fsrsStatusText(sense) {
            if (!sense.review_card_id || !sense.fsrs_enabled) {
                return '已暂停';
            }

            if (sense.fsrs_state === 'new') {
                return '新卡';
            }

            if (sense.fsrs_state === 'review') {
                return '学习中';
            }

            if (sense.fsrs_state === 'learning' || sense.fsrs_state === 'relearning') {
                return '学习中';
            }

            return sense.fsrs_state || '学习中';
        },
    },
};
</script>

<style scoped>
.lemma-surface-card,
.sense-form,
.sense-item,
.empty-pos,
.edit-form {
    border: 1px solid var(--v-gray2-base);
}

.lemma-surface-card,
.empty-pos,
.edit-form {
    background: rgba(127, 127, 127, 0.04);
}

.sense-item {
    background: rgba(76, 175, 80, 0.05);
    border-color: rgba(76, 175, 80, 0.25);
}

.sense-fsrs-row strong {
    color: var(--v-success-base);
}
</style>
