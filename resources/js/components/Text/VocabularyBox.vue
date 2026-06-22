<template>
    <v-card 
        id="vocab-box" 
        :class="{
            'new-phrase': type === 'new-phrase', 
            'rounded-lg': true,
            'd-flex': true
        }" 
        :style="{
            'top': positionTop + 'px', 
            'left': positionLeft + 'px',
            'width': width + 'px'
        }"
        @mouseup.stop=";"
    >   
        <!-- Vocab box content -->
        <div class="vocab-box-content pa-4 pb-1">
            <v-tabs-items v-model="tab">
                <!-- Word info page -->
                <v-tab-item :value="0">
                    <v-card-text class="pa-0">
                        <!-- Single word -->
                        <template v-if="type === 'word'">
                            <div class="vocab-box-subheader mb-2 mt-0"><span class="rounded-pill py-1 px-3">单词</span></div>
                            <!-- With base word -->
                            <div class="expression mb-2 text-center default-font" v-if="baseWord !== ''">
                                <ruby>
                                    {{ baseWord }}
                                    <rt v-if="($props.language == 'japanese' || $props.language == 'chinese')">
                                        {{ baseWordReading }}
                                    </rt>
                                </ruby>
                                <v-icon color="text">mdi-arrow-right-thick</v-icon>
                                <ruby>
                                    {{ word }}
                                    <rt v-if="($props.language == 'japanese' || $props.language == 'chinese')">
                                        {{ reading}}
                                    </rt>
                                </ruby>
                            </div>
                            
                            <!-- No base word -->
                            <div 
                                class="expression mb-2 text-center default-font" 
                                v-if="baseWord == ''"
                            >
                                <ruby>
                                    {{ word }}
                                    <rt v-if="($props.language == 'japanese' || $props.language == 'chinese')">
                                        {{ reading }}
                                    </rt>
                                </ruby>
                            </div>
                        </template>

                        <!-- Phrase -->
                        <template v-if="type !== 'word'">
                            <div class="vocab-box-subheader mb-2 mt-0"><span class="rounded-pill py-1 px-3">短语</span></div>
                            <!-- Phrase text -->
                            <div class="expression mb-2 default-font">
                                <template v-for="(word, index) in phrase" v-if="word.word !== 'NEWLINE'">
                                    <span :class="{'mr-2': word.spaceAfter}">{{ word.word }}</span>
                                </template>
                            </div>

                            <!-- Phrase reading -->
                            <template v-if="($props.language == 'japanese' || $props.language == 'chinese')">
                                <div class="vocab-box-subheader mb-2 mt-4"><span class="rounded-pill py-1 px-3">读音</span></div>
                                <div class="expression mb-2 mt-4 default-font">{{ reading }}</div>
                            </template>
                        </template>

                        <!-- Stage buttons-->
                        <template v-if="type !== 'new-phrase'">
                            <div class="vocab-box-subheader d-flex mb-2 mt-4">
                                <span class="rounded-pill py-1 px-3">等级</span>
                                <v-spacer />

                                <!-- Level info box -->
                                <v-menu offset-y left nudge-top="-12px">
                                    <template v-slot:activator="{ on, attrs }">
                                        <div>
                                            <v-icon class="mr-2" v-bind="attrs" v-on="on">mdi-help-circle-outline</v-icon>
                                        </div>
                                    </template>
                                    <v-card outlined class="rounded-lg pa-4" width="320px">
                                        单词或短语的等级表示你对它的熟悉程度。
                                        越接近 0，表示越接近学会，在复习中出现得也越少。<br><br>

                                        <v-icon class="mr-2">mdi-check</v-icon>
                                        表示已知词。<br>
                                        <v-icon class="mr-2">mdi-close</v-icon>
                                        表示已忽略词。已忽略词不会计入已学词统计。
                                    </v-card>
                                </v-menu>
                            </div>
                            <div id="vocab-box-stage-buttons" class="mb-4">
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
                                    <v-icon>mdi-check</v-icon>
                                </v-btn>
                                <v-btn 
                                    :class="{'v-btn--active': stage == 1}" 
                                    @click="setStage(1)" 
                                    v-if="type == 'word'"
                                >
                                    <v-icon>mdi-close</v-icon>
                                </v-btn>
                            </div>
                            <div v-if="type == 'word'" class="d-flex flex-wrap mb-3">
                                <v-btn small rounded depressed color="warning" class="mr-2 mb-2" @click="setStage(1)">忽略</v-btn>
                                <v-btn small rounded depressed color="success" class="mr-2 mb-2" @click="setStage(0)">标为已知</v-btn>
                                <v-btn small rounded depressed color="error" class="mb-2" @click="deleteWord">删除词条</v-btn>
                            </div>
                        </template>
                      
                        <!-- Translation (legacy) -->
                        <div class="vocab-box-subheader d-flex align-center mt-2" @click="showLegacyTranslation = !showLegacyTranslation" style="cursor:pointer;">
                            <span>旧词条释义（兼容）</span>
                            <v-spacer />
                            <v-icon small :color="showLegacyTranslation ? 'primary' : ''">{{ showLegacyTranslation ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                        </div>
                        <v-textarea
                            v-if="showLegacyTranslation"
                            :class="{'mt-2': $props.language !== 'japanese' && $props.language !== 'chinese'}"
                            label="旧词条释义（兼容，不推荐使用此编辑入口）"
                            filled
                            dense
                            no-resize
                            rounded
                            hide-details
                            height="80"
                            v-model="translationText"
                            @keyup="inputChanged('translation')"
                            @keydown.stop=";"
                        ></v-textarea>

                        <!-- Search field -->
                        <v-text-field 
                            placeholder="词典搜索"
                            class="dictionary-search-field mt-2 mb-3 default-font"
                            filled
                            dense
                            rounded
                            width="100%"
                            hide-details
                            prepend-inner-icon="mdi-magnify"
                            :value="searchField"
                            @change="searchFieldChanged"
                            @keydown.stop=";"
                        ></v-text-field>

                        <div class="vocab-box-subheader d-flex mt-3">词典结果</div>
                        <!-- Search box -->
                        <vocabulary-search-box
                            :any-api-dictionary-enabled="$props.anyApiDictionaryEnabled"
                            :language="$props.language"
                            :searchTerm="searchField"
                            @addDefinitionToInput="addDefinitionToInput"
                            @addDefinitionAsSense="addDefinitionAsSense"
                        ></vocabulary-search-box>

                        <!-- Saved word senses -->
                        <word-senses-list ref="wordSensesList" v-if="type === 'word'" :study-base="studyBase" :base-word="baseWord" :lemma="baseWord || word" :surface="word" :word="word" :language="$props.language" :legacy-translation="translationText" @word-learning-updated="$emit('word-learning-updated', $event)" />
                    </v-card-text>

                    <v-card-actions v-if="type !== 'word'" class="mt-2 pl-0">
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
                    </v-card-actions>
                </v-tab-item>

                <!-- Editing page -->
                <v-tab-item :value="1">
                    <v-card-text id="vocab-box-edit-page" class="pa-0">
                        <!-- Word text fields -->
                        <div class="d-flex" v-if="type == 'word'">
                            <v-text-field 
                                :class="{'default-font': true, 'mt-2': true, 'mb-2': ($props.language !== 'japanese' && $props.language !== 'chinese')}"
                                hide-details
                                label="词元"
                                filled
                                dense
                                rounded
                                v-model="baseWord"
                                @keyup="inputChanged"
                                @keydown.stop=";"
                            ></v-text-field>
                            <v-text-field 
                                :class="{'default-font': true, 'mt-2': true, 'mb-2': ($props.language !== 'japanese' && $props.language !== 'chinese')}"
                                hide-details
                                label="单词"
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
                                class="my-2 default-font"
                                hide-details
                                label="词元读音"
                                filled
                                dense
                                rounded
                                v-model="baseWordReading"
                                @keyup="inputChanged"
                                @keydown.stop=";"
                            ></v-text-field>
                            <v-text-field 
                                class="my-2 default-font"
                                hide-details
                                label="读音"
                                filled
                                dense
                                rounded
                                v-model="reading"
                                @keyup="inputChanged"
                                @keydown.stop=";"
                            ></v-text-field>
                        </div>

                        <!-- Phrase fields -->
                        <v-textarea
                            v-if="type !== 'word' && ($props.language == 'japanese' || $props.language == 'chinese')"
                            class="my-2 default-font"
                            label="读音"
                            filled
                            dense
                            no-resize
                            rounded
                            hide-details
                            height="100"
                            v-model="reading"
                            @keyup="inputChanged"
                            @keydown.stop=";"
                        ></v-textarea>
                    </v-card-text>
                </v-tab-item>

                <!-- Inflections tab -->
                <v-tab-item :value="2">
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
        </div>

        <!-- Vocab box toolbar -->
        <div class="vocab-box-toolbar d-flex flex-column align-center flex-wrap pt-1 rounded-r-lg">
            <v-btn icon @click="close" title="关闭"><v-icon>mdi-close</v-icon></v-btn>
            <v-btn icon @click="tab = 1;" title="编辑" v-if="tab == 0"><v-icon>mdi-pencil</v-icon></v-btn>
            <v-btn icon @click="addSelectedWordToAnki" v-if="tab === 0 && type !== 'new-phrase'" title="发送到 Anki"><v-icon class="mr-1">mdi-cards</v-icon></v-btn>
            <v-btn icon v-if="tab == 0 && $props.textToSpeechAvailable" title="朗读" @click="textToSpeech"><v-icon>mdi-bullhorn</v-icon></v-btn>
            <v-btn icon @click="tab = 2;" title="显示变形" v-if="tab == 0 && inflections.length"><v-icon>mdi-list-box</v-icon></v-btn>
            <v-btn icon @click="tab = 0;" v-if="tab !== 0" title="返回"><v-icon>mdi-arrow-left</v-icon></v-btn>
        </div>
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
            autoHighlightWords: Boolean,
            language: String,
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
            _studyBase: state => state.vocabularyBox.studyBase,
            _baseWordReading: state => state.vocabularyBox.baseWordReading,
            _phraseReading: state => state.vocabularyBox.phraseReading,
            _translationText: state => state.vocabularyBox.translationText,
            _searchField: state => state.vocabularyBox.searchField,
            positionLeft: state => state.vocabularyBox.positionLeft,
            positionTop: state => state.vocabularyBox.positionTop,
            width: state => state.vocabularyBox.width,
            height: state => state.vocabularyBox.height,
        }),
        data: function() {
            return {
                // data for word
                reading: '',
                baseWord: '',
                studyBase: '',
                baseWordReading: '',
                phraseReading: '',

                // data for both
                translationText: '',
                translationList: [],

                // ui data
                tab: 0,
                showLegacyTranslation: false,
                searchField: '',
                searchResults: [],
            };
        },
        mounted: function() {
            this.updateDataFromStore();
        },
        methods: {
            updateDataFromStore() {
                this.translationText = this._translationText;
                this.reading = this._reading;
                this.baseWord = this._baseWord;
                this.studyBase = this._studyBase;
                this.baseWordReading = this._baseWordReading;
                this.phraseReading = this._phraseReading;
                this.searchField = this._searchField;
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
            deleteWord() {
                this.$emit('deleteWord');
            },
            updateVocabBoxTranslationList() {
                this.translationList = this._translationText.split(';');
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
                this.updateVocabBoxTranslationList();

                this.$emit('updateVocabBoxData', {
                    reading: this.reading,
                    baseWord: this.baseWord,
                    baseWordReading: this.baseWordReading,
                    phraseReading: this.phraseReading,
                    translationText: this.translationText
                });

                if (inputName == 'translation' && this.stage >= 0 && this.$props.autoHighlightWords && this.translationText !== '') {
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
