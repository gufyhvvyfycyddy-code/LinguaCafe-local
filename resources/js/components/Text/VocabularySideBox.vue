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
            'width': '400px',
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
                            <div class="text-h6 default-font mb-1">{{ word }}</div>
                            <div class="text-caption text--secondary">
                                当前词形：<strong class="default-font">{{ word }}</strong>
                                <span class="mx-2">词元：
                                    <strong v-if="!editingLemma" class="default-font">{{ studyBase || baseWord || word }}</strong>
                                    <span v-if="!editingLemma" class="lemma-edit-link ml-1" @click="startEditLemma">[修改]</span>
                                    <span v-if="editingLemma" class="lemma-edit-inline">
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
                                </span>
                            </div>
                        </div>
                        <v-spacer />
                        <v-btn v-if="$props.textToSpeechAvailable" icon title="发音" @click="textToSpeech"><v-icon>mdi-bullhorn</v-icon></v-btn>
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
                    <div class="vocab-box-subheader d-flex mb-2">普通词汇状态</div>
                    <div id="vocab-box-stage-buttons" class="mb-2">
                        <v-btn v-for="stageNumber in [-7,-6,-5,-4,-3,-2,-1]" :key="stageNumber" :class="{'v-btn--active': stage == stageNumber}" @click="setStage(stageNumber)">{{ stageNumber * -1 }}</v-btn>
                        <v-btn :class="{'v-btn--active': stage == 0}" @click="setStage(0)"><v-icon small>mdi-check</v-icon></v-btn>
                        <v-btn :class="{'v-btn--active': stage == 1}" @click="setStage(1)" v-if="type == 'word'"><v-icon small>mdi-close</v-icon></v-btn>
                    </div>
                    <div v-if="type == 'word'" class="d-flex flex-wrap mb-3">
                        <v-btn small rounded depressed color="warning" class="mr-2 mb-2" @click="setStage(1)">忽略</v-btn>
                        <v-btn small rounded depressed color="success" class="mr-2 mb-2" @click="setStage(0)">标为已知</v-btn>
                        <v-btn small rounded depressed color="error" class="mb-2" @click="deleteWord">删除词条</v-btn>
                    </div>
                </template>

                <div class="vocab-box-subheader d-flex align-center" @click="showLegacyTranslation = !showLegacyTranslation" style="cursor:pointer;">
                    <span>旧词条释义（兼容）</span>
                    <v-spacer />
                    <v-icon small :color="showLegacyTranslation ? 'primary' : ''">{{ showLegacyTranslation ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                </div>
                <v-textarea v-if="showLegacyTranslation" class="mb-2 mt-1" placeholder="旧词条释义（兼容，不推荐使用此编辑入口）" title="旧词条释义" filled dense no-resize rounded hide-details height="80" v-model="translationText" @keyup="inputChanged('translation')" @keydown.stop=";" />
                <v-text-field placeholder="词典搜索" class="dictionary-search-field default-font mt-2 mb-3" width="100%" prepend-inner-icon="mdi-magnify" filled dense rounded hide-details :value="searchField" @change="searchFieldChanged" @keydown.stop=";" />

                <div class="vocab-box-subheader d-flex mt-3">词典结果</div>
                <vocabulary-search-box v-if="type !== 'empty'" :any-api-dictionary-enabled="$props.anyApiDictionaryEnabled" :language="$props.language" :searchTerm="searchField" @addDefinitionToInput="addDefinitionToInput" @addDefinitionAsSense="addDefinitionAsSense" />

                <word-senses-list ref="wordSensesList" v-if="type === 'word'" :study-base="studyBase" :base-word="baseWord" :lemma="baseWord || word" :surface="word" :word="word" :language="$props.language" :legacy-translation="translationText" @word-learning-updated="$emit('word-learning-updated', $event)" />

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

export default {
    components: {
        WordSensesList,
    },
    props: {
        language: String,
        autoHighlightWords: Boolean,
        anyApiDictionaryEnabled: Boolean,
        textToSpeechAvailable: Boolean,
    },
    computed: mapState({
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
    }),
    watch: {
        word() { this.updateDataFromStore(); },
        phrase() { this.updateDataFromStore(); },
    },
    data() {
        return {
            tab: 0,
            showLegacyTranslation: false,
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

            if (inputName == 'translation' && this.$store.state.vocabularyBox.stage >= 0 && this.$props.autoHighlightWords && this.translationText !== '') {
                this.setStage(-7);
            }
        },
        addSelectedWordToAnki() { this.$emit('addSelectedWordToAnki'); },
        close() { this.$emit('unselectAllWords'); }
    }
}
</script>
