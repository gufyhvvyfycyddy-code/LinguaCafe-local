<template>
        <div class="word-senses-section mt-3">
            <div v-if="!compact" class="sense-section-header d-flex align-center mb-1">
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

        <div v-if="!compact" class="lemma-surface-card rounded pa-2 mb-3">
            <div class="text-caption text--secondary">当前词形</div>
            <div class="d-flex align-center">
                <strong class="default-font mr-2">{{ surfaceWord || '未选择' }}</strong>
                <span class="text--secondary mr-1">词元：</span>
                <strong class="default-font">{{ effectiveLemma || '未识别' }}</strong>
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

        <!-- Inline sense preview panel (GLM-ReadingInlinePreview-First-1 +
             GLM-ReadingInlineConfirmationPersistence-1000-1)
             READ-ONLY preview + persisted "是这个意思 / 不是这个意思" buttons.
             Persisted confirmations are NOT review ratings; they do NOT
             write ReviewLog / FSRS / WordSense / ReviewCard. -->
        <inline-sense-preview-panel
            :lemma="effectiveLemma"
            :surface="surfaceWord"
            :sentence="sentenceText"
            :language="language"
            :chapter-id="chapterId"
            :sentence-index="sentenceIndex"
        />

        <!-- Known-sense candidates panel (Trae-LemmaKnownSenseBridge-1) -->
        <div v-if="!loading && !error && knownSenses.length > 0" class="known-sense-panel rounded pa-2 mb-3">
            <div class="d-flex align-center mb-1">
                <v-icon small color="success" class="mr-1">mdi-bookmark-check</v-icon>
                <strong class="known-sense-title">已学词义候选</strong>
                <v-chip x-small class="ml-2">{{ knownSenses.length }}</v-chip>
                <v-spacer />
                <span class="text-caption text--secondary">词元 {{ effectiveLemma || '未识别' }}</span>
            </div>
            <div v-for="sense in knownSenses" :key="sense.sense_id" class="known-sense-item rounded pa-2 mb-1">
                <div class="d-flex align-center mb-1">
                    <v-chip x-small color="success" class="mr-1">已学</v-chip>
                    <v-chip v-if="sense.pos" x-small outlined class="mr-1">{{ sense.pos }}</v-chip>
                    <v-chip v-if="sense.has_review_card" x-small outlined color="primary">FSRS</v-chip>
                    <v-spacer />
                    <span class="text-caption text--secondary" v-if="sense.fsrs_reps !== null && sense.fsrs_reps !== undefined">
                        已复习 {{ sense.fsrs_reps }} 次
                    </span>
                </div>
                <div v-if="sense.sense_zh" class="sense-zh mb-1"><strong>{{ sense.sense_zh }}</strong></div>
                <div v-if="sense.sense_en" class="sense-en mb-1 text--secondary">{{ sense.sense_en }}</div>
            </div>
        </div>

        <!-- Known-sense-new-meaning hint (熟词僻义前置结构, no AI) -->
        <v-alert
            v-if="!loading && !error && knownSenses.length > 0"
            dense
            text
            type="info"
            icon="mdi-lightbulb-outline"
            class="mb-3 known-sense-new-meaning-hint"
        >
            <div class="known-sense-new-meaning-title">这个词你学过一些意思，但这里可能是新意思。</div>
            <div class="text-caption text--secondary mt-1">
                如上述已学词义都不符合当前句意，可添加新释义。此提示仅为前置结构，未调用 AI 判断。
            </div>
        </v-alert>

        <!-- Compact empty state -->
        <div v-if="compact && !loading && !error && visibleSenseGroups.length === 0 && knownSenses.length === 0" class="text-caption text--secondary mb-2">
            暂无已保存释义，可添加新释义。
        </div>

        <v-expansion-panels v-if="!loading && !error" v-model="openPanels" multiple class="sense-groups">
            <v-expansion-panel v-for="(group, groupIndex) in visibleSenseGroups" :key="group.pos" class="sense-group-panel">
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
                            <v-chip x-small :color="statusColor(sense)" class="mr-1">{{ statusText(sense) }}</v-chip>
                            <v-chip x-small outlined class="mr-1">{{ group.label }}</v-chip>
                            <v-chip v-if="sense.review_card_id" x-small outlined color="primary">FSRS</v-chip>
                            <v-chip v-else x-small outlined class="mr-1">暂无复习卡</v-chip>
                            <v-spacer />
                            <v-btn v-if="editingSenseId !== sense.sense_id" x-small outlined color="primary" @click="startEdit(sense)">
                                编辑该释义
                            </v-btn>
                            <v-btn v-if="editingSenseId !== sense.sense_id" x-small text color="error" class="ml-1" @click="deleteSense(sense)">
                                删除释义
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
                                <v-btn x-small text :loading="loadingExamples[sense.sense_id]" @click="toggleExamples(sense)">
                                    {{ examplesExpanded[sense.sense_id] ? '收起例句' : '查看例句' }}
                                </v-btn>
                                <v-spacer />
                                <span class="text-caption text--secondary" v-if="sense.fsrs_reps !== null && sense.fsrs_reps !== undefined">
                                    已复习 {{ sense.fsrs_reps }} 次
                                </span>
                            </div>

                            <div v-if="examplesExpanded[sense.sense_id]" class="examples-area rounded pa-2 mt-2" :class="{ 'text--secondary': !examplesData[sense.sense_id] || !examplesData[sense.sense_id].length }">
                                <div v-if="loadingExamples[sense.sense_id]" class="text-caption">
                                    正在查询例句...
                                </div>
                                <div v-else-if="!examplesData[sense.sense_id] || !examplesData[sense.sense_id].length" class="text-caption">
                                    暂无例句
                                </div>
                                <div v-else v-for="(example, i) in examplesData[sense.sense_id]" :key="i" class="example-item mb-1">
                                    <div class="text-body-2">{{ example.sentence_en }}</div>
                                    <div v-if="example.sentence_zh" class="text-caption text--secondary">{{ example.sentence_zh }}</div>
                                </div>
                            </div>
                        </template>
                    </div>
                </v-expansion-panel-content>
            </v-expansion-panel>
        </v-expansion-panels>

        <add-sense-form
            v-if="showAddForm"
            :value="newForm"
            :pos-options="posOptions"
            :saving="saving"
            :prefill-source="prefillSource"
            @submit="onFormSubmit"
            @cancel="closeAddForm"
        />
    </div>
