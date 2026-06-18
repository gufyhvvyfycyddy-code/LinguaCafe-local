<template>
    <div v-if="!loading">
        <v-alert v-if="error" dense outlined type="error">{{ error }}</v-alert>
        <label class="font-weight-bold">选择导入方式</label>
        <div class="import-type-group flex-wrap">
            <!--Plain text -->
            <div class="import-type-button rounded-lg mx-2 mb-4" @click="selectImportType('plain-text')">
                <div class="import-type-button-icon-box">
                    <v-icon large>mdi-text-box</v-icon>
                </div>
                <span>粘贴文本</span>
            </div>
            
            <!-- Text file -->
            <div class="import-type-button rounded-lg mx-2 mb-4" @click="selectImportType('text-file')">
                <div class="import-type-button-icon-box">
                    <v-icon large>mdi-file-document</v-icon>
                </div>
                <span>文本文件</span>
            </div>

            <!-- E-book -->
            <div class="import-type-button rounded-lg mx-2 mb-4" @click="selectImportType('e-book')">
                <div class="import-type-button-icon-box">
                    <v-icon large>mdi-book</v-icon>
                </div>
                <span>电子书</span>
            </div>
        
            <!-- Youtube -->
            <div class="import-type-button rounded-lg mx-2 mb-4" @click="selectImportType('youtube')">
                <div class="import-type-button-icon-box">
                    <v-icon large>mdi-youtube</v-icon>
                </div>
                <span>YouTube 字幕</span>
            </div>

            <!-- Jellyfin subtitle -->
            <div class="import-type-button rounded-lg mx-2 mb-4" @click="selectImportType('jellyfin-subtitle')" v-if="jellyfinEnabled">
                <div class="import-type-button-icon-box">
                    <v-icon large>mdi-movie</v-icon>
                </div>
                <span>Jellyfin 字幕</span>
            </div>

            <!-- Subtitle file -->
            <div class="import-type-button rounded-lg mx-2 mb-4" @click="selectImportType('subtitle-file')">
                <div class="import-type-button-icon-box">
                    <v-icon large>mdi-subtitles</v-icon>
                </div>
                <span>字幕文件</span>
            </div>

            <!-- Website -->
            <div class="import-type-button rounded-lg mx-2 mb-4" @click="selectImportType('website')" v-if="websiteImportSupported">
                <div class="import-type-button-icon-box">
                    <v-icon large>mdi-web</v-icon>
                </div>
                <span>网页</span>
            </div>

            <!--
            <div class="import-type-button rounded-lg mx-2 mb-4" @click="selectImportType('rss')">
                <div class="import-type-button-icon-box">
                    <v-icon large>mdi-rss-box</v-icon>
                </div>
                <span>RSS feed</span>
            </div>
            
            <div class="import-type-button rounded-lg mx-2 mb-4">
                <div class="import-type-button-icon-box">
                    <v-icon large>mdi-file-document</v-icon>
                </div>
                <span>PDF</span>
            </div>

            <div class="import-type-button rounded-lg mx-2 mb-4">
                <div class="import-type-button-icon-box">
                    <v-icon large>mdi-play-circle</v-icon>
                </div>
                <span>Mpv player</span>
            </div>

            <div class="import-type-button rounded-lg mx-2 mb-4">
                <div class="import-type-button-icon-box">
                    <v-icon large>mdi-chat-processing</v-icon>
                </div>
                <span>Manga</span>
            </div>
            -->
        </div>
    </div>
    <div class="h-100 d-flex justify-center" v-else>
        <v-progress-linear
            indeterminate
            color="primary"
            height="4"
            rounded
        ></v-progress-linear>
    </div>
</template>

<script>
    import { requestErrorMessage } from './../../../services/UiTextService';

    export default {
        data: function() {
            return {
                loading: true,
                error: '',
                websiteImportSupported: false,
                jellyfinEnabled: false,
            }
        },
        props: {
            language: String
        },
        mounted() {
            this.loading = true;
            this.error = '';
            axios.all([
                axios.get('/config/get/linguacafe.languages.website_import_supported_languages'),
                axios.get('/settings/is-jellyfin-enabled'),
            ]).then(axios.spread((response1, response2) => {
                this.websiteImportSupported = response1.data.includes(this.$props.language);
                this.jellyfinEnabled = response2.data;
            })).catch((error) => {
                this.error = requestErrorMessage(error, '导入方式加载失败。已隐藏需要额外服务的导入方式。');
                this.websiteImportSupported = false;
                this.jellyfinEnabled = false;
            }).finally(() => {
                this.loading = false;
            });
        },
        methods: {
            selectImportType(type) {
                this.$emit('import-type-selected', type);
            }
        }
    }
</script>
