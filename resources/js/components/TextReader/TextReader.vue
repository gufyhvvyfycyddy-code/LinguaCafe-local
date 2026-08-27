<template>
    <div id="fullscreen-box" :class="{'fullscreen-mode': fullscreenMode}" :style="{'background-color': $vuetify.theme.currentTheme.background}">
        <v-container v-if="readerLoading || readerError" class="py-8">
            <v-card outlined class="rounded-lg pa-6 mx-auto" max-width="680">
                <v-progress-linear v-if="readerLoading" indeterminate color="primary" class="mb-4"></v-progress-linear>
                <div v-if="readerLoading">正在加载阅读内容...</div>
                <v-alert v-if="readerError" type="error" outlined class="mb-4">{{ readerError }}</v-alert>
                <v-btn v-if="readerError" rounded depressed color="primary" to="/books">
                    返回阅读材料
                </v-btn>
            </v-card>
        </v-container>

        <!-- Hotkey information dialog -->
        <text-reader-hotkey-information-dialog
            v-model="hotkeyDialog"
        ></text-reader-hotkey-information-dialog>

        <!-- AI Reading Assist Dialog -->
        <text-reader-ai-assist
            v-model="aiAssistDialog"
            :chapter-id="chapterId"
            :marked-targets="markedUnfamiliarTargets"
            :marked-targets-snapshot-version="markedUnfamiliarSnapshotVersion"
            :trust-ai-reading-sense-binding="settings.trustAiReadingSenseBinding"
            :auto-add-ai-new-sense-to-learning="settings.autoAddAiNewSenseToLearning"
            @confirmed="refreshReadingSenseVerification"
        ></text-reader-ai-assist>

        <reading-sense-verification-dialog
            v-model="readingSenseVerificationDialog"
            :items="readingSenseVerificationItems"
            :loading="readingSenseVerificationLoading"
            :error="readingSenseVerificationError"
            :busy-occurrence-id="readingSenseVerificationBusyId"
            :resolution-enabled="Boolean(readingSessionId)"
            @refresh="refreshReadingSenseVerification"
            @resolve="resolveReadingSenseEvidence"
        />

        <reader-inline-sense-review-dialog
            v-model="inlineReviewDialog"
            :occurrence="inlineReviewOccurrence"
            :candidates="inlineReviewCandidates"
            :reading-session-id="inlineReviewIntent ? inlineReviewIntent.readingSessionId : ''"
            :frozen-rating="inlineReviewIntent ? (inlineReviewIntent.rating || '') : ''"
            :manual-create-blocked="manualSenseCreateBlocked"
            :busy="inlineReviewBusy"
            :error="inlineReviewError"
            :outcome-unknown="Boolean(inlineOutcomeUnknownCommand)"
            @reveal="onInlineReviewReveal"
            @rating-intent="onInlineReviewRatingIntent"
            @submit="submitInlineOfficialRating"
            @retry-outcome-unknown="retryInlineOutcomeUnknownRating"
            @create-sense-and-submit="createManualSenseAndSubmitRating"
            @cancel="clearInlineReviewIntent"
        />

        <v-snackbar v-model="readerNotice.show" :color="readerNotice.color" :timeout="5000" top>
            {{ readerNotice.text }}
        </v-snackbar>

        <v-snackbar v-model="inlineUndoSnackbar.show" :timeout="6000" top color="info">
            {{ inlineUndoSnackbar.text }}
            <template #action="{ attrs }">
                <v-btn
                    v-if="inlineLastUndoAction && inlineLastUndoAction.undoable"
                    text
                    v-bind="attrs"
                    :loading="inlineUndoBusy"
                    @click="undoLastInlineRating"
                >撤销评分</v-btn>
            </template>
        </v-snackbar>

        <!-- Toolbar -->
        <div id="reader-box" :style="readerBoxStyle" v-if="chapterId !== null">
            <div id="toolbar-box">
                <div v-if="!finished && !saving" id="toolbar" :class="{'d-flex': true}" :style="{'top': toolbarTop + 'px'}">
                    <v-btn title="全屏" icon @click="fullscreen" v-if="!fullscreenMode"><v-icon>mdi-arrow-expand-all</v-icon></v-btn>
                    <v-btn title="退出全屏" icon @click="exitFullscreen" v-if="fullscreenMode"><v-icon>mdi-arrow-collapse-all</v-icon></v-btn>
                    <v-btn title="阅读设置" icon @click="openDialog('settings')"><v-icon>mdi-cog</v-icon></v-btn>
                    <v-btn title="章节" icon @click="openDialog('chapters')"><v-icon>mdi-book-alphabet</v-icon></v-btn>
                    <v-btn title="词汇表" icon @click="openDialog('glossary')"><v-icon>mdi-format-list-bulleted</v-icon></v-btn>
                    <v-btn title="增大字号" icon @click="increaseFontSize"><v-icon>mdi-magnify-plus</v-icon></v-btn>
                    <v-btn title="减小字号" icon @click="decreaseFontSize"><v-icon>mdi-magnify-minus</v-icon></v-btn>
                    <v-btn title="切换纯文本模式" icon @click="togglePlainTextMode"><v-icon :color="settings.plainTextMode ? 'primary' : ''">mdi-format-text</v-icon></v-btn>
                    <v-btn title="查看快捷键" icon @click="toggleHotkeyDialog"><v-icon>mdi-keyboard-outline</v-icon></v-btn>
                    <v-btn
                        :title="unfamiliarMarkMode ? '退出标记不认识模式' : '标记不认识的词或词组'"
                        icon
                        :disabled="readingSourceStale"
                        @click="toggleUnfamiliarMarkMode"
                    ><v-icon :color="unfamiliarMarkMode ? 'warning' : ''">mdi-marker</v-icon></v-btn>
                    <v-btn
                        title="词义核对列表"
                        icon
                        :disabled="!readingSenseVerificationItems.length"
                        @click="readingSenseVerificationDialog = true"
                    ><v-icon>mdi-format-list-checks</v-icon></v-btn>
                    <v-btn
                        title="复习当前点开的词义"
                        icon
                        :disabled="!inlineReviewOccurrence || !readingSessionId || inlineReviewIntentBusy"
                        :loading="inlineReviewIntentBusy"
                        @click="startInlineReview"
                    ><v-icon :color="inlineReviewOccurrence ? 'primary' : ''">mdi-cards-outline</v-icon></v-btn>
                    <v-btn title="AI 阅读辅助" icon :disabled="readingSourceStale" @click="openAiAssistDialog"><v-icon>mdi-robot</v-icon></v-btn>
                    <v-btn
                        v-if="hasSavedAiAssist"
                        :title="aiTranslationMode === 'hidden' ? '悬停或聚焦显示 AI 译文' : (aiTranslationMode === 'hover' ? '持续显示 AI 译文' : '隐藏 AI 译文')"
                        icon
                        @click="toggleAiTranslations"
                    >
                        <v-icon :color="aiTranslationMode !== 'hidden' ? 'primary' : ''">mdi-translate</v-icon>
                    </v-btn>
                    <v-btn v-else-if="chapterId !== null" icon disabled title="暂无已保存 AI 译文">
                        <v-icon color="grey lighten-1">mdi-translate-off</v-icon>
                    </v-btn>
                </div>
            </div>

            <!-- Settings -->
            <text-reader-settings
                v-if="language !== null"
                v-show="dialogs.settings"
                v-model="dialogs.settings"
                :language="language"
                ref="textReaderSettings"
                @changed="updateSettings"
            ></text-reader-settings>

            <!-- Chapters -->
            <text-reader-chapter-list
                :chapters="chapters"
                :current-chapter-id="chapterId"
                v-model="dialogs.chapters"
            ></text-reader-chapter-list>

            <!-- Glossary -->
            <text-reader-glossary
                :glossary="glossary"
                v-model="dialogs.glossary"
            ></text-reader-glossary>

            <!-- Text -->
            <v-card
                :outlined="theme !== 'eink'"
                :flat="theme == 'eink'"
                v-if="!finished && !saving"
                id="reader"
                :class="{
                    'plain-text-mode': settings.plainTextMode,
                    'vertical-text': settings.verticalText,
                    'rounded-lg': true,
                }"
                :style="{
                    'height': $vuetify.breakpoint.mdAndUp ? 'calc(100% - 24px - 24px)' : 'calc(100% - 24px - 24px - 64px)',
                    'padding-right': settings.vocabularySidebar && vocabularySidebarFits ? (sidebarPaddingWidth) : '0px'
                }"
            >
                <v-card-text id="reader-content" :class="{
                    'vocab-box-area': true,
                    'px-6': $vuetify.breakpoint.smAndUp,
                    'px-3': $vuetify.breakpoint.xsOnly,
                    'pt-4': $vuetify.breakpoint.smAndUp,
                    'pt-3': $vuetify.breakpoint.xsOnly,
                }">
                    <div id="chapter-name" class="mb-4 selected-font" :style="{'font-size': (settings.fontSize + 4) + 'px'}">
                        {{ chapterName }}
                    </div>

                    <text-block-group
                        v-if="text !== null"
                        ref="interactiveText"
                        :theme="theme"
                        :fullscreen="fullscreenMode"
                        :_text="text"
                        :chapter-id="chapterId"
                        :subtitle-timestamps="subtitleTimestamps"
                        :language="language"
                        :hide-all-highlights="settings.hideAllHighlights"
                        :hide-new-word-highlights="settings.hideNewWordHighlights"
                        :plain-text-mode="settings.plainTextMode"
                        :font-size="settings.fontSize"
                        :line-spacing="settings.lineSpacing"
                        :vocab-box-scroll-into-view="settings.vocabBoxScrollIntoView"
                        :furigana-on-highlighted-words="settings.furiganaOnHighlightedWords"
                        :furigana-on-new-words="settings.furiganaOnNewWords"
                        :vocabulary-hover-box="settings.vocabularyHoverBox"
                        :vocabulary-hover-box-search="settings.vocabularyHoverBoxSearch"
                        :vocabulary-hover-box-delay="settings.vocabularyHoverBoxDelay"
                        :vocabulary-hover-box-preferred-position="settings.vocabularyHoverBoxPreferredPosition"
                        :vocabulary-sidebar="settings.vocabularySidebar"
                        :vocabulary-bottom-sheet="settings.vocabularyBottomSheet"
                        :auto-highlight-words="settings.autoHighlightWords"
                        :show-subtitle-timestamps="settings.showSubtitleTimestamps"
                        :space-between-subtitles="settings.spaceBetweenSubtitles"
                        :vocabulary-sidebar-fits="vocabularySidebarFits"
                        :hotkeys-enabled="true"
                        :ai-translation-mode="aiTranslationMode"
                        :ai-sentence-translations="aiSentenceTranslations"
                        :unfamiliar-mark-mode="unfamiliarMarkMode"
                        :unfamiliar-word-indexes="markedUnfamiliarWordIndexes"
                        @mark-unfamiliar="onMarkUnfamiliar"
                        @unfamiliar-mark-rejected="onUnfamiliarMarkRejected"
                        @reader-occurrence-opened="onReaderOccurrenceOpened"
                        @increase-font-size="increaseFontSize"
                        @decrease-font-size="decreaseFontSize"
                        @toggle-plain-text-mode="togglePlainTextMode"
                    ></text-block-group>
                    <div :class="{
                        'd-flex': true,
                        'mt-16': $vuetify.breakpoint.smAndUp,
                        'mb-3': $vuetify.breakpoint.xsOnly,
                    }">
                        <v-spacer></v-spacer>
                        <v-btn rounded color="primary" @click="openFinishConfirmDialog()"><v-icon>mdi-text-box-check</v-icon> 完成阅读</v-btn>
                    </div>
                </v-card-text>
            </v-card>&nbsp;

            <!-- Finish box -->
            <v-card
                v-if="finished || saving"
                :loading="saving"
                outlined
                class="finished-box rounded-lg mx-auto"
                width="800px"
                background="foreground"
            >
                <!-- Title -->
                <v-card-title v-if="!saving"><v-icon large color="success" class="mr-1">mdi-bookmark-check</v-icon>阅读完成</v-card-title>
                <v-card-title v-if="saving">正在更新数据...</v-card-title>
                <v-card-text v-if="saving" height="200px"></v-card-text>

                <!-- Text and leveled up words list -->
                <div style="max-height: calc(100vh - 220px); overflow-y: auto;"  v-if="!saving">
                    <v-card-text>
                        <!-- Text -->
                        你已完成章节：<b>{{ chapterName }}</b>，本章共阅读 <b>{{ formatNumber(wordCount) }}</b> 个词。保持节奏，学习会稳步推进。

                        <template v-if="nextChapter === -1">
                            <br><br>
                            这是本书的最后一章。
                        </template>

                        <!-- Leveled up words -->
                        <template v-if="settings.autoLevelUpWords && leveledUpWordsAndPhrases.wordsAndPhrases.length">
                            <div class="subheader mt-8">升级的词汇</div>
                            <v-data-table
                                class="no-hover"
                                :headers="[
                                    { text: '词项', value: 'word' },
                                    { text: '等级', value: 'stage', align: 'center', width: '180px'},
                                ]"
                                :items="leveledUpWordsAndPhrases.wordsAndPhrases"
                                :items-per-page="-1"
                                hide-default-footer
                            >
                                <!-- Stage -->
                                <template v-slot:item.word="{ item }">
                                    <template v-if="item.type === 'word'">
                                        {{ item.word }}
                                    </template>
                                    <template v-else>
                                        {{ item.words.join(languageSpaces ? ' ' : '') }}
                                    </template>
                                </template>

                                <!-- Stage -->
                                <template v-slot:item.stage="{ item }">
                                    <template v-if="item.stage === -1">
                                        <v-icon color="success" class="mr-1">mdi-check</v-icon>已掌握
                                    </template>
                                    <template v-else>
                                        <span class="finished-stage-level rounded-pill">{{ item.stage * -1 }}</span>
                                        <v-icon class="finished-stage-arrow">mdi-arrow-right</v-icon>
                                        <span class="finished-stage-level rounded-pill">{{ (item.stage + 1) * -1 }}</span>
                                    </template>
                                </template>
                            </v-data-table>
                        </template>
                    </v-card-text>
                </div>

                <!-- Actions -->
                <v-card-actions>
                    <v-spacer />
                    <v-btn
                        rounded
                        depressed
                        :disabled="saving"
                        color="primary"
                        @click="$router.push('/books/' + bookId)"
                    >
                        <v-icon class="mr-1">mdi-book-open-variant</v-icon>
                        阅读材料
                    </v-btn>
                    <v-btn
                        v-if="nextChapter !== -1"
                        rounded
                        depressed
                        :disabled="saving"
                        color="primary"
                        :to="'/chapters/read/' + nextChapter"
                    >
                        <v-icon class="mr-1">mdi-page-next-outline</v-icon>
                        下一章
                    </v-btn>
                </v-card-actions>
            </v-card>
        </div>

        <!-- Finished reading confirmation dialog (UX guard against accidental clicks) -->
        <v-dialog v-model="finishConfirmDialog" max-width="480">
            <v-card>
                <v-card-title>确认完成阅读？</v-card-title>
                <v-card-text>
                    <p>系统会先检查本次阅读中的词义处理情况，并告诉你哪些词会记为「记得」、哪些需要先核对、哪些不会处理。</p>
                    <p class="text--secondary text-caption">这一步只做检查，不会直接保存完成结果。</p>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text :disabled="saving || finishChecking" @click="finishConfirmDialog = false">取消</v-btn>
                    <v-btn color="primary" :loading="finishChecking" :disabled="saving" @click="preflightFinishSettlement">确认完成</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <v-dialog v-model="finishCommitDialog" max-width="560" persistent>
            <v-card>
                <v-card-title>确认完成本次阅读？</v-card-title>
                <v-card-text>
                    <v-alert v-if="finishPreflight" dense text type="info">
                        将记为「记得」 {{ finishPreflight.passiveGoodCount }} 项 · 待核对 {{ finishPreflight.unresolvedCount }} 项 · 已排除 {{ finishPreflight.excludedCount }} 项 · 已处理 {{ finishPreflight.alreadySettledCount }} 项
                    </v-alert>
                    <div v-if="finishPreflight && finishPreflight.raw" class="body-2 mb-3">
                        <div v-if="finishItemLabels(finishPreflight.raw.passive_occurrence_ids).length">
                            <strong>会记为「记得」：</strong>{{ finishItemLabels(finishPreflight.raw.passive_occurrence_ids).join('、') }}
                        </div>
                        <div v-if="finishItemLabels(finishPreflight.raw.excluded_occurrence_ids).length" class="text--secondary mt-1">
                            <strong>本次不处理：</strong>{{ finishItemLabels(finishPreflight.raw.excluded_occurrence_ids).join('、') }}
                        </div>
                    </div>
                    <p>确认后会再次核对当前状态并完成阅读。如果还有需要确认的词义，系统会停止完成并带你回到核对列表。</p>
                    <p class="text--secondary text-caption">如果网络中断，重新点击「完成阅读」即可继续确认，不会重复记录同一次完成。</p>
                </v-card-text>
                <v-card-actions>
                    <v-btn text :disabled="saving" @click="finishCommitDialog = false">继续阅读</v-btn>
                    <v-spacer />
                    <v-btn color="primary" :loading="saving" :disabled="!finishPreflight || !finishPreflight.canCommit" @click="commitFinish">确认完成</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script>
    import {formatNumber} from './../../helper.js';
    import { DefaultLocalStorageManager, defaultSettings } from './../../services/LocalStorageManagerService';
    import { requestErrorMessage } from './../../services/UiTextService';
    import ReadingSenseVerificationDialog from './ReadingSenseVerificationDialog.vue';
    import ReaderInlineSenseReviewDialog from './ReaderInlineSenseReviewDialog.vue';
    import { createReviewApiClient } from '../Review/ReviewApiClient.js';
    import {
        awaitReaderInlineOpenedBarrier,
        createReaderInlineReviewIntent,
        freezeReaderInlineRatingIntent,
        readerInlineRatingIntentMatches,
        readerInlineReviewIntentMatches,
        readerInlineOpenedFailureMessage,
        resolveReaderInteractionAttempt,
    } from './../../services/ReaderInlineSenseReviewPolicy';
    import {
        readerUnfamiliarTargetKey,
        readerUnfamiliarWordIndexes,
    } from './../../services/ReaderUnfamiliarTargetPolicy';
    import {
        mergeReadingSenseVerificationItems,
        normalizeReadingSenseVerificationItems,
    } from './../../services/ReadingSenseVerificationPolicy';
    import {
        buildReaderExplicitRatingActionCommand,
        buildReaderFinishRequest,
        buildReadingInteractionRequest,
        clearReaderExplicitRatingRetry,
        clearReaderManualSenseContinuation,
        clearReadingSessionRecoveryId,
        createReaderRequestId,
        filterCandidatesToReadingTarget,
        findReadingTargetForOpenedSelection,
        loadReaderExplicitRatingRetry,
        loadReaderManualSenseContinuation,
        loadReadingSessionRecoveryId,
        normalizeReaderEvidencePage,
        normalizeReaderFinishResult,
        normalizeReaderUnfamiliarSnapshot,
        normalizeReadingContinuity,
        normalizeReadingSessionResponse,
        readerExplicitActionConflictCode,
        readerExplicitRatingCommandMatchesSession,
        readingCanonicalTokenIndexAtRenderedIndex,
        readingRenderedIndexForCanonicalToken,
        buildReadingProgressRequest,
        saveReaderExplicitRatingRetry,
        saveReaderManualSenseContinuation,
        saveReadingSessionRecoveryId,
    } from './../../services/ReaderRecoveryPolicy';
    import {
        getReaderSidebarReservationWidthForWorkspace,
        getReaderSidebarWidthForWorkspace,
        doesReaderSidebarFitWorkspace,
    } from './../../services/ReaderWorkspaceSizingService';

    const reviewApi = createReviewApiClient();

    export default {
        components: {
            ReadingSenseVerificationDialog,
            ReaderInlineSenseReviewDialog,
        },
        data: function() {
            return {
                hotkeyDialog: false,
                aiAssistDialog: false,
                readerLoading: true,
                readerError: '',
                text: null,
                dialogs: {
                    settings: false,
                    glossary: false,
                    chapters: false
                },
                toolbarTop: 68,
                theme: DefaultLocalStorageManager.loadSetting('theme') || 'light',
                vocabularySidebarFits: true,
                settings: {
                    ...defaultSettings,
                    hideAllHighlights: false,
                    hideNewWordHighlights: false,
                    plainTextMode: false,
                    fontSize: 20,
                    lineSpacing: 1,
                    maximumTextWidth: 3,
                    autoMoveWordsToKnown: false,
                    subtitleBlockSpacing: 1,
                    vocabBoxScrollIntoView: 'scroll-into-view',
                    verticalText: false,
                    furiganaOnHighlightedWords: false,
                    furiganaOnNewWords: false,
                    mediaControlsVisible: true,
                    vocabularySidebar: true,
                    vocabularyBottomSheet: true,
                    vocabularyHoverBox: true,
                    vocabularyHoverBoxSearch: true,
                    vocabularyHoverBoxDelay: 300,
                    vocabularyHoverBoxPreferredPosition: 'bottom',
                    autoHighlightWords: true,
                    autoLevelUpWords: false,
                    showSubtitleTimestamps: true,
                    spaceBetweenSubtitles: 20,
                },
                fullscreenMode: false,
                newlySavedWords: 0,
                learnedWords: 0,
                progressedWords: 0,
                glossary: [],
                nextChapter: -1,

                // chapter data
                type: 'text',
                subtitleTimestamps: [],
                bookName: null,
                chapterId: null,
                wordCount: 0,
                chapterName: null,
                bookId: null,
                language: null,
                languageSpaces: null,
                chapters: [],

                // finish / server-issued reading session
                finished: false,
                finishConfirmDialog: false,
                finishCommitDialog: false,
                finishChecking: false,
                finishPreflight: null,
                leveledUpWordsAndPhrases: null,
                saving: false,
                readingSessionId: '',
                readingSourceRevision: '',
                readingSourceStale: false,
                readingTargets: [],
                readingSessionLoading: false,
                readingInteractionEntries: {},
                readingInteractionPromises: {},
                readingEvidenceItems: [],
                currentReadingSelectionFingerprint: null,

                // canonical reading continuity
                readingContinuitySourceRevision: '',
                readingContinuityLastSavedIndex: null,
                readingContinuityPendingIndex: null,
                readingContinuityInFlightIndex: null,
                readingContinuitySaveTimer: null,
                readingContinuityWriteInFlight: false,
                readingContinuityScrollElement: null,
                readingContinuityLayoutSuppressUntil: 0,
                readingContinuityLayoutRestoreTimer: null,

                // AI reading assist / explicit unfamiliar targets
                aiTranslationMode: 'hidden',
                hasSavedAiAssist: false,
                aiSentenceTranslations: [],
                assistVerificationItems: [],
                unfamiliarMarkMode: false,
                unfamiliarMarkSaving: false,
                markedUnfamiliarTargets: [],
                markedUnfamiliarSnapshotVersion: '',
                readingSenseVerificationDialog: false,
                readingSenseVerificationItems: [],
                readingSenseVerificationLoading: false,
                readingSenseVerificationError: '',
                readingSenseVerificationBusyId: '',
                inlineReviewDialog: false,
                inlineReviewIntent: null,
                inlineReviewOccurrence: null,
                inlineReviewCandidates: [],
                inlineReviewCandidatesLoading: false,
                inlineReviewCandidatesError: '',
                inlineReviewIntentBusy: false,
                inlineReviewBusy: false,
                inlineReviewError: '',
                inlineOutcomeUnknownCommand: null,
                inlineLastUndoAction: null,
                inlineUndoBusy: false,
                inlineUndoRequestId: '',
                inlineUndoSnackbar: { show: false, text: '' },
                pendingManualSenseContinuation: null,
                readerNotice: { show: false, text: '', color: 'info' },

                // source highlight
                sourceHighlightTimer: null,
                _aiDialogWasOpen: false,
            }
        },
        watch: {
            aiAssistDialog(val) {
                if (!val && this._aiDialogWasOpen) {
                    // Dialog just closed — reload AI assist data
                    this.loadAiAssistCurrent();
                }
                this._aiDialogWasOpen = val;
            },
        },
        props: {
        },
        mounted: function () {
            this.settings = DefaultLocalStorageManager.loadAndParseSettings(this.settings);
            window.oncontextmenu = function(event) {
                event.preventDefault();
                event.stopPropagation();
                return false;
            };


            axios.post('/chapters/get/reader', {
                'chapterId': this.$route.params.chapterId,
            }).then((response) => {
                var data = response.data;
                this.type = data.type;

                // set subtitle timestamps
                if (this.type == 'subtitle') {
                    this.subtitleTimestamps = JSON.parse(data.subtitleTimestamps);

                    for (let i = 0; i < this.subtitleTimestamps.length; i++) {
                        for (let j = 0; j < data.words.length; j++) {
                            // find the first word of timestamp
                            if (data.words[j].sentence_index == this.subtitleTimestamps[i].sentenceIndexStart &&
                                (j == 0 || data.words[j-1].sentence_index !== data.words[j].sentence_index)) {
                                    data.words[j].subtitleIndex = i;
                            }
                        }
                    }
                }

                this.text = {
                    id: 0,
                    words: JSON.parse(JSON.stringify(data.words)),
                    phrases: JSON.parse(JSON.stringify(data.phrases)),
                    uniqueWords: JSON.parse(JSON.stringify(data.uniqueWords))
                };

                this.bookName = data.bookName;
                this.chapterId = data.chapterId;
                this.pendingManualSenseContinuation = loadReaderManualSenseContinuation(this.chapterId);
                this.inlineOutcomeUnknownCommand = loadReaderExplicitRatingRetry(this.chapterId);
                this.wordCount = data.wordCount;
                this.chapterName = data.chapterName;
                this.bookId = data.bookId;
                this.language = data.language;
                this.languageSpaces = data.languageSpaces;
                this.chapters = data.chapters;

                window.addEventListener('resize', this.handleReaderResize);
                window.addEventListener('scroll', this.updateToolbarPosition);
                const fullscreenBox = document.getElementById('fullscreen-box');
                if (fullscreenBox) {
                    fullscreenBox.addEventListener('fullscreenchange', this.updateFullscreen);
                }
                for (let i = 0; i < this.chapters.length; i++) {
                    if (this.chapters[i].id == this.chapterId && i < this.chapters.length - 1) {
                        this.nextChapter = this.chapters[i + 1].id;
                        break;
                    }
                }

                this.$forceUpdate();
                this.$nextTick(() => {
                    this.updateGlossary();
                    this.applySourceHighlightFromQuery();
                    this.updateToolbarPosition();
                });
                this.initializeReadingSession().finally(() => {
                    this.initializeReadingContinuity();
                });
                this.loadUnfamiliarTargets();
                this.loadAiAssistCurrent();
                this.vocabularySidebarTest();
                this.$forceUpdate();
            }).catch((error) => {
                this.readerError = requestErrorMessage(error, '阅读内容加载失败。章节可能仍在处理中，请稍后重试。');
            }).finally(() => {
                this.readerLoading = false;
            });
        },
        beforeRouteLeave(to, from, next) {
            this.flushReadingContinuityPosition();
            next();
        },
        beforeDestroy() {
            this.flushReadingContinuityPosition();
            this.detachReadingContinuityListeners();
            if (this.sourceHighlightTimer) {
                clearTimeout(this.sourceHighlightTimer);
            }
            window.removeEventListener('resize', this.handleReaderResize);
            window.removeEventListener('scroll', this.updateToolbarPosition);
            const fullscreenBox = document.getElementById('fullscreen-box');
            if (fullscreenBox) {
                fullscreenBox.removeEventListener('fullscreenchange', this.updateFullscreen);
            }
        },
        // this runs after the initial data
        // was downloaded with axios
        computed: {
            readerBoxStyle() {
                return {
                    '--reader-sidebar-width': this.sidebarWidthValue + 'px',
                };
            },
            sidebarWidthValue() {
                return this.readerSidebarWidthForContentWidth(this.readerWorkspaceWidth());
            },
            sidebarPaddingWidth() {
                return getReaderSidebarReservationWidthForWorkspace(this.readerWorkspaceWidth()) + 'px !important';
            },
            markedUnfamiliarWordIndexes() {
                return readerUnfamiliarWordIndexes(this.markedUnfamiliarTargets);
            },
            manualSenseCreateBlocked() {
                const continuation = this.pendingManualSenseContinuation;
                const intent = this.inlineReviewIntent;
                return Boolean(
                    continuation
                    && continuation.outcomeUnknown
                    && intent
                    && continuation.occurrenceId === intent.occurrenceId
                    && continuation.readingSessionId === intent.readingSessionId
                    && continuation.sourceRevision === intent.sourceRevision,
                );
            },
        },
        methods: {
            initializeReadingContinuity() {
                if (!this.chapterId) return Promise.resolve(false);
                return axios.get('/chapters/' + this.chapterId + '/reading-continuity')
                    .then((response) => {
                        const continuity = normalizeReadingContinuity(response.data || {});
                        if (!continuity) return false;
                        if (this.readingSourceRevision && continuity.sourceRevision !== this.readingSourceRevision) {
                            return false;
                        }

                        this.readingContinuitySourceRevision = continuity.sourceRevision;
                        this.readingContinuityLastSavedIndex = continuity.canonicalTokenIndex;
                        this.$nextTick(() => {
                            if (this._isBeingDestroyed || this._isDestroyed) return;
                            this.attachReadingContinuityListeners();
                            const hasExplicitSourceTarget = Boolean(
                                this.$route.query.source_word || this.$route.query.source_lemma,
                            );
                            if (!hasExplicitSourceTarget && continuity.canonicalTokenIndex !== null) {
                                this.restoreReadingContinuityPosition(continuity.canonicalTokenIndex);
                            }
                        });
                        return true;
                    })
                    .catch(() => false);
            },
            attachReadingContinuityListeners() {
                if (this.readingContinuityScrollElement) return;
                const readerContent = document.getElementById('reader-content');
                if (!readerContent) return;
                this.readingContinuityScrollElement = readerContent;
                readerContent.addEventListener('scroll', this.onReadingContinuityScroll, { passive: true });
                document.addEventListener('visibilitychange', this.onReadingContinuityVisibilityChange);
            },
            detachReadingContinuityListeners() {
                if (this.readingContinuitySaveTimer) {
                    clearTimeout(this.readingContinuitySaveTimer);
                    this.readingContinuitySaveTimer = null;
                }
                if (this.readingContinuityLayoutRestoreTimer) {
                    clearTimeout(this.readingContinuityLayoutRestoreTimer);
                    this.readingContinuityLayoutRestoreTimer = null;
                }
                if (this.readingContinuityScrollElement) {
                    this.readingContinuityScrollElement.removeEventListener('scroll', this.onReadingContinuityScroll);
                    this.readingContinuityScrollElement = null;
                }
                document.removeEventListener('visibilitychange', this.onReadingContinuityVisibilityChange);
            },
            onReadingContinuityScroll() {
                if (!this.readingContinuitySourceRevision) return;
                if (Date.now() < this.readingContinuityLayoutSuppressUntil) return;
                if (this.readingContinuitySaveTimer) clearTimeout(this.readingContinuitySaveTimer);
                this.readingContinuitySaveTimer = setTimeout(() => {
                    this.readingContinuitySaveTimer = null;
                    this.flushReadingContinuityPosition();
                }, 300);
            },
            onReadingContinuityVisibilityChange() {
                if (document.visibilityState === 'hidden') this.flushReadingContinuityPosition();
            },
            preserveReadingContinuityAcrossLayoutChange() {
                const anchor = this.currentReadingCanonicalTokenIndex()
                    ?? this.readingContinuityPendingIndex
                    ?? this.readingContinuityInFlightIndex
                    ?? this.readingContinuityLastSavedIndex;
                if (anchor === null || anchor === undefined) return;
                if (this.readingContinuitySaveTimer) {
                    clearTimeout(this.readingContinuitySaveTimer);
                    this.readingContinuitySaveTimer = null;
                }
                this.queueReadingContinuityWrite(anchor);
                this.readingContinuityLayoutSuppressUntil = Date.now() + 1200;
                if (this.readingContinuityLayoutRestoreTimer) {
                    clearTimeout(this.readingContinuityLayoutRestoreTimer);
                }
                this.readingContinuityLayoutRestoreTimer = setTimeout(() => {
                    this.readingContinuityLayoutRestoreTimer = null;
                    if (!this.readingContinuitySourceRevision) return;
                    this.restoreReadingContinuityPosition(anchor);
                }, 80);
            },
            currentReadingCanonicalTokenIndex() {
                const readerContent = this.readingContinuityScrollElement || document.getElementById('reader-content');
                const renderedWords = this.$refs.interactiveText && Array.isArray(this.$refs.interactiveText.words)
                    ? this.$refs.interactiveText.words
                    : [];
                if (!readerContent || !renderedWords.length) return null;

                const readerRect = readerContent.getBoundingClientRect();
                const elements = readerContent.querySelectorAll('.word[wordindex]');
                for (const element of elements) {
                    const rect = element.getBoundingClientRect();
                    if (rect.bottom <= readerRect.top || rect.top >= readerRect.bottom) continue;
                    const renderedIndex = Number(element.getAttribute('wordindex'));
                    const canonicalTokenIndex = readingCanonicalTokenIndexAtRenderedIndex(
                        renderedWords,
                        renderedIndex,
                    );
                    if (canonicalTokenIndex !== null) return canonicalTokenIndex;
                }
                return null;
            },
            restoreReadingContinuityPosition(canonicalTokenIndex) {
                const renderedWords = this.$refs.interactiveText && Array.isArray(this.$refs.interactiveText.words)
                    ? this.$refs.interactiveText.words
                    : [];
                const renderedIndex = readingRenderedIndexForCanonicalToken(renderedWords, canonicalTokenIndex);
                if (renderedIndex < 0) return false;
                const element = document.querySelector('#reader-content .word[wordindex="' + renderedIndex + '"]');
                if (!element || !element.scrollIntoView) return false;
                element.scrollIntoView({ behavior: 'instant', block: 'start' });
                return true;
            },
            flushReadingContinuityPosition() {
                if (this.readingContinuitySaveTimer) {
                    clearTimeout(this.readingContinuitySaveTimer);
                    this.readingContinuitySaveTimer = null;
                }
                const canonicalTokenIndex = this.currentReadingCanonicalTokenIndex();
                if (canonicalTokenIndex === null) return false;
                return this.queueReadingContinuityWrite(canonicalTokenIndex);
            },
            queueReadingContinuityWrite(canonicalTokenIndex) {
                if (!this.readingContinuitySourceRevision) return false;
                if (this.readingContinuityPendingIndex === null
                    && (canonicalTokenIndex === this.readingContinuityLastSavedIndex
                        || canonicalTokenIndex === this.readingContinuityInFlightIndex)) return true;
                this.readingContinuityPendingIndex = canonicalTokenIndex;
                this.writePendingReadingContinuityPosition();
                return true;
            },
            writePendingReadingContinuityPosition() {
                if (this.readingContinuityWriteInFlight || this.readingContinuityPendingIndex === null) return;
                const canonicalTokenIndex = this.readingContinuityPendingIndex;
                this.readingContinuityPendingIndex = null;
                if (canonicalTokenIndex === this.readingContinuityLastSavedIndex) return;
                const payload = buildReadingProgressRequest(
                    this.readingContinuitySourceRevision,
                    canonicalTokenIndex,
                );
                if (!payload || !this.chapterId) return;

                const sourceRevision = this.readingContinuitySourceRevision;
                this.readingContinuityInFlightIndex = canonicalTokenIndex;
                this.readingContinuityWriteInFlight = true;
                axios.put('/chapters/' + this.chapterId + '/reading-progress', payload)
                    .then((response) => {
                        const data = response.data || {};
                        if (data.source_revision === sourceRevision
                            && Number(data.canonical_token_index) === canonicalTokenIndex) {
                            this.readingContinuityLastSavedIndex = canonicalTokenIndex;
                        }
                    })
                    .catch((error) => {
                        const responseData = error && error.response && error.response.data ? error.response.data : {};
                        if (responseData.error_code === 'READING_CONTINUITY_STALE_SOURCE') {
                            this.readingContinuitySourceRevision = '';
                            this.readingContinuityPendingIndex = null;
                            this.detachReadingContinuityListeners();
                            this.invalidateStaleReadingSession();
                        } else if (responseData.error_code === 'READING_CONTINUITY_INVALID_TOKEN') {
                            this.readingContinuitySourceRevision = '';
                            this.readingContinuityPendingIndex = null;
                            this.detachReadingContinuityListeners();
                            this.setReaderNotice('当前阅读位置无法映射到文章的稳定词位置。已停止保存阅读位置，请刷新本章后重试。', 'warning');
                        }
                    })
                    .finally(() => {
                        this.readingContinuityInFlightIndex = null;
                        this.readingContinuityWriteInFlight = false;
                        if (this.readingContinuityPendingIndex !== null
                            && this.readingContinuityPendingIndex !== this.readingContinuityLastSavedIndex) {
                            this.writePendingReadingContinuityPosition();
                        }
                    });
            },
            setReaderNotice(text, color = 'info') {
                this.readerNotice = { show: true, text, color };
            },
            setPendingManualSenseContinuation(continuation) {
                this.pendingManualSenseContinuation = continuation;
                if (!this.chapterId) return false;
                if (continuation) {
                    return saveReaderManualSenseContinuation(this.chapterId, continuation);
                } else {
                    return clearReaderManualSenseContinuation(this.chapterId);
                }
            },
            setInlineOutcomeUnknownCommand(command) {
                if (!this.chapterId) {
                    this.inlineOutcomeUnknownCommand = command || null;
                    return Boolean(command);
                }
                if (!command) {
                    this.inlineOutcomeUnknownCommand = null;
                    clearReaderExplicitRatingRetry(this.chapterId);
                    return true;
                }
                if (!saveReaderExplicitRatingRetry(this.chapterId, command)) {
                    this.inlineOutcomeUnknownCommand = null;
                    clearReaderExplicitRatingRetry(this.chapterId);
                    return false;
                }
                this.inlineOutcomeUnknownCommand = command;
                return true;
            },
            prepareInlineOfficialRatingCommand(command, readingActionId = '') {
                const intent = this.inlineReviewIntent;
                const current = createReaderInlineReviewIntent(
                    this.readingSessionId,
                    this.readingSourceRevision,
                    this.inlineReviewOccurrence,
                );
                if (!command || !command.reviewCardId || !command.payload || !intent
                    || !readerInlineRatingIntentMatches(intent, current, command.payload.rating)) return null;
                const identifiedCommand = { ...command, sourceRevision: intent.sourceRevision };
                if (!readerExplicitRatingCommandMatchesSession(
                    identifiedCommand,
                    intent.readingSessionId,
                    intent.sourceRevision,
                )) {
                    this.inlineReviewError = '正式评分身份与当前服务器阅读会话不一致。已停止提交，请刷新当前词后重新选择。';
                    return null;
                }
                const actionCommand = buildReaderExplicitRatingActionCommand(identifiedCommand, readingActionId);
                if (!actionCommand) {
                    this.inlineReviewError = '当前浏览器无法提供安全随机 UUID。已停止正式评分，避免使用可预测的动作编号。';
                    this.setReaderNotice(this.inlineReviewError, 'warning');
                    return null;
                }
                return actionCommand;
            },
            clearManualContinuationForRatingCommand(command) {
                const continuation = this.pendingManualSenseContinuation;
                const payload = command && command.payload ? command.payload : {};
                if (!continuation) return;
                const sameAction = continuation.readingActionId
                    && continuation.readingActionId === payload.reading_action_id;
                const samePendingIntent = !continuation.readingActionId
                    && continuation.occurrenceId === command.occurrenceId
                    && continuation.rating === payload.rating
                    && continuation.readingSessionId === payload.reading_session_id
                    && continuation.sourceRevision === command.sourceRevision;
                if (sameAction || samePendingIntent) this.setPendingManualSenseContinuation(null);
            },
            releaseManualContinuationActionForRatingCommand(command) {
                const continuation = this.pendingManualSenseContinuation;
                const actionId = command && command.payload ? command.payload.reading_action_id : '';
                if (continuation && continuation.readingActionId && continuation.readingActionId === actionId) {
                    this.setPendingManualSenseContinuation({
                        ...continuation,
                        readingActionId: '',
                    });
                }
            },
            adoptActiveReadingSession(normalized) {
                if (!normalized || normalized.completed || !normalized.readingSessionId) return false;
                const previousSessionId = this.readingSessionId;
                const sessionChanged = Boolean(previousSessionId && previousSessionId !== normalized.readingSessionId);
                if (sessionChanged) {
                    for (const [key, entry] of Object.entries(this.readingInteractionEntries)) {
                        if (!entry) continue;
                        this.$set(this.readingInteractionEntries, key, { ...entry, status: 'failed' });
                    }
                    this.readingInteractionPromises = {};
                    this.inlineReviewIntent = null;
                    this.inlineReviewDialog = false;
                }
                this.readingSessionId = normalized.readingSessionId;
                this.readingSourceRevision = normalized.sourceRevision;
                this.readingSourceStale = false;
                this.readingTargets = normalized.targets;
                saveReadingSessionRecoveryId(this.chapterId, normalized.readingSessionId);
                this.syncCurrentVocabularyReadingContext();
                if (this.inlineOutcomeUnknownCommand) {
                    const retryPayload = this.inlineOutcomeUnknownCommand.payload || {};
                    if (retryPayload.reading_session_id !== normalized.readingSessionId
                        || this.inlineOutcomeUnknownCommand.sourceRevision !== normalized.sourceRevision) {
                        this.setInlineOutcomeUnknownCommand(null);
                        this.setReaderNotice('上一次结果未知的评分不属于当前服务器阅读会话，已停止自动重试。请重新打开词义后再评分。', 'warning');
                    }
                }
                if (this.pendingManualSenseContinuation
                    && (this.pendingManualSenseContinuation.readingSessionId !== normalized.readingSessionId
                        || this.pendingManualSenseContinuation.sourceRevision !== normalized.sourceRevision)) {
                    this.setReaderNotice('上一次新增词义动作不属于当前服务器阅读会话，已锁定且不会迁移或重复创建。请回到原阅读会话核对。', 'warning');
                }
                this.mergeReadingVerificationState();
                return true;
            },
            invalidateStaleReadingSession() {
                clearReadingSessionRecoveryId(this.chapterId);
                this.readingSessionId = '';
                this.readingSourceRevision = '';
                this.readingSourceStale = true;
                this.readingTargets = [];
                this.currentReadingSelectionFingerprint = null;
                this.markedUnfamiliarSnapshotVersion = '';
                this.readingInteractionEntries = {};
                this.readingInteractionPromises = {};
                this.inlineReviewOccurrence = null;
                this.inlineReviewCandidates = [];
                this.inlineReviewDialog = false;
                this.inlineReviewIntent = null;
                this.setInlineOutcomeUnknownCommand(null);
                if (this.pendingManualSenseContinuation && this.pendingManualSenseContinuation.readingActionId) {
                    this.setPendingManualSenseContinuation(null);
                }
                this.finishPreflight = null;
                this.finishCommitDialog = false;
                this.mergeReadingVerificationState();
                this.setReaderNotice('文章内容已在服务器发生变化。当前页面的词位置已失效，请刷新本章后再继续正式评分、AI 或完成阅读。', 'warning');
            },
            initializeReadingSession(forceFresh = false) {
                if (!this.chapterId) return Promise.resolve(false);
                const recoveryId = forceFresh
                    ? ''
                    : (this.readingSessionId || loadReadingSessionRecoveryId(this.chapterId));
                const requestBody = recoveryId ? { resume_reading_session_id: recoveryId } : {};
                this.readingSessionLoading = true;

                return axios.post('/chapters/' + this.chapterId + '/reading-sessions', requestBody)
                    .then((response) => {
                        const normalized = normalizeReadingSessionResponse(response.data, this.chapterId);
                        if (!normalized) throw new Error('Invalid reading-session response.');
                        if (normalized.completed) {
                            this.applyCompletedReadingResult(normalized.raw);
                            return true;
                        }

                        if (!this.adoptActiveReadingSession(normalized)) return false;
                        return this.loadAllReadingEvidence();
                    })
                    .catch((error) => {
                        const code = error && error.response && error.response.data
                            ? error.response.data.error_code
                            : '';
                        if (code === 'READING_SESSION_STALE_SOURCE') {
                            this.invalidateStaleReadingSession();
                            return false;
                        }
                        const recoverableResumeCodes = [
                            'READING_SESSION_NOT_FOUND',
                            'READING_SESSION_NOT_ACTIVE',
                            'READING_SESSION_CHAPTER_MISMATCH',
                        ];
                        if (recoveryId && !forceFresh && recoverableResumeCodes.includes(code)) {
                            clearReadingSessionRecoveryId(this.chapterId);
                            return this.initializeReadingSession(true);
                        }
                        this.setReaderNotice(
                            requestErrorMessage(error, '阅读会话暂时无法建立。普通阅读仍可继续，但正式评分和完成结算会保持关闭。'),
                            'warning',
                        );
                        return false;
                    })
                    .finally(() => {
                        this.readingSessionLoading = false;
                    });
            },
            refreshReadingSessionTargets() {
                if (!this.chapterId) return Promise.resolve(false);
                if (!this.readingSessionId) return this.initializeReadingSession(false);
                return axios.post('/chapters/' + this.chapterId + '/reading-sessions', {
                    resume_reading_session_id: this.readingSessionId,
                }).then((response) => {
                    const normalized = normalizeReadingSessionResponse(response.data, this.chapterId);
                    if (!normalized) return false;
                    if (normalized.completed) {
                        this.applyCompletedReadingResult(normalized.raw);
                        return true;
                    }
                    return this.adoptActiveReadingSession(normalized);
                }).catch((error) => {
                    const code = error && error.response && error.response.data
                        ? error.response.data.error_code
                        : '';
                    if (code === 'READING_SESSION_STALE_SOURCE') {
                        this.invalidateStaleReadingSession();
                        return false;
                    }
                    if (['READING_SESSION_NOT_FOUND', 'READING_SESSION_NOT_ACTIVE', 'READING_SESSION_CHAPTER_MISMATCH'].includes(code)) {
                        clearReadingSessionRecoveryId(this.chapterId);
                        return this.initializeReadingSession(true);
                    }
                    this.setReaderNotice('服务器暂时无法刷新本次阅读会话。需要正式评分或完成阅读时请重试。', 'warning');
                    return false;
                });
            },
            applyCompletedReadingResult(payload) {
                this.finishPreflight = normalizeReaderFinishResult(payload || {});
                this.readingSessionId = this.finishPreflight.readingSessionId || this.readingSessionId;
                this.readingSourceRevision = this.finishPreflight.sourceRevision || this.readingSourceRevision;
                clearReadingSessionRecoveryId(this.chapterId);
                this.setInlineOutcomeUnknownCommand(null);
                if (this.pendingManualSenseContinuation && this.pendingManualSenseContinuation.readingActionId) {
                    this.setPendingManualSenseContinuation(null);
                }
                if (!this.leveledUpWordsAndPhrases) {
                    this.leveledUpWordsAndPhrases = { wordsAndPhrases: [], wordIds: [], phraseIds: [] };
                }
                this.finished = true;
                this.saving = false;
                this.finishChecking = false;
                this.finishConfirmDialog = false;
                this.finishCommitDialog = false;
                this.inlineReviewDialog = false;
                this.setReaderNotice('服务器已确认本次阅读完成。', 'success');
            },
            loadUnfamiliarTargets() {
                if (!this.chapterId) return Promise.resolve(false);
                return axios.get('/chapters/' + this.chapterId + '/reading-unfamiliar-targets')
                    .then((response) => {
                        if (this.readingSourceStale) {
                            this.markedUnfamiliarSnapshotVersion = '';
                            return false;
                        }
                        const snapshot = normalizeReaderUnfamiliarSnapshot(response.data || {});
                        if (!snapshot.snapshotVersion) throw new Error('Missing unfamiliar-target snapshot version.');
                        this.markedUnfamiliarTargets = snapshot.targets;
                        this.markedUnfamiliarSnapshotVersion = snapshot.snapshotVersion;
                        return true;
                    })
                    .catch(() => {
                        this.markedUnfamiliarSnapshotVersion = '';
                        this.setReaderNotice('不认识标记的服务器快照加载失败。AI V2 请求会保持关闭，避免覆盖较新的服务器状态。', 'warning');
                        return false;
                    });
            },
            onMarkUnfamiliar(target) {
                if (this.readingSourceStale) {
                    this.setReaderNotice('文章内容已经变化，请先刷新本章，再继续标记不认识目标。', 'warning');
                    return;
                }
                if (!target || !this.chapterId || this.unfamiliarMarkSaving) return;
                const key = readerUnfamiliarTargetKey(target);
                const existing = this.markedUnfamiliarTargets.find(item => readerUnfamiliarTargetKey(item) === key);
                this.unfamiliarMarkSaving = true;
                const isAdding = !(existing && existing.occurrence_id);
                const request = !isAdding
                    ? axios.delete('/chapters/' + this.chapterId + '/reading-unfamiliar-targets/' + encodeURIComponent(existing.occurrence_id))
                    : axios.post('/chapters/' + this.chapterId + '/reading-unfamiliar-targets', {
                        kind: target.kind,
                        start_word_index: Number(target.start_word_index),
                        end_word_index: Number(target.end_word_index),
                    });

                return request.then(() => Promise.all([
                    isAdding ? this.recordReadingInteraction('marked_unknown', target) : Promise.resolve(true),
                    this.loadUnfamiliarTargets(),
                    this.refreshReadingSessionTargets(),
                ])).then(([interactionOk, snapshotOk, sessionOk]) => {
                    if (!interactionOk || !snapshotOk || !sessionOk) {
                        this.setReaderNotice('服务器已收到标记操作，但页面状态没有完整刷新。请稍后刷新本章再继续 AI 或完成结算。', 'warning');
                        return false;
                    }
                    this.setReaderNotice('本章当前有 ' + this.markedUnfamiliarTargets.length + ' 个服务器确认的不认识目标。', 'info');
                    return true;
                }).catch((error) => {
                    this.setReaderNotice(requestErrorMessage(error, '标记没有得到服务器确认，请重试。'), 'warning');
                    return false;
                }).finally(() => {
                    this.unfamiliarMarkSaving = false;
                });
            },
            mergeReadingVerificationState() {
                this.readingSenseVerificationItems = mergeReadingSenseVerificationItems({
                    targets: this.readingTargets,
                    assistItems: this.assistVerificationItems,
                    evidenceItems: this.readingEvidenceItems,
                });
            },
            loadAllReadingEvidence() {
                if (!this.chapterId) return Promise.resolve(false);
                const allItems = [];
                const seenOffsets = new Set();
                let expectedTotal = null;
                const fetchPage = (requestedOffset) => {
                    if (seenOffsets.has(requestedOffset)) return Promise.reject(new Error('Reading evidence pagination loop.'));
                    seenOffsets.add(requestedOffset);
                    return axios.get('/chapters/' + this.chapterId + '/reading-occurrence-evidence', {
                        params: { offset: requestedOffset, limit: 200 },
                    }).then((response) => {
                        const page = normalizeReaderEvidencePage(response.data || {});
                        if (!page || page.offset !== requestedOffset) throw new Error('Reading evidence pagination metadata is incomplete.');
                        if (page.sourceRevision !== this.readingSourceRevision) throw new Error('Reading evidence source revision changed.');
                        if (expectedTotal === null) expectedTotal = page.total;
                        if (page.total !== expectedTotal) throw new Error('Reading evidence total changed while paging.');
                        allItems.push(...page.items);
                        if (allItems.length > expectedTotal) throw new Error('Reading evidence page overflow.');
                        if (page.hasMore) return fetchPage(page.nextOffset);
                        if (allItems.length !== expectedTotal) throw new Error('Reading evidence page set is incomplete.');
                        return allItems;
                    });
                };

                this.readingSenseVerificationLoading = true;
                return fetchPage(0).then((items) => {
                    this.readingEvidenceItems = items;
                    this.readingSenseVerificationError = '';
                    this.mergeReadingVerificationState();
                    return true;
                }).catch(() => {
                    this.readingSenseVerificationError = '服务器词义证据列表没有完整加载。完成阅读已停止；请刷新后重试。';
                    return false;
                }).finally(() => {
                    this.readingSenseVerificationLoading = false;
                });
            },
            refreshReadingSenseVerification() {
                this.readingSenseVerificationLoading = true;
                return this.refreshReadingSessionTargets()
                    .then((sessionOk) => {
                        if (!sessionOk) return false;
                        return this.loadAllReadingEvidence();
                    })
                    .then((evidenceOk) => {
                        if (!evidenceOk) return false;
                        return this.loadAiAssistCurrent();
                    })
                    .finally(() => {
                        this.readingSenseVerificationLoading = false;
                    });
            },
            recordReadingInteraction(interactionType, occurrence) {
                const occurrenceId = typeof occurrence === 'string' ? occurrence : (occurrence && occurrence.occurrence_id);
                const sessionId = this.readingSessionId;
                const sourceRevision = this.readingSourceRevision;
                const payload = buildReadingInteractionRequest(sessionId, occurrenceId, interactionType);
                if (!payload) return Promise.resolve(false);
                const key = interactionType + ':' + occurrenceId;
                const existing = this.readingInteractionEntries[key];
                const attempt = resolveReaderInteractionAttempt(
                    existing,
                    sessionId,
                    sourceRevision,
                    this.readingInteractionPromises[key],
                );
                if (attempt.kind === 'acknowledged') return Promise.resolve(true);
                if (attempt.kind === 'pending') return attempt.promise;
                this.$set(this.readingInteractionEntries, key, {
                    interactionType,
                    occurrenceId,
                    sessionId,
                    sourceRevision,
                    status: 'pending',
                });
                const promise = axios.post('/chapters/reading-sessions/interactions', payload)
                    .then(() => {
                        const current = this.readingInteractionEntries[key];
                        if (current && current.sessionId === sessionId && current.sourceRevision === sourceRevision) {
                            this.$set(this.readingInteractionEntries, key, {
                                interactionType,
                                occurrenceId,
                                sessionId,
                                sourceRevision,
                                status: 'acknowledged',
                            });
                        }
                        return this.readingSessionId === sessionId && this.readingSourceRevision === sourceRevision;
                    })
                    .catch((error) => {
                        const current = this.readingInteractionEntries[key];
                        const status = error && error.response ? error.response.status : null;
                        const responseData = error && error.response && error.response.data ? error.response.data : {};
                        const errorCode = responseData.error_code || responseData.code || '';
                        if (current && current.sessionId === sessionId && current.sourceRevision === sourceRevision) {
                            this.$set(this.readingInteractionEntries, key, {
                                interactionType,
                                occurrenceId,
                                sessionId,
                                sourceRevision,
                                status: 'failed',
                                errorCode,
                                outcomeUnknown: !error || !error.response || status >= 500,
                            });
                        }
                        return false;
                    })
                    .finally(() => {
                        if (this.readingInteractionPromises[key] === promise) delete this.readingInteractionPromises[key];
                    });
                this.readingInteractionPromises[key] = promise;
                return promise;
            },
            flushReadingInteractions() {
                const pending = Object.values(this.readingInteractionEntries).filter(entry => entry && entry.status !== 'acknowledged');
                return Promise.all(pending.map(entry => this.recordReadingInteraction(entry.interactionType, entry.occurrenceId))).then((results) => {
                    if (results.some(result => result !== true)) {
                        const error = new Error('READING_INTERACTIONS_UNCONFIRMED');
                        error.readerPreCommitBlocked = true;
                        throw error;
                    }
                    return true;
                });
            },
            syncCurrentVocabularyReadingContext() {
                const currentContext = this.$store.state.vocabularyBox.readingContext;
                let startWordIndex = Number(this.currentReadingSelectionFingerprint && this.currentReadingSelectionFingerprint.startWordIndex);
                let endWordIndex = Number(this.currentReadingSelectionFingerprint && this.currentReadingSelectionFingerprint.endWordIndex);

                if (!Number.isInteger(startWordIndex) || startWordIndex < 0
                    || !Number.isInteger(endWordIndex) || endWordIndex < startWordIndex) {
                    startWordIndex = Number(currentContext && currentContext.startWordIndex);
                    endWordIndex = Number(currentContext && currentContext.endWordIndex);
                }
                if (!Number.isInteger(startWordIndex) || startWordIndex < 0
                    || !Number.isInteger(endWordIndex) || endWordIndex < startWordIndex) {
                    const selection = this.$refs.interactiveText && Array.isArray(this.$refs.interactiveText.selection)
                        ? this.$refs.interactiveText.selection
                        : [];
                    if (selection.length !== 1) return false;
                    startWordIndex = Number(selection[0] && selection[0].wordIndex);
                    endWordIndex = startWordIndex;
                }
                if (!Number.isInteger(startWordIndex) || startWordIndex < 0
                    || !Number.isInteger(endWordIndex) || endWordIndex < startWordIndex) return false;

                if (currentContext
                    && currentContext.startWordIndex === startWordIndex
                    && currentContext.endWordIndex === endWordIndex
                    && currentContext.readingSessionId === this.readingSessionId
                    && currentContext.sourceRevision === this.readingSourceRevision
                    && currentContext.occurrenceId) {
                    return true;
                }

                this.onReaderOccurrenceOpened({
                    start_word_index: startWordIndex,
                    end_word_index: endWordIndex,
                });
                return Boolean(this.$store.state.vocabularyBox.readingContext?.occurrenceId);
            },
            onReaderOccurrenceOpened(opened) {
                this.inlineReviewIntent = null;
                const startWordIndex = Number(opened && opened.start_word_index);
                const endWordIndex = Number(opened && opened.end_word_index);
                const hasSelectionFingerprint = Number.isInteger(startWordIndex)
                    && startWordIndex >= 0
                    && Number.isInteger(endWordIndex)
                    && endWordIndex >= startWordIndex;
                this.currentReadingSelectionFingerprint = hasSelectionFingerprint
                    ? { startWordIndex, endWordIndex }
                    : null;
                const selectionContext = hasSelectionFingerprint
                    ? {
                        startWordIndex,
                        endWordIndex,
                        readingSessionId: null,
                        sourceRevision: null,
                        occurrenceId: null,
                    }
                    : null;
                this.$store.commit('vocabularyBox/setReadingContext', selectionContext);

                const target = findReadingTargetForOpenedSelection(this.readingTargets, opened || {});
                if (!target || target.kind !== 'word') {
                    this.inlineReviewOccurrence = null;
                    this.inlineReviewCandidates = [];
                    return;
                }
                const readingContext = this.readingSessionId && this.readingSourceRevision && target.occurrence_id
                    ? {
                        startWordIndex: target.start_word_index,
                        endWordIndex: target.end_word_index,
                        readingSessionId: this.readingSessionId,
                        sourceRevision: this.readingSourceRevision,
                        occurrenceId: target.occurrence_id,
                    }
                    : selectionContext;
                this.$store.commit('vocabularyBox/setReadingContext', readingContext);
                this.inlineReviewOccurrence = target;
                this.inlineReviewCandidates = filterCandidatesToReadingTarget(target, target.candidate_word_senses || []);
                this.inlineReviewCandidatesError = '';
                this.recordReadingInteraction('opened', target);
                this.loadInlineReviewCandidates(target);
            },
            loadInlineReviewCandidates(target) {
                if (!target || target.kind !== 'word') return Promise.resolve([]);
                const occurrenceId = target.occurrence_id;
                const serverCandidates = filterCandidatesToReadingTarget(target, target.candidate_word_senses || []);
                if (!target.lemma || !this.language) {
                    this.inlineReviewCandidates = serverCandidates;
                    return Promise.resolve(serverCandidates);
                }
                this.inlineReviewCandidatesLoading = true;
                return axios.get('/senses/known-sense-lookup', { params: { lemma: target.lemma, language: this.language } })
                    .then((response) => {
                        if (!this.inlineReviewOccurrence || this.inlineReviewOccurrence.occurrence_id !== occurrenceId) return [];
                        this.inlineReviewCandidatesError = '';
                        const details = filterCandidatesToReadingTarget(target, response.data && Array.isArray(response.data.confirmed_senses) ? response.data.confirmed_senses : []);
                        const byId = new Map();
                        for (const candidate of serverCandidates) byId.set(Number(candidate.word_sense_id), { ...candidate });
                        for (const candidate of details) {
                            const id = Number(candidate.word_sense_id);
                            byId.set(id, { ...(byId.get(id) || {}), ...candidate, word_sense_id: id, sense_id: id });
                        }
                        this.inlineReviewCandidates = filterCandidatesToReadingTarget(target, [...byId.values()]);
                        return this.inlineReviewCandidates;
                    })
                    .catch(() => {
                        if (this.inlineReviewOccurrence && this.inlineReviewOccurrence.occurrence_id === occurrenceId) {
                            this.inlineReviewCandidates = [];
                            this.inlineReviewCandidatesError = '服务器词义卡详情没有加载成功。已停止正式评分和新增词义，避免把查询失败误当成没有已有词义。';
                        }
                        return [];
                    })
                    .finally(() => { this.inlineReviewCandidatesLoading = false; });
            },
            startInlineReview() {
                if (!this.readingSessionId || !this.inlineReviewOccurrence) return Promise.resolve(false);
                if (this.inlineReviewIntentBusy) return Promise.resolve(false);
                if (this.inlineOutcomeUnknownCommand
                    && this.inlineOutcomeUnknownCommand.occurrenceId !== this.inlineReviewOccurrence.occurrence_id) {
                    this.setReaderNotice('上一笔正式评分结果仍未知。请重新点回刚才的词安全重试，或刷新本章让服务器对账后再继续评分。', 'warning');
                    return Promise.resolve(false);
                }
                if (this.inlineReviewCandidatesLoading) {
                    this.setReaderNotice('正在加载服务器确认的词义卡信息，请稍后再点一次复习。', 'info');
                    return Promise.resolve(false);
                }
                if (this.inlineReviewCandidatesError) {
                    this.setReaderNotice(this.inlineReviewCandidatesError + ' 正在重试详情查询。', 'warning');
                    const target = this.inlineReviewOccurrence;
                    return this.loadInlineReviewCandidates(target).then(() => {
                        if (!this.inlineReviewCandidatesError
                            && this.inlineReviewOccurrence
                            && this.inlineReviewOccurrence.occurrence_id === target.occurrence_id) {
                            return this.startInlineReview();
                        }
                        return false;
                    });
                }
                const target = this.inlineReviewOccurrence;
                const intent = createReaderInlineReviewIntent(
                    this.readingSessionId,
                    this.readingSourceRevision,
                    target,
                );
                if (!intent) {
                    this.setReaderNotice('当前阅读会话还没有可确认的词义复习身份，请刷新本章后重试。', 'warning');
                    return Promise.resolve(false);
                }
                this.inlineReviewIntentBusy = true;
                this.inlineReviewError = '';
                return awaitReaderInlineOpenedBarrier(
                    intent,
                    () => this.recordReadingInteraction('opened', target),
                    () => createReaderInlineReviewIntent(
                        this.readingSessionId,
                        this.readingSourceRevision,
                        this.inlineReviewOccurrence,
                    ),
                ).then((barrierResult) => {
                    if (barrierResult === 'stale') {
                        this.setReaderNotice('当前词、文章版本或阅读会话已经变化。已停止打开评分，请重新点词后再试。', 'warning');
                        return false;
                    }
                    if (barrierResult !== 'acknowledged') {
                        const entry = this.readingInteractionEntries['opened:' + intent.occurrenceId];
                        if (entry && entry.errorCode === 'READING_SESSION_STALE_SOURCE') {
                            this.invalidateStaleReadingSession();
                            return false;
                        }
                        this.setReaderNotice(readerInlineOpenedFailureMessage(entry), 'warning');
                        return false;
                    }
                    const continuation = this.pendingManualSenseContinuation;
                    if (continuation && continuation.occurrenceId === intent.occurrenceId) {
                        if (continuation.readingSessionId !== intent.readingSessionId
                            || continuation.sourceRevision !== intent.sourceRevision) {
                            this.setReaderNotice('已保存的新增词义动作属于另一阅读会话或文章版本，已停止续接且不会重复创建。', 'warning');
                            return false;
                        }
                        if (continuation.senseId && continuation.reviewCardId) {
                            this.inlineReviewIntent = freezeReaderInlineRatingIntent(intent, intent, continuation.rating);
                            if (!this.inlineReviewIntent) return false;
                            this.inlineReviewDialog = false;
                            return this.continueManualSenseRating(continuation);
                        }
                        if (continuation.outcomeUnknown) {
                            this.inlineReviewIntent = freezeReaderInlineRatingIntent(intent, intent, continuation.rating);
                            if (!this.inlineReviewIntent) return false;
                            this.inlineReviewError = '上一次新增词义请求结果未知。原评分已保留；请从服务器当前候选中明确选择词义继续，新增词义按钮保持锁定，避免重复创建。';
                            this.inlineReviewDialog = true;
                            return true;
                        }
                        return false;
                    }
                    this.inlineReviewIntent = { ...intent, rating: '' };
                    this.inlineReviewDialog = true;
                    return true;
                }).finally(() => {
                    this.inlineReviewIntentBusy = false;
                });
            },
            onInlineReviewReveal(occurrence) {
                if (occurrence && occurrence.occurrence_id) this.recordReadingInteraction('helped', occurrence);
            },
            onInlineReviewRatingIntent(rating) {
                const current = createReaderInlineReviewIntent(
                    this.readingSessionId,
                    this.readingSourceRevision,
                    this.inlineReviewOccurrence,
                );
                if (!readerInlineReviewIntentMatches(this.inlineReviewIntent, current)) {
                    this.inlineReviewIntent = null;
                    this.inlineReviewDialog = false;
                    this.inlineReviewError = '当前阅读会话或词的位置已经变化。旧评分选择已取消，请重新打开这个词。';
                    return;
                }
                this.inlineReviewIntent = freezeReaderInlineRatingIntent(this.inlineReviewIntent, current, rating);
            },
            clearInlineReviewIntent() {
                this.inlineReviewIntent = null;
            },
            submitInlineOfficialRating(command) {
                if (this.inlineOutcomeUnknownCommand) {
                    return this.performInlineOfficialRating(this.inlineOutcomeUnknownCommand, true);
                }
                const actionCommand = this.prepareInlineOfficialRatingCommand(command);
                if (!actionCommand) return Promise.resolve(false);
                return this.performInlineOfficialRating(actionCommand, true);
            },
            retryInlineOutcomeUnknownRating() {
                if (!this.inlineOutcomeUnknownCommand) return Promise.resolve(false);
                return this.performInlineOfficialRating(this.inlineOutcomeUnknownCommand, true);
            },
            performInlineOfficialRating(command, manageBusy = true) {
                if (!command || !command.reviewCardId || !command.payload || !command.payload.reading_action_id) return Promise.resolve(false);
                if (!readerExplicitRatingCommandMatchesSession(command, this.readingSessionId, this.readingSourceRevision)) {
                    this.setInlineOutcomeUnknownCommand(null);
                    this.inlineReviewError = '这笔评分的恢复身份已经与当前服务器阅读会话不一致。已停止重试，请重新打开词义后评分。';
                    return Promise.resolve(false);
                }
                if (manageBusy && this.inlineReviewBusy) return Promise.resolve(false);
                if (manageBusy) this.inlineReviewBusy = true;
                this.inlineReviewError = '';
                return reviewApi.rateSenseCard(command.reviewCardId, command.payload)
                    .then((response) => {
                        const action = response.data && response.data.action ? response.data.action : null;
                        this.setInlineOutcomeUnknownCommand(null);
                        this.clearManualContinuationForRatingCommand(command);
                        this.inlineReviewDialog = false;
                        this.inlineReviewIntent = null;
                        this.inlineReviewError = '';
                        if (action && action.scored === false) {
                            this.inlineLastUndoAction = null;
                            this.inlineUndoRequestId = '';
                            this.inlineUndoSnackbar = { show: false, text: '' };
                            return true;
                        }
                        if (action && action.review_log_id) {
                            this.inlineLastUndoAction = action;
                            this.inlineUndoRequestId = '';
                            if (action.undoable) {
                                this.inlineUndoSnackbar = { show: true, text: '已提交 ' + (action.rating_label || action.rating || '评分') + '。需要时可撤销这次正式评分。' };
                            }
                        }
                        this.setReaderNotice('正式词义评分已由服务器确认。', 'success');
                        return true;
                    })
                    .catch((error) => {
                        const status = error && error.response ? error.response.status : null;
                        const code = error && error.response
                            ? readerExplicitActionConflictCode(error.response.data || {})
                            : '';
                        if (code === 'READING_EXPLICIT_ACTION_UNDONE') {
                            this.setInlineOutcomeUnknownCommand(null);
                            this.releaseManualContinuationActionForRatingCommand(command);
                            this.inlineReviewDialog = false;
                            this.inlineReviewError = '服务器确认这笔旧评分动作已经撤销。旧动作编号不会再次评分；需要重新评分时请重新打开词义。';
                            this.setReaderNotice(this.inlineReviewError, 'warning');
                            this.refreshReadingSessionTargets();
                        } else if (code === 'READING_EXPLICIT_ACTION_ACTIVE') {
                            this.setInlineOutcomeUnknownCommand(null);
                            this.clearManualContinuationForRatingCommand(command);
                            this.inlineReviewDialog = false;
                            this.inlineReviewError = '服务器确认当前卡片已有仍生效的阅读评分。页面已停止生成新的动作编号，请刷新对账后再决定是否撤销或重新评分。';
                            this.setReaderNotice(this.inlineReviewError, 'warning');
                            this.refreshReadingSessionTargets();
                        } else if (!error || !error.response || status >= 500) {
                            if (!this.setInlineOutcomeUnknownCommand(command)) {
                                this.inlineReviewError = '评分结果未知，但当前页面无法安全保存这笔动作编号。已停止自动重试；请刷新本章让服务器对账。';
                            } else {
                                this.inlineReviewError = '评分请求已经发出，但服务器结果暂时无法确认。当前评分、词义和动作编号已锁定；刷新或重试都会复用刚才这一笔，避免重复评分。';
                            }
                        } else if (status === 409 || status === 422) {
                            this.setInlineOutcomeUnknownCommand(null);
                            this.releaseManualContinuationActionForRatingCommand(command);
                            this.inlineReviewError = '服务器拒绝了这次评分：阅读会话、出现位置或词义候选已经变化。请刷新当前词后重新选择。';
                            this.refreshReadingSessionTargets();
                        } else {
                            this.setInlineOutcomeUnknownCommand(null);
                            this.clearManualContinuationForRatingCommand(command);
                            this.inlineReviewError = requestErrorMessage(error, '正式评分失败，请重试。');
                        }
                        return false;
                    })
                    .finally(() => { if (manageBusy) this.inlineReviewBusy = false; });
            },
            createManualSenseAndSubmitRating(intent) {
                const occurrence = intent && intent.occurrence;
                const rating = intent && intent.rating;
                const form = intent && intent.form;
                const frozen = this.inlineReviewIntent;
                const current = createReaderInlineReviewIntent(
                    this.readingSessionId,
                    this.readingSourceRevision,
                    this.inlineReviewOccurrence,
                );
                if (!occurrence || !occurrence.occurrence_id || !rating || !form || !frozen
                    || !readerInlineRatingIntentMatches(frozen, current, rating)
                    || frozen.occurrenceId !== occurrence.occurrence_id
                ) return Promise.resolve(false);
                const prior = this.pendingManualSenseContinuation;
                if (prior && prior.outcomeUnknown && prior.occurrenceId === occurrence.occurrence_id) {
                    this.inlineReviewError = '刚才的新增词义请求结果未知。为避免创建重复词义，已停止自动重试新增。请关闭窗口后重新点击这个词，先确认新词义是否已经出现。';
                    return Promise.resolve(false);
                }
                if (prior && prior.occurrenceId === occurrence.occurrence_id && prior.senseId && prior.reviewCardId) {
                    return this.continueManualSenseRating(prior);
                }
                this.inlineReviewBusy = true;
                this.inlineReviewError = '';
                const pending = {
                    occurrenceId: occurrence.occurrence_id,
                    rating,
                    outcomeUnknown: true,
                    sourceRevision: frozen.sourceRevision,
                    readingSessionId: frozen.readingSessionId,
                };
                if (!this.setPendingManualSenseContinuation(pending)) {
                    this.inlineReviewBusy = false;
                    this.inlineReviewError = '浏览器无法安全保存这次新增词义与原评分，已在发送请求前停止。请检查浏览器会话存储后重试。';
                    return Promise.resolve(false);
                }
                return axios.post('/senses/manual', {
                    lemma: occurrence.lemma || occurrence.surface,
                    surface_form: occurrence.surface || occurrence.lemma,
                    pos: form.pos,
                    sense_zh: form.sense_zh,
                    sense_en: form.sense_en || null,
                    chapter_id: this.chapterId,
                    sentence_id: occurrence.sentence_index !== null && occurrence.sentence_index !== undefined ? String(occurrence.sentence_index) : null,
                    reading_session_id: frozen.readingSessionId,
                    source_revision: frozen.sourceRevision,
                    occurrence_id: frozen.occurrenceId,
                }).then((response) => {
                    const senseId = Number(response.data && (response.data.sense_id || response.data.word_sense_id));
                    const reviewCardId = Number(response.data && response.data.review_card_id);
                    if (!Number.isInteger(senseId) || senseId <= 0 || !Number.isInteger(reviewCardId) || reviewCardId <= 0) {
                        const error = new Error('Manual sense response missing review identity.');
                        error.readerMalformedManualSenseResponse = true;
                        throw error;
                    }
                    const continuation = {
                        occurrenceId: occurrence.occurrence_id,
                        rating,
                        senseId,
                        reviewCardId,
                        sourceRevision: frozen.sourceRevision,
                        readingSessionId: frozen.readingSessionId,
                        outcomeUnknown: false,
                    };
                    this.setPendingManualSenseContinuation(continuation);
                    this.inlineReviewBusy = false;
                    return this.continueManualSenseRating(continuation);
                }).catch((error) => {
                    if (error && error.readerMalformedManualSenseResponse) {
                        this.inlineReviewError = '服务器已处理新增词义，但返回结果缺少词义卡身份。页面已保留原评分并阻止重复创建；刷新后可重新点这个词，从服务器候选中明确选择。';
                    } else if (!error || !error.response || (error.response && error.response.status >= 500)) {
                        this.inlineReviewError = '新增词义请求已经发出，但服务器结果未知。原评分已保存在本章会话中；刷新后不会重复新增，只能从服务器当前候选中明确选择词义继续。';
                    } else {
                        this.setPendingManualSenseContinuation(null);
                        this.inlineReviewError = requestErrorMessage(error, '新增词义失败，请修正后重试。');
                    }
                    return false;
                }).finally(() => { this.inlineReviewBusy = false; });
            },
            continueManualSenseRating(continuation) {
                if (!continuation || !continuation.senseId || !continuation.reviewCardId || !this.readingSessionId) return Promise.resolve(false);
                if (continuation.sourceRevision && continuation.sourceRevision !== this.readingSourceRevision) {
                    this.inlineReviewError = '新增词义后的绑定属于旧文章版本。已停止继续，请刷新本章后重新打开这个词。';
                    return Promise.resolve(false);
                }
                if (!continuation.readingSessionId || continuation.readingSessionId !== this.readingSessionId) {
                    this.inlineReviewError = '新增词义后的绑定属于另一阅读会话。已停止旧动作重试，请重新打开这个词。';
                    return Promise.resolve(false);
                }
                this.inlineReviewBusy = true;
                this.inlineReviewError = '';
                return axios.post('/chapters/' + this.chapterId + '/reading-occurrence-evidence', {
                    occurrence_id: continuation.occurrenceId,
                    resolution: 'matched_existing',
                    word_sense_id: continuation.senseId,
                }).then(() => this.refreshReadingSessionTargets())
                    .then((sessionOk) => {
                        if (!sessionOk) {
                            const error = new Error('READING_SESSION_REFRESH_FAILED');
                            error.readerContinuationBlocked = true;
                            throw error;
                        }
                        return this.loadAllReadingEvidence();
                    })
                    .then((evidenceOk) => {
                        if (!evidenceOk) {
                            const error = new Error('READING_EVIDENCE_REFRESH_FAILED');
                            error.readerContinuationBlocked = true;
                            throw error;
                        }
                        this.setPendingManualSenseContinuation(null);
                        this.setInlineOutcomeUnknownCommand(null);
                        this.inlineReviewDialog = false;
                        this.inlineReviewIntent = null;
                        this.inlineReviewError = '';
                        this.setReaderNotice('新词义已保存并绑定到当前句子。', 'success');
                        return true;
                    })
                    .catch((error) => {
                        if (error && error.readerContinuationBlocked) {
                            this.inlineReviewError = '新词义已经创建，但绑定后的服务器状态没有完整刷新。可以安全重试继续绑定，页面不会再次创建词义。';
                        } else if (!error || !error.response || (error.response && error.response.status >= 500)) {
                            this.inlineReviewError = '新词义已经创建，但本次出现位置的绑定结果暂时未知。已保留新词义；重试时只会继续绑定，不会重复创建词义。';
                        } else {
                            this.inlineReviewError = requestErrorMessage(error, '新词义已创建，但绑定当前出现位置失败。请重试继续步骤。');
                        }
                        return false;
                    })
                    .finally(() => { this.inlineReviewBusy = false; });
            },
            undoLastInlineRating() {
                const action = this.inlineLastUndoAction;
                if (!action || !action.undoable || !action.review_log_id || this.inlineUndoBusy) return;
                const reviewSessionId = action.review_session_id;
                if (!reviewSessionId) {
                    this.inlineLastUndoAction = { ...action, undoable: false };
                    this.inlineUndoSnackbar = { show: true, text: '服务器没有返回这次评分所属的会话身份，已停止撤销，避免撤销到别的阅读会话。' };
                    return;
                }
                if (!this.inlineUndoRequestId) {
                    this.inlineUndoRequestId = createReaderRequestId();
                    if (!this.inlineUndoRequestId) {
                        this.inlineUndoSnackbar = { show: true, text: '当前浏览器无法生成安全撤销请求编号。已停止撤销，请刷新后重试。' };
                        return;
                    }
                }
                this.inlineUndoBusy = true;
                const requestId = this.inlineUndoRequestId;
                return reviewApi.undoSenseReviewAction(action.review_log_id, {
                    review_session_id: reviewSessionId,
                    undo_request_id: requestId,
                    source: 'sense_review_snackbar',
                }).then(() => {
                    this.inlineLastUndoAction = { ...action, undoable: false };
                    this.inlineUndoRequestId = '';
                    this.inlineUndoSnackbar = { show: true, text: '服务器已确认撤销这次评分。' };
                    this.setReaderNotice('已撤销刚才的正式词义评分。', 'success');
                    return true;
                }).catch((error) => {
                    const status = error && error.response ? error.response.status : null;
                    if (!error || !error.response || status >= 500) {
                        this.inlineUndoSnackbar = { show: true, text: '撤销请求已经发出，但服务器结果未知。已保留同一个撤销请求编号；再次点击会安全重试。' };
                    } else if (status === 409) {
                        this.inlineLastUndoAction = { ...action, undoable: false };
                        this.inlineUndoRequestId = '';
                        this.inlineUndoSnackbar = { show: true, text: '无法撤销：卡片状态已经变化，或该评分已不再是可撤销的最新操作。' };
                    } else if (status === 404) {
                        this.inlineLastUndoAction = { ...action, undoable: false };
                        this.inlineUndoRequestId = '';
                        this.inlineUndoSnackbar = { show: true, text: '无法撤销：服务器找不到属于当前阅读会话的这次评分。' };
                    } else {
                        this.inlineUndoSnackbar = { show: true, text: requestErrorMessage(error, '撤销失败，请重试。') };
                    }
                    return false;
                }).finally(() => { this.inlineUndoBusy = false; });
            },
            resolveReadingSenseEvidence(intent) {
                if (!intent || !intent.occurrence_id || !this.chapterId || !this.readingSessionId) return;
                this.readingSenseVerificationBusyId = intent.occurrence_id;
                this.readingSenseVerificationError = '';
                return axios.post('/chapters/' + this.chapterId + '/reading-occurrence-evidence', {
                    occurrence_id: intent.occurrence_id,
                    resolution: intent.resolution,
                    word_sense_id: intent.word_sense_id,
                }).then(() => this.refreshReadingSessionTargets())
                    .then((sessionOk) => sessionOk ? this.loadAllReadingEvidence() : false)
                    .then((ok) => {
                        if (!ok) {
                            this.readingSenseVerificationError = '服务器已收到核对操作，但页面没有完整刷新。请点“刷新”后再继续完成阅读。';
                            return false;
                        }
                        this.setReaderNotice('词义核对结果已由服务器保存。', 'success');
                        return true;
                    })
                    .catch((error) => {
                        const status = error && error.response ? error.response.status : null;
                        if (!error || !error.response || status >= 500) {
                            this.readingSenseVerificationError = '词义核对请求已经发出，但服务器结果暂时未知。请刷新核对列表确认当前绑定；需要时可安全重试同一选择。';
                        } else {
                            this.readingSenseVerificationError = requestErrorMessage(error, '服务器拒绝了词义核对结果，请刷新后重试。');
                        }
                        return false;
                    })
                    .finally(() => { this.readingSenseVerificationBusyId = ''; });
            },
            buildFinishBasePayload() {
                if (!this.$refs.interactiveText) return null;
                this.leveledUpWordsAndPhrases = this.$refs.interactiveText.getLeveledUpWordsAndPhrases();
                return {
                    uniqueWords: JSON.stringify(this.$refs.interactiveText.uniqueWords),
                    autoLevelUpWords: this.settings.autoLevelUpWords,
                    leveledUpWords: JSON.stringify(this.leveledUpWordsAndPhrases.wordIds),
                    leveledUpPhrases: JSON.stringify(this.leveledUpWordsAndPhrases.phraseIds),
                    phrases: JSON.stringify(this.$refs.interactiveText.phrases),
                    language: this.language,
                    chapterId: this.chapterId,
                    autoMoveWordsToKnown: this.settings.autoMoveWordsToKnown,
                };
            },
            preFinishSafetyCheck() {
                return this.flushReadingInteractions()
                    .then(() => this.refreshReadingSessionTargets())
                    .then((sessionOk) => {
                        if (!sessionOk || this.finished) {
                            if (this.finished) return true;
                            const error = new Error('READING_SESSION_UNAVAILABLE');
                            error.readerPreCommitBlocked = true;
                            throw error;
                        }
                        return this.loadAllReadingEvidence();
                    })
                    .then((evidenceOk) => {
                        if (!evidenceOk) {
                            const error = new Error('READING_EVIDENCE_INCOMPLETE');
                            error.readerPreCommitBlocked = true;
                            throw error;
                        }
                        return true;
                    });
            },
            finishItemLabels(occurrenceIds) {
                const ids = new Set((Array.isArray(occurrenceIds) ? occurrenceIds : []).map(id => String(id)));
                if (!ids.size) return [];
                return this.readingSenseVerificationItems
                    .filter(item => ids.has(String(item.occurrence_id)))
                    .map(item => item.surface || item.phrase || item.lemma || '')
                    .filter((label, index, labels) => label && labels.indexOf(label) === index);
            },
            handleFinishProjection(payload, expectedMode) {
                const normalized = normalizeReaderFinishResult(payload || {});
                const identityMatches = normalized.chapterId === Number(this.chapterId)
                    && normalized.readingSessionId === this.readingSessionId
                    && Boolean(normalized.sourceRevision)
                    && normalized.sourceRevision === this.readingSourceRevision;
                const modeMatches = normalized.completed
                    ? normalized.settlementMode === 'commit'
                    : normalized.settlementMode === expectedMode;
                if (!identityMatches || !modeMatches) {
                    const error = new Error('READING_FINISH_RESPONSE_CONTRACT_INVALID');
                    error.readerFinishContractInvalid = true;
                    throw error;
                }
                if (normalized.completed) {
                    this.applyCompletedReadingResult(payload);
                    return 'completed';
                }
                this.finishPreflight = normalized;
                this.finishConfirmDialog = false;
                if (normalized.unresolvedCount > 0 || !normalized.canCommit) {
                    this.finishCommitDialog = false;
                    this.readingSenseVerificationDialog = true;
                    this.setReaderNotice('本次检查：将记为「记得」 ' + normalized.passiveGoodCount + ' 项，待核对 ' + normalized.unresolvedCount + ' 项，已排除 ' + normalized.excludedCount + ' 项。请先处理待核对词义。', 'warning');
                    return 'unresolved';
                }
                this.finishCommitDialog = true;
                return 'ready';
            },
            preflightFinishSettlement() {
                if (this.finishChecking || this.saving) return;
                if (!this.readingSessionId) {
                    this.setReaderNotice('完成检查还没准备好，请稍后重试。', 'warning');
                    return;
                }
                const basePayload = this.buildFinishBasePayload();
                if (!basePayload) return;
                this.finishChecking = true;
                return this.preFinishSafetyCheck()
                    .then(() => {
                        if (this.finished) return null;
                        const requestPayload = buildReaderFinishRequest(basePayload, this.readingSessionId, 'preflight');
                        if (!requestPayload) throw new Error('Invalid preflight request.');
                        return axios.post('/chapters/finish', requestPayload);
                    })
                    .then((response) => {
                        if (!response || this.finished) return true;
                        this.handleFinishProjection(response.data || {}, 'preflight');
                        return true;
                    })
                    .catch((error) => {
                        if (error && error.readerPreCommitBlocked) {
                            this.setReaderNotice('查词、查看答案或词义核对还没有准备完整，暂时不能完成阅读。请稍后重试。', 'warning');
                        } else if (error && error.readerFinishContractInvalid) {
                            this.setReaderNotice('完成检查结果与当前文章不一致。为了避免记错，本次没有继续，请刷新本章后重试。', 'warning');
                        } else {
                            this.setReaderNotice(requestErrorMessage(error, '完成检查暂时无法确认，尚未保存完成结果。请重试。'), 'warning');
                        }
                        return false;
                    })
                    .finally(() => { this.finishChecking = false; });
            },
            commitFinish() {
                if (!this.finishPreflight || !this.finishPreflight.canCommit || !this.readingSessionId) return;
                const basePayload = this.buildFinishBasePayload();
                if (!basePayload) return;
                this.finishCommitDialog = false;
                this.saving = true;
                this.finished = false;
                return this.preFinishSafetyCheck()
                    .then(() => {
                        if (this.finished) return null;
                        const requestPayload = buildReaderFinishRequest(basePayload, this.readingSessionId, 'commit');
                        if (!requestPayload) throw new Error('Invalid commit request.');
                        return axios.post('/chapters/finish', requestPayload);
                    })
                    .then((response) => {
                        if (!response || this.finished) return true;
                        const state = this.handleFinishProjection(response.data || {}, 'commit');
                        if (state !== 'completed') this.saving = false;
                        return state === 'completed';
                    })
                    .catch((error) => {
                        this.saving = false;
                        this.finished = false;
                        if (error && error.readerPreCommitBlocked) {
                            this.setReaderNotice('查词、查看答案或词义核对还没有准备完整，因此本次没有完成阅读。请稍后重试。', 'warning');
                        } else if (!error || !error.response || (error.response && error.response.status >= 500)) {
                            this.setReaderNotice('完成请求已经发出，但暂时没有收到明确结果。你的阅读进度已保留；重新点击「完成阅读」即可安全确认结果。', 'warning');
                        } else {
                            this.setReaderNotice(requestErrorMessage(error, '本次完成没有保存。请按页面提示核对词义后再重试。'), 'warning');
                        }
                        return false;
                    });
            },
            readerWorkspaceWidth() {
                const readerWorkspace = document.getElementById('fullscreen-box');
                return readerWorkspace ? readerWorkspace.clientWidth : window.innerWidth;
            },
            readerSidebarWidthForContentWidth(width) {
                return getReaderSidebarWidthForWorkspace(width);
            },
            loadAiAssistCurrent() {
                if (!this.chapterId) return Promise.resolve(false);
                this.readingSenseVerificationLoading = true;
                return axios.get('/chapters/ai-assist/current/' + this.chapterId).then((response) => {
                    const data = response.data || {};
                    if (data.success) {
                        this.hasSavedAiAssist = data.has_saved_assist;
                        this.aiSentenceTranslations = data.sentence_translations || [];
                        this.assistVerificationItems = normalizeReadingSenseVerificationItems(data);
                        this.mergeReadingVerificationState();
                        if (!data.has_saved_assist) this.aiTranslationMode = 'hidden';
                    }
                    return true;
                }).catch(() => {
                    // AI assist is optional; ordinary Reader interaction remains usable.
                    return true;
                }).finally(() => {
                    this.readingSenseVerificationLoading = false;
                });
            },
            vocabularySidebarTest() {
                const workspaceWidth = this.readerWorkspaceWidth();
                this.vocabularySidebarFits = doesReaderSidebarFitWorkspace(workspaceWidth);
                this.$forceUpdate();

                this.$nextTick(() => {
                    if (this.$refs.interactiveText) {
                        this.$refs.interactiveText.updateVocabBoxPosition();
                    }
                });
            },
            fullscreen() {
                const fullscreenBox = document.getElementById('fullscreen-box');
                if (document.fullscreenEnabled && fullscreenBox) {
                    fullscreenBox.requestFullscreen();
                    this.fullscreenMode = true;
                }
            },
            exitFullscreen() {
                if (document.fullscreenElement) {
                    document.exitFullscreen();
                }
                this.fullscreenMode = false;
            },
            updateFullscreen: function() {
                this.fullscreenMode = document.fullscreenElement !== null;
            },
            handleReaderResize() {
                this.preserveReadingContinuityAcrossLayoutChange();
                this.updateToolbarPosition();
                this.vocabularySidebarTest();
            },
            updateToolbarPosition: function(event) {
                this.toolbarTop = 28 - document.documentElement.scrollTop;

                if (document.documentElement.scrollTop > 28 || window.innerWidth < 620) {
                    this.toolbarTop = 0;
                }
            },
            updateSettings(settings) {
                this.settings = settings;
                this.$forceUpdate();

                setTimeout(() => {
                    if (this.$refs.interactiveText) {
                        this.$refs.interactiveText.updateVocabBoxPosition();
                    }
                }, 200);
            },
            toolbarSettingChanged() {
                this.$refs.textReaderSettings.changeSetting('fontSize', this.settings.fontSize);
                this.$refs.textReaderSettings.changeSetting('plainTextMode', this.settings.plainTextMode, true);
            },
            openDialog(dialog) {
                if (document.fullscreenElement !== null) {
                    this.exitFullscreen();
                }

                this.$refs.interactiveText.unselectAllWords();
                this.updateGlossary();

                if (dialog == 'settings') {
                    this.dialogs.settings = true;
                }

                if (dialog == 'glossary') {
                    this.dialogs.glossary = true;
                }

                if (dialog == 'chapters') {
                    this.dialogs.chapters = true;
                }
            },
            updateGlossary() {
                this.glossary = [];

                let phrases = this.$refs.interactiveText.phrases;
                for (let i = 0; i < phrases.length; i++) {
                    if (phrases[i].stage < 0) {
                        this.glossary.push({
                            word: phrases[i].words.join(''),
                            stage: phrases[i].stage,
                            reading: phrases[i].reading,
                            base_word: '',
                            base_word_reading: '',
                            translation: phrases[i].translation,
                        });
                    }
                }

                let uniqueWords = this.$refs.interactiveText.uniqueWords;
                for (let i = 0; i < uniqueWords.length; i++) {
                    if (uniqueWords[i].stage < 0 || uniqueWords[i].stage == 2) {
                        this.glossary.push({
                            word: uniqueWords[i].word,
                            stage: uniqueWords[i].stage,
                            reading: uniqueWords[i].reading,
                            base_word: uniqueWords[i].base_word,
                            base_word_reading: uniqueWords[i].base_word_reading,
                            translation: uniqueWords[i].translation,
                        });
                    }
                }

                this.glossary.sort((a, b) => {
                    return a.stage - b.stage;
                });
            },
            increaseFontSize() {
                this.preserveReadingContinuityAcrossLayoutChange();
                this.settings.fontSize ++;
                this.toolbarSettingChanged();
            },
            decreaseFontSize() {
                this.preserveReadingContinuityAcrossLayoutChange();
                this.settings.fontSize --;
                this.toolbarSettingChanged();
            },
            togglePlainTextMode() {
                this.settings.plainTextMode = !this.settings.plainTextMode;
                this.toolbarSettingChanged();
            },
            toggleHotkeyDialog() {
                this.hotkeyDialog = !this.hotkeyDialog;
            },
            toggleUnfamiliarMarkMode() {
                this.unfamiliarMarkMode = !this.unfamiliarMarkMode;
                if (this.$refs.interactiveText) {
                    this.$refs.interactiveText.unselectAllWords();
                }
                this.readerNotice = {
                    show: true,
                    color: this.unfamiliarMarkMode ? 'warning' : 'info',
                    text: this.unfamiliarMarkMode
                        ? '标记模式已开启：点一下标记单词；长按/拖动可标记同一句词组。再次点已标记位置可取消。'
                        : '已退出标记模式，点词恢复普通查词。',
                };
            },
            onUnfamiliarMarkRejected(message) {
                this.readerNotice = { show: true, color: 'warning', text: message };
            },
            openAiAssistDialog() {
                this.aiAssistDialog = true;
            },
            toggleAiTranslations() {
                this.preserveReadingContinuityAcrossLayoutChange();
                if (this.aiTranslationMode === 'hidden') {
                    this.aiTranslationMode = 'hover';
                } else if (this.aiTranslationMode === 'hover') {
                    this.aiTranslationMode = 'visible';
                } else {
                    this.aiTranslationMode = 'hidden';
                }
            },
            openFinishConfirmDialog() {
                // UX guard against accidental clicks on "完成阅读". The
                // backend `/chapters/finish` semantics are unchanged — this
                // dialog only inserts a confirmation step before the request.
                this.finishConfirmDialog = true;
            },
            formatNumber: formatNumber,
            applySourceHighlightFromQuery() {
                const sourceWord = this.$route.query.source_word;
                const sourceLemma = this.$route.query.source_lemma;
                const sourceSentenceId = this.$route.query.source_sentence_id;

                if (!sourceWord && !sourceLemma) {
                    return;
                }

                this.$nextTick(() => {
                    const targetIndex = this.findSourceWordIndex(sourceWord, sourceLemma, sourceSentenceId);

                    if (targetIndex === -1) {
                        return;
                    }

                    this.markSourceWord(targetIndex);
                    this.scrollSourceWordIntoView(targetIndex);
                });
            },
            findSourceWordIndex(sourceWord, sourceLemma, sourceSentenceId) {
                if (!this.text || !Array.isArray(this.text.words)) {
                    return -1;
                }

                const normalize = (value) => (value || '').toString().trim().toLowerCase();

                const word = normalize(sourceWord);
                const lemma = normalize(sourceLemma);
                const sentenceId = sourceSentenceId !== undefined && sourceSentenceId !== null
                    ? String(sourceSentenceId)
                    : null;

                for (let i = 0; i < this.text.words.length; i++) {
                    const token = this.text.words[i];
                    const tokenWord = normalize(token.word);
                    const tokenBase = normalize(token.base_word || token.lemma || token.study_base);
                    const tokenSentence = token.sentence_index !== undefined && token.sentence_index !== null
                        ? String(token.sentence_index)
                        : (token.sentence_id !== undefined && token.sentence_id !== null ? String(token.sentence_id) : null);

                    const sentenceMatches = sentenceId === null || tokenSentence === sentenceId;
                    const wordMatches = tokenWord === word || tokenWord === lemma || tokenBase === word || tokenBase === lemma;

                    if (sentenceMatches && wordMatches) {
                        return i;
                    }
                }

                return -1;
            },
            markSourceWord(index) {
                if (!this.text || !Array.isArray(this.text.words) || !this.text.words[index]) {
                    return;
                }

                this.$set(this.text.words[index], 'sourceHighlight', true);

                if (this.sourceHighlightTimer) {
                    clearTimeout(this.sourceHighlightTimer);
                }

                this.sourceHighlightTimer = setTimeout(() => {
                    if (this.text && this.text.words[index]) {
                        this.$set(this.text.words[index], 'sourceHighlight', false);
                    }
                }, 8000);
            },
            scrollSourceWordIntoView(index) {
                this.$nextTick(() => {
                    const element = document.querySelector('#reader-content [wordindex="' + index + '"]');
                    if (element && element.scrollIntoView) {
                        element.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                });
            },
        }
    }
</script>