</template>

<script>
import { mapState } from 'vuex';
import AddSenseForm from './AddSenseForm.vue';
import InlineSensePreviewPanel from './InlineSensePreviewPanel.vue';
import {
    buildWordSenseCandidateLookupContext,
    buildWordSenseCandidateLookupKey,
    fetchWordSenseCandidates,
} from '../../services/WordSenseCandidateService';

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
    components: {
        AddSenseForm,
        InlineSensePreviewPanel,
    },
    props: {
        lemma: {
            type: String,
            required: true,
        },
        studyBase: {
            type: String,
            default: '',
        },
        baseWord: {
            type: String,
            default: '',
        },
        surface: {
            type: String,
            default: '',
        },
        word: {
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
        compact: {
            type: Boolean,
            default: false,
        },
    },
    computed: {
        ...mapState({
            chapterId: state => state.vocabularyBox.chapterId,
            sentenceIndex: state => state.vocabularyBox.sentenceIndex,
            sentenceText: state => state.vocabularyBox.sentenceText,
            encounteredWordId: state => state.vocabularyBox.encounteredWordId,
        }),
        effectiveLemma() {
            // Full fallback chain: studyBase → baseWord → lemma → surface → word
            return (this.studyBase || this.baseWord || this.lemma || this.surface || this.word || '').trim().toLowerCase();
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
        visibleSenseGroups() {
            if (!this.compact) return this.senseGroups;
            return this.senseGroups.filter(g => g.senses.length > 0);
        },
    },
    watch: {
        effectiveLemma: {
            immediate: true,
            handler() {
                this.fetchSenses();
                this.fetchKnownSenseLookup();
            },
        },
        language() {
            this.fetchSenses();
            this.fetchKnownSenseLookup();
        },
    },
    data() {
        return {
            senses: [],
            knownSenses: [],
            knownSenseLoading: false,
            knownSenseError: false,
            latestKnownSenseLookupKey: '',
            loading: false,
            error: false,
            saving: false,
            showAddForm: false,
            editingSenseId: null,
            message: '',
            saveError: '',
            prefillSource: '',
            openPanels: [],
            latestSenseLookupKey: '',
            posOptions: POS_OPTIONS,
            newForm: this.emptyForm(),
            editForm: this.emptyForm(),
            // Snapshot of selected word context, captured when form opens
            // Prevents data loss if the store is accidentally reset (e.g. v-select click-outside)
            snapshot: {
                chapterId: null,
                sentenceIndex: null,
                sentenceText: '',
                encounteredWordId: null,
            },
            examplesExpanded: {},
            examplesData: {},
            loadingExamples: {},
        };
    },
    methods: {
        refreshLemma() {
            // Force re-fetch senses when the lemma has been manually edited.
            // The effectiveLemma watcher normally handles this, but this provides
            // a direct reset point for external callers.
            this.fetchSenses();
            this.fetchKnownSenseLookup();
        },
        fetchKnownSenseLookup() {
            // Trae-LemmaKnownSenseBridge-1: fetch confirmed-sense candidates
            // for the current lemma to render the "已学词义候选" panel and the
            // "熟词僻义" hint. Read-only — does not write anything.
            const lemma = this.effectiveLemma;
            const language = this.language;
            if (!lemma) {
                this.latestKnownSenseLookupKey = '';
                this.knownSenses = [];
                this.knownSenseLoading = false;
                this.knownSenseError = false;
                return;
            }

            const lookupKey = language + '|' + lemma;
            this.latestKnownSenseLookupKey = lookupKey;
            this.knownSenseLoading = true;
            this.knownSenseError = false;

            axios.get('/senses/known-sense-lookup', {
                params: { lemma: lemma, language: language },
            }).then((response) => {
                if (this.latestKnownSenseLookupKey !== lookupKey) return;
                const data = response && response.data;
                const confirmed = (data && Array.isArray(data.confirmed_senses)) ? data.confirmed_senses : [];
                this.knownSenses = confirmed;
            }).catch(() => {
                if (this.latestKnownSenseLookupKey !== lookupKey) return;
                this.knownSenseError = true;
                this.knownSenses = [];
            }).finally(() => {
                if (this.latestKnownSenseLookupKey !== lookupKey) return;
                this.knownSenseLoading = false;
            });
        },
        emptyForm() {
            return {
                pos: 'verb',
                sense_zh: '',
                sense_en: '',
                aliases_zh: '',
                collocations: '',
                example_sentence_en: '',
                keep_new: false,
            };
        },
        fetchSenses() {
            const context = buildWordSenseCandidateLookupContext({
                lemma: this.effectiveLemma,
                language: this.language,
            });

            if (!context) {
                this.latestSenseLookupKey = '';
                this.senses = [];
                this.loading = false;
                this.error = false;
                this.openPanels = [];
                return;
            }

            const lookupKey = buildWordSenseCandidateLookupKey(context);
            this.latestSenseLookupKey = lookupKey;
            this.loading = true;
            this.error = false;
            this.senses = [];

            fetchWordSenseCandidates(axios, context)
                .then((senses) => {
                    if (this.latestSenseLookupKey !== lookupKey) return;
                    this.senses = senses;
                    // Default: expand only the most relevant sense group
                    const firstConfirmedIdx = this.senseGroups.findIndex(
                        group => group.senses.some(s => s.status === 'confirmed')
                    );
                    if (firstConfirmedIdx >= 0) {
                        this.openPanels = [firstConfirmedIdx];
                    } else {
                        const firstNonEmptyIdx = this.senseGroups.findIndex(group => group.senses.length > 0);
                        this.openPanels = firstNonEmptyIdx >= 0 ? [firstNonEmptyIdx] : [];
                    }
                })
                .catch(() => {
                    if (this.latestSenseLookupKey !== lookupKey) return;
                    this.error = true;
                })
                .finally(() => {
                    if (this.latestSenseLookupKey !== lookupKey) return;
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
        statusText(sense) {
            if (sense.status === 'confirmed') {
                return '已保存';
            }

            if (sense.status === 'ai_suggested') {
                return 'AI 建议';
            }

            if (sense.status === 'rejected') {
                return '已拒绝';
            }

            return sense.status || '候选';
        },
        statusColor(sense) {
            if (sense.status === 'confirmed') {
                return 'success';
            }

            if (sense.status === 'ai_suggested') {
                return 'warning';
            }

            if (sense.status === 'rejected') {
                return 'error';
            }

            return 'info';
        },
        openAddForm(pos = 'verb', prefill = null) {
            this.showAddForm = true;
            this.saveError = '';
            this.message = '';
            this.prefillSource = '';
            this.newForm = this.emptyForm();
            this.newForm.pos = pos || 'verb';

            // Snapshot current Vuex state in case store is reset (e.g. v-select click-outside)
            this.snapshot = {
                chapterId: this.chapterId,
                sentenceIndex: this.sentenceIndex,
                sentenceText: this.sentenceText,
                encounteredWordId: this.encounteredWordId,
            };

            if (prefill) {
                this.prefillSource = prefill.dictionary || '词典';
                this.newForm.pos = prefill.pos || this.newForm.pos;
                if (prefill.sense_zh) {
                    this.newForm.sense_zh = prefill.sense_zh;
                } else if (prefill.definition) {
                    this.newForm.sense_zh = this.cleanDictionaryDefinition(prefill.definition);
                }
                if (prefill.source_sentence) {
                    this.newForm.example_sentence_en = prefill.source_sentence;
                }
            }
        },
        openAddFormFromAi(payload) {
            const prefill = {
                pos: payload?.pos || 'other',
                sense_zh: payload?.sense_zh || '',
                source_sentence: payload?.source_sentence || '',
                ai_reason: payload?.reason || '',
                dictionary: 'AI 建议',
            };
            this.openAddForm(prefill.pos, prefill);
            this.$nextTick(() => {
                if (payload?.source_sentence && this.$refs.sourceSentence) {
                    this.$refs.sourceSentence.focus();
                }
            });
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
        onFormSubmit(formData) {
            // Replace newForm with the submitted form data, then call the existing save logic
            this.newForm = {
                pos: formData.pos || 'verb',
                sense_zh: formData.sense_zh || '',
                sense_en: formData.sense_en || '',
                aliases_zh: formData.aliases_zh || '',
                collocations: formData.collocations || '',
                example_sentence_en: formData.example_sentence_en || '',
                keep_new: formData.keep_new === true,
            };
            this.createSense();
        },
        closeAddForm() {
            this.showAddForm = false;
            this.prefillSource = '';
            this.newForm = this.emptyForm();
        },
        createPayload(form) {
            // Use snapshot as fallback in case Vuex state was reset (e.g. v-select click-outside)
            const chapterId = this.chapterId !== null ? this.chapterId : this.snapshot.chapterId;
            const sentenceIndex = this.sentenceIndex !== null && this.sentenceIndex !== undefined
                ? this.sentenceIndex : this.snapshot.sentenceIndex;
            const sentenceText = this.sentenceText || this.snapshot.sentenceText;

            return {
                lemma: this.effectiveLemma,
                surface_form: this.surfaceWord,
                pos: form.pos,
                sense_zh: form.sense_zh,
                sense_en: form.sense_en,
                aliases_zh: this.splitList(form.aliases_zh),
                collocations: this.splitList(form.collocations),
                chapter_id: chapterId,
                sentence_id: sentenceIndex !== null && sentenceIndex !== undefined ? String(sentenceIndex) : null,
                sentence_en: form.example_sentence_en || sentenceText,
                encountered_word_id: this.snapshot?.encounteredWordId ?? this.encounteredWordId ?? null,
                keep_new: form.keep_new === true,
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
                .then((response) => {
                    this.message = '已保存新词义，并已创建词义复习卡。';

                    // Handle auto-mark Learning 7 result from backend
                    const updatedWord = response.data.updated_word;
                    if (updatedWord && updatedWord.id && updatedWord.stage !== null) {
                        // Sync Vuex store for sidebar panel display
                        this.$store.commit('vocabularyBox/setStage', updatedWord.stage);
                        // Emit to TextBlockGroup to update text token colors
                        this.$emit('word-learning-updated', {
                            encounteredWordId: updatedWord.id,
                            stage: updatedWord.stage,
                        });
                    }

                    const pos = this.newForm.pos;
                    this.closeAddForm();
                    this.fetchSenses();
                    const index = this.senseGroups.findIndex(group => group.pos === pos);
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
        toggleExamples(sense) {
            const senseId = sense.sense_id;
            const currently = this.examplesExpanded[senseId];

            if (currently) {
                this.$set(this.examplesExpanded, senseId, false);
                return;
            }

            // First time — fetch examples
            this.$set(this.examplesExpanded, senseId, true);
            if (this.examplesData[senseId]) {
                return; // Already cached
            }

            this.$set(this.loadingExamples, senseId, true);
            axios.get(`/senses/${senseId}/examples`)
                .then((response) => {
                    this.$set(this.examplesData, senseId, response.data.occurrences || []);
                })
                .catch(() => {
                    this.$set(this.examplesData, senseId, []);
                })
                .finally(() => {
                    this.$set(this.loadingExamples, senseId, false);
                });
        },
        deleteSense(sense) {
            if (!confirm(`确认要删除释义「${sense.sense_zh || sense.sense_en || '（无释义文本）'}」吗？该释义将从列表中移除，相关 Sense Review 卡将被禁用。`)) {
                return;
            }

            this.saving = true;
            this.saveError = '';
            this.message = '';

            axios.put(`/senses/${sense.sense_id}/archive`)
                .then(() => {
                    this.message = '已删除该释义。';
                    this.fetchSenses();
                })
                .catch(() => {
                    this.saveError = '删除释义失败，请稍后重试。';
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

.examples-area {
    background: rgba(127, 127, 127, 0.06);
    border: 1px solid var(--v-gray2-base);
}

.example-item {
    border-bottom: 1px solid rgba(127, 127, 127, 0.1);
    padding-bottom: 4px;
}

.example-item:last-child {
    border-bottom: none;
    padding-bottom: 0;
}
</style>
