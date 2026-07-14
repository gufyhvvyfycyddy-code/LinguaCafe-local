<template>
    <v-container
        v-if="currentReviewIndex !== -1 || finished"
        id="review-box"
        :class="{
            'pa-0': $vuetify.breakpoint.smAndDown
        }"
    >

        <!-- Review hotkeys dialog -->
        <review-hotkey-information-dialog
            v-model="hotkeyDialog"
        ></review-hotkey-information-dialog>

        <!-- Settings -->
        <review-settings
            v-show="settingsDialog"
            v-model="settingsDialog"
            ref="reviewSettings"
            :language="language"
            @changed="updateSettings"
        ></review-settings>

        <sense-example-dialog
            v-model="sourceFallbackDialog"
            :payload="sourceFallbackContext"
            :language="language"
            :font-size="settings.fontSize"
        />

            <!-- Review finished box -->
            <v-card
                v-if="finished"
                outlined
                id="finish-review-box"
                class="mt-4 mx-auto rounded-lg"
                width="500px"
            >
                <!-- There were no cards at all -->
                <template v-if="totalReviews === 0">
                    <!-- Card title -->
                    <v-card-title>
                        <v-icon large color="error" class="mr-1">mdi-cards</v-icon>{{ reviewError || '当前没有到期的词义卡。' }}
                    </v-card-title>

                    <!-- Card content -->
                    <v-card-text>
                        {{ reviewError ? '请稍后重试，或检查后端服务是否正在运行。' : '当前没有到期的词义卡。阅读中添加词义后，会在这里复习。' }}
                    </v-card-text>

                    <!-- Daily limit reached but cards hidden -->
                    <v-card-text v-if="dailyLimitSummary && dailyLimitSummary.limit_reached && !dailyLimitSummary.ignore_daily_limits">
                        <v-alert dense outlined type="info" class="mb-0">
                            今天已完成 {{ dailyLimitSummary.reviewed_today_count }} 张复习，达到每日复习上限。
                            还有 {{ dailyLimitSummary.hidden_due_count }} 张到期卡暂时未显示。
                            <template v-if="dailyLimitSummary.can_continue_over_limit">
                                <v-btn
                                    small
                                    outlined
                                    color="primary"
                                    class="mt-2 d-block"
                                    @click="enableIgnoreDailyLimits"
                                >
                                    今天继续超额复习
                                </v-btn>
                                <div class="caption grey--text mt-1">只影响今天，不会修改你的上限设置。</div>
                            </template>
                        </v-alert>
                    </v-card-text>

                    <!-- Over-limit mode message -->
                    <v-card-text v-if="dailyLimitSummary && dailyLimitSummary.ignore_daily_limits">
                        <v-alert dense outlined type="info" class="mb-0">
                            🚀 今天已开启超额复习，本页会显示超过每日上限的到期卡。
                        </v-alert>
                    </v-card-text>
                </template>

                <!-- Review finished with cards -->
                <template v-if="totalReviews > 0">
                    <!-- Card title -->
                    <v-card-title>
                        <v-icon large color="success" class="mr-1">mdi-bookmark-check</v-icon>复习完成
                    </v-card-title>

                    <!-- Card content -->
                    <v-card-text>
                        你已经完成 {{ formatNumber(totalReviews) }} 张卡片。保持节奏，学习会稳步推进。
                    </v-card-text>

                    <!-- Hidden cards notice -->
                    <v-card-text v-if="dailyLimitSummary && dailyLimitSummary.hidden_due_count > 0 && !dailyLimitSummary.ignore_daily_limits">
                        <v-alert dense outlined type="info" class="mb-0">
                            今天的上限内复习已完成。还有 {{ dailyLimitSummary.hidden_due_count }} 张到期卡被每日上限暂时隐藏。
                            <template v-if="dailyLimitSummary.can_continue_over_limit">
                                <v-btn
                                    small
                                    outlined
                                    color="primary"
                                    class="mt-2 d-block"
                                    @click="enableIgnoreDailyLimits"
                                >
                                    今天继续超额复习
                                </v-btn>
                                <div class="caption grey--text mt-1">只影响今天，不会修改你的上限设置。</div>
                            </template>
                        </v-alert>
                    </v-card-text>

                    <!-- Over-limit mode message (during review) -->
                    <v-card-text v-if="dailyLimitSummary && dailyLimitSummary.ignore_daily_limits">
                        <v-alert dense outlined type="info" class="mb-0">
                            🚀 今天已开启超额复习，本页会显示超过每日上限的到期卡。
                        </v-alert>
                    </v-card-text>
                </template>

            <!-- Card buttons -->
            <v-card-actions>
                <v-spacer />
                <v-btn rounded depressed color="primary" to="/">
                    <v-icon class="mr-1">mdi-home</v-icon>
                    首页
                </v-btn>
            </v-card-actions>
        </v-card>

        <!-- Review -->
        <div id="review" v-if="!finished">
            <!-- Progress bar -->
            <div id="review-progress-line" class="d-flex align-center">
                <v-badge
                    left
                    overlap
                    color="success"
                    icon="mdi-check"
                >
                    <div
                        id="progress-bar-correct-counter"
                        class="border"
                        :style="{'border-color': $vuetify.theme.currentTheme.success}"
                    >
                        {{ correctReviews }}
                    </div>
                </v-badge>

                <v-progress-linear
                    id="review-progress-bar"
                    color="success"
                    background-color="foreground"
                    background-opacity="1"
                    height="36"
                    :value="correctReviews / totalReviews * 100"
                    class="rounded-pill border mx-6"
                >
                </v-progress-linear>
                <v-badge
                    overlap
                    color="error"
                    icon="mdi-cards"
                >
                    <div id="progress-bar-remaining-counter" class="border">{{ totalReviews - correctReviews }}</div>
                </v-badge>
            </div>

            <!-- Toolbar -->
            <div id="toolbar">
                <v-btn title="全屏" icon class="my-2" @click="openFullscreen" v-if="!fullscreen"><v-icon>mdi-arrow-expand-all</v-icon></v-btn>
                <v-btn title="退出全屏" icon class="my-2" @click="exitFullscreen" v-if="fullscreen"><v-icon>mdi-arrow-collapse-all</v-icon></v-btn>
                <v-btn title="复习设置" icon @click="settingsDialog = true;"><v-icon>mdi-cog</v-icon></v-btn>
                <v-btn
                    class="my-2"
                    icon
                    title="朗读"
                    :disabled="!textToSpeechAvailable"
                    @click="textToSpeech"
                >
                    <v-icon>mdi-bullhorn</v-icon>
                </v-btn>

                <v-menu offset-y left class="rounded-lg">
                    <template v-slot:activator="{ on, attrs }">
                        <v-btn
                            icon
                            title="例句模式"
                            class="my-2"
                            v-bind="attrs"
                            v-on="on"
                        >
                            <v-icon>mdi-text-long</v-icon>
                        </v-btn>
                    </template>
                    <v-btn
                        class="menu-button justify-start"
                        tile
                        color="white"
                        @click="settings.reviewSentenceMode = 'disabled'; saveSettings();"
                    >
                        <v-icon class="mr-1">mdi-close</v-icon>
                        关闭

                    </v-btn>
                    <v-btn
                        class="menu-button justify-start"
                        tile
                        color="white"
                        @click="settings.reviewSentenceMode = 'plain-text'; saveSettings();"
                    >
                        <v-icon class="mr-1">mdi-text-long</v-icon>
                        纯文本
                    </v-btn>
                    <v-btn
                        class="menu-button justify-start"
                        tile
                        color="white"
                        @click="settings.reviewSentenceMode = 'interactive-text'; saveSettings();"
                    >
                        <v-icon class="mr-1">mdi-comment-text-outline</v-icon>
                        交互文本
                    </v-btn>
                </v-menu>

                <v-btn title="增大字号" icon class="my-2" @click="increaseFontSize"><v-icon>mdi-magnify-plus</v-icon></v-btn>
                <v-btn title="减小字号" icon class="my-2" @click="decreaseFontSize"><v-icon>mdi-magnify-minus</v-icon></v-btn>
                <v-btn title="查看快捷键" icon class="my-2" @click="hotkeyDialog = !hotkeyDialog;"><v-icon>mdi-keyboard-outline</v-icon></v-btn>
            </div>

            <!-- DEV-QO-2 (Task 2000-12): Persistent rating error alert.
                 Shown when a rating request failed and the queue is being
                 reloaded. Stays visible after reload until the user
                 dismisses it or completes the next successful rating. -->
            <v-alert
                v-if="reviewError && !finished"
                type="error"
                dense
                outlined
                dismissible
                @input="reviewError = ''"
                class="mt-2 mb-0"
            >
                {{ reviewError }}
            </v-alert>

            <!-- Card -->
            <div id="review-card"
                :class="{
                    'revealed': revealed,
                    'back-to-deck-animation': backToDeckAnimation,
                    'into-the-correct-deck-animation': intoTheCorrectDeckAnimation,
                    'draw-new-card-animation': newCardAnimation
                }">
                <div class="vocab-box-area">
                    <div id="review-card-content">
                        <!-- Review card front -->
                        <div id="review-card-front" class="rounded-lg border">
                            <!-- Word review -->
                            <template v-if="reviews[currentReviewIndex] !== undefined && reviews[currentReviewIndex].type == 'word'">
                                <!-- Example sentence mode -->
                                <div :style="{'font-size': (settings.fontSize) + 'px'}" class="selected-font">
                                    <template v-if="reviews[currentReviewIndex].base_word !== ''">{{ reviews[currentReviewIndex].base_word }} <v-icon>mdi-arrow-right-thick</v-icon> </template>
                                    {{ reviews[currentReviewIndex].word }}<hr>

                                    <!-- Example sentence interactive text mode -->
                                    <text-block-group
                                        v-if="!revealed && exampleSentence !== null && settings.reviewSentenceMode === 'interactive-text'"
                                        ref="textBlock"
                                        :key="'text-block-1' + textBlockKey"
                                        :theme="theme"
                                        :fullscreen="fullscreen"
                                        :_text="exampleSentence"
                                        :language="language"
                                        :highlight-words="true"
                                        :plain-text-mode="false"
                                        :line-spacing="0"
                                        :font-size="settings.fontSize"
                                        :vocabulary-hover-box="settings.vocabularyHoverBox"
                                        :vocabulary-hover-box-search="settings.vocabularyHoverBoxSearch"
                                        :vocabulary-hover-box-delay="settings.vocabularyHoverBoxDelay"
                                        :vocabulary-hover-box-preferred-position="settings.vocabularyHoverBoxPreferredPosition"
                                        :vocabulary-hover-box-position-corrections="false"
                                        :vocabulary-bottom-sheet="settings.vocabularyBottomSheet"
                                    />

                                    <!-- Example sentence plain text mode -->
                                    <template v-if="exampleSentence !== null && settings.reviewSentenceMode === 'plain-text' && reviews[currentReviewIndex] !== undefined">
                                        <div class="phrase-words" :style="{'font-size': (settings.fontSize) + 'px'}">
                                            <span
                                                v-for="(word, wordIndex) in exampleSentence.words" :key="wordIndex"
                                                :class="{'selected-font': true, 'mr-2': word.spaceAfter}"
                                            >{{ word.word }}</span>
                                        </div>
                                    </template>
                                </div>

                                <!-- Single word  mode -->
                                <div class="single-word selected-font" v-if="!settings.reviewSentenceMode" :style="{'font-size': (settings.fontSize) + 'px'}">
                                    <template v-if="reviews[currentReviewIndex].base_word !== ''">{{ reviews[currentReviewIndex].base_word }} <v-icon>mdi-arrow-right-thick</v-icon> </template>
                                    {{ reviews[currentReviewIndex].word }}
                                </div>
                            </template>

                            <!-- Phrase review -->
                            <template v-if="reviews[currentReviewIndex] !== undefined && reviews[currentReviewIndex].type == 'phrase'">
                                <!-- Phrase only mode -->
                                <div class="phrase-words selected-font" :style="{'font-size': (settings.fontSize) + 'px'}">
                                    <template v-if="languageSpaces">
                                        {{ JSON.parse(reviews[currentReviewIndex].words).join(' ') }}
                                    </template>
                                    <template v-else>
                                        {{ JSON.parse(reviews[currentReviewIndex].words).join('') }}
                                    </template>

                                    <!-- Example sentence interactive text mode -->
                                    <hr v-if="settings.reviewSentenceMode !== 'disabled'">
                                    <text-block-group
                                        v-if="!revealed && exampleSentence !== null && settings.reviewSentenceMode === 'interactive-text'"
                                        ref="textBlock"
                                        :key="'text-block-2' + textBlockKey"
                                        :theme="theme"
                                        :fullscreen="fullscreen"
                                        :_text="exampleSentence"
                                        :language="language"
                                        :highlight-words="true"
                                        :plain-text-mode="false"
                                        :line-spacing="0"
                                        :font-size="settings.fontSize"
                                        :vocabulary-hover-box="settings.vocabularyHoverBox"
                                        :vocabulary-hover-box-search="settings.vocabularyHoverBoxSearch"
                                        :vocabulary-hover-box-delay="settings.vocabularyHoverBoxDelay"
                                        :vocabulary-bottom-sheet="settings.vocabularyBottomSheet"
                                    />

                                    <!-- Example sentence plain text mode -->
                                    <template v-if="exampleSentence !== null && settings.reviewSentenceMode === 'plain-text' && reviews[currentReviewIndex] !== undefined">
                                        <div class="phrase-words" :style="{'font-size': (settings.fontSize) + 'px'}">
                                            <span
                                                v-for="(word, wordIndex) in exampleSentence.words" :key="wordIndex"
                                                :class="{'selected-font': true, 'mr-2': word.spaceAfter}"
                                            >{{ word.word }}</span>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            <!-- Sense review -->
                            <template v-if="reviews[currentReviewIndex] !== undefined && reviews[currentReviewIndex].type == 'sense'">
                                <div class="selected-font" :style="{'font-size': (settings.fontSize) + 'px'}">
                                    <div class="text-h6 mb-2">{{ reviews[currentReviewIndex].lemma }}</div>
                                    <div class="text--secondary mb-3">
                                        {{ reviews[currentReviewIndex].surface_form || reviews[currentReviewIndex].lemma }}
                                        <span v-if="reviews[currentReviewIndex].pos"> / {{ reviews[currentReviewIndex].pos }}</span>
                                    </div>
                                    <v-sheet outlined rounded class="pa-3 mt-2">
                                        <sense-sentence-preview
                                            :tokens="reviews[currentReviewIndex].example_sentence_tokens"
                                            :sentence-text="reviews[currentReviewIndex].example_sentence_en"
                                            :target-surface="reviews[currentReviewIndex].surface_form"
                                            :target-lemma="reviews[currentReviewIndex].lemma"
                                            :language="language"
                                            :font-size="settings.fontSize"
                                            fallback-text="（回忆这个词义）"
                                        />
                                    </v-sheet>
                                    <v-btn
                                        small
                                        outlined
                                        class="mt-2"
                                        :loading="sourceLoading"
                                        @click.stop="openSenseSource"
                                    >
                                        查看原文/译文
                                    </v-btn>

                                    <div class="text-caption text--secondary mt-2">
                                        这里的 {{ reviews[currentReviewIndex].lemma }} 是什么意思？
                                    </div>
                                </div>
                            </template>

                            <!-- Reveal button -->
                            <div class="review-button-box">
                                <v-btn rounded id="review-reveal-button" color="success" @click="reveal" v-if="!revealed && !newCardAnimation && !backToDeckAnimation && !intoTheCorrectDeckAnimation"><v-icon>mdi-rotate-3d-variant</v-icon> 显示答案</v-btn>
                            </div>
                        </div>

                        <!-- Review card back -->
                        <div id="review-card-back" class="rounded-lg border" :style="{'background-color': backgroundColor}">
                            <!-- Word / Phrase review back (non-sense) -->
                            <template v-if="reviews[currentReviewIndex] !== undefined && reviews[currentReviewIndex].type != 'sense'">
                                <!-- Word review -->
                                <template v-if="reviews[currentReviewIndex].type == 'word'">
                                    <!-- Single word  mode -->
                                    <div class="word selected-font" :style="{'font-size': (settings.fontSize) + 'px'}">
                                        <template v-if="reviews[currentReviewIndex].base_word !== ''">{{ reviews[currentReviewIndex].base_word }} <v-icon>mdi-arrow-right-thick</v-icon> </template>
                                        {{ reviews[currentReviewIndex].word }}
                                    </div>
                                </template>

                                <!-- Phrase review -->
                                <template v-if="reviews[currentReviewIndex].type == 'phrase'">
                                    <div class="selected-font" :style="{'font-size': (settings.fontSize) + 'px'}">
                                        <template v-if="languageSpaces">
                                            {{ JSON.parse(reviews[currentReviewIndex].words).join(' ') }}
                                        </template>
                                        <template v-else>
                                            {{ JSON.parse(reviews[currentReviewIndex].words).join('') }}
                                        </template>
                                    </div>
                                </template>

                                <!-- Reading -->
                                <div class="reading selected-font" v-if="(language == 'japanese' || language == 'chinese')" :style="{'font-size': (settings.fontSize) + 'px'}">
                                    <hr>
                                    <template v-if="reviews[currentReviewIndex].type == 'word' && reviews[currentReviewIndex].base_word !== ''">{{ reviews[currentReviewIndex].base_word_reading }} <v-icon>mdi-arrow-right-thick</v-icon> </template>
                                    {{ reviews[currentReviewIndex].reading }}
                                </div>

                                <!-- Example sentence interactive text mode -->
                                <hr v-if="settings.reviewSentenceMode !== 'disabled'">
                                <text-block-group
                                    v-if="revealed && exampleSentence !== null && settings.reviewSentenceMode === 'interactive-text'"
                                    ref="textBlock"
                                    :key="'text-block-3' + textBlockKey"
                                    :theme="theme"
                                    :fullscreen="fullscreen"
                                    :_text="exampleSentence"
                                    :language="language"
                                    :highlight-words="true"
                                    :plain-text-mode="false"
                                    :line-spacing="0"
                                    :font-size="settings.fontSize"
                                    :vocabulary-hover-box="settings.vocabularyHoverBox"
                                    :vocabulary-hover-box-search="settings.vocabularyHoverBoxSearch"
                                    :vocabulary-hover-box-delay="settings.vocabularyHoverBoxDelay"
                                    :vocabulary-bottom-sheet="settings.vocabularyBottomSheet"
                                />

                                <!-- Example sentence plain text mode -->
                                <template v-if="exampleSentence !== null && settings.reviewSentenceMode === 'plain-text'">
                                    <div class="phrase-words" :style="{'font-size': (settings.fontSize) + 'px'}">
                                        <span
                                            v-for="(word, wordIndex) in exampleSentence.words" :key="wordIndex"
                                            :class="{'selected-font': true, 'mr-2': word.spaceAfter}"
                                        >{{ word.word }}</span>
                                    </div>
                                </template>

                                <!-- Translation -->
                                <hr>
                                <div id="translation" :style="{'font-size': (settings.fontSize) + 'px'}">
                                    {{ reviews[currentReviewIndex].translation }}
                                </div>
                            </template>

                            <!-- Sense review back -->
                            <template v-if="reviews[currentReviewIndex] !== undefined && reviews[currentReviewIndex].type == 'sense'">
                                <div class="selected-font" :style="{'font-size': (settings.fontSize) + 'px'}">
                                    <div class="text-h6">{{ reviews[currentReviewIndex].lemma }}</div>
                                    <div class="text--secondary mb-2">
                                        <span v-if="reviews[currentReviewIndex].pos">{{ reviews[currentReviewIndex].pos }}</span>
                                    </div>
                                    <hr>
                                    <!-- 中文释义优先，降级到英文释义，再降级到"暂无释义" -->
                                    <div v-if="reviews[currentReviewIndex].sense_zh" class="mb-3" style="font-size: 24px; font-weight: 600;">
                                        {{ reviews[currentReviewIndex].sense_zh }}
                                    </div>
                                    <div v-else-if="reviews[currentReviewIndex].sense_en" class="mb-3" style="font-size: 24px; font-weight: 600;">
                                        {{ reviews[currentReviewIndex].sense_en }}
                                    </div>
                                    <div v-else class="mb-3" style="font-size: 20px; color: #999;">
                                        暂无释义
                                    </div>
                                    <!-- 英文释义：中文释义存在时作为补充 -->
                                    <div v-if="reviews[currentReviewIndex].sense_zh && reviews[currentReviewIndex].sense_en" class="mb-2">
                                        {{ reviews[currentReviewIndex].sense_en }}
                                    </div>
                                    <v-sheet outlined rounded class="pa-3 mb-3">
                                        <sense-sentence-preview
                                            :tokens="reviews[currentReviewIndex].example_sentence_tokens"
                                            :sentence-text="reviews[currentReviewIndex].example_sentence_en"
                                            :target-surface="reviews[currentReviewIndex].surface_form"
                                            :target-lemma="reviews[currentReviewIndex].lemma"
                                            :language="language"
                                            :font-size="settings.fontSize"
                                            fallback-text="（无例句）"
                                        />
                                        <div v-if="reviews[currentReviewIndex].example_sentence_zh" class="text--secondary mt-1">
                                            {{ reviews[currentReviewIndex].example_sentence_zh }}
                                        </div>
                                    </v-sheet>
                                </div>
                            </template>

                            <!-- Answer buttons -->
                            <!-- DEV-LOCK-2 (Task 2000-14): bind :disabled to
                                 ratingLoading so buttons are visually greyed
                                 out during rating request AND during recovery
                                 reload. hotkey is still guarded inside
                                 rateReview(). -->
                            <div class="review-button-box">
                                <v-btn rounded class="review-rating-button" color="error" :disabled="ratingLoading" @click="rateReview('again')" v-if="revealed">忘了</v-btn>
                                <v-btn rounded class="review-rating-button" color="warning" :disabled="ratingLoading" @click="rateReview('hard')" v-if="revealed">勉强记得</v-btn>
                                <v-btn rounded class="review-rating-button" color="success" :disabled="ratingLoading" @click="rateReview('good')" v-if="revealed">记得</v-btn>
                                <v-btn rounded class="review-rating-button" color="primary" :disabled="ratingLoading" @click="rateReview('easy')" v-if="revealed">很熟</v-btn>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </v-container>
