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
        <!-- Vocab box content -->
        <v-alert id="no-word-selected-title" prominent color="foreground" class="text--text" v-if="type == 'empty'">
            请选择一个单词或短语
        </v-alert>

        <!-- Toolbar -->
        <div class="pa-0 w-full" v-if="type !== 'empty'">
            <!-- Word/phrase info -->
            <div class="vocab-box-subheader d-flex mb-2">
                <span id="vocab-side-box-title" v-if="type == 'new-phrase'">新短语</span>
                <span id="vocab-side-box-title" v-else>{{ type === 'word' ? '单词' : '短语' }}</span>
                <v-spacer />

                <!-- Inflections table button -->
                <v-btn 
                    v-if="tab == 0 && inflections.length"
                    icon
                    title="显示变形"
                    @click="tab = 1;"
                >
                    <v-icon>mdi-list-box</v-icon>
                </v-btn>

                <v-btn 
                    v-if="tab == 0 && $props.textToSpeechAvailable"
                    icon
                    title="朗读"
                    @click="textToSpeech"
                >
                    <v-icon>mdi-bullhorn</v-icon>
                </v-btn>

                <!-- Send to Anki button -->
                <v-btn 
                    v-if="tab == 0 && type !== 'new-phrase'"
                    icon
                    title="发送到 Anki"
                    @mouseup.stop="addSelectedWordToAnki"
                >
                    <v-icon>mdi-cards</v-icon>
                </v-btn>

                <!-- Back button -->
                <v-btn 
                    v-if="tab == 1"
                    icon
                    title="返回单词"
                    @click="tab = 0;"
                >
                    <v-icon>mdi-arrow-left</v-icon>
                </v-btn>

                <!-- Unselect word button -->
                <v-btn dark icon title="取消选择" @click="close">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </div>
        </div> 

        <v-tabs-items v-model="tab" v-if="type !== 'empty'">
            <!-- Word/phrase tab -->
            <v-tab-item :value="0" class="sidebar-tab">
                <!-- Word text fields -->
                <div class="d-flex" v-if="type == 'word'">
                    <v-text-field 
                        :class="{'default-font': true, 'mt-2': true, 'mb-2': ($props.language !== 'japanese' && $props.language !== 'chinese')}"
                        hide-details
                        placeholder="词元"
                        title="词元"
                        filled
                        dense
                        rounded
                        v-model="baseWord"
                        @keyup="inputChanged"
                        @keydown.stop=";"
                    ></v-text-field>
                    <v-icon class="mt-1 mx-1">mdi-arrow-right</v-icon>
                    <v-text-field 
                        :class="{'default-font': true, 'mt-2': true, 'mb-2': ($props.language !== 'japanese' && $props.language !== 'chinese')}"
                        hide-details
                        placeholder="单词"
                        title="单词"
                        disabled
                        filled
                        dense
                        rounded
                        :value="word"
                        @keyup="inputChanged"
                        @keydown.stop=";"
                    ></v-text-field>
                </div>

                <!-- Reading fields -->
                <div class="d-flex" v-if="type == 'word' && ($props.language == 'japanese' || $props.language == 'chinese')">
                    <v-text-field 
                        class="default-font my-2"
                        hide-details
                        placeholder="词元读音"
                        title="词元读音"
                        filled
                        dense
                        rounded
                        v-model="baseWordReading"
                        @keyup="inputChanged"
                        @keydown.stop=";"
                    ></v-text-field>
                    <v-icon class="mt-1 mx-1">mdi-arrow-right</v-icon>
                    <v-text-field 
                        class="default-font my-2"
                        hide-details
                        placeholder="读音"
                        title="读音"
                        filled
                        dense
                        rounded
                        v-model="reading"
                        @keyup="inputChanged"
                        @keydown.stop=";"
                    ></v-text-field>
                </div>

                <!-- Phrase text -->
                <v-textarea
                    v-if="type !== 'word'"
                    class="default-font my-2"
                    label="短语"
                    filled
                    dense
                    no-resize
                    rounded
                    hide-details
                    height="80"
                    disabled
                    :value="phraseText"
                    @keydown.stop=";"
                ></v-textarea>

                <!-- Phrase reading -->
                <v-textarea
                    v-if="type !== 'word' && ($props.language == 'japanese' || $props.language == 'chinese')"
                    class="default-font my-2"
                    label="读音"
                    filled
                    dense
                    no-resize
                    rounded
                    hide-details
                    height="80"
                    v-model="reading"
                    @keyup="inputChanged"
                    @keydown.stop=";"
                ></v-textarea>
                
                <!-- Stage buttons-->
                <template v-if="type !== 'new-phrase'">
                    <div id="vocab-box-stage-buttons" class="mb-2">
                        <v-btn :class="{'v-btn--active': stage == -7}" @click="setStage(-7)">7</v-btn>
                        <v-btn :class="{'v-btn--active': stage == -6}" @click="setStage(-6)">6</v-btn>
                        <v-btn :class="{'v-btn--active': stage == -5}" @click="setStage(-5)">5</v-btn>
                        <v-btn :class="{'v-btn--active': stage == -4}" @click="setStage(-4)">4</v-btn>
                        <v-btn :class="{'v-btn--active': stage == -3}" @click="setStage(-3)">3</v-btn>
                        <v-btn :class="{'v-btn--active': stage == -2}" @click="setStage(-2)">2</v-btn>
                        <v-btn :class="{'v-btn--active': stage == -1}" @click="setStage(-1)">1</v-btn>
                        <v-btn 
                            :class="{'v-btn--active': stage == 0}"
                            @click="setStage(0)" 
                        >
                            <v-icon small>mdi-check</v-icon>
                        </v-btn>
                        <v-btn 
                            :class="{'v-btn--active': stage == 1}" 
                            @click="setStage(1)" 
                            v-if="type == 'word'"
                        >
                            <v-icon small>mdi-close</v-icon>
                        </v-btn>
                    </div>
                </template>
                
                <!-- Translation -->
                <div class="vocab-box-subheader d-flex">
                    释义
                </div>
                <v-textarea
                    class="mb-2 mt-1"
                    placeholder="释义"
                    title="释义"
                    filled
                    dense
                    no-resize
                    rounded
                    hide-details
                    height="100"
                    v-model="translationText"
                    @keyup="inputChanged('translation')"
                    @keydown.stop=";"
                ></v-textarea>

                <!-- Search field -->
                <v-text-field 
                    placeholder="词典搜索"
                    class="dictionary-search-field default-font mt-2 mb-3"
                    width="100%"
                    prepend-inner-icon="mdi-magnify"
                    filled
                    dense
                    rounded
                    hide-details
                    :value="searchField"
                    @change="searchFieldChanged"
                    @keydown.stop=";"
                ></v-text-field>

                <!-- Search box -->
                <vocabulary-search-box
                    v-if="type !== 'empty'"
                    :any-api-dictionary-enabled="$props.anyApiDictionaryEnabled"
                    :language="$props.language"
                    :searchTerm="searchField"
                    @addDefinitionToInput="addDefinitionToInput"
                ></vocabulary-search-box>

                <div v-if="type !== 'word'" class="d-flex mt-2 pl-0">
                    <v-spacer />
                    <v-btn 
                        small
                        rounded
                        color="success"
                        @click="addNewPhrase"
                        v-if="type == 'new-phrase'"
                    >保存短语</v-btn>
                    <v-btn 
                        small
                        rounded
                        color="error"
                        @click="deletePhrase"
                        v-if="type == 'phrase'"
                    >删除短语</v-btn>
                </div>
            </v-tab-item>
            
            <!-- Inflections tab -->
            <v-tab-item :value="1">
                <v-simple-table
                    v-if="inflections.length"
                    class="border rounded-lg no-hover mx-auto default-font" 
                >
                    <thead>
                        <tr>
                            <th class="text-center">形式</th>
                            <th class="text-center">肯定</th>
                            <th class="text-center">否定</th>
                        </tr>
                    </thead>
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
    
    export default {
        props: {
            language: String,
            autoHighlightWords: Boolean,
            anyApiDictionaryEnabled: Boolean,
            textToSpeechAvailable: Boolean,
        },
        computed: mapState({
            active: state => state.vocabularyBox.active,
            type: state => state.vocabularyBox.type,
            word: state => state.vocabularyBox.word,
            phrase: state => state.vocabularyBox.phrase,
            stage: state => state.vocabularyBox.stage,
            inflections: state => state.vocabularyBox.inflections,
            _reading: state => state.vocabularyBox.reading,
            _baseWord: state => state.vocabularyBox.baseWord,
            _baseWordReading: state => state.vocabularyBox.baseWordReading,
            _phraseReading: state => state.vocabularyBox.phraseReading,
            _translationText: state => state.vocabularyBox.translationText,
            _searchField: state => state.vocabularyBox.searchField,
            positionLeft: state => state.vocabularyBox.positionLeft,
            positionTop: state => state.vocabularyBox.positionTop,
            height: state => state.vocabularyBox.height,
        }),
        watch: {
            word: function () {
                this.updateDataFromStore();
            },
            phrase: function () {
                this.updateDataFromStore();
            },
        },
        data: function() {
            return {
                tab: 0,

                //temp, to be reviewed
                phraseCurrentlySaving: false,

                // data for word
                phraseText: '',
                reading: '',
                baseWord: '',
                baseWordReading: '',
                phraseReading: '',

                // data for both
                translationText: '',

                // ui data
                tab: 0,
                searchField: '',
                searchResults: [],
            };
        },
        mounted: function() {
            
        },
        methods: {
            updateDataFromStore() {
                this.phraseCurrentlySaving = false;
                this.phraseText = '';

                this.translationText = this._translationText;
                this.reading = this._reading;
                this.baseWord = this._baseWord;
                this.baseWordReading = this._baseWordReading;
                this.phraseReading = this._phraseReading;
                this.searchField = this._searchField;

                // generate phrase text
                for (let wordIndex = 0; wordIndex < this.$store.state.vocabularyBox.phrase.length; wordIndex++) {
                    if (this.$store.state.vocabularyBox.phrase[wordIndex].word === 'NEWLINE') {
                        continue;
                    }
                    
                    this.phraseText += this.$store.state.vocabularyBox.phrase[wordIndex].word;

                    if (this.$store.state.vocabularyBox.phrase[wordIndex].spaceAfter) {
                        this.phraseText += ' ';
                    }
                }
            },
            textToSpeech() {
                this.$emit('textToSpeech');
            },
            searchFieldChanged(event) {
                if (event === '') {
                    return;
                }

                this.searchField = event;
            },
            setStage(stage) {
                this.$emit('setStage', stage);
            },
            openKanji(kanji) {
                window.location.href = '/kanji/' + kanji;
            },
            addNewPhrase() {
                this.$emit('addNewPhrase');
            },
            deletePhrase() {
                this.$emit('deletePhrase');
            },
            addDefinitionToInput(definition) {
                if (this.translationText.length && this.translationText[this.translationText.length - 1] !== ';') {
                    this.translationText += ';';
                }

                this.translationText += definition;
                this.inputChanged('translation');
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
            unselectAllWords() {
                this.$emit('unselectAllWords');
            },
            addSelectedWordToAnki() {
                this.$emit('addSelectedWordToAnki');
            },
            close() {
                this.$emit('unselectAllWords');
            }
        }
    }
</script>
