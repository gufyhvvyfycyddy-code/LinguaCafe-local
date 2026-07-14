<template>
    <v-card
        id="vocab-side-box"
        elevation="0"
        :class="{
            'new-phrase': type === 'new-phrase',
            'word-selected': type === 'word',
            'phrase-selected': type === 'phrase',
            'new-phrase-selected': type === 'new-phrase',
            'pa-4': true,
            'rounded-l-0': true,
            'rounded-r-lg': true
        }"
        :style="{
            'width': sidebarWidth,
            'border-left': '1px solid var(--v-gray2-base)',
            'left': positionLeft + 'px',
            'top': positionTop + 'px',
            'height': height + 'px',
        }"
        @mouseup.stop=";"
    >
        <v-alert id="no-word-selected-title" prominent color="foreground" class="text--text" v-if="type == 'empty'">
            请选择一个单词或短语
        </v-alert>

        <div class="pa-0 w-full" v-if="type !== 'empty'">
            <div class="vocab-box-subheader d-flex mb-2">
                <span id="vocab-side-box-title" v-if="type == 'new-phrase'">新短语</span>
                <span id="vocab-side-box-title" v-else>{{ type === 'word' ? '单词' : '短语' }}</span>
                <v-spacer />
                <v-btn v-if="tab == 0 && inflections.length" icon title="显示变形" @click="tab = 1;"><v-icon>mdi-list-box</v-icon></v-btn>
                <v-btn v-if="tab == 0 && $props.textToSpeechAvailable" icon title="朗读" @click="textToSpeech"><v-icon>mdi-bullhorn</v-icon></v-btn>
                <v-btn v-if="tab == 0 && type !== 'new-phrase'" icon title="发送到 Anki" @mouseup.stop="addSelectedWordToAnki"><v-icon>mdi-cards</v-icon></v-btn>
                <v-btn v-if="tab == 1" icon title="返回单词" @click="tab = 0;"><v-icon>mdi-arrow-left</v-icon></v-btn>
                <v-btn dark icon title="取消选择" @click="close"><v-icon>mdi-close</v-icon></v-btn>
            </div>
        </div>

        <v-tabs-items v-model="tab" v-if="type !== 'empty'">
            <v-tab-item :value="0" class="sidebar-tab">
                <div class="word-basic-info rounded pa-3 mb-3" v-if="type == 'word'">
                    <div class="text-caption font-weight-bold mb-1">单词基础信息</div>
                    <div class="d-flex align-center">
                        <div>
                            <div class="text-h6 default-font mb-1">
                                <template v-if="!editingLemma">
                                    <span v-if="lemmaDisplay !== word" class="d-flex align-center flex-wrap">
                                        <span class="default-font">{{ word }}</span>
                                        <span class="mx-1 text--secondary">&rarr;</span>
                                        <span class="default-font">{{ lemmaDisplay }}</span>
                                        <span class="lemma-edit-link ml-1 text-caption" @click="startEditLemma">[修改]</span>
                                    </span>
                                    <span v-else class="d-flex align-center flex-wrap">
                                        <span class="default-font">{{ word }}</span>
                                        <span class="lemma-edit-link ml-1 text-caption" @click="startEditLemma">[修改]</span>
                                    </span>
                                </template>
                                <template v-if="editingLemma">
                                    <span class="default-font">{{ word }} &rarr; </span>
                                    <span class="lemma-edit-inline">
                                        <input
                                            ref="lemmaInput"
                                            v-model="editLemmaValue"
                                            class="lemma-edit-input"
                                            @keyup.enter="saveLemma"
                                            @keyup.escape="cancelEditLemma"
                                            @blur="saveLemma"
                                        />
                                        <v-icon x-small class="ml-1" @click="saveLemma">mdi-check</v-icon>
                                        <v-icon x-small @click="cancelEditLemma">mdi-close</v-icon>
                                    </span>
                                </template>
                            </div>
                        </div>
                                <v-spacer />
                                <v-btn v-if="$props.textToSpeechAvailable" icon title="发音" @click="textToSpeech"><v-icon>mdi-bullhorn</v-icon></v-btn>
                            </div>
                            <div v-if="fsrsFamiliarityHasData" class="d-flex align-center mt-1">
                                <span class="text-caption grey--text mr-2" style="white-space: nowrap;">FSRS 熟悉度：{{ fsrsFamiliarityPercent }}%</span>
                                <v-progress-linear
                                    :value="fsrsFamiliarityPercent"
                                    color="#4CAF50"
                                    height="4"
                                    rounded
                                    class="flex-grow-1"
                                    style="max-width: 140px;"
                                ></v-progress-linear>
                            </div>
                            <div v-else-if="stage <= -1" class="text-caption grey--text mt-1">
                                FSRS 熟悉度：尚未复习
                            </div>
                        </div>

                <div class="d-flex" v-if="type == 'word' && ($props.language == 'japanese' || $props.language == 'chinese')">
                    <v-text-field class="default-font my-2" hide-details placeholder="词元读音" title="词元读音" filled dense rounded v-model="baseWordReading" @keyup="inputChanged" @keydown.stop=";" />
                    <v-icon class="mt-1 mx-1">mdi-arrow-right</v-icon>
                    <v-text-field class="default-font my-2" hide-details placeholder="读音" title="读音" filled dense rounded v-model="reading" @keyup="inputChanged" @keydown.stop=";" />
                </div>

                <v-textarea v-if="type !== 'word'" class="default-font my-2" label="短语" filled dense no-resize rounded hide-details height="80" disabled :value="phraseText" @keydown.stop=";" />
                <v-textarea v-if="type !== 'word' && ($props.language == 'japanese' || $props.language == 'chinese')" class="default-font my-2" label="读音" filled dense no-resize rounded hide-details height="80" v-model="reading" @keyup="inputChanged" @keydown.stop=";" />

                <template v-if="type !== 'new-phrase'">
                    <div v-if="type == 'word'" class="d-flex flex-wrap mb-3">
                        <v-btn small rounded depressed color="warning" class="mr-2 mb-2" @click="setStage(1)">忽略</v-btn>
                        <v-btn small rounded depressed color="success" class="mr-2 mb-2" @click="setStage(0)">标为已知</v-btn>
                        <v-btn small rounded depressed color="error" class="mr-2 mb-2" @click="deleteWord">回归为新词</v-btn>
                        <!-- V1-V5: AI study card workflow 由共享 feature island 组件负责 -->
                        <AiStudyCardDesktopWorkflow ref="aiStudyCardWorkflow" />
                    </div>
                </template>

                <!-- Saved Senses (compact, no empty groups) -->
                <word-senses-list
                    ref="wordSensesList"
                    v-if="type === 'word'"
                    :study-base="studyBase"
                    :base-word="baseWord"
                    :lemma="baseWord || word"
                    :surface="word"
                    :word="word"
                    :language="$props.language"
                    :legacy-translation="translationText"
                    compact
                    @word-learning-updated="$emit('word-learning-updated', $event)"
                />

                <!-- Unified 添加新释义 panel -->
                <div class="add-sense-panel mt-3" v-if="type === 'word'">
                    <div class="vocab-box-subheader d-flex align-center pa-2 rounded" @click="showAddSensePanel = !showAddSensePanel" style="cursor:pointer; border: 1px solid var(--v-primary-base);">
                        <v-icon small color="primary" class="mr-2">mdi-plus-circle-outline</v-icon>
                        <span class="font-weight-medium primary--text">添加新释义</span>
                        <v-chip
                            v-if="!showAddSensePanel && (aiVocabSuggestions.length + aiPhraseSuggestions.length)"
                            x-small
                            color="primary"
                            class="ml-2"
                        >{{ aiVocabSuggestions.length + aiPhraseSuggestions.length }} 条建议</v-chip>
                        <v-spacer />
                        <v-icon x-small :color="showAddSensePanel ? 'primary' : ''">{{ showAddSensePanel ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                    </div>

                    <template v-if="showAddSensePanel">
                        <!-- Section: Unified candidate list (AI + dictionary) -->
                        <div class="mt-2">
                            <v-text-field
                                placeholder="搜索词典..."
                                class="dictionary-search-field default-font"
                                dense
                                filled
                                rounded
                                hide-details
                                prepend-inner-icon="mdi-magnify"
                                :value="searchField"
                                @change="searchFieldChanged"
                                @keydown.stop=";"
                            />
                            <div class="vocab-box-subheader d-flex align-center mt-1" @click="showDictionaryResults = !showDictionaryResults" style="cursor:pointer;">
                                <v-icon x-small class="mr-1">mdi-book-open-variant</v-icon>
                                <span class="text-caption">候选结果（AI + 词典）</span>
                                <v-spacer />
                                <v-icon x-small>{{ showDictionaryResults ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                            </div>
                            <vocabulary-search-box
                                v-if="showDictionaryResults"
                                :any-api-dictionary-enabled="$props.anyApiDictionaryEnabled"
                                :language="$props.language"
                                :searchTerm="searchField"
                                :ai-vocab-suggestions="aiVocabSuggestions"
                                :ai-phrase-suggestions="aiPhraseSuggestions"
                                :ai-lookup-loading="aiLookupLoading"
                                :ai-lookup-error="aiLookupError"
                                @addDefinitionToInput="addDefinitionToInput"
                                @addDefinitionAsSense="addDefinitionAsSense"
                                @use-vocab-suggestion="useAiSuggestion"
                                @use-phrase-suggestion="useAiPhraseSuggestion"
                            />
                        </div>

                        <!-- Section: Manual -->
                        <div class="mt-2 mb-2">
                            <v-btn small text color="primary" block @click="openManualAddForm">
                                <v-icon x-small class="mr-1">mdi-pencil</v-icon>
                                手动输入释义
                            </v-btn>
                        </div>
                    </template>
                </div>

                <div v-if="type !== 'word'" class="d-flex mt-2 pl-0">
                    <v-spacer />
                    <v-btn small rounded color="success" @click="addNewPhrase" v-if="type == 'new-phrase'">保存短语</v-btn>
                    <v-btn small rounded color="error" @click="deletePhrase" v-if="type == 'phrase'">删除短语</v-btn>
                </div>
            </v-tab-item>

            <v-tab-item :value="1">
                <v-simple-table v-if="inflections.length" class="border rounded-lg no-hover mx-auto default-font">
                    <thead><tr><th class="text-center">形式</th><th class="text-center">肯定</th><th class="text-center">否定</th></tr></thead>
                    <tbody>
                        <tr v-for="(inflection, index) in inflections" :key="index">
                            <td class="px-2">{{ inflection.name }}</td>
                            <td class="px-1 text-center">{{ inflection.affPlain }}</td>
                            <td class="px-1 text-center">{{ inflection.negPlain }}</td>
                        </tr>
                    </tbody>
                </v-simple-table>
            </v-tab-item>
        </v-tabs-items>

    </v-card>
</template>

<script>
import { mapState } from 'vuex';
import WordSensesList from './WordSensesList.vue';
import AiStudyCardDesktopWorkflow from './AiStudyCardDesktopWorkflow.vue';
import { getReaderSidebarCssWidthForWorkspace } from './../../services/ReaderWorkspaceSizingService';
import {
    buildAiSuggestionLookupContext,
    buildAiSuggestionLookupKey,
    fetchAiSuggestions,
    buildAiVocabSensePayload,
    buildAiPhraseSensePayload,
    hasAiSuggestions,
} from './../../services/VocabularyAiSuggestionService';

export default {
    components: {
        WordSensesList,
        AiStudyCardDesktopWorkflow,
    },
    props: {
        language: String,
        autoHighlightWords: Boolean,
        anyApiDictionaryEnabled: Boolean,
        textToSpeechAvailable: Boolean,
    },
    computed: {
        sidebarWidth() {
            const readerWorkspace = typeof document !== 'undefined'
                ? document.getElementById('fullscreen-box')
                : null;
            const width = readerWorkspace ? readerWorkspace.clientWidth : window.innerWidth;
            return getReaderSidebarCssWidthForWorkspace(width);
        },
        lemmaDisplay() {
            return this._studyBase || this._baseWord || this.word;
        },
        ...mapState({
            type: state => state.vocabularyBox.type,
            word: state => state.vocabularyBox.word,
            phrase: state => state.vocabularyBox.phrase,
            stage: state => state.vocabularyBox.stage,
            inflections: state => state.vocabularyBox.inflections,
            _reading: state => state.vocabularyBox.reading,
            _baseWord: state => state.vocabularyBox.baseWord,
            _studyBase: state => state.vocabularyBox.studyBase,
            _baseWordReading: state => state.vocabularyBox.baseWordReading,
            _phraseReading: state => state.vocabularyBox.phraseReading,
            _translationText: state => state.vocabularyBox.translationText,
            _searchField: state => state.vocabularyBox.searchField,
            positionLeft: state => state.vocabularyBox.positionLeft,
            positionTop: state => state.vocabularyBox.positionTop,
            height: state => state.vocabularyBox.height,
            fsrsFamiliarityPercent: state => state.vocabularyBox.fsrsFamiliarityPercent,
            fsrsFamiliarityLevel10: state => state.vocabularyBox.fsrsFamiliarityLevel10,
            fsrsFamiliarityScore: state => state.vocabularyBox.fsrsFamiliarityScore,
            fsrsFamiliarityHasData: state => state.vocabularyBox.fsrsFamiliarityHasData,
            _chapterId: state => state.vocabularyBox.chapterId,
            _sentenceIndex: state => state.vocabularyBox.sentenceIndex,
            _sentenceText: state => state.vocabularyBox.sentenceText,
            aiVocabSuggestions: state => state.vocabularyBox.aiVocabSuggestions,
            aiPhraseSuggestions: state => state.vocabularyBox.aiPhraseSuggestions,
            aiLookupLoading: state => state.vocabularyBox.aiLookupLoading,
            aiLookupError: state => state.vocabularyBox.aiLookupError,
        }),
    },
    watch: {
        word() {
            this.updateDataFromStore();
            this.loadAiSuggestions();
            // V1-V5 pending feedback state is now owned by <AiStudyCardDesktopWorkflow>.
        },
        phrase() { this.updateDataFromStore(); },
        // Re-trigger AI lookup when sentence changes (new word in same sentence or different sentence)
        '_sentenceIndex'() {
            if (this.$store.state.vocabularyBox.active && this.word) {
                this.loadAiSuggestions();
            }
        },
        // When the add-sense panel expands, auto-expand the unified candidate list
        // so AI suggestions and dictionary rows are visible together.
        showAddSensePanel(val) {
            if (val) {
                this.showDictionaryResults = true;
            }
        },
    },
    data() {
        return {
            tab: 0,
            showDictionaryResults: false,
            showAddSensePanel: false,
            editingLemma: false,
            editLemmaValue: '',
            phraseText: '',
            reading: '',
            baseWord: '',
            studyBase: '',
            baseWordReading: '',
            phraseReading: '',
            translationText: '',
            searchField: '',
            latestAiLookupKey: '',
        };
    },
    methods: {
        updateDataFromStore() {
            this.phraseText = '';
            this.translationText = this._translationText;
            this.reading = this._reading;
            this.baseWord = this._baseWord;
            this.studyBase = this._studyBase;
            this.baseWordReading = this._baseWordReading;
            this.phraseReading = this._phraseReading;
            this.searchField = this._searchField;

            for (let wordIndex = 0; wordIndex < this.$store.state.vocabularyBox.phrase.length; wordIndex++) {
                const word = this.$store.state.vocabularyBox.phrase[wordIndex];
                if (word.word === 'NEWLINE') {
                    continue;
                }
                this.phraseText += word.word;
                if (word.spaceAfter) {
                    this.phraseText += ' ';
                }
            }
        },
        textToSpeech() { this.$emit('textToSpeech'); },
        searchFieldChanged(event) {
            if (event !== '') {
                this.searchField = event;
            }
        },
        setStage(stage) { this.$emit('setStage', stage); },
        addNewPhrase() { this.$emit('addNewPhrase'); },
        deletePhrase() { this.$emit('deletePhrase'); },
        deleteWord() { this.$emit('deleteWord'); },
        startEditLemma() {
            this.editLemmaValue = this.studyBase || this.baseWord || this.word;
            this.editingLemma = true;
            this.$nextTick(() => {
                if (this.$refs.lemmaInput) {
                    this.$refs.lemmaInput.focus();
                    this.$refs.lemmaInput.select();
                }
            });
        },
        saveLemma() {
            if (!this.editingLemma) return;
            const newValue = (this.editLemmaValue || '').trim().toLowerCase();
            if (newValue && newValue !== this.studyBase) {
                this.studyBase = newValue;
                // Update Vuex store so saveWord picks up the new study_base
                this.$store.commit('vocabularyBox/setStudyBase', newValue);
                // Emit to parent so it persists to encountered_word.study_base
                this.$emit('updateVocabBoxData', {
                    reading: this.reading,
                    baseWord: this.baseWord,
                    studyBase: newValue,
                    baseWordReading: this.baseWordReading,
                    phraseReading: this.phraseReading,
                    translationText: this.translationText
                });
                // Trigger save to persist immediately
                this.$emit('saveWord', true);
            }
            this.editingLemma = false;
        },
        cancelEditLemma() {
            this.editingLemma = false;
            this.editLemmaValue = '';
        },
        addDefinitionToInput(definition) {
            if (this.translationText.length && this.translationText[this.translationText.length - 1] !== ';') {
                this.translationText += ';';
            }
            this.translationText += definition;
            this.inputChanged('translation');
        },
        addDefinitionAsSense(payload) {
            this.showAddSensePanel = true;
            if (this.$refs.wordSensesList && this.$refs.wordSensesList.openAddFormFromDictionary) {
                this.$refs.wordSensesList.openAddFormFromDictionary(payload);
            }
        },
        inputChanged(inputName = '') {
            this.$emit('updateVocabBoxData', {
                reading: this.reading,
                baseWord: this.baseWord,
                baseWordReading: this.baseWordReading,
                phraseReading: this.phraseReading,
                translationText: this.translationText
            });

        },
        loadAiSuggestions() {
            // AI suggestion panel manages its own expanded state via :key="word" reset

            const context = buildAiSuggestionLookupContext({
                chapterId: this.$store.state.vocabularyBox.chapterId,
                sentenceIndex: this.$store.state.vocabularyBox.sentenceIndex,
                word: this.word,
                studyBase: this.studyBase,
                storeStudyBase: this._studyBase,
                baseWord: this._baseWord,
            });
            if (!context) {
                this.latestAiLookupKey = '';
                this.$store.commit('vocabularyBox/setAiLookupError', '');
                this.$store.commit('vocabularyBox/setAiLookupLoading', false);
                this.$store.commit('vocabularyBox/setAiVocabSuggestions', []);
                this.$store.commit('vocabularyBox/setAiPhraseSuggestions', []);
                return;
            }
            const lookupKey = buildAiSuggestionLookupKey(context);
            this.latestAiLookupKey = lookupKey;
            this.$store.commit('vocabularyBox/setAiLookupLoading', true);
            this.$store.commit('vocabularyBox/setAiLookupError', '');
            fetchAiSuggestions(axios, context).then((result) => {
                if (this.latestAiLookupKey !== lookupKey) {
                    return;
                }
                this.$store.commit('vocabularyBox/setAiVocabSuggestions', result.vocabularySuggestions);
                this.$store.commit('vocabularyBox/setAiPhraseSuggestions', result.phraseSuggestions);
                if (hasAiSuggestions(result)) {
                    this.showAddSensePanel = true;
                }
            }).catch(() => {
                if (this.latestAiLookupKey !== lookupKey) {
                    return;
                }
                this.$store.commit('vocabularyBox/setAiLookupError', '无法读取 AI 建议。');
                this.$store.commit('vocabularyBox/setAiVocabSuggestions', []);
                this.$store.commit('vocabularyBox/setAiPhraseSuggestions', []);
            }).finally(() => {
                if (this.latestAiLookupKey !== lookupKey) {
                    return;
                }
                this.$store.commit('vocabularyBox/setAiLookupLoading', false);
            });
        },
        openManualAddForm() {
            this.showAddSensePanel = true;
            if (this.$refs.wordSensesList && this.$refs.wordSensesList.openAddForm) {
                this.$refs.wordSensesList.openAddForm();
                this.$nextTick(() => {
                    const el = this.$refs.wordSensesList.$el.querySelector('.sense-form');
                    if (el && el.scrollIntoView) el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                });
            }
        },
        useAiSuggestion(vi) {
            this.showAddSensePanel = true;
            if (this.$refs.wordSensesList && this.$refs.wordSensesList.openAddFormFromAi) {
                this.$refs.wordSensesList.openAddFormFromAi(buildAiVocabSensePayload(vi));
            }
        },
        useAiPhraseSuggestion(pi) {
            this.showAddSensePanel = true;
            if (this.$refs.wordSensesList && this.$refs.wordSensesList.openAddFormFromAi) {
                this.$refs.wordSensesList.openAddFormFromAi(buildAiPhraseSensePayload(pi));
            }
        },
        addSelectedWordToAnki() { this.$emit('addSelectedWordToAnki'); },
        close() { this.$emit('unselectAllWords'); }
    }
}
</script>