</template>

<script>
    const moment = require('moment');
    import TextToSpeechService from './../../services/TextToSpeechService';
    import {formatNumber} from './../../helper.js';
    import { DefaultLocalStorageManager } from './../../services/LocalStorageManagerService';
    import { requestErrorMessage } from './../../services/UiTextService';
    import { runAuthoritativeRatingRecovery } from './ReviewRatingRecovery.js';
    import { createTracker, pause as pauseDuration, resume as resumeDuration, durationMs } from './ReviewDurationTracker.js';
    import SenseSentencePreview from './SenseSentencePreview.vue';
    import SenseExampleDialog from './SenseExampleDialog.vue';

    export default {
        components: {
            SenseSentencePreview,
            SenseExampleDialog,
        },
        data: function() {
            return {
                textToSpeechService: null,
                textToSpeechAvailable: false,
                theme: DefaultLocalStorageManager.loadSetting('theme') || 'light',
                hotkeyDialog: false,
                textBlockKey: 0,
                exampleSentence: [
                    {
                        id: -1,
                        words: [],
                        phrases: [],
                        uniqueWords: [],
                    }
                ],
                practiceMode: false,
                revealed: false,
                backToDeckAnimation: false,
                intoTheCorrectDeckAnimation: false,
                backgroundColor: this.$vuetify.theme.currentTheme.foreground,
                newCardAnimation: false,
                settingsDialog: false,
                settings: {
                    fontSize: DefaultLocalStorageManager.loadSetting('fontSize') || 20,
                    reviewSentenceMode: DefaultLocalStorageManager.loadSetting('reviewSentenceMode') || 'plain-text',
                    vocabularyHoverBox: DefaultLocalStorageManager.loadSetting('vocabularyHoverBox') || true,
                    vocabularyHoverBoxSearch: DefaultLocalStorageManager.loadSetting('vocabularyHoverBoxSearch') || true,
                    vocabularyHoverBoxDelay: DefaultLocalStorageManager.loadSetting('vocabularyHoverBoxDelay') || 300,
                    vocabularyHoverBoxPreferredPosition: DefaultLocalStorageManager.loadSetting('vocabularyHoverBoxPreferredPosition') || 'bottom',
                    vocabularyBottomSheet: DefaultLocalStorageManager.loadSetting('vocabularyBottomSheet') || true,
                },
                transitionDuration: DefaultLocalStorageManager.loadSetting('theme') === 'eink' ? 0 : 400,
                fullscreen: false,
                currentReviewIndex: -1,
                reviews: [],
                totalReviews: 0,
                sourceLoading: false,
                sourceFallbackDialog: false,
                sourceFallbackContext: null,
                sourceError: '',
                correctReviews: 0,
                language: '',
                languageSpaces: false,
                readWords: 0,
                finishedReviews: -1,
                reviewError: '',
                finished: false,
                today: new moment().format('YYYY-MM-DD'), // CHANGE TO SERVER SIDE
                ignoreDailyLimits: false,
                dailyLimitSummary: null,
                // DEV-QO-6: rating request race protection.
                // ratingLoading disables re-rating while a request is in
                // flight. ratingRequestSequence is monotonically incremented
                // per request; only the response carrying the latest sequence
                // is allowed to mutate reviews / currentReviewIndex /
                // dailyLimitSummary / finished. Slow responses are dropped.
                ratingLoading: false,
                ratingRequestSequence: 0,
                reviewDurationTracker: createTracker(undefined, document.visibilityState !== 'hidden'),
            }
        },
        props: {
        },
        mounted: function() {
            // Check localStorage for today's ignore_daily_limits status
            var lsKey = 'linguacafe_sense_review_ignore_daily_limits_' + this.today;
            var stored = localStorage.getItem(lsKey);
            if (stored === 'true') {
                this.ignoreDailyLimits = true;
            }

            this.loadReviews();
            document.addEventListener('visibilitychange', this.handleReviewVisibility);
        },
        beforeDestroy: function () {
            window.removeEventListener('keyup', this.hotkey);
            document.removeEventListener('visibilitychange', this.handleReviewVisibility);
            // DEV-QO-6: invalidate in-flight rating requests on destroy.
            this.ratingRequestSequence++;
            this.ratingLoading = false;
        },
        methods: {
            handleReviewVisibility() {
                if (document.visibilityState === 'hidden') pauseDuration(this.reviewDurationTracker);
                else resumeDuration(this.reviewDurationTracker);
            },
            enableIgnoreDailyLimits() {
                var lsKey = 'linguacafe_sense_review_ignore_daily_limits_' + this.today;
                localStorage.setItem(lsKey, 'true');
                this.ignoreDailyLimits = true;
                this.finished = false;
                this.reviews = [];
                this.totalReviews = 0;
                this.currentReviewIndex = -1;
                this.correctReviews = 0;
                this.reviewError = '';
                this.dailyLimitSummary = null;
                // DEV-QO-6: invalidate any in-flight rating request so its
                // response cannot overwrite the freshly reloaded queue.
                this.ratingRequestSequence++;
                this.ratingLoading = false;
                this.$nextTick(() => {
                    this.loadReviews();
                });
            },
            loadReviews() {
                // DEV-QO-6: invalidate any in-flight rating request when the
                // queue is (re)loaded so stale responses cannot mutate the
                // new queue state.
                this.ratingRequestSequence++;
                this.ratingLoading = false;

                var data = {
                    bookId: -1,
                    chapterId: -1,
                    practiceMode: this.practiceMode,
                };

                if (this.$route.params.bookId !== undefined) {
                    data.bookId = parseInt(this.$route.params.bookId);
                }

                if (this.$route.params.chapterId !== undefined) {
                    data.chapterId = parseInt(this.$route.params.chapterId);
                }

                if (this.$route.params.practiceMode !== undefined) {
                    data.practiceMode = this.$route.params.practiceMode === 'true';
                    this.practiceMode = this.$route.params.practiceMode === 'true';
                }

                if (this.ignoreDailyLimits) {
                    data.ignoreDailyLimits = true;
                }

                // DEV-RECOVERY-2 (Task 2000-13): return the full axios
                // Promise so the rating-recovery helper can reliably await
                // success or failure. Callers that ignore the return value
                // (mounted / ignoreDailyLimits) are unaffected.
                return axios.post('/reviews', data).then((response) => {
                    var data = response.data;
                    this.reviews = data.reviews;
                    this.totalReviews = data.reviews.length;
                    this.language = data.language;
                    this.languageSpaces = data.languageSpaces;
                    this.dailyLimitSummary = data.summary || null;

                    if (this.reviews.length) {
                        this.$nextTick(() => {
                            this.next();
                            this.$nextTick(() => {
                                document.getElementById('review-box').addEventListener('fullscreenchange', this.updateFullscreen);
                            });
                        });
                    } else {
                        this.finish();
                    }

                    this.textToSpeechService = new TextToSpeechService(this.language, this.updateTextToSpeechState);
                    window.addEventListener('keyup', this.hotkey);
                    // DEV-QO-2 (Task 2000-12): reload complete — unlock
                    // rating buttons. This is essential when loadReviews is
                    // called from the rating-error recovery path.
                    this.ratingLoading = false;
                }).catch((error) => {
                    this.reviewError = requestErrorMessage(error, '复习队列加载失败。');
                    this.totalReviews = 0;
                    this.finished = true;
                    // DEV-QO-2 (Task 2000-12): reload failed — no cards to
                    // rate, unlock buttons (finished view is shown anyway).
                    this.ratingLoading = false;
                });
            },
            textToSpeech() {
                var text = '';
                var joinSeparator = this.languageSpaces ? ' ' : '';

                if (this.reviews[this.currentReviewIndex].type == 'sense') {
                    text = this.reviews[this.currentReviewIndex].lemma || '';
                } else if (this.reviews[this.currentReviewIndex].type == 'phrase') {
                    if (this.reviews[this.currentReviewIndex].reading.length) {
                        text = this.reviews[this.currentReviewIndex].reading;
                    } else {
                        text = JSON.parse(this.reviews[this.currentReviewIndex].words).join(joinSeparator);
                    }
                } else {
                    if (this.reviews[this.currentReviewIndex].reading.length) {
                        text = this.reviews[this.currentReviewIndex].reading;
                    } else {
                        text = this.reviews[this.currentReviewIndex].word;
                    }
                }

                this.textToSpeechService.speak(text);
            },
            updateTextToSpeechState() {
                this.textToSpeechAvailable = this.textToSpeechService.getLanguageVoices().length > 0;
            },
            hotkey (event) {
                if (!this.finished && !this.revealed && event.which == 13) {
                    this.reveal();
                }

                if (!this.finished && this.revealed && event.which == 49) {
                    this.rateReview('again');
                }

                if (!this.finished && this.revealed && event.which == 50) {
                    this.rateReview('hard');
                }

                if (!this.finished && this.revealed && event.which == 51) {
                    this.rateReview('good');
                }

                if (!this.finished && this.revealed && event.which == 52) {
                    this.rateReview('easy');
                }
            },
            openFullscreen() {
                if (document.fullscreenEnabled) {
                    document.getElementById('review-box').requestFullscreen();
                    this.fullscreen = true;
                }
            },
            exitFullscreen() {
                document.exitFullscreen();
                this.fullscreen = false;
            },
            updateFullscreen: function() {
                this.fullscreen = document.fullscreenElement !== null;
            },
            updateSettings(settings) {
                this.settings = settings;
                this.$forceUpdate();
            },
            saveSettings() {
                DefaultLocalStorageManager.saveSetting('fontSize', this.settings.fontSize);
                DefaultLocalStorageManager.saveSetting('reviewSentenceMode', this.settings.reviewSentenceMode);
                DefaultLocalStorageManager.saveSetting('vocabularyHoverBox', this.settings.vocabularyHoverBox);
                DefaultLocalStorageManager.saveSetting('vocabularyHoverBoxSearch', this.settings.vocabularyHoverBoxSearch);
                DefaultLocalStorageManager.saveSetting('vocabularyHoverBoxDelay', this.settings.vocabularyHoverBoxDelay);
                DefaultLocalStorageManager.saveSetting('vocabularyHoverBoxPreferredPosition', this.settings.vocabularyHoverBoxPreferredPosition);
                DefaultLocalStorageManager.saveSetting('vocabularyBottomSheet', this.settings.vocabularyBottomSheet);
            },
            increaseFontSize() {
                this.settings.fontSize ++;
                this.saveSettings();
            },
            decreaseFontSize() {
                this.settings.fontSize --;
                this.saveSettings();
            },
            reveal() {
                if (this.intoTheCorrectDeckAnimation || this.backToDeckAnimation || this.newCardAnimation) {
                    return;
                }

                if (this.$refs.textBlock !== undefined && this.settings.reviewSentenceMode === 'interactive-text') {
                    this.$refs.textBlock.unselectAllWords(true);
                }

                this.revealed = true;
                this.newCardAnimation = false;
            },
            countReadWords() {
                // sense 卡片无单词统计，跳过
                if (this.reviews[this.currentReviewIndex].type == 'sense') {
                    return;
                }

                var wordsToSkip = ['。', '、', ':', '？', '！', '＜', '＞', '：', ' ', '「', '」', '（', '）', '｛', '｝', '≪', '≫', '〈', '〉',
                        '《', '》','【', '】', '『', '』', '〔', '〕', '［', '］', '・', '?', '(', ')', ' ', ' NEWLINE ', '.', '%', '-',
                        '«', '»', "'", '’', '–', 'NEWLINE'];

                if (this.settings.reviewSentenceMode === 'disabled' || this.exampleSentence === null) {
                    if (this.reviews[this.currentReviewIndex].type == 'word') {
                        this.readWords ++;
                    } else {
                        this.readWords += JSON.parse(this.reviews[this.currentReviewIndex].words).length;
                    }
                } else {
                    for (var i = 0; i < this.exampleSentence.words.length; i++) {
                        if (wordsToSkip.includes(this.exampleSentence.words[i].word)) {
                            continue;
                        }

                        this.readWords ++;
                    }
                }
            },
            rateReview(rating) {
                if (this.reviews[this.currentReviewIndex] === undefined) {
                    return;
                }

                // DEV-QO-6: race protection — disable re-rating while a
                // request is in flight. This prevents duplicate ReviewLog
                // entries and prevents slow responses from overwriting newer
                // page state.
                if (this.ratingLoading) {
                    return;
                }

                this.revealed = false;
                this.intoTheCorrectDeckAnimation = true;
                this.backToDeckAnimation = false;
                this.newCardAnimation = false;
                this.backgroundColor = rating === 'again'
                    ? this.$vuetify.theme.currentTheme.error
                    : this.$vuetify.theme.currentTheme.success;

                // DEV-QO-2 (Task 2000-12): Do NOT increment correctReviews
                // or countReadWords() before the server confirms the rating.
                // If the request fails, these statistics must not have been
                // permanently increased. They are now incremented inside the
                // .then() success path only.

                if (!this.practiceMode) {
                    // DEV-QO-6: increment sequence and capture for this
                    // request. Only the response with the latest sequence
                    // may mutate queue state.
                    this.ratingLoading = true;
                    const seq = ++this.ratingRequestSequence;

                    // DEV-QO-5: pass ignoreDailyLimits so /reviews/rate uses
                    // the same daily-limit boundary as /reviews.
                    const payload = {
                        reviewCardId: this.reviews[this.currentReviewIndex].review_card_id,
                        rating: rating,
                        review_duration_ms: durationMs(this.reviewDurationTracker),
                    };
                    if (this.ignoreDailyLimits) {
                        payload.ignoreDailyLimits = true;
                    }

                    axios.post('/reviews/rate', payload).then((response) => {
                        // DEV-QO-6: drop stale responses. If a newer request
                        // has been issued (or the queue was reloaded), this
                        // response must not mutate state.
                        if (seq !== this.ratingRequestSequence) {
                            return;
                        }

                        // DEV-QO-2 (Task 2000-12): Server has confirmed the
                        // rating. NOW it is safe to increment success counters
                        // and count read words for the just-rated card. These
                        // MUST NOT happen before server confirmation — if the
                        // request failed, they must not have been increased.
                        this.correctReviews ++;
                        this.countReadWords();
                        // Clear any persistent rating error from a previous
                        // failed attempt — the user has now completed a
                        // successful rating.
                        this.reviewError = '';

                        axios.post('/goals/achievement/review/update').catch(() => {});

                        // DEV-QO-5: update daily limit summary from server.
                        if (response.data && response.data.summary) {
                            this.dailyLimitSummary = response.data.summary;
                        }

                        // Remove the just-rated card from the local queue.
                        this.reviews.splice(this.currentReviewIndex, 1)[0];

                        // DEV-QO-5: consume server-authoritative next_card.
                        const nextCard = response.data && response.data.next_card
                            ? response.data.next_card
                            : null;

                        if (nextCard) {
                            // If next_card already exists in the remaining
                            // queue (e.g. relearning card re-entering), move
                            // it to index 0. Otherwise insert it at index 0.
                            const existingIdx = this.reviews.findIndex(
                                c => c.review_card_id === nextCard.review_card_id
                            );
                            if (existingIdx !== -1) {
                                // Move existing card to front.
                                const [existing] = this.reviews.splice(existingIdx, 1);
                                this.reviews.unshift(existing);
                            } else {
                                // Insert server-provided next card at front.
                                this.reviews.unshift(nextCard);
                            }
                            setTimeout(this.next, this.transitionDuration);
                        } else {
                            // No next card from server. If the local queue
                            // is empty, finish; otherwise continue with the
                            // remaining local queue (old-response compat).
                            if (this.reviews.length === 0) {
                                this.finish();
                            } else {
                                setTimeout(this.next, this.transitionDuration);
                            }
                        }
                    }).catch((error) => {
                        // DEV-QO-6: only handle error if this is still the
                        // latest request. Otherwise the error belongs to a
                        // stale response.
                        if (seq !== this.ratingRequestSequence) {
                            return;
                        }
                        // DEV-RECOVERY-1 (Task 2000-13): delegate the
                        // recovery orchestration to the pure JS helper.
                        // The helper keeps ratingLoading=true (buttons
                        // disabled), calls loadReviews() which returns a
                        // Promise, waits for it to settle, then unlocks.
                        // The helper does NOT touch statistics, ReviewLog,
                        // FSRS, or lifecycle.
                        // Reset animation and background so the page looks
                        // operable during reload.
                        this.intoTheCorrectDeckAnimation = false;
                        this.backToDeckAnimation = false;
                        this.newCardAnimation = false;
                        this.backgroundColor = this.$vuetify.theme.currentTheme.foreground;
                        runAuthoritativeRatingRecovery({
                            reloadQueue: () => this.loadReviews(),
                            lockRating: () => { this.ratingLoading = true; },
                            unlockRating: () => { this.ratingLoading = false; },
                            setRecoveryMessage: () => {
                                this.reviewError = '评分结果状态不确定，正在重新加载正式复习队列，请不要重复评分。';
                            },
                            preserveLoadError: () => !!this.reviewError,
                        });
                    }).finally(() => {
                        // DEV-QO-6: restore operable state only if this is
                        // still the latest request. When the catch handler
                        // triggers loadReviews(), the sequence is incremented,
                        // so this finally will NOT reset ratingLoading — the
                        // reload's own .then/.catch is responsible for that.
                        if (seq === this.ratingRequestSequence) {
                            this.ratingLoading = false;
                        }
                    });
                } else {
                    // practiceMode: no ReviewLog, no backend call. Keep old
                    // behavior — just splice and advance locally.
                    if (this.reviews.length == 1) {
                        this.finish();
                    } else {
                        this.reviews.splice(this.currentReviewIndex, 1)[0];
                        setTimeout(this.next, this.transitionDuration);
                    }
                }
            },
            correct() {
                this.rateReview('good');
            },
            missed() {
                this.rateReview('again');
            },
            next() {
                this.backToDeckAnimation = false;
                this.intoTheCorrectDeckAnimation = false;
                this.newCardAnimation = true;
                this.backgroundColor = this.$vuetify.theme.currentTheme.foreground;

                if (this.$refs.textBlock !== undefined && this.settings.reviewSentenceMode === 'interactive-text') {
                    this.$refs.textBlock.unselectAllWords(true);
                }

                setTimeout(() => {
                    this.newCardAnimation = false;
                }, this.transitionDuration);

                this.finishedReviews ++;
                // ADR-0015 V1: 后端已按 Queue Order 排序返回 reviews，
                // 前端不再自己随机选择下一张。始终使用队列第一张（index 0）。
                // 评分后 rateReview 会 splice 掉刚评过的卡，因此 index 0 自然
                // 变成队列中的下一张。这保证 /reviews 与 /reviews/senses 两个
                // 入口返回相同的卡顺序。
                this.currentReviewIndex = 0;
                this.reviewDurationTracker = createTracker(undefined, document.visibilityState !== 'hidden');

                this.exampleSentence = null;
                this.sourceFallbackDialog = false;
                this.sourceFallbackContext = null;
                this.sourceError = '';

                // sense 卡片已在 payload 中自带例句，无需 API 加载
                if (this.reviews[this.currentReviewIndex].type !== 'sense') {
                    axios.get('/vocabulary/example-sentence/' + this.reviews[this.currentReviewIndex].type + '/' + this.reviews[this.currentReviewIndex].id).then((response) => {
                        if (response.data.words !== undefined) {
                            this.exampleSentence = {
                                id: 0,
                                words: response.data.words,
                                phrases: response.data.phrases,
                                uniqueWords: response.data.uniqueWords,
                            };
                        }

                        this.textBlockKey++;
                    }).catch(() => {});
                }

                // update reviewed and read words data
                axios.post('/reviews/update', {
                    readWords: this.readWords,
                }).then(() => {
                    this.readWords = 0;
                }).catch(() => {});
            },
            finish() {
                this.finished = true;
            },
            openSenseSource() {
                const card = this.reviews[this.currentReviewIndex];

                if (!card || card.type !== 'sense' || !card.word_sense_id) {
                    return;
                }

                this.sourceLoading = true;
                this.sourceError = '';

                axios.get('/senses/' + card.word_sense_id + '/source-context')
                    .then((response) => {
                        this.sourceFallbackContext = {
                            card: card,
                            context: response.data,
                        };
                        this.sourceFallbackDialog = true;
                    })
                    .catch(() => {
                        this.sourceFallbackContext = {
                            card: card,
                            context: null,
                            error: '原文位置加载失败。',
                        };
                        this.sourceFallbackDialog = true;
                    })
                    .finally(() => {
                        this.sourceLoading = false;
                    });
            },
            formatNumber: formatNumber
        },
    }
</script>
