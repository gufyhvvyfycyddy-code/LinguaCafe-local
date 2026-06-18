<template>
    <v-dialog v-model="value" scrollable persistent width="1000px" @keydown.enter.prevent="enterPressed">
        <v-card id="import-dialog" class="rounded-lg" :loading="importLoading">
            <!-- Card title -->
            <v-card-title>
                <v-icon class="mr-2">mdi-file-import</v-icon>导入阅读材料

                <v-spacer />
                <v-btn icon @click="close">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </v-card-title>
            
            <!-- Import form -->
            <v-card-text>
                <v-stepper id="import-stepper" v-model="stepperPage" elevation="0" class="pb-0" min-height="70vh">
                    <v-stepper-header>
                        <v-stepper-step :complete="stepperPage > 1" step="1" :color="stepperPage > 1 ? 'success' : 'primary'">
                            类型
                            <small>导入类型</small>
                        </v-stepper-step>
                        <v-divider/>

                        <v-stepper-step :complete="stepperPage > 2" step="2" :color="stepperPage > 2 ? 'success' : 'primary'">
                            来源
                            <small>导入来源</small>
                        </v-stepper-step>
                        <v-divider/>

                        <v-stepper-step :complete="stepperPage > 3" step="3" :color="stepperPage > 3 ? 'success' : 'primary'">
                            位置
                            <small>阅读材料位置</small>
                        </v-stepper-step>
                        <v-divider/>
                        
                        <v-stepper-step :complete="stepperPage > 4" step="4" :color="stepperPage > 4 ? 'success' : 'primary'">
                            设置
                            <small>文本处理设置</small>
                        </v-stepper-step>
                        <v-divider/>

                        <v-stepper-step 
                            :complete="stepperPage > 4 && importResult === 'success'" 
                            step="5" 
                            :color="stepperPage > 4 && importResult !== '' ? importResult : 'primary'"
                        >
                            完成
                            <small>导入结果</small>
                        </v-stepper-step>

                    </v-stepper-header>

                    <!-- Stepper items -->
                    <v-stepper-items>
                        <!-- Import type selection -->
                        <v-stepper-content step="1">
                            <import-type-selection 
                                :language="$props.language"
                                @import-type-selected="selectImportType"
                            ></import-type-selection>
                        </v-stepper-content>

                        <!-- Import source selection -->
                        <v-stepper-content :class="{
                            'plain-text-source': importType == 'plain-text',
                            'youtube-subtitle-source': importType == 'youtube',
                            'jellyfin-subtitle-source': importType == 'jellyfin-subtitle'
                        }" step="2">
                            <!-- Plain text -->
                            <import-plain-text-source
                                v-if="stepperPage == 2 && importType == 'plain-text'"
                                @text-selected="selectImportText" 
                            ></import-plain-text-source>

                            <!-- Text file -->
                            <import-text-file-source
                                v-if="stepperPage == 2 && importType == 'text-file'"
                                @text-selected="selectImportText" 
                            ></import-text-file-source>

                            <!-- Subtitle file -->
                            <import-subtitle-file-source
                                v-if="stepperPage == 2 && importType == 'subtitle-file'"
                                @subtitle-selected="selectImportSubtitle"
                            ></import-subtitle-file-source>

                            <!-- E-book -->
                            <import-ebook-file-source
                                v-if="stepperPage == 2 && importType == 'e-book'"
                                @file-selected="selectImportFile" 
                            ></import-ebook-file-source>

                            <!-- Youtube -->
                            <import-youtube-subtitle-source
                                v-if="stepperPage == 2 && importType == 'youtube'"
                                :language="$props.language"
                                @text-selected="selectImportText" 
                            ></import-youtube-subtitle-source>

                            <!-- Jellyfin subtitle -->
                            <import-jellyfin-subtitle-source
                                v-if="stepperPage == 2 && importType == 'jellyfin-subtitle'"
                                :language="$props.language"
                                @subtitle-selected="selectImportSubtitle" 
                            ></import-jellyfin-subtitle-source>

                            <!-- Website -->
                            <import-website-source
                                v-if="stepperPage == 2 && importType == 'website'"
                                :language="$props.language"
                                @text-selected="selectImportText"
                            ></import-website-source>
                        </v-stepper-content>

                        <!-- Library import settings -->
                        <v-stepper-content step="3">
                            <import-library-options
                                v-if="stepperPage > 2"
                                @input-changed="libraryInputChanged"
                            ></import-library-options>
                        </v-stepper-content>

                        <!-- Library import settings -->
                        <v-stepper-content step="4">
                            <import-options
                                v-if="stepperPage > 3"
                                :language="$props.language"
                                :type="importType"
                                @import-options-changed="importOptionsChanged"
                            ></import-options>
                        </v-stepper-content>
                        <v-stepper-content step="5">
                            <!-- Importing info -->
                            <v-alert dark border="left" type="info" color="primary" v-if="importResult === ''">
                                正在导入你选择的文本，请稍等。耗时取决于：
                                <ul>
                                    <li>文本长度。</li>
                                    <li>新词数量。</li>
                                    <li>需要索引的已保存短语数量。</li>
                                    <li>本机文本处理服务速度。</li>
                                </ul>
                            </v-alert>

                            <!-- Error message -->
                            <v-alert dark border="left" type="error" color="error" v-if="importResult === 'error'">
                                {{ importError || '导入文本时发生错误。' }}
                            </v-alert>

                            <!-- Success message -->
                            <v-alert dark border="left" type="success" color="success" v-if="importResult === 'success'">
                                阅读材料和章节已创建成功。章节处理完成后即可阅读。
                            </v-alert>

                            <v-alert dark border="left" type="warning" color="warning" v-if="importResult === 'success' && importWarning">
                                {{ importWarning }}
                            </v-alert>
                        </v-stepper-content>

                        
                    </v-stepper-items>
                </v-stepper>

            </v-card-text>

            <!-- Action buttons -->
            <v-card-actions>
                <v-spacer></v-spacer>
                <v-btn 
                    v-if="importResult === 'success'"
                    rounded 
                    text 
                    @click="close"
                >关闭</v-btn>

                <v-btn 
                    rounded 
                    text 
                    @click="close" 
                    v-if="stepperPage == 1"
                >取消</v-btn>

                <v-btn 
                    v-if="stepperPage > 1 && importResult !== 'success'"
                    rounded 
                    text 
                    :disabled="stepperPage == 5 && importResult === ''"
                    @click="stepBack"
                >上一步</v-btn>

                <v-btn 
                    v-if="stepperPage < 4"
                    ref="continueButton"
                    rounded 
                    depressed
                    color="primary" 
                    :disabled="
                        (stepperPage == 1) ||
                        (stepperPage == 2 && importType === 'e-book' && (!isImportSourceValid || importFile === null)) ||
                        (stepperPage == 2 && importType === 'plain-text' && (!isImportSourceValid || importText === '')) ||
                        (stepperPage == 2 && importType === 'text-file' && (!isImportSourceValid || importText === '')) ||
                        (stepperPage == 2 && importType === 'subtitle-file' && !isImportSourceValid) ||
                        (stepperPage == 2 && importType === 'youtube' && (!isImportSourceValid || importText === '')) ||
                        (stepperPage == 2 && importType === 'jellyfin-subtitle' && !isImportSourceValid) ||
                        (stepperPage == 2 && importType === 'website' && (!isImportSourceValid || importText === '')) ||
                        (stepperPage == 3 && !isLibraryValid)
                    "
                    @click="stepForward"
                >
                    继续
                </v-btn>
                <v-btn 
                    v-if="stepperPage > 3 && importResult !== 'success'"
                    ref="importButton"
                    rounded 
                    depressed
                    color="primary"
                    :loading="importLoading"
                    :disabled="stepperPage == 5 && importResult === '' || !isImportOptionsValid"
                    @click="finishImport"
                >
                    导入
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
    import { requestErrorMessage } from './../../../services/UiTextService';

    export default {
        data: function() {
            return {
                importLoading: false,
                importResult: '',
                importError: '',
                importWarning: '',
                stepperPage: 1  ,
                isImportSourceValid: false,
                isImportOptionsValid: false,
                isLibraryValid: false,
                textProcessingMethod: 'detailed',
                eBookChapterSortMethod: 'default',
                maximumCharactersPerChapter: 200,
                importType: '',

                // these come from the source step item
                importFile: null,
                importText: '',
                importSubtitles: [],
                
                newOrExistingBook: '',
                bookId: -1,
                bookName: '',
                chapterName: '',
            }
        },
        props: {
            value : Boolean,
            language: String,
        },
        emits: ['input'],
        mounted() {
        },
        methods: {
            enterPressed() {
                if (this.$refs.continueButton !== undefined && !this.$refs.continueButton.disabled) {
                    this.stepForward();
                }

                if (this.$refs.importButton !== undefined && !this.$refs.importButton.disabled) {
                    this.finishImport();
                }
            },
            selectImportType(type) {
                this.stepperPage = 2;
                this.importType = type;
            },
            selectImportFile(data) {
                this.importFile = data.importFile;
                this.isImportSourceValid = data.isImportSourceValid;
            },
            selectImportText(data) {
                this.importText = data.text;
                this.isImportSourceValid = data.isImportSourceValid;
            },
            selectImportSubtitle(data) {
                this.importSubtitles = data.subtitles;
                this.isImportSourceValid = data.isImportSourceValid;
            },
            libraryInputChanged(data) {
                this.isLibraryValid = data.isFormValid;
                this.newOrExistingBook = data.newOrExistingBook;
                this.chapterName = data.chapterName;

                if(data.newOrExistingBook == 'new') {
                    this.bookId = -1;
                    this.bookName = data.bookName;
                } else {
                    this.bookId = data.bookId;
                    this.bookName = '';
                }
                
            },
            importOptionsChanged(newOptions) {
                this.textProcessingMethod = newOptions.textProcessingMethod;
                this.isImportOptionsValid = newOptions.isValid;
                this.maximumCharactersPerChapter = newOptions.maximumCharactersPerChapter;
                this.eBookChapterSortMethod = newOptions.eBookChapterSortMethod;
            },
            stepForward() {
                this.stepperPage++;
            },
            stepBack() {
                this.stepperPage--;

                if(this.stepperPage < 2) {
                    this.importType = '';
                }

                if(this.stepperPage < 3) {
                    this.importFile = null;
                    this.importSubtitles = null;
                    this.importText = '';
                    this.isImportSourceValid = false;
                }

                if(this.stepperPage < 3) {
                    this.bookId = -1;
                    this.bookName = '';
                    this.newOrExistingBook = '';
                    this.isLibraryValid = false;
                }

                if(this.stepperPage < 4) {
                    this.textProcessingMethod = 'detailed';
                }
            },
            finishImport() {
                var data = new FormData();
                data.set('importType', this.importType);
                
                if (this.importType === 'e-book') {
                    data.set('importFile', this.importFile);
                } else if (['youtube', 'plain-text', 'text-file', 'website'].includes(this.importType)) {
                    data.set('importText', this.importText);
                } if (['jellyfin-subtitle', 'subtitle-file'].includes(this.importType)) {
                    data.set('importSubtitles', JSON.stringify(this.importSubtitles));
                }

                data.set('textProcessingMethod', this.textProcessingMethod);
                data.set('eBookChapterSortMethod', this.eBookChapterSortMethod);
                data.set('bookId', this.bookId);
                data.set('bookName', this.bookName);
                data.set('chapterName', this.chapterName);
                data.set('maximumCharactersPerChapter', this.maximumCharactersPerChapter);

                this.importLoading = true;
                this.stepperPage = 5;
                this.importResult = '';
                this.importError = '';
                this.importWarning = '';

                axios.post('/import', data).then((response) => {
                    if (response.status == 200) {
                        this.importResult = 'success';
                        if (response.data && response.data.processing_mode === 'fallback') {
                            this.importWarning = '已使用基础英文分词导入。高级词形分析需要 Python tokenizer 服务。';
                        }
                        this.$emit('import-finished', false);
                    } else {
                        this.importResult = 'error';
                        this.importError = '导入请求没有成功完成。';
                    }
                }).catch((error) => {
                    this.importResult = 'error';
                    this.importError = requestErrorMessage(error, '文本处理服务未启动。请先启动 Python tokenizer，或使用基础英文分词 fallback。');
                }).finally(() => {
                    this.importLoading = false;
                });
            },
            close() {
                this.$emit('input', false);
            }
        }
    }
</script>
