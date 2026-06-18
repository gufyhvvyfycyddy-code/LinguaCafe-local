<template>
    <div class="d-flex flex-column align-stretch">
        <!-- Subtitle file -->
        <label class="font-weight-bold">字幕文件</label>
        <v-file-input
            v-model="subtitleFile"
            filled
            dense
            rounded
            persistent-hint
            hint="支持格式：.srt .ass"
            ref="subtitleFile"
            accept=".srt,.ass"
            placeholder="字幕文件"
            prepend-icon="mdi-book"
            class="mb-4"
            :rules="[rules.subtitleFileRule]"
            @change="subtitleFileSelected"
        ></v-file-input>

        <!-- Subtitle content loading -->
        <div class="d-flex justify-center">
            <v-progress-circular
                v-if="loading"
                indeterminate
                color="primary"
            ></v-progress-circular>
        </div>

        <!-- Error -->
        <v-alert
            v-if="!loading && error"
            border="left"
            type="error"
        >
            {{ errorMessage || '读取字幕文件失败，请重试。' }}
        </v-alert>
    </div>
</template>

<script>
import axios from 'axios';
import { requestErrorMessage } from './../../../../services/UiTextService';

    export default {
        data: function() {
            return {
                subtitles: null,
                subtitleFile: null,
                isFormValid: false,
                loading: false,
                error: false,
                errorMessage: '',
                rules: {
                    subtitleFileRule: (value) => {
                        if (value === null || value === undefined) {
                            return '请选择文件。';
                        }
                        
                        let extension = value.name.split('.');
                        extension = extension[extension.length - 1];
                        if (extension !== 'srt' && extension !== 'ass') {
                            return '请选择 .srt 或 .ass 文件。';
                        }

                        return true;
                    }
                }
            }
        },
        props: {
            language: String,
        },
        mounted() {
        },
        methods: {
            subtitleFileSelected() {
                // validate
                this.subtitles = null;
                this.error = false;
                this.errorMessage = '';
                
                if (!this.$refs.subtitleFile.validate()) {
                    // disable continue button in import dialog
                    this.$emit('subtitle-selected', {
                        subtitles: null,
                        isImportSourceValid: false
                    });

                    return;
                }

                this.loading = true;
                var formData = new FormData();
                formData.set('subtitleFile', this.subtitleFile);

                axios.post('/subtitle/get-subtitle-file-content', formData).then((response) => {
                    this.subtitles = response.data;
                    this.loading = false;

                    this.$emit('subtitle-selected', {
                        subtitles: this.subtitles,
                        isImportSourceValid: true
                    });
                }).catch((error) => {
                    this.subtitles = null;
                    this.error = true;
                    this.errorMessage = requestErrorMessage(error, '读取字幕文件失败，请重试。');
                    this.loading = false;

                    this.$emit('subtitle-selected', {
                        subtitles: this.subtitles,
                        isImportSourceValid: false
                    });
                });

            }
        }
    }
</script>
