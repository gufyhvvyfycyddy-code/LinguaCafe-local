<template>
    <v-dialog v-model="value" persistent scrollable max-width="1000">
        <v-card
            id="text-reader-settings"
            outlined
            class="rounded-lg"
        >
            <v-card-title>
                <span class="text-h5">阅读设置</span>
                <v-spacer></v-spacer>
                <v-btn icon @click="close">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </v-card-title>
            <v-card-text class="pb-12" v-if="settingsLoaded" style="height: 800px;">
                <v-tabs :show-arrows="false" v-model="tab" background-color="foreground" class="rounded-lg border overflow-hidden">
                    <v-tab>文本</v-tab>
                    <v-tab>词汇框</v-tab>
                    <v-tab>悬浮词汇框</v-tab>
                </v-tabs>
                <v-tabs-items v-model="tab" elevation="0" class="rounded-lg mt-4 pa-6">
                    <!-- Text section -->
                    <v-tab-item :value="0">
                        <!-- Font type -->
                        <v-row v-if="fontTypes.length">
                            <v-col cols="12" md="4" class="switch-container d-flex align-center mt-0 mb-md-5">字体：</v-col>
                            <v-col cols="12" md="8" class="switch-container d-flex align-center mt-0 pt-3 justify-end">
                                <v-select
                                    v-model="selectedFontType"
                                    :items="fontTypes"
                                    item-text="name"
                                    item-value="id"
                                    dense
                                    rounded
                                    filled
                                    hide-details
                                    @change="saveSettings"
                                ></v-select>
                            </v-col>
                        </v-row>

                        <!-- Line spacing -->
                        <v-row>
                            <v-col cols="12" sm="3" class="d-flex align-center mt-0 mt-md-0 mb-md-5 pb-0 pb-sm-0 pb-md-3">行距：</v-col>
                            <v-col class="slider-container d-flex pt-xs-0 pt-sm-0 pt-md-3 align-center">
                                <v-slider
                                    v-model="settings.lineSpacing"
                                    :tick-labels="['小', '', '', '', '', '', '', '', '', '', '大']"
                                    :tick-size="0"
                                    :max="10"
                                    thumb-label="always"
                                    thumb-size="38"
                                    step="1"
                                    track-color="#c5c5c5"
                                    @change="saveSettings"
                                >
                                </v-slider>
                            </v-col>
                        </v-row>

                        <!-- Maximum text width -->
                        <v-row>
                            <v-col cols="12" sm="3" class="d-flex align-center mt-0 mt-md-0 mb-md-5 pb-0 pb-sm-0 pb-md-3">最大文本宽度：</v-col>
                            <v-col class="slider-container d-flex pt-xs-0 pt-sm-0 pt-md-3 align-center">
                                <v-slider
                                    v-model="settings.maximumTextWidth"
                                    :tick-labels="['窄', '', '', '', '', '', '全宽']"
                                    :tick-size="0"
                                    :max="6"
                                    thumb-label="always"
                                    thumb-size="38"
                                    step="1"
                                    track-color="#c5c5c5"
                                    @change="saveSettings"
                                >
                                    <template v-slot:thumb-label>{{ maximumTextWidthData[settings.maximumTextWidth] }}</template>
                                </v-slider>
                            </v-col>
                        </v-row>

                        <!-- Font size -->
                        <v-row>
                            <v-col cols="12" sm="3" class="d-flex align-center mt-0 mt-md-0 mb-md-5 pb-0 pb-sm-0 pb-md-3">字号：</v-col>
                            <v-col class="slider-container d-flex pt-xs-0 pt-sm-0 pt-md-3 align-center">
                                <v-slider
                                    v-model="settings.fontSize"
                                    :tick-labels="['小', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '大']"
                                    :tick-size="0"
                                    :min="12"
                                    :max="30"
                                    step="1"
                                    thumb-label="always"
                                    thumb-size="38"
                                    track-color="#c5c5c5"
                                    @change="saveSettings"
                                ></v-slider>
                            </v-col>
                        </v-row>

                        <!-- Hide all highlighting -->
                        <v-row>
                            <v-col cols="8" md="4" class="switch-container d-flex align-center mt-0 mb-md-5 ">隐藏所有高亮：</v-col>
                            <v-col cols="4" md="8" class="switch-container d-flex align-center mt-0 pt-3 justify-end">
                                <v-switch
                                    color="primary"
                                    v-model="settings.hideAllHighlights"
                                    @change="saveSettings('hideAllHighlights')"
                                ></v-switch>
                            </v-col>
                        </v-row>

                        <!-- Hide new word highlighting -->
                        <v-row>
                            <v-col cols="8" md="4" class="switch-container d-flex align-center mt-0 mb-md-5 ">隐藏生词高亮：</v-col>
                            <v-col cols="4" md="8" class="switch-container d-flex align-center mt-0 pt-3 justify-end">
                                <v-switch
                                    color="primary"
                                    v-model="settings.hideNewWordHighlights"
                                    @change="saveSettings"
                                ></v-switch>
                            </v-col>
                        </v-row>

                        <!-- Vertical text -->
                        <v-row>
                            <v-col cols="8" md="4" class="switch-container d-flex align-center mt-0 mb-md-5">竖排文本：</v-col>
                            <v-col cols="4" md="8" class="switch-container d-flex align-center mt-0 pt-3 justify-end">
                                <v-switch
                                    color="primary"
                                    v-model="settings.verticalText"
                                    @change="saveSettings"
                                    disabled
                                ></v-switch>
                            </v-col>
                        </v-row>

                        <!-- Furigana on highlighted words -->
                        <v-row>
                            <v-col cols="8" md="4" class="switch-container d-flex align-center mt-0 mb-md-5">高亮词显示假名：</v-col>
                            <v-col cols="4" md="8" class="switch-container d-flex align-center mt-0 pt-3 justify-end">
                                <v-switch
                                    color="primary"
                                    v-model="settings.furiganaOnHighlightedWords"
                                    @change="saveSettings"
                                ></v-switch>
                            </v-col>
                        </v-row>

                        <!-- Furigana on new words -->
                        <v-row>
                            <v-col cols="8" md="4" class="switch-container d-flex align-center mt-0 mb-md-5">生词显示假名：</v-col>
                            <v-col cols="4" md="8" class="switch-container d-flex align-center mt-0 pt-3 justify-end">
                                <v-switch
                                    color="primary"
                                    v-model="settings.furiganaOnNewWords"
                                    @change="saveSettings"
                                ></v-switch>
                            </v-col>
                        </v-row>

                        <!-- Auto move words to known -->
                        <v-row>
                            <v-col cols="8" md="4" class="switch-container d-flex align-center mt-0 mb-md-5">自动把词标为已知：</v-col>
                            <v-col cols="4" md="8" class="switch-container d-flex align-center mt-0 pt-3 justify-end">
                                <v-menu offset-y left nudge-top="-12px">
                                    <template v-slot:activator="{ on, attrs }">
                                        <v-icon class="mr-2" v-bind="attrs" v-on="on">mdi-help-circle-outline</v-icon>
                                    </template>
                                    <v-card outlined class="rounded-lg pa-4" width="320px">
                                        点击<b>完成阅读</b>后，会把新词移动到已知词。
                                    </v-card>
                                </v-menu>

                                <v-switch
                                    color="primary"
                                    v-model="settings.autoMoveWordsToKnown"
                                    @change="saveSettings"
                                ></v-switch>
                            </v-col>
                        </v-row>

                        <!-- Auto highlight words -->
                        <v-row>
                            <v-col cols="8" md="4" class="switch-container d-flex align-center mt-0 mb-md-5">自动高亮词汇：</v-col>
                            <v-col cols="4" md="8" class="switch-container d-flex align-center mt-0 pt-3 justify-end">
                                <v-menu offset-y left nudge-top="-12px">
                                    <template v-slot:activator="{ on, attrs }">
                                        <v-icon class="mr-2" v-bind="attrs" v-on="on">mdi-help-circle-outline</v-icon>
                                    </template>
                                    <v-card outlined class="rounded-lg pa-4" width="320px">
                                        为单词添加翻译后自动高亮。
                                    </v-card>
                                </v-menu>

                                <v-switch
                                    color="primary"
                                    v-model="settings.autoHighlightWords"
                                    @change="saveSettings"
                                ></v-switch>
                            </v-col>
                        </v-row>

                        <!-- Auto level up words -->
                        <v-row>
                            <v-col cols="8" md="4" class="switch-container d-flex align-center mt-0 mb-md-5">自动提升词汇等级：</v-col>
                            <v-col cols="4" md="8" class="switch-container d-flex align-center mt-0 pt-3 justify-end">
                                <v-menu offset-y left nudge-top="-12px">
                                    <template v-slot:activator="{ on, attrs }">
                                        <v-icon class="mr-2" v-bind="attrs" v-on="on">mdi-help-circle-outline</v-icon>
                                    </template>
                                    <v-card outlined class="rounded-lg pa-4" width="320px">
                                        点击“完成阅读”后，会自动提升没有打开过词汇框的单词和短语。
                                    </v-card>
                                </v-menu>

                                <v-switch
                                    color="primary"
                                    v-model="settings.autoLevelUpWords"
                                    @change="saveSettings"
                                ></v-switch>
                            </v-col>
                        </v-row>

                        <!-- Show subtitle timestamps -->
                        <v-row>
                            <v-col cols="8" md="4" class="switch-container d-flex align-center mt-0 mb-md-5">显示字幕时间戳：</v-col>
                            <v-col cols="4" md="8" class="switch-container d-flex align-center mt-0 pt-3 justify-end">
                                <v-switch
                                    color="primary"
                                    v-model="settings.showSubtitleTimestamps"
                                    @change="saveSettings"
                                ></v-switch>
                            </v-col>
                        </v-row>

                        <!-- Space between subtitles -->
                        <v-row>
                            <v-col cols="12" sm="3" class="d-flex align-center mt-0 mt-md-0 mb-md-5 pb-0 pb-sm-0 pb-md-3">字幕间距：</v-col>
                            <v-col class="slider-container d-flex pt-xs-0 pt-sm-0 pt-md-3 align-center">
                                <v-slider
                                    v-model="settings.spaceBetweenSubtitles"
                                    :tick-labels="['小', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '大']"
                                    :tick-size="0"
                                    :min="0"
                                    :max="40"
                                    step="2"
                                    thumb-label="always"
                                    thumb-size="38"
                                    track-color="#c5c5c5"
                                    @change="saveSettings"
                                ></v-slider>
                            </v-col>
                        </v-row>

                        <!-- Text to speech section -->
                        <div class="subheader subheader-margin-top d-flex mb-2" v-if="textToSpeechVoices.length">
                            朗读
                        </div>

                        <!-- Text to speech -->
                        <v-row v-if="textToSpeechVoices.length">
                            <v-col cols="12" md="4" class="switch-container d-flex align-center mt-0 mb-md-5">朗读声音：</v-col>
                            <v-col cols="12" md="8" class="switch-container d-flex align-center mt-0 pt-3 justify-end">
                                <v-select
                                    v-model="textToSpeechSelectedVoice"
                                    :items="textToSpeechVoices"
                                    item-text="name"
                                    item-value="name"
                                    dense
                                    rounded
                                    filled
                                    hide-details
                                    @change="saveSettings"
                                ></v-select>
                            </v-col>
                        </v-row>

                        <!-- Text to speech speed -->
                        <v-row v-if="textToSpeechVoices.length">
                            <v-col cols="12" md="4" class="switch-container d-flex align-center mt-0 mb-md-5">朗读速度：</v-col>
                            <v-col cols="12" md="8" class="switch-container d-flex align-center mt-0 pt-3 justify-end">
                                <v-slider
                                    v-model="settings.textToSpeechSpeed"
                                    :tick-labels="['0.3', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '2']"
                                    :tick-size="0"
                                    :max="2"
                                    :min="0.3"
                                    thumb-label="always"
                                    thumb-size="38"
                                    step="0.1"
                                    track-color="#c5c5c5"
                                    class="align-center"
                                    @change="saveSettings"
                                />
                            </v-col>
                        </v-row>
                    </v-tab-item>

                    <!-- Vocabulary box section-->
                    <v-tab-item :value="1">
                        <!-- Vocab box scroll into view -->
                        <v-row>
                            <v-col cols="12" md="4" class="switch-container d-flex align-center mt-0 mb-md-5">词汇框滚动方式：</v-col>
                            <v-col cols="12" md="8" class="switch-container d-flex align-center mt-0 pt-3 justify-end">
                                <v-select
                                    v-model="settings.vocabBoxScrollIntoView"
                                    :items="vocabBoxScrollIntoViewData"
                                    item-text="name"
                                    item-value="value"
                                    dense
                                    rounded
                                    filled
                                    hide-details
                                    @change="saveSettings"
                                ></v-select>
                            </v-col>
                        </v-row>

                        <!-- Vocabulary sidebar -->
                        <v-row>
                            <v-col cols="8" md="4" class="switch-container d-flex align-center mt-0 mb-md-5 ">
                                词汇侧边栏：
                            </v-col>
                            <v-col cols="4" md="8" class="switch-container d-flex align-center mt-0 pt-3 justify-end">
                                <!-- Vocabulary sidebar info box -->
                                <v-menu offset-y left nudge-top="-12px">
                                    <template v-slot:activator="{ on, attrs }">
                                        <v-icon class="mr-2" v-bind="attrs" v-on="on">mdi-help-circle-outline</v-icon>
                                    </template>
                                    <v-card outlined class="rounded-lg pa-4" width="320px">
                                        固定显示在侧边的词汇框，会替代弹出的词汇框。<br><br>
                                        这个选项只在屏幕宽度至少 960px 的设备上可用；在字幕阅读器中，还需要隐藏媒体控制栏才可用。
                                    </v-card>
                                </v-menu>

                                <v-switch
                                    color="primary"
                                    v-model="settings.vocabularySidebar"
                                    @change="saveSettings"
                                ></v-switch>
                            </v-col>
                        </v-row>

                        <!-- Vocabulary bottom sheet -->
                        <v-row>
                            <v-col cols="8" md="4" class="switch-container d-flex align-center mt-0 mb-md-5 ">
                                底部词汇面板：
                            </v-col>
                            <v-col cols="4" md="8" class="switch-container d-flex align-center mt-0 pt-3 justify-end">
                                <!-- Vocabulary sidebar info box -->
                                <v-menu offset-y left nudge-top="-12px">
                                    <template v-slot:activator="{ on, attrs }">
                                        <v-icon class="mr-2" v-bind="attrs" v-on="on">mdi-help-circle-outline</v-icon>
                                    </template>
                                    <v-card outlined class="rounded-lg pa-4" width="320px">
                                        面向手机屏幕的底部词汇面板，会替代弹出的词汇框。<br><br>
                                        这个选项只在屏幕宽度小于或等于 768px 的设备上可用。
                                    </v-card>
                                </v-menu>

                                <v-switch
                                    color="primary"
                                    v-model="settings.vocabularyBottomSheet"
                                    @change="saveSettings"
                                ></v-switch>
                            </v-col>
                        </v-row>
                    </v-tab-item>

                    <!-- Vocabulary hover box section-->
                    <v-tab-item :value="2">
                        <!-- Vocabulary hover box -->
                        <v-row>
                            <v-col cols="8" md="4" class="switch-container d-flex align-center mt-0 mb-md-5">悬浮词汇框：</v-col>
                            <v-col cols="4" md="8" class="switch-container d-flex align-center mt-0 pt-3 justify-end">
                                <v-menu offset-y left nudge-top="-12px">
                                    <template v-slot:activator="{ on, attrs }">
                                        <v-icon class="mr-2" v-bind="attrs" v-on="on">mdi-help-circle-outline</v-icon>
                                    </template>
                                    <v-card outlined class="rounded-lg pa-4" width="320px">
                                        鼠标移到单词或短语上时显示的简洁词汇框。
                                    </v-card>
                                </v-menu>

                                <v-switch
                                    color="primary"
                                    v-model="settings.vocabularyHoverBox"
                                    @change="saveSettings"
                                ></v-switch>
                            </v-col>
                        </v-row>

                        <!-- Hover vocabulary box dictionary search -->
                        <v-row>
                            <v-col cols="8" md="4" class="switch-container d-flex align-center mt-0 mb-md-5">悬浮词汇框词典搜索：</v-col>
                            <v-col cols="4" md="8" class="switch-container d-flex align-center mt-0 pt-3 justify-end">
                                <v-switch
                                    v-model="settings.vocabularyHoverBoxSearch"
                                    color="primary"
                                    @change="saveSettings"
                                ></v-switch>
                            </v-col>
                        </v-row>

                        <!-- Hover vocabulary delay -->
                        <v-row>
                            <v-col cols="12" sm="3" class="d-flex align-center mt-0 mt-md-0 mb-md-5 pb-0 pb-sm-0 pb-md-3">悬浮延迟：</v-col>
                            <v-col class="slider-container d-flex pt-xs-0 pt-sm-0 pt-md-3 align-center">
                                <v-slider
                                    v-model="settings.vocabularyHoverBoxDelay"
                                    :tick-labels="['200ms', '', '', '', '', '', '', '', '1000ms']"
                                    :tick-size="0"
                                    :min="200"
                                    :max="1000"
                                    thumb-label="always"
                                    thumb-size="38"
                                    step="100"
                                    track-color="#c5c5c5"
                                    @change="saveSettings"
                                >
                                </v-slider>
                            </v-col>
                        </v-row>

                        <!-- Hover vocabulary preferred position -->
                        <v-row>
                            <v-col cols="12" md="4" class="switch-container d-flex align-center mt-0 mb-md-5">优先显示位置：</v-col>
                            <v-col cols="12" md="8" class="switch-container d-flex align-center mt-0 pt-3 justify-end">
                                <v-select
                                    v-model="settings.vocabularyHoverBoxPreferredPosition"
                                    :items="vocabularyHoverBoxPreferredPositionData"
                                    item-text="name"
                                    item-value="value"
                                    dense
                                    rounded
                                    filled
                                    hide-details
                                    @change="saveSettings"
                                ></v-select>
                            </v-col>
                        </v-row>
                    </v-tab-item>
                </v-tabs-items>

            </v-card-text>

            <v-card-actions>
                <v-spacer></v-spacer>
                <v-btn rounded color="primary" @click="close">关闭</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
    import TextToSpeechService from './../../services/TextToSpeechService';
    import FontTypeService from './../../services/FontTypeService';
    import {  defaultSettings, DefaultLocalStorageManager } from './../../services/LocalStorageManagerService';

    export default {
        emits: ['input'],
        data: function() {
            return {
                /*
                    Text to speech and font type settings are handled differently,
                    because they are a separate setting for every language.
                */
            fontTypeService: new FontTypeService(this.$props.language, this.fontTypesLoaded),
            fontTypes: [],
            selectedFontType: null,
            textToSpeechService: new TextToSpeechService(this.$props.language, this.textToSpeechVoicesChanged),
            textToSpeechVoices: [],
            textToSpeechSelectedVoice: null,

            tab: 0,
            settingsLoaded: false,
            settings: { ...defaultSettings },
            vocabularyHoverBoxPreferredPositionData: [
                {
                    name: '显示在词下方',
                    value: 'bottom'
                },
                {
                    name: '显示在词上方',
                    value: 'top'
                },
            ],
            vocabBoxScrollIntoViewData: [
                {
                    name: '关闭',
                    value: 'disabled'
                },
                {
                    name: '滚动到可见位置',
                    value: 'scroll-into-view'
                },
                {
                    name: '需要时滚动到可见位置（并非所有浏览器都支持）',
                    value: 'scroll-into-view-if-needed'
                }
            ],
            maximumTextWidthData: ['800px', '900px', '1000px', '1200px', '1400px', '1600px', '100%'],
            }
        },
        props: {
            value : Boolean,
            language: String,
        },
        mounted() {
            this.settings = DefaultLocalStorageManager.loadAndParseSettings(this.settings);
            this.settingsLoaded = true;
            this.saveSettings();

            this.textToSpeechVoicesChanged();
        },
        methods: {
            fontTypesLoaded() {
                // set selected font
                this.selectedFontType = this.fontTypeService.getSelectedFontTypeId();

                // set font list
                this.fontTypes = this.fontTypeService.fonts;
            },
            textToSpeechVoicesChanged() {
                // set selected voice
                var selectedVoice = this.textToSpeechService.getSelectedVoice();
                if (selectedVoice !== null) {
                    this.textToSpeechSelectedVoice = selectedVoice.name;
                }

                // get list of voice
                this.textToSpeechVoices = this.textToSpeechService.getVoiceNames();
            },
            saveSettings(settingName = '') {
                if (settingName == 'hideAllHighlights') {
                    this.settings.hideNewWordHighlights = this.settings.hideAllHighlights;
                }

                if (this.settings.fontSize < 12) {
                    this.settings.fontSize = 12;
                }

                if (this.settings.fontSize > 30) {
                    this.settings.fontSize = 30;
                }

                DefaultLocalStorageManager.saveSettings(this.settings);

                // save text to speech
                if (this.textToSpeechSelectedVoice !== null) {
                    localStorage.setItem(`${this.$props.language}-text-to-speech-voice`, JSON.stringify(this.textToSpeechSelectedVoice));
                }

                // save font
                if (this.fontTypeService !== null && this.selectedFontType) {
                    this.fontTypeService.selectFontType(this.selectedFontType);
                    this.fontTypeService.loadSelectedFontTypeIntoDom(this.selectedFontType);
                }

                this.$emit('changed', this.settings);
                this.$forceUpdate();
            },
            saveSetting(name) {
                DefaultLocalStorageManager.saveSetting(name, this.settings[name]);
            },
            changeSetting(name, value, emitResult = false) {
                this.settings[name] = value

                if (this.settings.fontSize < 12) {
                    this.settings.fontSize = 12;
                }

                if (this.settings.fontSize > 30) {
                    this.settings.fontSize = 30;
                }

                ;
                this.saveSetting(name);

                if (emitResult) {
                    this.$emit('changed', this.settings);
                }
            },
            close(){
                this.$emit('input', false);
            }
        }
    }
</script>
