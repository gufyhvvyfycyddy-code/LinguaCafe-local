<template>
    <div class="word-senses-section mt-3">
        <div class="vocab-box-subheader d-flex mb-2">词元释义</div>

        <div class="text-caption text--secondary mb-2">
            <div>当前词形：<strong>{{ surfaceWord || '未选择' }}</strong></div>
            <div>词元：<strong>{{ effectiveLemma || '未识别' }}</strong></div>
        </div>

        <div v-if="loading" class="search-result disabled">
            <div class="search-result-definition rounded pr-2">
                正在查询 <v-progress-circular indeterminate class="ml-1" size="16" width="2" color="primary" />
            </div>
        </div>

        <div v-else-if="error" class="search-result disabled">
            <div class="search-result-definition rounded pr-2">
                词义查询失败，请稍后重试。
            </div>
        </div>

        <div v-else-if="!senses.length" class="search-result disabled">
            <div class="search-result-definition rounded pr-2">
                暂无已保存词义。
            </div>
        </div>

        <div v-else>
            <div v-for="group in groupedSenses" :key="group.pos" class="mb-3">
                <div class="text-caption font-weight-bold mb-1">{{ group.label }}</div>
                <div
                    v-for="sense in group.senses"
                    :key="sense.sense_id"
                    class="sense-item rounded mb-2 pa-2"
                    :class="{ 'sense-confirmed': sense.status === 'confirmed', 'sense-suggested': sense.status === 'ai_suggested' }"
                >
                    <div class="d-flex align-center mb-1">
                        <v-chip x-small class="mr-1" :color="sense.status === 'confirmed' ? 'success' : 'warning'">
                            {{ sense.status === 'confirmed' ? '已确认' : 'AI 建议' }}
                        </v-chip>
                        <v-chip x-small outlined class="mr-1">{{ posLabel(sense.pos) }}</v-chip>
                        <v-chip v-if="sense.review_card_id" x-small outlined color="primary">FSRS</v-chip>
                        <v-spacer />
                        <v-btn v-if="editingSenseId !== sense.sense_id" x-small text color="primary" @click="startEdit(sense)">
                            编辑该释义
                        </v-btn>
                    </div>

                    <template v-if="editingSenseId === sense.sense_id">
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
                            label="英文释义"
                            v-model="editForm.sense_en"
                        />
                        <v-text-field dense filled rounded hide-details class="mb-2" label="近义译法，用逗号分隔" v-model="editForm.aliases_zh" />
                        <v-text-field dense filled rounded hide-details class="mb-2" label="搭配，用逗号分隔" v-model="editForm.collocations" />
                        <div class="d-flex">
                            <v-spacer />
                            <v-btn x-small text class="mr-2" @click="cancelEdit">取消</v-btn>
                            <v-btn x-small color="success" :loading="saving" @click="saveEdit(sense)">保存释义</v-btn>
                        </div>
                    </template>

                    <template v-else>
                        <div v-if="sense.sense_zh" class="sense-zh mb-1">
                            <strong>{{ sense.sense_zh }}</strong>
                        </div>
                        <div v-if="sense.sense_en" class="sense-en mb-1 text--secondary">
                            {{ sense.sense_en }}
                        </div>

                        <div v-if="sense.aliases_zh && sense.aliases_zh.length" class="sense-aliases mb-1">
                            <span class="text--secondary">近义译法：</span>
                            <v-chip v-for="(alias, i) in sense.aliases_zh" :key="i" x-small class="mr-1 mb-1">{{ alias }}</v-chip>
                        </div>

                        <div v-if="sense.collocations && sense.collocations.length" class="sense-collocations mb-1">
                            <span class="text--secondary">搭配：</span>
                            <v-chip v-for="(col, i) in sense.collocations" :key="i" x-small outlined class="mr-1 mb-1">{{ col }}</v-chip>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <v-alert v-if="message" dense text type="success" class="mt-2 mb-2">{{ message }}</v-alert>
        <v-alert v-if="saveError" dense text type="error" class="mt-2 mb-2">{{ saveError }}</v-alert>

        <v-btn small rounded depressed color="primary" class="mt-2" @click="showAddForm = !showAddForm" :disabled="!effectiveLemma">
            {{ showAddForm ? '收起添加释义' : '添加释义' }}
        </v-btn>

        <div v-if="showAddForm" class="sense-form rounded pa-2 mt-2">
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
                label="英文释义"
                v-model="newForm.sense_en"
            />
            <v-text-field dense filled rounded hide-details class="mb-2" label="近义译法，用逗号分隔" v-model="newForm.aliases_zh" />
            <v-text-field dense filled rounded hide-details class="mb-2" label="搭配，用逗号分隔" v-model="newForm.collocations" />
            <div class="d-flex">
                <v-spacer />
                <v-btn small rounded color="success" :loading="saving" @click="createSense">保存新释义</v-btn>
            </div>
        </div>
    </div>
</template>

<script>
import { mapState } from 'vuex';

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
        groupedSenses() {
            const groups = {};
            this.senses.forEach((sense) => {
                const pos = sense.pos || 'other';
                if (!groups[pos]) {
                    groups[pos] = [];
                }
                groups[pos].push(sense);
            });

            return Object.keys(groups)
                .sort()
                .map(pos => ({
                    pos,
                    label: this.posLabel(pos),
                    senses: groups[pos],
                }));
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
            };
        },
        posLabel(pos) {
            const match = POS_OPTIONS.find(option => option.value === pos);
            return match ? match.label : '其他 other';
        },
        fetchSenses() {
            if (!this.effectiveLemma) {
                this.senses = [];
                this.loading = false;
                this.error = false;
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
                sentence_en: this.sentenceText,
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
                    this.newForm = this.emptyForm();
                    this.showAddForm = false;
                    this.fetchSenses();
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
    },
};
</script>

<style scoped>
.sense-form,
.sense-item {
    border: 1px solid var(--v-gray2-base);
}
</style>
