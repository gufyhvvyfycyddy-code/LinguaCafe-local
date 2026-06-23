<template>
    <div
        :class="{
            'text-block-group': true,
            'plain-text-mode': plainTextMode,
            'w-100': true,
            'spaceless-language': ['chinese', 'japanese', 'thai'].includes($props.language)
        }"
    >
        <!-- Delete phrase dialog -->
        <delete-phrase-dialog
            v-model="deletePhraseDialog"
            @confirm="deletePhrase"
        />

        <!-- Anki api notifications -->
        <v-snackbar
            v-for="(snackBar, snackBarIndex) in snackBars"
            :key="'snackbar-' + snackBarIndex"
            :value="true"
            right
            top
            :light="$props.theme == 'light'"
            :dark="$props.theme == 'dark'"
            color="foreground"
            class="anki-snackbar rounded-lg mr-2"
            height="108"
            :style="{'margin-top': ((snackBarIndex) * 124 + 16) + 'px'}"
            :timeout="-1"
            @mouseup.native.stop=";"
        >
            <div class="pl-3 pr-2 pt-1 d-flex font-weight-bold snackbar-title">
                <v-icon v-if="snackBar.type !== 'success' && snackBar.type !== 'update success'" color="error" class="mr-2">mdi-alert</v-icon>
                <v-icon v-else color="success" class="mr-2">mdi-cards</v-icon>

                <template v-if="snackBar.type =='error'">Anki error</template>
                <template v-if="snackBar.type =='success'">Added to anki</template>
                <template v-if="snackBar.type =='update success'">Updated in anki</template>

                <v-spacer />
                <v-btn icon>
                    <v-icon @click="removeSnackbar(snackBar.id)">mdi-close</v-icon>
                </v-btn>
            </div>
            <div class="py-2 px-4">
                {{ snackBar.content }}
            </div>
        </v-snackbar>

        <!-- Text -->
        <div
            class="text-block"
            :style="{
                'font-size': fontSize + 'px',
            }"
            @mousedown.stop="startSelectionMouseEvent"
            @mousemove.stop="updateSelectionMouseEvent"
            @mouseup.stop="finishSelection"
            @touchstart="startSelectionTouchEvent"
            @touchmove="updateSelectionTouchEvent"
            @touchend="finishSelection"
            >

            <template v-for="(word, wordIndex) in words"><!--
                --><div
                        v-if="word.subtitleIndex !== -1"
                        :class="['subtitle-timestamp', $props.showSubtitleTimestamps ? '' : 'hidden', 'rounded-pill', 'py-1']"
                        :style="{'margin-top': word.subtitleIndex > 0 ? ($props.spaceBetweenSubtitles * 3) + 'px' : '0px'}"
                    ><!--
                    -->{{ subtitleTimestamps[word.subtitleIndex].start }}<!--
                --></div><!--
                --><br v-if="word.is_structure && word.word === 'NEWLINE'" /><!--
                --><div v-else-if="word.is_structure && word.word === 'PARAGRAPH_BREAK'" style="display:block;height:1.2em;clear:both;"></div><!--
                --><span
                    v-else-if="word.is_structure && isSectionMarker(word.word)"
                    class="word selected-font"
                    :style="{'margin-bottom': (lineSpacing * 4) + 'px', 'font-weight': 'bold'}"
                    :wordindex="wordIndex"
                    :stage="word.stage"
                    :key="wordIndex"
                >{{ word.word }}</span><!--
                --><span
                    v-else
                    :wordindex="wordIndex"
                    :stage="word.stage"
                    :phrasestage="word.phraseStage"
                    :class="{
                        'no-highlight': hideAllHighlights || (hideNewWordHighlights && word.stage == 2),
                        'word': true,
                        'selected-font': true,
                        'highlighted': word.selected || word.hover,
                        'source-highlight': word.sourceHighlight,
                        'phrase': word.phraseIndexes.length,
                        'space-after': word.spaceAfter,
                        'phrase-start': word.phraseStart,
                        'phrase-end': word.phraseEnd,
                    }"
                    :style="{
                        'margin-bottom': (lineSpacing * 4) + 'px'
                    }"

                    :key="wordIndex"
                ><!--
                    --><template v-if="$props.language == 'japanese'"><!--
                        --><ruby class="rubyword selected-font" :wordindex="wordIndex"><!--
                            -->{{ word.word }}<!--
                            --><rt v-if="word.stage == 2 && furiganaOnNewWords && word.furigana.length && word.word !== word.furigana && !plainTextMode" :style="{'font-size': (fontSize - 4) + 'px'}"><!--
                                -->{{ word.furigana }}<!--
                            --></rt><!--
                            --><rt v-if="word.stage < 0 && furiganaOnHighlightedWords && word.furigana.length && word.word !== word.furigana && !plainTextMode" :style="{'font-size': (fontSize - 4) + 'px'}"><!--
                                -->{{ word.furigana }}<!--
                            --></rt><!--
                        --></ruby>
                    </template><!--
                    --><template v-else>{{ word.word }}</template><!--
                    --><template v-if="plainTextMode && word.spaceAfter">&nbsp;</template><!--
                --></span><!--
            --></template>
        </div>

        <!-- Vocabulary popup box -->
        <vocabulary-hover-box
            v-if="$store.state.hoverVocabularyBox.active &&  !vocabularyBoxActive && !$store.state.hoverVocabularyBox.disabledWhileSelecting"
            ref="hoverVocabBox"
            :key="'hover-vocab-box' + $store.state.hoverVocabularyBox.key"
        ></vocabulary-hover-box>

        <!-- Vocabulary popup box -->
        <vocabulary-box
            v-if="(!$props.vocabularySidebar || !$props.vocabularySidebarFits) && $store.state.vocabularyBox.active && (!$props.vocabularyBottomSheet || !$store.state.vocabularyBox.vocabularyBottomSheetVisible)"
            ref="vocabularyBox"
            :language="$props.language"
            :auto-highlight-words="$props.autoHighlightWords"
            :any-api-dictionary-enabled="anyApiDictionaryEnabled"
            :text-to-speech-available="textToSpeechAvailable"
            @textToSpeech="textToSpeech"
            @setStage="setStage"
            @unselectAllWords="unselectAllWords"
            @updateVocabBoxData="updateVocabBoxData"
            @addNewPhrase="addNewPhrase"
            @showDeletePhraseDialog="showDeletePhraseDialog"
            @deleteWord="deleteWord"
            @addSelectedWordToAnki="addSelectedWordToAnki"
            @word-learning-updated="onWordLearningUpdated"
        ></vocabulary-box>

        <!-- Vocabulary bottom sheet -->
        <v-bottom-sheet
            v-if="
                (!$props.vocabularySidebar || !$props.vocabularySidebarFits)
                && $store.state.vocabularyBox.active
                && $props.vocabularyBottomSheet
                && $store.state.vocabularyBox.vocabularyBottomSheetVisible
            "
            v-model="$store.state.vocabularyBox.active"
            persistent
            scrollable
        >
            <vocabulary-bottom-sheet
                :language="$props.language"
                :auto-highlight-words="$props.autoHighlightWords"
                :any-api-dictionary-enabled="anyApiDictionaryEnabled"
                :text-to-speech-available="textToSpeechAvailable"
                @textToSpeech="textToSpeech"
                @setStage="setStage"
                @unselectAllWords="unselectAllWords"
                @updateVocabBoxData="updateVocabBoxData"
                @addNewPhrase="addNewPhrase"
                @deletePhrase="deletePhrase"
                @deleteWord="deleteWord"
                @addSelectedWordToAnki="addSelectedWordToAnki"
            ></vocabulary-bottom-sheet>
        </v-bottom-sheet>

        <!--Vocabulary sidebar-->
        <vocabulary-side-box
            ref="vocabularySideBox"
            v-if="$props.vocabularySidebar && !$store.state.vocabularyBox.sidebarHidden"
            :language="$props.language"
            :auto-highlight-words="$props.autoHighlightWords"
            :any-api-dictionary-enabled="anyApiDictionaryEnabled"
            :text-to-speech-available="textToSpeechAvailable"
            @textToSpeech="textToSpeech"
            @setStage="setStage"
            @unselectAllWords="unselectAllWords"
            @updateVocabBoxData="updateVocabBoxData"
            @saveWord="onSaveWordFromSideBox"
            @addNewPhrase="addNewPhrase"
            @deletePhrase="deletePhrase"
            @deleteWord="deleteWord"
            @addSelectedWordToAnki="addSelectedWordToAnki"
            @word-learning-updated="onWordLearningUpdated"
        ></vocabulary-side-box>
    </div>
</template>

