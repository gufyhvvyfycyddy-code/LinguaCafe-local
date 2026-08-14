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
            saveSettings() {
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
            close(){
                this.$emit('input', false);
            }
        }
    }
</script>
