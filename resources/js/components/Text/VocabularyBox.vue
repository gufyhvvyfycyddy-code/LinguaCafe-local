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
                                    {{ word }}
                                    <rt v-if="($props.language == 'japanese' || $props.language == 'chinese')">
                                        {{ reading }}
                                    </rt>
                                </ruby>
                                <v-icon color="text">mdi-arrow-right-thick</v-icon>
                                <ruby>
                                    {{ baseWord }}
                                    <rt v-if="($props.language == 'japanese' || $props.language == 'chinese')">
                                        {{ baseWordReading }}
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
                                <v-btn small rounded depressed color="error" class="mr-2 mb-2" @click="deleteWord">回归为新词</v-btn>
                                <v-btn
                                    small
                                    rounded
                                    outlined
                                    color="primary"
                                    class="mb-2"
                                    :loading="aiStudyCardPendingLoading"
                                    @click="markAiStudyCardPending"
                                >
                                    <v-icon x-small class="mr-1">mdi-lightbulb-outline</v-icon>
                                    待 AI 解释
                                </v-btn>
                                <v-btn
                                    small
                                    rounded
                                    depressed
                                    color="info"
                                    class="mb-2"
                                    @click="openAiPendingListDialog"
                                >
                                    <v-icon x-small class="mr-1">mdi-format-list-bulleted</v-icon>
                                    待 AI 解释列表
                                </v-btn>
                            </div>
                            <v-alert
                                v-if="aiStudyCardPendingMessage"
                                dense
                                text
                                class="mb-3"
                                :type="aiStudyCardPendingError ? 'error' : 'success'"
                            >{{ aiStudyCardPendingMessage }}</v-alert>
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

                        <div class="vocab-box-subheader d-flex mt-3">候选结果（AI + 词典）</div>
                        <!-- Search box -->
                        <vocabulary-search-box
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

        <!-- V2: 待 AI 解释列表面板 -->
        <v-dialog v-model="aiPendingListDialog" max-width="640" scrollable>
            <v-card>
                <v-card-title class="d-flex align-center">
                    <v-icon small class="mr-2">mdi-format-list-bulleted</v-icon>
                    待 AI 解释的词
                    <v-spacer />
                    <v-btn icon small @click="aiPendingListDialog = false"><v-icon>mdi-close</v-icon></v-btn>
                </v-card-title>
                <v-card-text style="max-height: 60vh;">
                    <v-alert
                        v-if="aiPendingListError"
                        dense
                        text
                        type="error"
                        class="mt-2"
                    >{{ aiPendingListError }}</v-alert>
                    <v-alert
                        v-if="aiPendingListMessage"
                        dense
                        text
                        type="success"
                        class="mt-2"
                    >{{ aiPendingListMessage }}</v-alert>

                    <div v-if="aiPendingListLoading" class="text-center pa-4">
                        <v-progress-circular indeterminate color="primary" />
                    </div>

                    <div v-else-if="aiPendingItems.length === 0" class="text-center text--secondary pa-4">
                        暂无待 AI 解释的词。
                    </div>

                    <v-list v-else dense>
                        <v-list-item v-for="item in aiPendingItems" :key="item.id" class="px-0">
                            <v-list-item-content>
                                <v-list-item-title class="d-flex align-center">
                                    <span class="font-weight-medium default-font">{{ item.word }}</span>
                                    <span v-if="item.lemma && item.lemma !== item.word" class="text-caption text--secondary ml-2">({{ item.lemma }})</span>
                                </v-list-item-title>
                                <v-list-item-subtitle v-if="item.sentence_text" class="text-caption text--secondary mt-1" style="white-space: normal; line-height: 1.4;">
                                    {{ item.sentence_text }}
                                </v-list-item-subtitle>
                                <v-list-item-subtitle class="text-caption text--secondary mt-1">
                                    状态：{{ item.status === 'pending' ? '待解释' : item.status }}
                                    <span class="ml-2">| 添加于 {{ formatDate(item.created_at) }}</span>
                                </v-list-item-subtitle>
                            </v-list-item-content>
                            <v-list-item-action>
                                <v-btn
                                    x-small
                                    rounded
                                    depressed
                                    color="error"
                                    :loading="aiPendingDismissLoadingId === item.id"
                                    @click="dismissAiPendingItem(item.id)"
                                >
                                    <v-icon x-small class="mr-1">mdi-close</v-icon>
                                    取消
                                </v-btn>
                            </v-list-item-action>
                        </v-list-item>
                    </v-list>
                </v-card-text>
                <v-card-actions class="d-flex flex-column align-stretch pa-3">
                    <v-btn
                        block
                        color="primary"
                        :disabled="aiPendingItems.length === 0"
                        @click="openAiStudyCardPreview"
                    >
                        <v-icon small class="mr-1">mdi-rocket-launch</v-icon>
                        生成 AI 示意卡
                    </v-btn>
                    <div class="text-caption text--secondary text-center mt-2">
                        当前共 {{ aiPendingItems.length }} 个待解释词
                    </div>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- V2: 生成 AI 示意卡预览弹窗雏形 -->
        <v-dialog v-model="aiStudyCardPreviewDialog" max-width="720" scrollable>
            <v-card>
                <v-card-title class="d-flex align-center">
                    <v-icon small class="mr-2">mdi-rocket-launch</v-icon>
                    生成 AI 示意卡预览
                    <v-spacer />
                    <v-btn icon small @click="aiStudyCardPreviewDialog = false"><v-icon>mdi-close</v-icon></v-btn>
                </v-card-title>
                <v-card-text style="max-height: 65vh;">
                    <v-alert
                        dense
                        text
                        type="info"
                        class="mt-2"
                    >
                        当前只是预览，不会调用 AI，也不会生成复习卡。
                    </v-alert>

                    <div class="mt-4">
                        <div class="text-subtitle-1 font-weight-medium mb-2">
                            <v-icon x-small class="mr-1">mdi-account-check</v-icon>
                            你已选的词（自动进入生成范围）
                        </div>
                        <div v-if="aiPendingItems.length === 0" class="text-caption text--secondary pa-3 rounded" style="border: 1px dashed var(--v-gray2-base);">
                            暂无已选词。请先在阅读页点词并加入「待 AI 解释」。
                        </div>
                        <div v-else class="d-flex flex-wrap">
                            <v-chip
                                v-for="item in aiPendingItems"
                                :key="item.id"
                                small
                                color="primary"
                                text-color="white"
                                class="ma-1"
                            >
                                <v-icon x-small class="mr-1">mdi-check</v-icon>
                                {{ item.word }}
                            </v-chip>
                        </div>
                    </div>

                    <div class="mt-5">
                        <div class="text-subtitle-1 font-weight-medium mb-2">
                            <v-icon x-small class="mr-1">mdi-robot</v-icon>
                            AI 推荐词（下一阶段由 AI 推荐）
                        </div>
                        <div class="text-caption text--secondary pa-3 rounded" style="border: 1px dashed var(--v-gray2-base);">
                            <v-icon x-small class="mr-1">mdi-clock-outline</v-icon>
                            下一阶段开放。本轮不会请求 AI 推荐。
                        </div>
                        <div class="text-caption text--secondary mt-2">
                            规则预览：AI 推荐词默认不选；不会与你已选的词重复；需你手动确认才会进入生成范围。
                        </div>
                    </div>

                    <div class="mt-5 pa-3 rounded" style="background: var(--v-gray1-base);">
                        <div class="text-caption font-weight-medium mb-1">未来生成规则预览：</div>
                        <ul class="text-caption text--secondary" style="line-height: 1.6;">
                            <li>你已选的词会自动进入生成范围。</li>
                            <li>AI 推荐词默认不选，需手动勾选。</li>
                            <li>AI 推荐词不会与你已选的词重复。</li>
                            <li>只有你确认后，才会真正生成示意卡。</li>
                        </ul>
                    </div>
                </v-card-text>
                <v-card-actions class="d-flex pa-3">
                    <v-btn text @click="aiStudyCardPreviewDialog = false">关闭</v-btn>
                    <v-spacer />
                    <v-btn color="primary" disabled>
                        <v-icon small class="mr-1">mdi-lock</v-icon>
                        确认生成（下一阶段开放）
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-card>
</template>

