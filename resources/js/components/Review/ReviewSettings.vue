<template>
    <v-dialog v-model="value" scrollable persistent max-width="1000">
        <v-card
            id="review-settings"
            outlined
            class="rounded-lg"
        >
            <v-card-title>
                <span class="text-h5">复习设置</span>
                <v-spacer></v-spacer>
                <v-btn icon @click="close">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </v-card-title>
            <v-card-text class="pt-6 pb-12" v-if="settingsLoaded">
                <!-- Text section-->
                <div class="subheader d-flex mb-2">
                    文本
                </div>

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

                <!-- Sentence mode -->
                <v-row>
                    <v-col cols="4" md="4" class="switch-container d-flex align-center mt-0 mb-md-5">句子模式：</v-col>
                    <v-col cols="8" md="8" class="switch-container d-flex align-center mt-0 pt-3 justify-end">
                        <v-select
                            v-model="settings.reviewSentenceMode"
                            :items="sentenceModes"
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

                <!-- Vocabulary box section-->
                <div class="subheader subheader-margin-top d-flex mb-2">
                    词汇框
                </div>

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

                <!-- Vocabulary hover box section-->
                <div class="subheader subheader-margin-top d-flex mb-2">
                    悬浮词汇框
                </div>

                <!-- Vocabulary hover box -->
                <v-alert
                    v-if="settings.reviewSentenceMode !== 'interactive-text'"
                    type="error"
                    color="warning"
                >
                    只有把“句子模式”设置为“互动文本”后，悬浮词汇框才会生效。
                </v-alert>

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

                <!-- Vocabulary hover box dictionary search -->
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

                <!-- Text to speech section -->
                <div class="subheader subheader-margin-top d-flex mb-2">
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
    import { defaultSettings, DefaultLocalStorageManager } from './../../services/LocalStorageManagerService';

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
            sentenceModes: [
                {
                    name: '关闭',
                    value: 'disabled',
                },
                {
                    name: '纯文本',
                    value: 'plain-text',
                },
                {
                    name: '互动文本',
                    value: 'interactive-text',
                },
            ],
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
