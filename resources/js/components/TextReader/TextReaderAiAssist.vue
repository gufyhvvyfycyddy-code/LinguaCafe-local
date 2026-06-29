<template>
    <v-dialog v-model="visible" max-width="720" scrollable>
        <v-card>
            <v-card-title>
                <v-icon left class="mr-2">mdi-robot</v-icon>
                AI 阅读辅助
            </v-card-title>

            <v-card-text>
                <v-alert
                    v-if="error"
                    type="error"
                    dense
                    outlined
                    class="mb-3"
                >{{ error }}</v-alert>

                <!-- Step 1: Copy prompt -->
                <div class="mb-4">
                    <div class="text-subtitle-1 font-weight-medium mb-2">
                        步骤 1：复制全文 + AI 分析提示词
                    </div>
                    <v-btn
                        depressed
                        color="primary"
                        :loading="sourceLoading"
                        :disabled="previewMode"
                        @click="loadSource"
                    >
                        <v-icon left>mdi-content-copy</v-icon>
                        复制全文 + AI 提示词
                    </v-btn>
                    <div v-if="sourceCopied" class="caption green--text mt-1">
                        <v-icon small color="success">mdi-check-circle</v-icon>
                        已复制本章英文和 AI 分析提示词。你可以发送给 DeepSeek Flash 或 DeepSeek Pro。
                    </div>
                </div>

                <v-divider class="mb-4"></v-divider>

                <!-- Step 2: Paste AI return -->
                <div class="mb-3">
                    <div class="text-subtitle-1 font-weight-medium mb-2">
                        步骤 2：粘贴 AI 返回内容
                    </div>
                    <v-textarea
                        v-model="aiText"
                        label="把 AI 返回的 JSON 粘贴到这里"
                        rows="6"
                        outlined
                        dense
                        hide-details="auto"
                        :disabled="previewLoading"
                        placeholder="将 DeepSeek 或 GPT 返回的内容粘贴到这里..."
                        class="mb-2"
                    ></v-textarea>
                    <v-btn
                        depressed
                        color="secondary"
                        :loading="previewLoading"
                        :disabled="!aiText.trim()"
                        @click="parsePreview"
                    >
                        <v-icon left>mdi-magnify-scan</v-icon>
                        解析预览
                    </v-btn>
                </div>

                <v-divider class="mb-4"></v-divider>

                <!-- Preview results -->
                <div v-if="previewResult">
                    <div class="text-subtitle-1 font-weight-medium mb-2">解析结果</div>

                    <!-- Summary chips -->
                    <div class="d-flex flex-wrap mb-3" style="gap: 8px;">
                        <v-chip small color="primary" outlined>
                            句子译文 {{ previewResult.summary.sentence_translation_count }} 条
                        </v-chip>
                        <v-chip small color="success" outlined>
                            生词释义 {{ previewResult.summary.vocabulary_item_count }} 条
                        </v-chip>
                        <v-chip small color="warning" outlined>
                            词组释义 {{ previewResult.summary.phrase_item_count }} 条
                        </v-chip>
                        <v-chip small color="grey" outlined>
                            警告 {{ previewResult.summary.warning_count }} 条
                        </v-chip>
                    </div>

                    <v-alert
                        dense
                        outlined
                        type="info"
                        text
                        class="mb-3"
                    >
                        <v-icon small left>mdi-information-outline</v-icon>
                        当前只是预览，不会写入学习数据。
                    </v-alert>

                    <!-- Sentence translation samples -->
                    <div v-if="previewResult.samples.sentence_translations.length" class="mb-3">
                        <div class="text-caption font-weight-medium mb-1">句子译文样例：</div>
                        <v-sheet
                            v-for="(st, i) in previewResult.samples.sentence_translations"
                            :key="'st-' + i"
                            outlined
                            rounded
                            class="pa-2 mb-1"
                        >
                            <div class="body-2">{{ st.source_text }}</div>
                            <div class="caption grey--text">{{ st.translation_zh }}</div>
                        </v-sheet>
                    </div>

                    <!-- Vocabulary samples -->
                    <div v-if="previewResult.samples.vocabulary_items.length" class="mb-3">
                        <div class="text-caption font-weight-medium mb-1">生词释义样例：</div>
                        <v-sheet
                            v-for="(vi, i) in previewResult.samples.vocabulary_items"
                            :key="'vi-' + i"
                            outlined
                            rounded
                            class="pa-2 mb-1"
                        >
                            <div class="body-2">
                                <strong>{{ vi.surface }}</strong>
                                <span v-if="vi.suggested_lemma" class="caption grey--text"> ({{ vi.suggested_lemma }})</span>
                                <span v-if="vi.pos" class="caption grey--text"> / {{ vi.pos }}</span>
                            </div>
                            <div class="caption">{{ vi.meaning_zh }}</div>
                        </v-sheet>
                    </div>

                    <!-- Phrase samples -->
                    <div v-if="previewResult.samples.phrase_items.length" class="mb-3">
                        <div class="text-caption font-weight-medium mb-1">词组释义样例：</div>
                        <v-sheet
                            v-for="(pi, i) in previewResult.samples.phrase_items"
                            :key="'pi-' + i"
                            outlined
                            rounded
                            class="pa-2 mb-1"
                        >
                            <div class="body-2"><strong>{{ pi.phrase }}</strong></div>
                            <div class="caption">{{ pi.meaning_zh }}</div>
                        </v-sheet>
                    </div>
                </div>

                <!-- Errors detail -->
                <div v-if="previewError" class="mt-2">
                    <div class="text-caption font-weight-medium mb-1">错误详情：</div>
                    <v-sheet
                        v-for="(err, i) in previewErrorList"
                        :key="'err-' + i"
                        outlined
                        rounded
                        class="pa-2 mb-1 error--text"
                    >
                        <div class="caption">{{ err.field }}: {{ err.message }}</div>
                    </v-sheet>
                </div>
            </v-card-text>

            <v-card-actions>
                <v-spacer />
                <v-btn text @click="close">关闭</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
    export default {
        props: {
            value: Boolean,
            chapterId: [Number, String],
        },
        data: function() {
            return {
                sourceLoading: false,
                sourceCopied: false,
                previewLoading: false,
                aiText: '',
                previewResult: null,
                previewError: '',
                previewErrorList: [],
                error: '',
            }
        },
        computed: {
            visible: {
                get() { return this.value; },
                set(v) { this.$emit('input', v); },
            },
        },
        watch: {
            value(newVal) {
                if (newVal) {
                    this.reset();
                }
            },
        },
        methods: {
            reset() {
                this.sourceLoading = false;
                this.sourceCopied = false;
                this.previewLoading = false;
                this.aiText = '';
                this.previewResult = null;
                this.previewError = '';
                this.previewErrorList = [];
                this.error = '';
            },
            loadSource() {
                if (!this.chapterId) {
                    this.error = '未选择章节。';
                    return;
                }

                this.sourceLoading = true;
                this.error = '';
                this.sourceCopied = false;

                axios.post('/chapters/ai-assist/source', {
                    chapterId: this.chapterId,
                }).then((response) => {
                    const data = response.data;
                    const prompt = data.prompt || '';
                    navigator.clipboard.writeText(prompt).then(() => {
                        this.sourceCopied = true;
                    }).catch(() => {
                        // Fallback: select and copy from a temp textarea
                        const textarea = document.createElement('textarea');
                        textarea.value = prompt;
                        textarea.style.position = 'fixed';
                        textarea.style.opacity = '0';
                        document.body.appendChild(textarea);
                        textarea.select();
                        document.execCommand('copy');
                        document.body.removeChild(textarea);
                        this.sourceCopied = true;
                    });
                }).catch((error) => {
                    this.error = error.response?.data?.message || '加载失败。';
                }).finally(() => {
                    this.sourceLoading = false;
                });
            },
            parsePreview() {
                if (!this.aiText.trim()) {
                    this.error = '请先粘贴 AI 返回内容。';
                    return;
                }

                if (!this.chapterId) {
                    this.error = '未选择章节。';
                    return;
                }

                this.previewLoading = true;
                this.error = '';
                this.previewResult = null;
                this.previewError = '';
                this.previewErrorList = [];

                axios.post('/chapters/ai-assist/preview', {
                    chapterId: this.chapterId,
                    aiText: this.aiText,
                }).then((response) => {
                    this.previewResult = response.data;
                }).catch((error) => {
                    const data = error.response?.data;
                    if (data && !data.parsed) {
                        this.previewError = data.message || '解析失败。';
                        this.previewErrorList = data.errors || [];
                    } else {
                        this.error = error.response?.data?.message || '请求失败。';
                    }
                }).finally(() => {
                    this.previewLoading = false;
                });
            },
            close() {
                this.visible = false;
            },
        },
    }
</script>