<script>
    import TextToSpeechService from './../../services/TextToSpeechService';
    import { mapState } from 'vuex';

    const ENGLISH_ABBREVIATIONS = new Set([
        // person
        'mr', 'mrs', 'ms', 'dr', 'prof', 'sr', 'jr',
        // address / company
        'st', 'ave', 'blvd', 'rd', 'inc', 'ltd', 'co', 'corp',
        // latin abbreviations
        'etc', 'vs', 'viz',
        // time
        'jan', 'feb', 'mar', 'apr', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec',
        // military / titles
        'gen', 'col', 'capt', 'lt', 'maj', 'sgt', 'cpl', 'pvt',
        'rev', 'hon', 'gov', 'sen', 'rep',
        // other
        'no', 'vol', 'pp', 'ch', 'sec', 'fig', 'eq', 'al',
        'dept', 'univ', 'assn', 'bros',
    ]);

    const COMPOUND_ABBREVIATIONS = new Set([
        'e.g', 'i.e', 'u.s', 'u.k', 'u.n', 'a.m', 'p.m',
        'e.g.', 'i.e.', 'u.s.', 'u.k.', 'u.n.', 'a.m.', 'p.m.',
    ]);

    export default {
        data: function() {
            return {
                // dialogs
                deletePhraseDialog: false,

                // tts
                textToSpeechService: new TextToSpeechService(this.$props.language, this.updateTextToSpeechState),
                textToSpeechAvailable: false,

                // text
                words: [],
                uniqueWords: JSON.parse(JSON.stringify(this.$props._text.uniqueWords)),
                uniqueWordMap: new Map(),
                phrases: JSON.parse(JSON.stringify(this.$props._text.phrases)),

                snackBars: [
                ],
                snackbarId: 1,
                ankiAutoAddCards: false,
                ankiShowNotifications: false,
                anyApiDictionaryEnabled: false,

                // hover vocabulary box
                hoverVocabularyDelayTimeout: null,
                
                // vocabulary box
                selectedPhrase: -1,
                phraseCurrentlySaving: false,

                // text selection
                phraseLengthLimit: 14,
                touchTimer: null,
                touchStartWordIndex: -1,
                selection: [],
                ongoingSelection: [],
                selectedPhrase: -1,
                ongoingSelectionStartingWordIndex: -1,
            }
        },
        props: {
            textType: {
                type: String,
                default: 'simple-text',
            },
            theme: String,
            fullscreen: Boolean,
            _text: Object,
            subtitleTimestamps: {
                type: Array,
                default: () => {
                    return [];
                }
            },
            language: String,
            hideAllHighlights: {
                type: Boolean,
                default: false
            },
            hideNewWordHighlights: {
                type: Boolean,
                default: false
            },
            plainTextMode: {
                type: Boolean,
                default: false
            },
            fontSize: Number,
            lineSpacing: {
                type: Number,
                default: 0
            },
            vocabBoxScrollIntoView: {
                type: String,
                default: 'Disabled'
            },
            furiganaOnHighlightedWords: {
                type: Boolean,
                default: false
            },
            furiganaOnNewWords: {
                type: Boolean,
                default: false
            },
            vocabularySidebar: {
                type: Boolean,
                default: false
            },
            vocabularyBottomSheet: {
                type: Boolean,
                default: false
            },
            vocabularySidebarFits: {
                type: Boolean,
                default: true
            },
            hotkeysEnabled: {
                type: Boolean,
                default: false
            },
            vocabularyHoverBox: {
                type: Boolean,
                default: false
            },
            vocabularyHoverBoxSearch: {
                type: Boolean,
                default: false
            },
            vocabularyHoverBoxDelay: {
                type: Number,
                default: 300
            },
            vocabularyHoverBoxPreferredPosition: {
                type: String,
                default: 'bottom'
            },
            vocabularyHoverBoxPositionCorrections: {
                type: Boolean,
                default: true,
            },
            autoHighlightWords: {
                type: Boolean,
                default: true
            },
            showSubtitleTimestamps: {
                type: Boolean,
                default: true
            },
            spaceBetweenSubtitles: {
                type: Number,
                default: 20
            }
        },
        computed: mapState({
            vocabularyBoxActive: state => state.vocabularyBox.active,
            vocabularyBottomSheetVisible: state => state.vocabularyBox.vocabularyBottomSheetVisible,
        }),
        mounted() {
            this.preProcessWords();
            window.addEventListener('resize', this.resizeHandle);
            window.addEventListener('mouseup', this.unselectAllWordsOnEmptyClick);
            window.addEventListener('keydown', this.hotkeyHandle);
            window.addEventListener('mousemove', this.closeHoverBox);

            axios.get('/settings/get-anki-settings').then((response) => {
                this.ankiAutoAddCards = response.data.ankiAutoAddCards;
                this.ankiShowNotifications = response.data.ankiShowNotifications;
            });

            axios.get('/dictionaries/api/is-enabled').then((response) => {
                this.anyApiDictionaryEnabled = response.data;
            });

            this.resizeHandle();
            this.updatePhraseBorders();
            this.updateTextToSpeechState();
        },
        beforeDestroy() {
            window.removeEventListener('resize', this.resizeHandle);
            window.removeEventListener('mouseup', this.unselectAllWordsOnEmptyClick);
            window.removeEventListener('keydown', this.hotkeyHandle);
            window.removeEventListener('mousemove', this.closeHoverBox);
        },
        methods: {
            isSectionMarker(word) {
                if (typeof word !== 'string') return false;
                // 新格式: [A] [B] [C] ... [Z]
                if (word.length === 3 && word[0] === '[' && word[2] === ']' && word[1] >= 'A' && word[1] <= 'Z') return true;
                // 兼容旧格式: _SECT_X_
                if (word.startsWith('_SECT_') && word.length === 8) return true;
                return false;
            },
            textToSpeech() {
                if (!this.selection.length) {
                    return;
                }

                if (this.$store.state.vocabularyBox.type === 'word') {
                    var text = this.$store.state.vocabularyBox.reading.length ? this.$store.state.vocabularyBox.reading : this.$store.state.vocabularyBox.word;
                } else if (this.$store.state.vocabularyBox.type !== 'word' && this.$store.state.vocabularyBox.reading.length) {
                    var text = this.$store.state.vocabularyBox.reading;
                } else {
                    var text = '';

                    this.$store.state.vocabularyBox.phrase.forEach((phraseWord, index) => {
                        if (index) {
                            text += ' ';
                        }

                        text += phraseWord.word;
                    });
                }

                this.textToSpeechService.speak(text);
            },
            updateTextToSpeechState() {
                this.textToSpeechAvailable = this.textToSpeechService.getLanguageVoices().length > 0;
            },
            startSelectionTouchEvent: function(event) {
                // Normalize event.target to an Element (may be a text node)
                var element = event.target instanceof Element ? event.target : event.target?.parentElement;
                if (!element) {
                    this.unselectAllWords();
                    return;
                }

                // Handle ruby and rt child elements
                if (element.localName === 'ruby') {
                    element = element.parentElement;
                } else if (element.localName === 'rt') {
                    element = element.parentElement.parentElement;
                }

                // Fallback: walk up to find the nearest .word ancestor
                if (!element || !element.classList || !element.classList.contains('word')) {
                    element = element?.closest ? element.closest('.word') : null;
                    if (!element) {
                        this.unselectAllWords();
                        return;
                    }
                }

                if (this.$props.plainTextMode) {
                    return;
                }

                var wordIndex = parseInt(element.getAttribute('wordindex'));
                if (isNaN(wordIndex)) {
                    this.unselectAllWords();
                    return;
                }

                this.touchStartWordIndex = wordIndex;
                this.touchTimer = setTimeout(() => {
                    this.startSelection(wordIndex);
                }, 500);
            },
            startSelectionMouseEvent(event) {
                if (this.$props.plainTextMode) {
                    return;
                }

                this.startTime = performance.now();

                // Normalize event.target to an Element (may be a text node)
                var element = event.target instanceof Element ? event.target : event.target?.parentElement;
                if (!element) {
                    this.unselectAllWords();
                    return;
                }

                // Handle ruby and rt child elements
                if (element.localName === 'ruby') {
                    element = element.parentElement;
                } else if (element.localName === 'rt') {
                    element = element.parentElement.parentElement;
                }

                // Fallback: walk up to find the nearest .word ancestor
                if (!element || !element.classList || !element.classList.contains('word')) {
                    element = element?.closest ? element.closest('.word') : null;
                    if (!element) {
                        this.unselectAllWords();
                        return;
                    }
                }

                var wordIndex = parseInt(element.getAttribute('wordindex'));
                if (isNaN(wordIndex)) {
                    this.unselectAllWords();
                    return;
                }

                this.startSelection(wordIndex);
            },
            updateSelectionTouchEvent: function(event) {
                if (!event.cancelable) {
                    if (this.touchTimer) {
                        clearTimeout(this.touchTimer);
                        this.touchTimer = null;
                    }

                    return;
                }

                if (this.ongoingSelection.length) {
                    event.preventDefault();
                }

                var touch = event.changedTouches[0];
                var element = document.elementFromPoint( touch.clientX, touch.clientY );

                var wordIndex = null;
                if (element !== null && element.classList.contains('word') || element.classList.contains('rubyword')) {
                    wordIndex = element.getAttribute('wordindex');
                }

                if (this.touchTimer) {
                    if ((wordIndex === null || parseInt(wordIndex) !== this.touchStartWordIndex)) {
                        clearTimeout(this.touchTimer);
                        this.touchTimer = null;
                    }

                    return;
                }

                if (wordIndex !== null && this.ongoingSelection.length) {
                    this.updateSelection(wordIndex);
                }
            },
            updateSelectionMouseEvent(event) {
                var element = event.target;
                var wordIndex = -1;
                if (event.target.localName === 'ruby') {
                    element = event.target.parentElement;
                }

                if (element.classList.contains('word')) {
                    wordIndex = parseInt(element.attributes['wordindex'].nodeValue);
                }

                if (wordIndex === -1) {
                    if (wordIndex !== this.$store.state.hoverVocabularyBox.lastHoveredWordIndex) {
                        this.closeHoverBox();
                        this.removePhraseHover();
                    }

                    return;
                }

                if (event.buttons === 0 && wordIndex === -1 || wordIndex !== this.$store.state.hoverVocabularyBox.lastHoveredWordIndex) {
                    this.removePhraseHover();
                }

                if (wordIndex === -1) {
                    return;
                }

                if (event.buttons === 0 && wordIndex !== this.$store.state.hoverVocabularyBox.lastHoveredWordIndex) {
                    this.updateHoverSelection(wordIndex);
                }

                if (!this.ongoingSelection.length) {
                }

                if (!event.buttons !== 1) {
                }

                if (this.ongoingSelection.length && event.buttons === 1) {
                    this.updateSelection(wordIndex);
                }

            },
            startSelection: function(wordIndex) {
                if (this.$props.plainTextMode) {
                    return;
                }

                // update vocab box
                this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'disabledWhileSelecting', value: true });
                if (this.$refs.vocabularyBox !== undefined) {
                    this.$refs.vocabularyBox.inputChanged();
                }

                if (this.$refs.vocabularySideBox !== undefined) {
                    this.$refs.vocabularySideBox.inputChanged();
                }
                
                if (this.selection.length == 1) {
                    this.saveWord();
                } else if (this.selectedPhrase !== -1) {
                    this.savePhrase();
                }

                this.$store.commit('vocabularyBox/setActive', false);
                this.touchTimer = null;

                if (this.ongoingSelection.length == 1 && this.ongoingSelection[0].wordIndex == wordIndex) {
                    return;
                }

                for (let i  = 0; i < this.words.length; i++) {
                    this.words[i].selected = false;
                }

                // set selected word
                var selectedWord = {
                    word: this.words[wordIndex].word,
                    spaceAfter: this.words[wordIndex].spaceAfter,
                    wordIndex: wordIndex,
                    sentence_index: this.words[wordIndex].sentence_index
                };


                this.ongoingSelection = [selectedWord];
                this.words[wordIndex].selected = true;

                this.ongoingSelectionStartingWordIndex = wordIndex;

            },
            updateSelection(wordIndex) {
                if (this.touchTimer) {
                    return;
                }

                if (wordIndex == this.ongoingSelection[0].wordIndex ||
                    (wordIndex < this.ongoingSelection[0].wordIndex && this.ongoingSelection.length == this.phraseLengthLimit) ||
                    (wordIndex > this.ongoingSelection[this.ongoingSelection.length - 1].wordIndex && this.ongoingSelection.length == this.phraseLengthLimit) ||
                    wordIndex == this.ongoingSelection[this.ongoingSelection.length - 1].wordIndex) {
                        return;
                }

                var firstWordIndex = this.ongoingSelectionStartingWordIndex;
                var lastWordIndex = wordIndex;

                if (firstWordIndex > lastWordIndex) {
                    firstWordIndex = wordIndex;
                    lastWordIndex = this.ongoingSelectionStartingWordIndex;
                }


                if (firstWordIndex < this.ongoingSelectionStartingWordIndex - this.phraseLengthLimit + 1) {
                    firstWordIndex = this.ongoingSelectionStartingWordIndex - this.phraseLengthLimit + 1;
                }

                if (lastWordIndex - firstWordIndex > this.phraseLengthLimit + 1) {
                    lastWordIndex -= lastWordIndex - firstWordIndex - this.phraseLengthLimit + 1;
                }

                this.ongoingSelection = [];
                for (let i  = 0; i < this.words.length; i++) {
                    this.words[i].selected = false;

                    if (i < firstWordIndex || i > lastWordIndex || this.words[i].word === 'NEWLINE') {
                        continue;
                    }

                    this.words[i].selected = true;
                    var selectedWord = {
                        word: this.words[i].word,
                        wordIndex: i,
                        sentence_index: this.words[i].sentence_index,
                        spaceAfter: this.words[i].spaceAfter,
                    };

                    this.ongoingSelection.push(selectedWord);
                }

                if (!this.ongoingSelection.length) {
                }

            },
            finishSelection: function() {
                if (this.touchTimer) {
                    clearTimeout(this.touchTimer);
                    this.touchTimer = null;
                    return;
                }

                this.selectionOngoing = false;
                if (this.ongoingSelection.length == 1) {
                    // if the selected word is in an phrase, select the phrase instead
                    var selectedPhrase = this.getSelectedPhraseIndex();
                    var newWordSelected = this.selection.find(o => o.wordIndex == this.ongoingSelection[0].wordIndex) !== undefined;
                    var phraseIndexes = this.words[this.ongoingSelection[0].wordIndex].phraseIndexes;
                    if (phraseIndexes.length && selectedPhrase !== phraseIndexes[phraseIndexes.length - 1]) {
                        if (selectedPhrase == -1 || !newWordSelected) {
                            this.selectPhraseInstanceByWord(this.ongoingSelection[0].wordIndex, phraseIndexes[0]);
                        } else {
                            for (let i = 0; i < phraseIndexes.length; i++) {
                                if (phraseIndexes[i] == selectedPhrase && i < phraseIndexes.length - 1) {
                                    this.selectPhraseInstanceByWord(this.ongoingSelection[0].wordIndex, phraseIndexes[i + 1]);
                                    break;
                                }
                            }
                        }
                    }
                }

                // update selected word classes after automatic phrase selection
                for (let i  = 0; i < this.words.length; i++) {
                    this.words[i].selected = false;
                }

                // set words to selected, and collect their information
                var validSelection = [];
                for (let i = 0; i < this.ongoingSelection.length; i++) {
                    this.words[this.ongoingSelection[i].wordIndex].selected = true;
                    const key = this.normalizeWordKey(this.ongoingSelection[i].word);
                    const uniqueWordIndex = this.uniqueWordMap.get(key);
                    if (uniqueWordIndex === undefined || !this.uniqueWords[uniqueWordIndex]) {
                        continue;
                    }
                    this.ongoingSelection[i].uniqueWordIndex = uniqueWordIndex;
                    this.ongoingSelection[i].reading = this.uniqueWords[uniqueWordIndex].reading;
                    this.ongoingSelection[i].kanji = this.uniqueWords[uniqueWordIndex].kanji;
                    validSelection.push(this.ongoingSelection[i]);
                }

                // If all items were skipped, clean up and abort without opening the panel
                if (validSelection.length === 0) {
                    this.ongoingSelection = [];
                    return;
                }

                this.selection = validSelection;
                this.ongoingSelection = [];

                if (this.selection.length) {
                    this.selectedPhrase = this.getSelectedPhraseIndex();

                    // update lookup counts
                    if (this.selection.length == 1) {
                        var singleUniqueWordIndex = this.selection[0].uniqueWordIndex;
                        var inflectionSearchTerm = this.uniqueWords[singleUniqueWordIndex].base_word.length ? this.uniqueWords[singleUniqueWordIndex].base_word : this.uniqueWords[singleUniqueWordIndex].word;
                        this.requestInflections(inflectionSearchTerm);
                        this.updateWordLookupCount(this.selection[0].word);
                    } else if (this.selectedPhrase !== -1) {
                        this.updatePhraseLookupCount(this.selectedPhrase);
                    }

                    this.updatePhraseBorders();
                    this.updateVocabBoxDataAfterSelection();
                }
            },
            requestInflections: function(term) {
                if (this.$props.language !== 'japanese') {
                    return;
                }

                // search inflections
                this.$store.commit('vocabularyBox/setInflections', []);
                
                axios.post('/dictionaries/search/inflections', {
                    term: term
                }).then((response) => {
                    let inflections = [];
                    if (response.data === '[]' || response.data == '') {
                        return;
                    }

                    var data = JSON.parse(response.data);
                    var displayedInflections = ['Non-past', 'Non-past, polite', 'Past', 'Past, polite', 'Te-form', 'Potential', 'Passive', 'Causative', 'Causative Passive', 'Imperative'];

                    for (var i = 0; i < data.length; i++) {
                        if (!displayedInflections.includes(data[i].name)) {
                            continue;
                        }

                        var index = inflections.findIndex(item => item.name === data[i].name);
                        if (index == -1) {
                            inflections.push({
                                name: data[i].name,
                            });
                            index = inflections.length - 1;
                        }
                        // add different forms to the item
                        if (data[i].form == 'aff-plain:') {
                            inflections[index].affPlain = data[i].value;
                        }
                        if (data[i].form == 'aff-formal:') {
                            inflections[index].affFormal = data[i].value;
                        }
                        if (data[i].form == 'neg-plain:') {
                            inflections[index].negPlain = data[i].value;
                        }
                        if (data[i].form == 'neg-formal:') {
                            inflections[index].negFormal = data[i].value;
                        }
                    }

                    this.$store.commit('vocabularyBox/setInflections', inflections);
                });
            },
            selectPhraseInstanceByWord: function(wordIndex, phraseIndex) {
                var currentWordIndex = wordIndex;
                var newSelection = [];

                // find the first word of the phrase
                while (currentWordIndex > 0 && (this.words[currentWordIndex - 1].word == 'NEWLINE' || this.words[currentWordIndex - 1].phraseIndexes.includes(phraseIndex))) {
                    currentWordIndex --;
                }

                // select the phrasew
                do {
                    if (this.words[currentWordIndex].word !== 'NEWLINE') {
                        const key = this.normalizeWordKey(this.words[currentWordIndex].word);
                        const uniqueWordIndex = this.uniqueWordMap.get(key);
                        if (uniqueWordIndex === undefined || !this.uniqueWords[uniqueWordIndex]) {
                            currentWordIndex++;
                            continue;
                        }
                        var uniqueWord = this.uniqueWords[uniqueWordIndex];
                        newSelection.push({
                            word: this.words[currentWordIndex].word,
                            reading: uniqueWord.reading,
                            kanji: uniqueWord.kanji,
                            sentence_index: this.words[currentWordIndex].sentence_index,
                            wordIndex: currentWordIndex,
                            uniqueWordIndex: uniqueWordIndex,
                            spaceAfter: this.words[currentWordIndex].spaceAfter,
                        });
                    }

                    currentWordIndex ++;
                } while(currentWordIndex < this.words.length && (this.words[currentWordIndex].word == 'NEWLINE' || this.words[currentWordIndex].phraseIndexes.includes(phraseIndex)));

                this.ongoingSelection = newSelection;
            },
            updateHoverSelection: function(wordIndex) {
                this.closeHoverBox();

                var hoveredWords = [];
                var hoveredPhraseIndex = -1;
                this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'lastHoveredWordIndex', value: wordIndex });

                var phraseIndexes = this.words[wordIndex].phraseIndexes;
                if (!phraseIndexes.length) {

                    // update hovered words
                    var word = JSON.parse(JSON.stringify(this.words[wordIndex]));
                    word.hover = true;
                    hoveredWords.push(word);
                    hoveredWords[0].wordIndex = wordIndex;
                    this.showHoverVocabBox(hoveredWords);

                    return;
                } else {
                    hoveredPhraseIndex = this.words[wordIndex].phraseIndexes[0];
                }

                // find the first word of the phrase
                var currentWordIndex = wordIndex;
                while (currentWordIndex > 0 && (this.words[currentWordIndex - 1].word == 'NEWLINE' || this.words[currentWordIndex - 1].phraseIndexes.some(el => phraseIndexes.includes(el)))) {
                    currentWordIndex--;
                }

                // highlight the phrase
                do {
                    this.words[currentWordIndex].hover = true;

                    // add words for hover vocabulary box
                    if (this.words[currentWordIndex].phraseIndexes.includes(hoveredPhraseIndex) && this.words[currentWordIndex].word !== 'NEWLINE') {
                        hoveredWords.push(this.words[currentWordIndex]);
                        hoveredWords[hoveredWords.length - 1].wordIndex = currentWordIndex;
                    }

                    currentWordIndex ++;
                } while(currentWordIndex < this.words.length && (this.words[currentWordIndex].word == 'NEWLINE' || this.words[currentWordIndex].phraseIndexes.some(el => phraseIndexes.includes(el))));

                this.showHoverVocabBox(hoveredWords, hoveredPhraseIndex);
            },
            showHoverVocabBox: function(hoveredWords, hoveredPhraseIndex) {
                var data = {
                    hoveredWords: JSON.parse(JSON.stringify(hoveredWords)),
                    translation: '',
                    reading: '',
                };

                if (hoveredWords !== null && hoveredWords.length === 1) {
                    const key = this.normalizeWordKey(hoveredWords[0].word);
                    const uniqueWordIndex = this.uniqueWordMap.get(key);
                    if (uniqueWordIndex === undefined || !this.uniqueWords[uniqueWordIndex]) {
                        return;
                    }
                    var uniqueWord = this.uniqueWords[uniqueWordIndex];

                    data.translation = uniqueWord.translation;
                    data.reading = uniqueWord.reading;
                    data.stage = uniqueWord.stage < 0 ? uniqueWord.stage : null;
                    data.hoveredWords[0].lemma = uniqueWord.base_word;
                }

                if (hoveredWords !== null && hoveredWords.length > 1) {
                    data.translation = this.phrases[hoveredPhraseIndex].translation;
                    data.reading = this.phrases[hoveredPhraseIndex].reading;
                    data.stage = this.phrases[hoveredPhraseIndex].stage < 0 ? this.phrases[hoveredPhraseIndex].stage : null;
                }

                this.updateHoverVocabularyBox(data);
            },
            updateHoverVocabularyBox(data) {
                if (!this.$props.vocabularyHoverBox || this.$props.plainTextMode || data.hoveredWords === null) {
                    this.closeHoverBox();
                    return;
                } else {
                    this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'hoveredWords', value: data.hoveredWords });
                    this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'hoveredPhrase', value: data.hoveredPhrase });
                    this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'userTranslation', value: data.translation });
                    this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'dictionaryTranslation', value: 'loading' });
                    this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'apiTranslations', value: this.anyApiDictionaryEnabled ? ['loading'] : [] });
                    this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'reading', value: data.reading });
                    this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'stage', value: data.stage });

                    // clear previous delay timeout
                    if (this.hoverVocabularyDelayTimeout !== null) {
                        this.clearHoverVocabularyBoxTimeout();
                    }

                    // check if dictionary search option is enabled
                    if (!this.$props.vocabularyHoverBoxSearch) {
                        this.hoverVocabularyDelayTimeout = setTimeout(() => {
                            this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'dictionaryTranslation', value: 'dictionary-search-disabled' });
                            this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'apiTranslations', value: [] });
                            this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'active', value: true });
                            this.$nextTick(() => {
                                this.updateHoverVocabularyBoxPosition();
                            });
                        }, this.$props.vocabularyHoverBoxDelay);

                        return;
                    }

                    // call the hover vocabulary search function with a delay
                    this.hoverVocabularyDelayTimeout = setTimeout(() => {
                        this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'active', value: true });
                        this.$nextTick(() => {
                            this.updateHoverVocabularyBoxPosition();
                        });

                        if (data.hoveredWords.length === 1) {
                            var term = data.hoveredWords[0].word;
                            if (data.hoveredWords[0].lemma.length) {
                                term = this.trimSearchTerm(data.hoveredWords[0].lemma);
                            }
                        } else {

                            // build search term for phrases, and adding spaces
                            var term = '';
                            for (let i = 0; i < data.hoveredWords.length; i++) {
                                term += data.hoveredWords[i].word;

                                if (data.hoveredWords[i].spaceAfter && i < data.hoveredWords.length - 1) {
                                    term += ' ';
                                }
                            }

                            data.hoveredWords.map(hoveredWord => hoveredWord.word).join('');
                        }


                        this.makeHoverVocabularyBoxSearchRequest(term);
                    }, this.$props.vocabularyHoverBoxDelay);
                }
            },
            updateHoverVocabularyBoxPosition() {
                var hoverVocabBoxElement = document.getElementById('vocab-hover-box');
                if (hoverVocabBoxElement === null) {
                    return;
                }

                var margin = 8;
                var hoverVocabBoxWidth = 300;
                var hoverVocabBoxHeight = hoverVocabBoxElement.getBoundingClientRect().height;
                var vocabBoxAreaElement = document.getElementsByClassName('vocab-box-area')[0];
                var vocabBoxArea = vocabBoxAreaElement.getBoundingClientRect();


                if (this.$store.state.hoverVocabularyBox.hoveredWords.length == 1) {
                    var hoveredWordPositions = document.querySelector('[wordindex="' + this.$store.state.hoverVocabularyBox.hoveredWords[0].wordIndex + '"]').getBoundingClientRect();
                } else {
                    var hoveredWordPositions = document.querySelector('[wordindex="' + this.$store.state.hoverVocabularyBox.hoveredWords[parseInt(this.$store.state.hoverVocabularyBox.hoveredWords.length / 2)].wordIndex + '"]').getBoundingClientRect();
                }

                var hoveredWordPositions = document.querySelector('[wordindex="' + this.$store.state.hoverVocabularyBox.hoveredWords[0].wordIndex + '"]').getBoundingClientRect();

                // set horizontal position
               this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'positionLeft', value: hoveredWordPositions.right - vocabBoxArea.left - hoverVocabBoxWidth / 2 - (hoveredWordPositions.right - hoveredWordPositions.left) / 2 });
                if (this.$store.state.hoverVocabularyBox.positionLeft < margin) {
                   this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'positionLeft', value: margin });
                } else if (this.$store.state.hoverVocabularyBox.positionLeft > vocabBoxArea.right - vocabBoxArea.left - hoverVocabBoxWidth - margin) {
                   this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'positionLeft', value: vocabBoxArea.right - vocabBoxArea.left - hoverVocabBoxWidth - margin });
                }

                // set vertical position

                // set preferred location
               this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'arrowPosition', value: this.$props.vocabularyHoverBoxPreferredPosition });

                // correct preferred location based on available space

                /*
                    Is there enough space on the bottom? If not, move the hover box to the top.

                    There is a special case, when there is not enough space on the bottom, however the top half of the screen is smaller
                    than the bottom one. However, overflow by the hover box on the top does not affect the scrollbar, while on the bottom it
                    does, so it won't be corrected.
                */
                if (
                    this.$props.vocabularyHoverBoxPositionCorrections &&
                    this.$store.state.hoverVocabularyBox.arrowPosition == 'bottom' &&
                    (vocabBoxArea.height + vocabBoxAreaElement.scrollTop) - (hoveredWordPositions.bottom - vocabBoxArea.top + vocabBoxAreaElement.scrollTop + 25) < hoverVocabBoxHeight
                ) {
                    this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'arrowPosition', value: 'top' });
                }

                /*
                    Is there enough space on the top?
                */
                if (
                    this.$props.vocabularyHoverBoxPositionCorrections &&
                    this.$store.state.hoverVocabularyBox.arrowPosition == 'top' &&
                    hoveredWordPositions.top - 25 - 30 < hoverVocabBoxHeight
                ) {
                    /*
                        If there's not enuogh space on the top, move the hover box to the bottom, but only if there's enough space on the bottom,
                        otherwise prefer to use the top position, because that does not cause scroll issues.
                    */
                    if ((vocabBoxArea.height + vocabBoxAreaElement.scrollTop) - (hoveredWordPositions.bottom - vocabBoxArea.top + vocabBoxAreaElement.scrollTop + 25) >= hoverVocabBoxHeight) {
                        this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'arrowPosition', value: 'bottom' });
                    }
                }

                // set hover vocabulary box's location based on preference and correction
                if (this.$store.state.hoverVocabularyBox.arrowPosition == 'top') {
                    this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'positionTop', value: hoveredWordPositions.top - vocabBoxArea.top + vocabBoxAreaElement.scrollTop - hoverVocabBoxHeight - 25 });
                } else {
                    this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'positionTop', value: hoveredWordPositions.bottom - vocabBoxArea.top + vocabBoxAreaElement.scrollTop + 25 });
                }
            },
            removePhraseHover: function() {
                for (let i  = 0; i < this.words.length; i++) {
                    this.words[i].hover = false;
                }
            },
            closeHoverBox() {
                this.clearHoverVocabularyBoxTimeout();
                this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'lastHoveredWordIndex', value: -1 });
                this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'dictionarySearchTerm', value: '' });
                this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'hoveredWords', value: null });
                this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'active', value: false });
                this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'positionLeft', value: 0 });
                this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'positionTop', value: 0 });
                this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'userTranslation', value: '' });
                this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'dictionaryTranslation', value: '' });
                this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'apiTranslations', value: [] });
                this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'reading', value: '' });
                this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'hoveredPhrase', value: -1 });
                this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'stage', value: -1 });
                this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'key', value: this.$store.state.hoverVocabularyBox.key + 1 });
            },
            normalizeWordKey(word) {
                return (word || '').toString().trim().toLowerCase();
            },
            // Receive notification from WordSensesList that a manual sense was added
            // and the backend auto-marked the word as Learning 7 (or confirmed existing stage).
            onWordLearningUpdated(payload) {
                const { encounteredWordId, stage } = payload;
                if (!encounteredWordId || stage === null || stage === undefined) {
                    return;
                }

                const targetWord = this.uniqueWords.find(w => w.id === encounteredWordId);
                if (!targetWord) {
                    return;
                }

                // Use normalizeWordKey for consistent case-insensitive matching (b20f668)
                const targetKey = this.normalizeWordKey(targetWord.word);

                // Update uniqueWords[] — all same-name words share the stage
                for (let i = 0; i < this.uniqueWords.length; i++) {
                    if (this.normalizeWordKey(this.uniqueWords[i].word) === targetKey) {
                        this.uniqueWords[i].stage = stage;
                    }
                }

                // Update words[] — visible text tokens refresh their color immediately
                for (let i = 0; i < this.words.length; i++) {
                    if (this.normalizeWordKey(this.words[i].word) === targetKey) {
                        this.words[i].stage = stage;
                    }
                }

                // Sync Vuex store so the sidebar panel shows the updated stage
                this.$store.commit('vocabularyBox/setStage', stage);
            },
            preProcessWords() {
                for (let i = 0; i < this.uniqueWords.length; i++) {
                    const key = this.normalizeWordKey(this.uniqueWords[i].word);
                    if (key && !this.uniqueWordMap.has(key)) {
                        this.uniqueWordMap.set(key, i);
                    }
                }

                for (let i = 0; i < this.$props._text.words.length; i++) {
                    // skip whitespace
                    if (/\S/.test(this.$props._text.words[i].word) === false) {
                        continue;
                    }

                    this.words.push(this.$props._text.words[i]);
                }
            },
            hotkeyHandle(event) {
                if (!this.$props.hotkeysEnabled) {
                    return;
                }

                // Never intercept browser/system shortcuts (Ctrl+F, Ctrl+C, Ctrl+V, Ctrl+A, etc.)
                if (event.ctrlKey || event.metaKey || event.altKey) {
                    return;
                }

                // Do not intercept hotkeys when the user is typing in an input field,
                // textarea, select, or contentEditable element
                const target = event.target;
                if (target instanceof Element) {
                    const tag = target.tagName;
                    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || target.isContentEditable) {
                        return;
                    }
                }

                // Do not intercept when a Vuetify dialog, menu, or select is active
                if (document.querySelector('.v-dialog--active') ||
                    document.querySelector('.v-menu__content--active') ||
                    document.querySelector('.v-overlay--active') ||
                    document.querySelector('.menuable__content__active')) {
                    return;
                }

                switch(event.which) {
                    // text to speech
                    case 86:
                        this.textToSpeech();
                        break;

                    // set level to new
                    case 67:
                        this.setStage(2);
                        break;

                    // set level 0-7
                    case 48:
                    case 49:
                    case 50:
                    case 51:
                    case 52:
                    case 53:
                    case 54:
                    case 55:
                        event.preventDefault();
                        this.setStage(48 - event.which);
                        break;

                    // set level 0-7 numpad
                    case 96:
                    case 97:
                    case 98:
                    case 99:
                    case 100:
                    case 101:
                    case 102:
                    case 103:
                        event.preventDefault();
                        this.setStage(96 - event.which);
                        break;

                    // set level to ignore
                    case 88:
                        event.preventDefault();
                        this.setStage(1);
                        break;

                        // decrease font size
                    case 73:
                        // do not do anything if shift+i is pressed
                        if (event.shiftKey) {
                            return;
                        }

                        this.$emit('decrease-font-size');
                        break;

                    // increase font size
                    case 79:
                        event.preventDefault();
                        this.$emit('increase-font-size');
                        break;

                    // scroll up
                    case 38:
                    case 87:
                        event.preventDefault();
                        this.scrollText('up', event.shiftKey);
                        break;

                    // scroll down
                    case 40:
                    case 83:
                        event.preventDefault();
                        this.scrollText('down', event.shiftKey);
                        break;

                    // add selected word to anki
                    case 70:
                        event.preventDefault();
                        this.addSelectedWordToAnki();
                        break;

                    // unselect all words
                    case 27:
                        event.preventDefault();
                        this.unselectAllWords();
                        break;

                    // previous
                    case 37:
                    case 65:
                        event.preventDefault();
                        this.selectPreviousWord(false, event.shiftKey);
                        break;

                    // next
                    case 39:
                    case 68:
                        event.preventDefault();
                        this.selectNextWord(false, event.shiftKey);
                        break;

                    // plain text mode
                    case 80:
                        event.preventDefault();
                        this.unselectAllWords();
                        this.closeHoverBox();
                        this.$emit('toggle-plain-text-mode');
                        break;
                }
            },
            selectPreviousWord(newWordOnly, highlightedWordOnly) {
                if (!this.selection.length) {
                    var currentWordIndex = this.words.length - 1;
                } else {
                    var currentWordIndex = this.selection[0].wordIndex;
                }

                var wordToSelect = -1;

                // there are no previous words
                if (currentWordIndex == 0) {
                    return;
                }

                // go through the text backwards, and find a word to select
                for (var wordIndex = currentWordIndex - 1; wordIndex >= 0; wordIndex--) {
                    // skip not displayed whitespace words
                    if (document.querySelector('.word[wordindex="' + wordIndex  + '"]') === null) {
                        continue;
                    }

                    // select the previous word if it's a simple arrow key press
                    if (!newWordOnly && !highlightedWordOnly) {
                        wordToSelect = wordIndex;
                        break;
                    }

                    // select the previous new word
                    if (newWordOnly && this.words[wordIndex].stage == 2) {
                        wordToSelect = wordIndex;
                        break;
                    }

                    // select the previous highlighted word
                    if (highlightedWordOnly && this.words[wordIndex].stage < 0) {
                        wordToSelect = wordIndex;
                        break;
                    }
                }

                // return if no selectable word was found
                if (wordToSelect === -1) {
                    return;
                }

                // select the new word
                this.unselectAllWords();
                this.$nextTick(() => {
                    this.startSelection(wordToSelect);
                    this.finishSelection();;
                });
            },
            selectNextWord(newWordOnly, highlightedWordOnly) {
                if (!this.selection.length) {
                    var currentWordIndex = 0;
                } else {
                    var currentWordIndex = this.selection[this.selection.length - 1].wordIndex;
                }

                var wordToSelect = -1;

                // there are no next words to select
                if (currentWordIndex == this.words.length - 1) {
                    return;
                }

                // go through the text forward, and find a word to select
                for (var wordIndex = currentWordIndex + 1; wordIndex < this.words.length; wordIndex++) {
                    // skip not displayed whitespace words
                    if (document.querySelector('.word[wordindex="' + wordIndex  + '"]') === null) {
                        continue;
                    }

                    // select the previous word if it's a simple arrow key press
                    if (!newWordOnly && !highlightedWordOnly) {
                        wordToSelect = wordIndex;
                        break;
                    }

                    // select the previous new word
                    if (newWordOnly && this.words[wordIndex].stage == 2) {
                        wordToSelect = wordIndex;
                        break;
                    }

                    // select the previous highlighted word
                    if (highlightedWordOnly && this.words[wordIndex].stage < 0) {
                        wordToSelect = wordIndex;
                        break;
                    }

                }

                // return if no selectable word was found
                if (wordToSelect === -1) {
                    return;
                }

                // select the new word
                this.unselectAllWords();
                this.$nextTick(() => {
                    this.startSelection(wordToSelect);
                    this.finishSelection();;
                });
            },
            scrollText(direction, largeScroll) {
                let scrollChange = direction == 'up' ? -40 : 40;

                if (largeScroll) {
                    scrollChange *= 8;
                }


                document.getElementsByClassName('vocab-box-area')[0].scrollBy(0, scrollChange);
            },
            updateVocabBoxDataAfterSelection() {
                this.$store.commit('vocabularyBox/reset');
                this.$store.commit('vocabularyBox/setActive', true);

                if (this.selection.length == 1) {
                    var uniqueWord = this.uniqueWords[this.selection[0].uniqueWordIndex];
                    this.$store.commit('vocabularyBox/setType', 'word');
                    this.$store.commit('vocabularyBox/setWord', uniqueWord.word);
                    this.$store.commit('vocabularyBox/setReading', uniqueWord.reading);
                    this.$store.commit('vocabularyBox/setBaseWord', uniqueWord.base_word);
                    this.$store.commit('vocabularyBox/setStudyBase', uniqueWord.study_base || uniqueWord.base_word);
                    this.$store.commit('vocabularyBox/setBaseWordReading', uniqueWord.base_word_reading);
                    this.$store.commit('vocabularyBox/setTranslationText', uniqueWord.translation);
                    this.$store.commit('vocabularyBox/setStage', uniqueWord.stage);
                    this.$store.commit('vocabularyBox/setEncounteredWordId', uniqueWord.id || null);
                    const chapterId = this.$props._text && this.$props._text.chapterId !== undefined && this.$props._text.chapterId !== null
                        ? this.$props._text.chapterId
                        : null;
                    this.$store.commit('vocabularyBox/setChapterId', chapterId);
                    this.$store.commit('vocabularyBox/setSentenceIndex', this.selection[0].sentence_index);
                    this.$store.commit('vocabularyBox/setSentenceText', this.buildSelectedSentenceTextFromTokenWindow());
                    if (uniqueWord.base_word !== '') {
                        this.$store.commit('vocabularyBox/setSearchField', this.trimSearchTerm(uniqueWord.base_word));
                    } else {
                        this.$store.commit('vocabularyBox/setSearchField', uniqueWord.word);
                    }
                } else {
                    if (this.selectedPhrase !== -1) {
                        this.$store.commit('vocabularyBox/setType', 'phrase');
                        this.$store.commit('vocabularyBox/setReading', this.phrases[this.selectedPhrase].reading);
                        this.$store.commit('vocabularyBox/setTranslationText', this.phrases[this.selectedPhrase].translation);
                        this.$store.commit('vocabularyBox/setStage', this.phrases[this.selectedPhrase].stage);
                    } else {
                        this.$store.commit('vocabularyBox/setType', 'new-phrase');
                    }

                    for (let i = 0; i < this.selection.length; i++) {
                        if (this.selection[i].word.toLowerCase() == 'newline') {
                            continue;
                        }

                        if (this.selection.length > 1) {
                            this.$store.commit('vocabularyBox/setType', this.selectedPhrase === -1 ? 'new-phrase' : 'phrase');
                            this.$store.commit('vocabularyBox/pushWordToPhrase', this.selection[i]);
                        }

                        this.$store.commit('vocabularyBox/appendSearchField', this.selection[i].word);
                        if (this.selection[i].spaceAfter) {
                            this.$store.commit('vocabularyBox/appendSearchField', ' ');
                        }

                        if (this.selectedPhrase == -1) {
                            this.$store.commit('vocabularyBox/appendReading', this.selection[i].reading);
                        }
                    }
                }

                // collect unique kanji
                for (let wordIndex = 0; wordIndex < this.selection.length; wordIndex ++) {
                    var kanji = this.selection[wordIndex].kanji;
                    for (let kanjiIndex = 0; kanjiIndex < kanji.length; kanjiIndex ++) {
                        if (this.$store.state.vocabularyBox.kanjiList.indexOf(kanji[kanjiIndex]) === -1) {
                            this.$store.commit('vocabularyBox/pushKanjiToList', kanji[kanjiIndex]);
                        }
                    }
                }

                this.$store.commit('vocabularyBox/update');
                this.resizeHandle();
                this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'disabledWhileSelecting', value: false });
            },
            clearHoverVocabularyBoxTimeout() {
                if (this.hoverVocabularyDelayTimeout === null) {
                    return;
                }

                clearTimeout(this.hoverVocabularyDelayTimeout);
                this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'hoverVocabularyDelayTimeout', value: null });
            },
            makeHoverVocabularyBoxSearchRequest(term) {
                if (!this.$props.vocabularyHoverBoxSearch) {
                    this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'dictionaryTranslation', value: '' });
                    this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'apiTranslations', value: this.anyApiDictionaryEnabled ? ['loading'] : [] });
                }

                // do not make a search request if a word has been selected
                if (this.selection.length) {
                    return;
                }

                // do not make search request for empty string
                if (term === '') {
                    return;
                }

                term = term.toLowerCase();
                this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'dictionarySearchTerm', value: term });


                // make dictionary search
                axios.post('/dictionaries/search-for-hover-vocabulary', {
                    language: this.$props.language,
                    term: term
                }).then((response) => {
                    // return if a different word has been selected
                    // after the request was sent
                    if (this.$store.state.hoverVocabularyBox.dictionarySearchTerm !== response.data.term) {
                        return;
                    }

                    // return if there is no word selected anymore
                    if (this.$store.state.hoverVocabularyBox.dictionarySearchTerm === '') {
                        return;
                    }

                    this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'dictionaryTranslation', value: response.data.definitions.join(';') });
                    this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'key', value: this.$store.state.hoverVocabularyBox.key + 1 });
                    this.$nextTick(() => {
                        this.updateHoverVocabularyBoxPosition();
                    });
                });

                // make api search
                if (this.anyApiDictionaryEnabled) {
                    axios.post('/dictionaries/api/search', {
                        language: this.$props.language,
                        term: term
                    }).then((response) => {
                        let apiDefinitions = [];
                        response.data.forEach((item) => {
                            apiDefinitions = apiDefinitions.concat(item.definitions);
                        });

                        console.log('apiDefinitions', response.data, apiDefinitions);
                        this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'apiTranslations', value: apiDefinitions });
                        this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'key', value: this.$store.state.hoverVocabularyBox.key + 1 });
                        this.$nextTick(() => {
                            this.updateHoverVocabularyBoxPosition();
                        });
                    }).catch(() => {
                        this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'apiTranslations', value: ['error'] });
                    });
                }
            },
            unselectAllWordsOnEmptyClick(event) {
                // Normalize event.target to an Element (may be a text node)
                const el = event.target instanceof Element ? event.target : event.target?.parentElement;
                if (!el || !el.classList) {
                    return;
                }

                // Ignore clicks inside Vuetify overlays, menus, selects, and dialogs
                // (v-select/v-menu dropdowns are teleported to body, outside the side panel DOM)
                if (el.classList.contains('v-overlay__scrim') || el.classList.contains('v-overlay')) {
                    return;
                }
                if (el.closest('.v-menu__content') || el.closest('.v-select-list')
                    || el.closest('.v-list-item') || el.closest('.v-dialog')
                    || el.closest('.v-overlay') || el.closest('.menuable__content__active')
                    || el.closest('#vocab-side-box') || el.closest('#vocab-box')) {
                    return;
                }

                // Do not unselect when clicking on words or the text block.
                // Under normal circumstances these clicks are stopped by @mouseup.stop
                // on .text-block, but if propagation is disrupted (e.g. by browser find
                // highlights), this guard prevents word clicks from being misclassified.
                if (el.closest('.word') || el.closest('.text-block') || el.closest('[wordindex]')) {
                    return;
                }

                this.unselectAllWords();
            },
            unselectAllWords() {
                if (this.selection.length == 1) {
                    this.saveWord();
                } else if (this.selectedPhrase !== -1) {
                    this.savePhrase();
                }

                this.selectedPhrase = -1;
                this.selection = [];
                this.ongoingSelection = [];
                this.$store.commit('vocabularyBox/setActive', false);

                this.unselectAllWordsProcess();
                this.removePhraseHover();
                this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'disabledWhileSelecting', value: false });
            },
            unselectAllWordsProcess() {
                this.selectedPhrase = -1;
                this.selection = [];
                this.$store.commit('vocabularyBox/reset');

                for(let i = 0; i < this.words.length; i++) {
                    this.words[i].selected = false;
                }
            },
            updateWordLookupCount(word) {
                const key = this.normalizeWordKey(word);
                const uniqueWordIndex = this.uniqueWordMap.get(key);
                if (uniqueWordIndex === undefined || !this.uniqueWords[uniqueWordIndex]) {
                    return;
                }

                this.uniqueWords[uniqueWordIndex].lookup_count ++;
                this.uniqueWords[uniqueWordIndex].definitions_checked  = true;
                for (var i  = 0; i < this.words.length; i++) {
                    if (this.words[i].word.toLowerCase() == word) {
                        this.words[i].lookup_count ++;
                    }
                }
            },
            updatePhraseLookupCount(phraseIndex) {
                this.phrases[phraseIndex].lookup_count ++;
                this.phrases[phraseIndex].definitions_checked  = true;
            },
            updateSelectedWordLookupCount(id) {

            },
            addSelectedWordToAnki() {
                if (this.selection.length === 0 || (this.selection.length > 1 && this.selectedPhrase === -1)) {
                    return;
                }

                // get example sentence and add space.
                var exampleSentence = this.getExampleSentence(true);
                var exampleSentenceText = '';
                for (let wordIndex = 0; wordIndex < exampleSentence.length; wordIndex++) {
                    exampleSentenceText += exampleSentence[wordIndex].word;
                }

                if (this.selection.length == 1) {
                    var data = {
                        word: this.uniqueWords[this.selection[0].uniqueWordIndex].word,
                        reading: this.$store.state.vocabularyBox.reading,
                        translation: this.$store.state.vocabularyBox.translationText,
                        exampleSentence: exampleSentenceText,
                    };
                } else {
                    let wordsText = '';
                    for (let wordIndex = 0; wordIndex < this.selection.length; wordIndex ++) {
                        wordsText += this.selection[wordIndex].word;
                        if (this.selection[wordIndex].spaceAfter) {
                            wordsText += ' ';
                        }
                    }

                    var data = {
                        word: wordsText,
                        reading: this.$store.state.vocabularyBox.reading,
                        translation: this.$store.state.vocabularyBox.translationText,
                        exampleSentence: exampleSentenceText
                    };
                }

                axios.post('/anki/add-card', data).catch((error) => {
                        if (!this.ankiShowNotifications) {
                            return;
                        }

                        this.snackBars.push({id: this.snackbarId, content: data.word + ': ' + error.response.data.message, type: 'error'});
                        var snackbarToRemove = this.snackbarId;
                        this.snackbarId ++;
                        setTimeout(() => {
                            this.removeSnackbar(snackbarToRemove);
                        }, 5000);
                }).then((response) => {
                    if (response.status !== 200) {
                         return;
                    }

                    if (!this.ankiShowNotifications) {
                        return;
                    }

                    this.snackBars.push({id: this.snackbarId, content: data.word, type: response.data});

                    var snackbarToRemove = this.snackbarId;
                    this.snackbarId ++;
                    setTimeout(() => {
                        this.removeSnackbar(snackbarToRemove);
                    }, 5000);
                });
            },
            removeSnackbar(snackbarId) {
                for (let snackBarIndex = 0; snackBarIndex < this.snackBars.length; snackBarIndex++) {
                    if (this.snackBars[snackBarIndex].id == snackbarId) {
                        this.snackBars.splice(snackBarIndex, 1);
                    }
                }
            },
            addNewPhrase() {
                // create phrase object
                var phrase = {
                    id: -1,
                    stage: 0,
                    words: [],
                    reading: this.$store.state.vocabularyBox.reading,
                    translation: '',
                    definitions_checked: true,
                };

                for (var i = 0; i < this.selection.length; i++) {
                    if (this.selection[i].word.toLowerCase() == 'newline') {
                        continue;
                    }

                    phrase.words.push(this.selection[i].word.toLowerCase());
                }

                // find all instance of the new phrase in the text
                var phraseOccurences = [];
                for (var i = 0; i < this.words.length; i++) {
                    // check if the current word is the start of the phrase
                    if (this.words[i].word.toLowerCase() == phrase.words[0]) {
                        phraseOccurences.push([
                            {
                                word: this.words[i].word.toLowerCase(),
                                wordIndex: i,
                                newLineCount: 0
                            }
                        ]);
                    }

                    // check if the current word is the continuation of a phrase
                    for (let p = 0 ; p < phraseOccurences.length; p++) {
                        if (phraseOccurences[p].length == phrase.words.length) {
                            continue;
                        }

                        if (phrase.words[phraseOccurences[p].length] == this.words[i].word.toLowerCase() &&
                            (i - 1) == phraseOccurences[p][phraseOccurences[p].length - 1].wordIndex + phraseOccurences[p][phraseOccurences[p].length - 1].newLineCount) {
                            phraseOccurences[p].push({
                                word: this.words[i].word.toLowerCase(),
                                wordIndex: i,
                                newLineCount: 0
                            });
                        }

                        // count 'NEWLINE' words for comparison
                        if (this.words[i].word.toLowerCase() == 'newline') {
                            phraseOccurences[p][phraseOccurences[p].length - 1].newLineCount ++;
                        }
                    }

                }

                // mark all instance of the new phrase in the text
                for (let p = 0 ; p < phraseOccurences.length; p++) {
                    if (phraseOccurences[p].length < phrase.words.length) {
                        continue;
                    }

                    for (let i = 0; i < phraseOccurences[p].length; i++) {
                        this.words[phraseOccurences[p][i].wordIndex].phraseIndexes.push(this.phrases.length);
                    }
                }

                this.phrases.push(JSON.parse(JSON.stringify(phrase)));

                this.updatePhraseBorders();
                this.selectedPhrase = this.getSelectedPhraseIndex();

                this.updateSelectedWordStage();
                this.resizeHandle();
                this.savePhrase();
                this.$store.commit('vocabularyBox/setType', 'phrase');
            },
            getSelectedPhraseIndex() {
                var phraseIndex = -1;
                var selectedText = this.selection.map(a => a.word.toLowerCase()).join('');

                while (selectedText.indexOf('newline') !== -1) {
                    selectedText = selectedText.replace('newline', '');
                }


                for (let i = 0; i < this.phrases.length; i++) {
                    if (selectedText == this.phrases[i].words.join('')) {
                        phraseIndex = i;
                        break;
                    }
                }

                return phraseIndex;
            },
            showDeletePhraseDialog() {
                this.deletePhraseDialog = true;
            },
            deletePhrase() {
                if (this.selectedPhrase == -1) {
                    return;
                }

                this.deletePhraseDialog = false;
                var deletedPhraseId = this.phrases[this.selectedPhrase].id;
                var deletedPhraseIndex = this.phrases.map(e => e.id).indexOf(deletedPhraseId);

                for (var i  = 0; i < this.words.length; i++) {
                    // remove phrase index from words
                    for (var p = this.words[i].phraseIndexes.length - 1; p >= 0; p--) {
                        if (this.words[i].phraseIndexes[p] == deletedPhraseIndex) {
                            this.words[i].phraseIndexes.splice(p, 1);
                            break;
                        }
                    }

                    // decrease phrase indexes larger than the deleted one
                    for (var p = this.words[i].phraseIndexes.length - 1; p >= 0; p--) {
                        if (this.words[i].phraseIndexes[p] > deletedPhraseIndex) {
                            this.words[i].phraseIndexes[p] --;
                        }
                    }
                }

                // delete phrase
                this.phrases.splice(deletedPhraseIndex, 1);

                axios.post('/vocabulary/phrases/delete', {
                    phraseId: deletedPhraseId
                }).then(function (response) {
                });


                this.selectedPhrase = -1;
                this.selection = [];
                this.removePhraseHover();
                this.unselectAllWords();
                this.updatePhraseBorders();
            },
            deleteWord() {
                if (this.selectedPhrase !== -1 || this.selection.length !== 1) {
                    return;
                }

                var selectedWord = this.uniqueWords[this.selection[0].uniqueWordIndex];
                if (!selectedWord || !selectedWord.id) {
                    return;
                }

                if (!window.confirm(`确定要删除词条“${selectedWord.word}”吗？这会将它标为已忽略并停用复习卡。`)) {
                    return;
                }

                axios.post('/vocabulary/word/delete', {
                    id: selectedWord.id
                }).then(() => {
                    for (var i = 0; i < this.uniqueWords.length; i++) {
                        if (this.uniqueWords[i].word.toLowerCase() == selectedWord.word.toLowerCase()) {
                            this.uniqueWords[i].stage = 1;
                        }
                    }

                    for (var w = 0; w < this.words.length; w++) {
                        if (this.words[w].word.toLowerCase() == selectedWord.word.toLowerCase()) {
                            this.words[w].stage = 1;
                        }
                    }

                    this.$store.commit('vocabularyBox/setStage', 1);
                    this.unselectAllWords();
                }).catch(() => {
                    alert('词条删除失败，请稍后重试。');
                });
            },
            savePhrase(withStage = false, exampleSentenceChanged = false) {
                if (this.phraseCurrentlySaving) {
                    return;
                }

                this.phraseCurrentlySaving = true;
                var selectedPhraseId = this.phrases[this.selectedPhrase].id;
                for (var i  = 0; i < this.phrases.length; i++) {
                    if (this.phrases[i].id == selectedPhraseId) {
                        this.phrases[i].translation = this.$store.state.vocabularyBox.translationText;
                        this.phrases[i].reading = this.$store.state.vocabularyBox.reading;
                    }
                }

                var url = '/vocabulary/phrases/update';
                var saveData = {
                    reading: this.phrases[this.selectedPhrase].reading,
                    translation: this.phrases[this.selectedPhrase].translation,
                    lookup_count: this.phrases[this.selectedPhrase].lookup_count,
                };

                if (this.phrases[this.selectedPhrase].id === -1) {
                    saveData.words = JSON.stringify(this.phrases[this.selectedPhrase].words);
                    saveData.stage = this.phrases[this.selectedPhrase].stage;
                    url = '/vocabulary/phrases/create';
                } else {
                    saveData.id = this.phrases[this.selectedPhrase].id;
                }

                if (withStage) {
                    saveData.stage = this.phrases[this.selectedPhrase].stage;
                }

                axios.post(url, saveData).then((response) => {
                    for (let i = 0; i < this.phrases.length; i++) {
                        if (this.phrases[i].id == -1) {
                            this.phrases[i].id = parseInt(response.data);
                        }
                    }

                    this.phraseCurrentlySaving = false;
                }).catch((error) => {
                });

                if (exampleSentenceChanged) {
                    this.updateExampleSentence();
                }
            },
            updatePhraseBorders() {
                    for (var i = 0; i < this.words.length; i++) {
                    if (this.words[i].phraseIndexes.length) {
                        var lowestPhraseStage = 1000;
                        for (let p = 0; p < this.words[i].phraseIndexes.length; p++) {
                            if (parseInt(this.phrases[this.words[i].phraseIndexes[p]].stage) < lowestPhraseStage) {
                                lowestPhraseStage = parseInt(this.phrases[this.words[i].phraseIndexes[p]].stage);
                            }
                        }

                        this.words[i].phraseStage = lowestPhraseStage;
                    }

                    // phrase start
                    this.words[i].phraseStart = false;
                    this.words[i].phraseEnd = false;
                    if (this.words[i].phraseIndexes.length && (i == 0 || !this.words[i - 1].phraseIndexes.length)) {
                        this.words[i].phraseStart = true;
                    }

                    // phrase end
                    if (this.words[i].phraseIndexes.length && (i + 1 == this.words.length || !this.words[i + 1].phraseIndexes.length)) {
                        this.words[i].phraseEnd = true;
                    }
                }
            },
            updateVocabBoxData(newVocabBoxData) {
                this.$store.commit('vocabularyBox/setReading', newVocabBoxData.reading);
                this.$store.commit('vocabularyBox/setBaseWord', newVocabBoxData.baseWord);
                if (newVocabBoxData.studyBase !== undefined) {
                    this.$store.commit('vocabularyBox/setStudyBase', newVocabBoxData.studyBase);
                }
                this.$store.commit('vocabularyBox/setBaseWordReading', newVocabBoxData.baseWordReading);
                this.$store.commit('vocabularyBox/setPhraseReading', newVocabBoxData.phraseReading);
                this.$store.commit('vocabularyBox/setTranslationText', newVocabBoxData.translationText);
            },
            // Called when user edits the lemma from the side box
            onSaveWordFromSideBox(withStage = false) {
                if (this.selection.length !== 1) return;
                this.saveWord(withStage, false);
                // Notify WordSensesList to refresh with new lemma
                this.$nextTick(() => {
                    if (this.$refs.vocabularySideBox && this.$refs.vocabularySideBox.$refs.wordSensesList) {
                        this.$refs.vocabularySideBox.$refs.wordSensesList.refreshLemma();
                    }
                });
            },
            saveWord(withStage = false, exampleSentenceChanged = false) {
                var selectedWord = this.uniqueWords[this.selection[0].uniqueWordIndex];


                // update unique words in all blocks
                for (var i  = 0; i < this.uniqueWords.length; i++) {
                    if (this.uniqueWords[i].word.toLowerCase() == selectedWord.word.toLowerCase()) {
                        this.uniqueWords[i].translation = this.$store.state.vocabularyBox.translationText;
                        this.uniqueWords[i].reading = this.$store.state.vocabularyBox.reading;
                        this.uniqueWords[i].base_word = this.$store.state.vocabularyBox.baseWord;
                        this.uniqueWords[i].base_word_reading = this.$store.state.vocabularyBox.baseWordReading;
                        this.uniqueWords[i].stage = selectedWord.stage;
                    }
                }

                // update stages in all text
                for (var i  = 0; i < this.words.length; i++) {
                    if (this.words[i].word.toLowerCase() == selectedWord.word.toLowerCase()) {
                        this.words[i].stage = selectedWord.stage;
                        this.words[i].furigana = this.$store.state.vocabularyBox.reading;
                    }
                }

                var saveData = {
                    id: selectedWord.id,
                    translation: this.$store.state.vocabularyBox.translationText,
                    reading: this.$store.state.vocabularyBox.reading,
                    base_word: this.$store.state.vocabularyBox.baseWord,
                    study_base: this.$store.state.vocabularyBox.studyBase,
                    base_word_reading: this.$store.state.vocabularyBox.baseWordReading,
                    lookup_count: selectedWord.lookup_count,
                };

                if (withStage) {
                    saveData.stage = selectedWord.stage;
                }

                // 当词条进入 Learning 状态时，传递上下文以创建 word_sense 桥接
                if (saveData.stage < 0 && saveData.translation) {
                    if (this.$props._text && this.$props._text.chapterId) {
                        saveData.chapter_id = this.$props._text.chapterId;
                    }
                    if (this.selection && this.selection[0] && this.selection[0].sentence_index !== undefined) {
                        saveData.sentence_index = this.selection[0].sentence_index;
                    }
                    saveData.word = selectedWord.word;
                }

                axios.post('/vocabulary/word/update', saveData).catch(function (error) {
                });

                if (exampleSentenceChanged) {
                    this.updateExampleSentence();
                }
            },
            setStage(stage) {
                var hoverSetStage = false;

                // do not set selected phrases to ignored
                if (this.selection.length > 1 && stage > 0) {
                    return;
                }

                if (!this.selection.length && this.$store.state.hoverVocabularyBox.hoveredWords !== null) {
                    hoverSetStage = true;

                    // do not set hovered phrases to ignored
                    if (this.$store.state.hoverVocabularyBox.hoveredWords.length > 1 && stage > 0) {
                        return;
                    }

                    // select hovered word and click on it
                    for (let i = 0; i < this.$store.state.hoverVocabularyBox.hoveredWords.length; i++) {
                        if (!this.$store.state.hoverVocabularyBox.hoveredWords[i].hover) {
                            continue;
                        }

                        this.startSelection(this.$store.state.hoverVocabularyBox.hoveredWords[0].wordIndex);
                        this.finishSelection();
                        break;
                    }
                }

                if (!this.selection.length || (this.selection.length > 1 && this.selectedPhrase === -1)) {
                    return;
                }

                // determine if saving is needed
                var save = 'none';
                if (this.selection.length == 1 && this.uniqueWords[this.selection[0].uniqueWordIndex].stage !== stage) {
                    save = 'word';
                } else if (this.selection.length > 1 && this.phrases[this.selectedPhrase].stage !== stage) {
                    save = 'phrase';
                }

                if (this.selectedPhrase == -1 && this.selection.length == 1) {
                    if (stage == 0) {
                        this.learnedWords ++;
                    }

                    // set stage for all unique words that match the selected word
                    for (var i  = 0; i < this.uniqueWords.length; i++) {
                        if (this.uniqueWords[i].word == this.selection[0].word.toLowerCase()) {
                            this.uniqueWords[i].stage = stage;
                        }
                    }

                    // set stage for all words that match the selected word
                    for (var i  = 0; i < this.words.length; i++) {
                        if (this.words[i].word.toLowerCase() == this.selection[0].word.toLowerCase()) {
                            this.words[i].stage = stage;
                        }
                    }
                } else if (this.selectedPhrase !== -1) {
                    // set stage for all phrases that match the selected word
                    for (var i  = 0; i < this.phrases.length; i++) {
                        if (this.phrases[i].id == this.phrases[this.selectedPhrase].id) {
                            this.phrases[i].stage = stage;
                        }
                    }

                    this.updatePhraseBorders();
                }

                // add word/phrase to anki
                if (this.ankiAutoAddCards && stage < 0 && (this.$store.state.vocabularyBox.stage >= 0 || this.$store.state.vocabularyBox.stage === undefined)) {
                    this.addSelectedWordToAnki();
                }

                // save word/phrase
                this.updateSelectedWordStage();
                if (save == 'word') {
                    this.saveWord(true, stage < 0);

                } else if (save == 'phrase') {
                    this.savePhrase(true, stage < 0);
                }

                this.$store.commit('vocabularyBox/setStage', stage);

                // unselect word if it was hovered
                if (hoverSetStage) {
                    this.unselectAllWords();
                    this.$store.commit('hoverVocabularyBox/setValue', { propertyName: 'stage', value: stage < 0 ? stage : null });
                }
            },
            updateSelectedWordStage() {
                if (this.selectedPhrase == -1 && this.selection.length) {
                    this.$store.commit('vocabularyBox/setStage', parseInt(this.uniqueWords[this.selection[0].uniqueWordIndex].stage));
                } else if (this.selectedPhrase !== -1){
                    this.$store.commit('vocabularyBox/setStage', parseInt(this.phrases[this.selectedPhrase].stage));
                }

                if (this.$store.state.vocabularyBox.stage == 2) {
                    this.$store.commit('vocabularyBox/setStage', undefined);
                }
            },
            getExampleSentence(withSpaces = false) {
                var sentenceIndexes = [];
                for (var i = 0; i < this.selection.length; i++) {
                    if (sentenceIndexes.indexOf(this.selection[i].sentence_index) == -1) {
                        sentenceIndexes.push(this.selection[i].sentence_index);
                    }
                }

                var exampleSentence = [];
                for (var i = 0; i < this.words.length; i++) {
                    if (this.words[i].word == 'NEWLINE'
                        || sentenceIndexes.indexOf(this.words[i].sentence_index) == -1) {
                        continue;
                    }

                    exampleSentence.push({
                        word: this.words[i].word,
                        phrase_ids: []
                    });

                    if (withSpaces && this.words[i].spaceAfter) {
                        exampleSentence[exampleSentence.length - 1].word += ' ';
                    }
                }

                return exampleSentence;
            },
            // === Token-window sentence extraction (Task B) ===

            // Find the array index of the selected word in this.words.
            // Uses object reference first, then falls back to wordIndex (template index).
            resolveSelectedWordArrayIndex() {
                if (!this.selection.length) return -1;

                const selected = this.selection[0];

                // Primary: object reference match
                for (let i = 0; i < this.words.length; i++) {
                    if (this.words[i] === selected) return i;
                }

                // Fallback: use selected.wordIndex (template index = array index)
                const idx = selected.wordIndex;
                if (idx !== undefined && Number.isInteger(idx) && idx >= 0 && idx < this.words.length) {
                    if (this.words[idx].word === selected.word) return idx;
                }

                return -1;
            },

            // Hard boundaries: never cross these
            isHardBoundary(word) {
                if (!word) return true;
                if (word.word === 'NEWLINE' || word.word === 'PARAGRAPH_BREAK') return true;
                if (word.is_structure) return true;
                if (this.isSectionMarker(word.word)) return true;
                return false;
            },

            // Check if a token ending in "." is a known compound abbreviation.
            // Examples: Mr. Dr. e.g. i.e. U.S. a.m. p.m.
            isKnownAbbreviationToken(word) {
                if (!word) return false;
                const cleaned = word.replace(/\.+$/, '').toLowerCase();
                return ENGLISH_ABBREVIATIONS.has(cleaned)
                    || COMPOUND_ABBREVIATIONS.has(cleaned + '.')
                    || COMPOUND_ABBREVIATIONS.has(cleaned);
            },

            // Check if a token is a decimal number like 15.2, 3.14, 1,234.56
            isDecimalToken(word) {
                if (!word) return false;
                return /^\d[\d,]*\.\d+$/.test(word);
            },

            // Check if a token is an initialism chain like U.S. U.K. U.N.
            isInitialismToken(word) {
                if (!word) return false;
                return /^([A-Z]\.){2,}$/.test(word);
            },

            // Check if the token before a standalone "." is in the abbreviation whitelist.
            // Example: "Mr . Smith" — prev = "Mr" → true
            isAbbreviationPrecursor(prev) {
                if (!prev) return false;
                const cleaned = prev.word.replace(/\.+$/, '').toLowerCase();
                return ENGLISH_ABBREVIATIONS.has(cleaned);
            },

            // Check if a standalone "." is part of a dotted abbreviation chain
            // like U . S .  or e . g .  (needs at least 2 letters + 2 dots).
            isDottedAbbreviationPeriod(index) {
                const current = this.words[index];
                if (!current || current.word !== '.') return false;

                const prev = this.words[index - 1];
                const next = this.words[index + 1];

                // Precondition: the token before "." must be a single letter
                if (!prev || !/^[A-Za-z]$/.test(prev.word)) return false;

                // Case 1: middle dot — U . S  or e . g (next is a single letter, chain continues)
                if (next && /^[A-Za-z]$/.test(next.word)) {
                    return true;
                }

                // Case 2: terminal dot — U . S . retail  or e . g . tools
                // Need: single-letter + . + single-letter + current .
                // i.e. words[index-3] is a single letter, words[index-2] is "."
                const beforePrev = this.words[index - 2];
                const beforeBeforePrev = this.words[index - 3];

                if (
                    beforeBeforePrev &&
                    beforePrev &&
                    /^[A-Za-z]$/.test(beforeBeforePrev.word) &&
                    beforePrev.word === '.'
                ) {
                    return true;
                }

                return false;
            },

            // Check if a standalone "." is a decimal point: 15 . 2
            isDecimalSplit(prev, next) {
                if (!prev || !next) return false;
                return /\d$/.test(prev.word) && /^\d/.test(next.word);
            },

            // Determine if a word/token is a sentence boundary.
            // Handles ? ! . with three-way classification for ".".
            isSentenceBoundary(word, index) {
                if (!word) return false;
                const w = word.word;

                // ? and ! are always boundaries
                if (w === '?' || w === '!') return true;
                if (w.endsWith('?') || w.endsWith('!')) return true;

                // Standalone "." token
                if (w === '.') {
                    const prev = index > 0 ? this.words[index - 1] : null;
                    if (!prev) return true;

                    // Previous token already ends with "." (e.g. "Mr.") → this "." is standalone punctuation
                    if (prev.word.endsWith('.')) return true;

                    // Abbreviation whitelist (Mr .  Dr .  etc.)
                    if (this.isAbbreviationPrecursor(prev)) return false;

                    // Dotted abbreviation chain (U . S .  e . g .  i . e .  a . m .  p . m .)
                    if (this.isDottedAbbreviationPeriod(index)) return false;

                    // Decimal point
                    const next = index < this.words.length - 1 ? this.words[index + 1] : null;
                    if (this.isDecimalSplit(prev, next)) return false;

                    // Normal sentence-ending period
                    return true;
                }

                // Non-standalone token ending with "." — three-way classification
                if (w.endsWith('.') && w !== '.') {
                    if (this.isKnownAbbreviationToken(w)) return false;   // Mr. Dr. e.g. i.e.
                    if (this.isDecimalToken(w)) return false;              // 15.2 3.14
                    if (this.isInitialismToken(w)) return false;           // U.S. U.K.
                    return true;  // left. stayed. happened. → sentence boundary
                }

                return false;
            },

            // Token-window based sentence extraction.
            // Scans left and right from the selected word until a hard or sentence boundary.
            // Non-English languages fall back to the original sentence_index-based method.
            buildSelectedSentenceTextFromTokenWindow() {
                if (!this.selection.length) return '';

                // Non-English: use original sentence_index-based logic
                if (this.$props.language !== 'english') {
                    return this.buildSelectedSentenceText();
                }

                const startIndex = this.resolveSelectedWordArrayIndex();
                if (startIndex < 0) {
                    return this.buildSelectedSentenceText();  // fallback
                }

                const MAX_TOKENS = 120;

                // Scan left
                let left = startIndex;
                let tokenCount = startIndex - left;
                while (left > 0 && tokenCount < MAX_TOKENS) {
                    const candidate = this.words[left - 1];
                    if (this.isHardBoundary(candidate)) break;
                    if (this.isSentenceBoundary(candidate, left - 1)) break;
                    left--;
                    tokenCount++;
                }

                // Scan right
                let right = startIndex;
                tokenCount = right - startIndex;
                while (right < this.words.length - 1 && tokenCount < MAX_TOKENS) {
                    const candidate = this.words[right + 1];
                    if (this.isHardBoundary(candidate)) break;
                    if (this.isSentenceBoundary(this.words[right], right)) break;
                    right++;
                    tokenCount++;
                }

                // Join tokens [left, right]
                let text = '';
                for (let i = left; i <= right; i++) {
                    text += this.words[i].word;
                    if (this.words[i].spaceAfter && i < right) {
                        text += ' ';
                    }
                }
                text = text.trim();

                // Fallback if result is too long
                if (text.length > 600) {
                    return this.buildSelectedSentenceText();
                }

                return text;
            },

            // Original sentence_index-based extraction.  Kept for non-English languages
            // and as a fallback for the token-window method.
            buildSelectedSentenceText() {
                if (!this.selection.length) {
                    return '';
                }

                var sentenceIndex = this.selection[0].sentence_index;
                var sentenceText = '';

                for (var i = 0; i < this.words.length; i++) {
                    if (this.words[i].word == 'NEWLINE' || this.words[i].sentence_index !== sentenceIndex) {
                        continue;
                    }

                    sentenceText += this.words[i].word;
                    if (this.words[i].spaceAfter) {
                        sentenceText += ' ';
                    }
                }

                return sentenceText.trim();
            },
            updateExampleSentence() {
                var exampleSentence = this.getExampleSentence();

                var targetType = this.selection.length > 1 ? 'phrase' : 'word';
                var targetId = this.uniqueWords[this.selection[0].uniqueWordIndex].id;

                if (targetType == 'phrase') {
                    targetId = this.phrases[this.selectedPhrase].id;
                }

                axios.post('/vocabulary/example-sentence/create-or-update', {
                    targetType: targetType,
                    targetId: targetId,
                    exampleSentenceWords: JSON.stringify(exampleSentence),
                });
            },
            resizeHandle() {
                // update bottom sheet vocabulary
                this.$store.commit('vocabularyBox/setVocabularyBottomSheetVisible', (window.innerWidth <= 768));

                this.$nextTick(() => {
                    this.updateVocabBoxPosition();
                });
            },
            updateVocabBoxPosition() {
                var margin = 8;
                this.$store.commit('vocabularyBox/setWidth', 400);
                this.$store.commit('vocabularyBox/setVocabularyBottomSheetVisible', (window.innerWidth <= 768));
                var vocabBoxAreaElement = document.getElementsByClassName('vocab-box-area')[0];
                var vocabBoxArea = vocabBoxAreaElement.getBoundingClientRect();


                // update sidebar
                if (this.$props.vocabularySidebarFits && this.$props.vocabularySidebar) {
                    this.$store.commit('vocabularyBox/setSidebarHidden', false);
                    this.$store.commit('vocabularyBox/setHeight', vocabBoxAreaElement.offsetHeight);
                    this.$store.commit('vocabularyBox/setPositionLeft', vocabBoxArea.right);
                    this.$store.commit('vocabularyBox/setPositionTop', vocabBoxArea.top);
                    return;
                }

                if (!this.selection.length) {
                    return;
                }

                if (this.selection.length == 1) {
                    var selectedWordPositions = document.querySelector('[wordindex="' + this.selection[0].wordIndex + '"]').getBoundingClientRect();
                } else if (this.selection.length > 1) {
                    var selectedWordPositions = document.querySelector('[wordindex="' + this.selection[parseInt(this.selection.length / 2)].wordIndex + '"]').getBoundingClientRect();
                }

                this.$store.commit(
                    'vocabularyBox/setPositionLeft', 
                    selectedWordPositions.right - vocabBoxArea.left - this.$store.state.vocabularyBox.width / 2 - (selectedWordPositions.right - selectedWordPositions.left) / 2
                );

                if (window.innerWidth  < 440) {
                    this.$store.commit('vocabularyBox/setPositionLeft', 0);
                } else if (this.$store.state.vocabularyBox.positionLeft < margin) {
                    this.$store.commit('vocabularyBox/setPositionLeft', margin);
                } else if (this.$store.state.vocabularyBox.positionLeft > vocabBoxArea.right - vocabBoxArea.left - this.$store.state.vocabularyBox.width - margin) {
                    this.$store.commit(
                        'vocabularyBox/setPositionLeft', 
                        vocabBoxArea.right - vocabBoxArea.left - this.$store.state.vocabularyBox.width - margin
                    );
                }

                this.$store.commit(
                    'vocabularyBox/setPositionTop', 
                    selectedWordPositions.bottom - vocabBoxArea.top + vocabBoxAreaElement.scrollTop + 25
                );

                this.scrollToVocabBox();
            },
            scrollToVocabBox() {
                setTimeout(() => {
                    var vocabBox = document.getElementById('vocab-box');
                    if (vocabBox && this.$props.vocabBoxScrollIntoView == 'scroll-into-view') {
                        vocabBox.scrollIntoView(false);
                    }

                    if (vocabBox && this.$props.vocabBoxScrollIntoView == 'scroll-into-view-if-needed') {
                        vocabBox.scrollIntoViewIfNeeded(false);
                    }
                }, 450);
            },
            trimSearchTerm(searchTerm) {
                searchTerm = searchTerm.toLowerCase();
                var trimmedSearchTerm = searchTerm;

                // norwegian
                if (this.$props.language == 'norwegian' && searchTerm.substring(0, 2) == 'å ') {
                    trimmedSearchTerm = searchTerm.slice(2);
                }

                if (this.$props.language == 'norwegian' && searchTerm.substring(0, 3) == 'et ') {
                    trimmedSearchTerm = searchTerm.slice(3);
                }

                if (this.$props.language == 'norwegian' && searchTerm.substring(0, 3) == 'en ') {
                    trimmedSearchTerm = searchTerm.slice(3);
                }

                if (this.$props.language == 'norwegian' && searchTerm.substring(0, 3) == 'ei ') {
                    trimmedSearchTerm = searchTerm.slice(3);
                }

                // german
                if (this.$props.language == 'german' && searchTerm.substring(0, 4) == 'die ') {
                    trimmedSearchTerm = searchTerm.slice(4);
                }

                if (this.$props.language == 'german' && searchTerm.substring(0, 4) == 'der ') {
                    trimmedSearchTerm = searchTerm.slice(4);
                }

                if (this.$props.language == 'german' && searchTerm.substring(0, 4) == 'das ') {
                    trimmedSearchTerm = searchTerm.slice(4);
                }

                return trimmedSearchTerm;
            },
            getLeveledUpWordsAndPhrases() {
                let data = {
                    wordIds: [],
                    phraseIds: [],
                    wordsAndPhrases: [],
                }

                // collect words
                this.uniqueWords.forEach((word) => {
                    if (!word.definitions_checked && word.stage < 0) {
                        data.wordIds.push(word.id);
                        data.wordsAndPhrases.push(word);
                        data.wordsAndPhrases[data.wordsAndPhrases.length - 1].type = 'word';
                    }
                });

                // collect phrases
                this.phrases.forEach((phrase) => {
                    if (!phrase.definitions_checked && phrase.stage < 0) {
                        data.phraseIds.push(phrase.id);
                        data.wordsAndPhrases.push(phrase);
                        data.wordsAndPhrases[data.wordsAndPhrases.length - 1].type = 'phrase';
                    }
                });

                return data;
            }
        }
    }
</script>