<script>
    import { mapState } from 'vuex';
    import WordSensesList from './WordSensesList.vue';
    import {
        buildAiSuggestionLookupContext,
        buildAiSuggestionLookupKey,
        fetchAiSuggestions,
        buildAiVocabSensePayload,
        buildAiPhraseSensePayload,
    } from './../../services/VocabularyAiSuggestionService';

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
            _chapterId: state => state.vocabularyBox.chapterId,
            _sentenceIndex: state => state.vocabularyBox.sentenceIndex,
            _sentenceText: state => state.vocabularyBox.sentenceText,
            aiVocabSuggestions: state => state.vocabularyBox.aiVocabSuggestions,
            aiPhraseSuggestions: state => state.vocabularyBox.aiPhraseSuggestions,
            aiLookupLoading: state => state.vocabularyBox.aiLookupLoading,
            aiLookupError: state => state.vocabularyBox.aiLookupError,
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
                latestAiLookupKey: '',
                searchField: '',
                searchResults: [],
                aiStudyCardPendingLoading: false,
                aiStudyCardPendingMessage: '',
                aiStudyCardPendingError: false,
                // V2: 待解释列表面板状态
                aiPendingListDialog: false,
                aiPendingListLoading: false,
                aiPendingListMessage: '',
                aiPendingListError: '',
                aiPendingItems: [],
                aiPendingDismissLoadingId: null,
                // V2: 生成预览弹窗状态
                aiStudyCardPreviewDialog: false,
            };
        },
        watch: {
            word() {
                this.updateDataFromStore();
                this.loadAiSuggestions();
                this.resetAiStudyCardPendingFeedback();
            },
            phrase() {
                this.updateDataFromStore();
            },
            // Re-trigger AI lookup when sentence changes (new word in same sentence or different sentence)
            '_sentenceIndex'() {
                if (this.$store.state.vocabularyBox.active && this.word) {
                    this.loadAiSuggestions();
                }
            },
        },
        mounted: function() {
            this.updateDataFromStore();
            this.loadAiSuggestions();
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
            resetAiStudyCardPendingFeedback() {
                this.aiStudyCardPendingLoading = false;
                this.aiStudyCardPendingMessage = '';
                this.aiStudyCardPendingError = false;
            },
            markAiStudyCardPending() {
                if (this.type !== 'word') {
                    return;
                }

                const chapterId = this.$store.state.vocabularyBox.chapterId;
                const sentenceIndex = this.$store.state.vocabularyBox.sentenceIndex;
                if (!chapterId || sentenceIndex === null || sentenceIndex === undefined || !this.word) {
                    this.aiStudyCardPendingError = true;
                    this.aiStudyCardPendingMessage = '缺少章节或句子位置，暂时无法加入待 AI 解释。';
                    return;
                }

                this.aiStudyCardPendingLoading = true;
                this.aiStudyCardPendingError = false;
                this.aiStudyCardPendingMessage = '';

                axios.post('/ai-study-card/pending-items', {
                    chapter_id: chapterId,
                    text_block_index: sentenceIndex,
                    sentence_index: sentenceIndex,
                    sentence_id: String(sentenceIndex),
                    word: this.word,
                    surface: this.word,
                    lemma: this.baseWord || this.word,
                    sentence_text: this.$store.state.vocabularyBox.sentenceText || '',
                    source_payload: {
                        source: 'reader_vocabulary_box',
                    },
                }).then((response) => {
                    this.aiStudyCardPendingMessage = response.data && response.data.message
                        ? response.data.message
                        : '已加入待 AI 解释。';
                }).catch((error) => {
                    this.aiStudyCardPendingError = true;
                    this.aiStudyCardPendingMessage = error.response && error.response.data && error.response.data.message
                        ? error.response.data.message
                        : '加入待 AI 解释失败。';
                }).finally(() => {
                    this.aiStudyCardPendingLoading = false;
                });
            },
            // V2: 打开待解释列表面板
            openAiPendingListDialog() {
                this.aiPendingListDialog = true;
                this.aiPendingListMessage = '';
                this.aiPendingListError = '';
                this.loadAiPendingItems();
            },
            // V2: 加载待解释项
            loadAiPendingItems() {
                this.aiPendingListLoading = true;
                this.aiPendingListError = '';
                const chapterId = this.$store.state.vocabularyBox.chapterId;
                const params = {};
                if (chapterId) {
                    params.chapter_id = chapterId;
                }
                axios.get('/ai-study-card/pending-items', { params })
                    .then((response) => {
                        const items = response.data && response.data.items ? response.data.items : [];
                        this.aiPendingItems = items;
                    })
                    .catch((error) => {
                        this.aiPendingListError = error.response && error.response.data && error.response.data.message
                            ? error.response.data.message
                            : '加载待解释列表失败。';
                        this.aiPendingItems = [];
                    })
                    .finally(() => {
                        this.aiPendingListLoading = false;
                    });
            },
            // V2: 取消待解释项
            dismissAiPendingItem(itemId) {
                this.aiPendingDismissLoadingId = itemId;
                this.aiPendingListMessage = '';
                this.aiPendingListError = '';
                axios.post(`/ai-study-card/pending-items/${itemId}/dismiss`)
                    .then((response) => {
                        this.aiPendingListMessage = response.data && response.data.message
                            ? response.data.message
                            : '已取消。';
                        this.aiPendingItems = this.aiPendingItems.filter(i => i.id !== itemId);
                    })
                    .catch((error) => {
                        this.aiPendingListError = error.response && error.response.data && error.response.data.message
                            ? error.response.data.message
                            : '取消失败。';
                    })
                    .finally(() => {
                        this.aiPendingDismissLoadingId = null;
                    });
            },
            // V2: 打开生成预览弹窗
            openAiStudyCardPreview() {
                this.aiStudyCardPreviewDialog = true;
            },
            // V2: 日期格式化
            formatDate(value) {
                if (!value) return '';
                const date = new Date(value);
                if (isNaN(date.getTime())) return value;
                const yyyy = date.getFullYear();
                const mm = String(date.getMonth() + 1).padStart(2, '0');
                const dd = String(date.getDate()).padStart(2, '0');
                const hh = String(date.getHours()).padStart(2, '0');
                const mi = String(date.getMinutes()).padStart(2, '0');
                return `${yyyy}-${mm}-${dd} ${hh}:${mi}`;
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
            loadAiSuggestions() {
                // Uses VocabularyAiSuggestionService so the responsive
                // (half-screen / narrow) vocab box and the wide-screen side box
                // share the same AI candidate lookup rules. The service owns the
                // request and response shaping; this component owns Vuex state.
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
            useAiSuggestion(vi) {
                if (this.$refs.wordSensesList && this.$refs.wordSensesList.openAddFormFromAi) {
                    this.$refs.wordSensesList.openAddFormFromAi(buildAiVocabSensePayload(vi));
                }
            },
            useAiPhraseSuggestion(pi) {
                if (this.$refs.wordSensesList && this.$refs.wordSensesList.openAddFormFromAi) {
                    this.$refs.wordSensesList.openAddFormFromAi(buildAiPhraseSensePayload(pi));
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
