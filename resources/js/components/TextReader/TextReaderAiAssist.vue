<template>
    <v-dialog v-model="visible" max-width="780" scrollable persistent>
        <v-card>
            <v-card-title v-if="previewStep === 'input'">
                <v-icon left class="mr-2">mdi-robot</v-icon>
                AI 阅读辅助
            </v-card-title>

            <v-card-title v-if="previewStep === 'overview'" class="pb-0">
                <v-icon left class="mr-2">mdi-view-dashboard</v-icon>
                AI 解析预览总览
            </v-card-title>

            <v-card-title v-if="previewStep === 'detail'" class="pb-0">
                <v-btn icon class="mr-2" @click="goBackToOverview" title="返回总览">
                    <v-icon>mdi-arrow-left</v-icon>
                </v-btn>
                <v-icon left class="mr-2">mdi-format-list-bulleted</v-icon>
                {{ detailTitle }}
            </v-card-title>

            <v-card-text>
                <v-alert
                    v-if="error"
                    type="error"
                    dense
                    outlined
                    class="mb-3"
                >{{ error }}</v-alert>

                <!-- ─── INPUT STEP ───────────────────── -->
                <template v-if="previewStep === 'input'">
                    <!-- Step 1: Copy prompt -->
                    <div class="mb-4">
                        <div class="text-subtitle-1 font-weight-medium mb-2">
                            步骤 1：复制全文 + AI 分析提示词
                        </div>
                        <v-btn
                            depressed
                            color="primary"
                            :loading="sourceLoading"
                            @click="loadSource"
                        >
                            <v-icon left>mdi-content-copy</v-icon>
                            复制全文 + AI 提示词
                        </v-btn>
                        <div v-if="sourceCopied" class="caption green--text mt-1">
                            <v-icon small color="success">mdi-check-circle</v-icon>
                            已复制本章英文和 AI 分析提示词。你可以把提示词发送给 DeepSeek Flash 或 DeepSeek Pro。两者都使用同一导入格式，系统不强制选择。
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

                    <!-- Errors detail (stay in input step) -->
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
                </template>

                <!-- ─── OVERVIEW STEP ────────────────── -->
                <template v-if="previewStep === 'overview' && previewResult">
                    <v-alert
                        dense
                        outlined
                        type="info"
                        text
                        class="mb-4"
                    >
                        <v-icon small left>mdi-information-outline</v-icon>
                        当前只是预览，不会写入学习数据。
                    </v-alert>

                    <v-row>
                        <v-col cols="12" sm="6" class="pb-2">
                            <v-card
                                outlined
                                hover
                                :ripple="false"
                                class="pa-3"
                                style="cursor: pointer;"
                                @click="openDetail('translations')"
                            >
                                <v-icon left color="primary">mdi-translate</v-icon>
                                <div class="text-h6 font-weight-medium mb-1">句子译文</div>
                                <div class="text-body-2 grey--text">
                                    {{ previewResult.summary.sentence_translation_count }} 条句子
                                </div>
                            </v-card>
                        </v-col>

                        <v-col cols="12" sm="6" class="pb-2">
                            <v-card
                                outlined
                                hover
                                :ripple="false"
                                class="pa-3"
                                style="cursor: pointer;"
                                @click="openDetail('vocabulary')"
                            >
                                <v-icon left color="success">mdi-book-open-variant</v-icon>
                                <div class="text-h6 font-weight-medium mb-1">生词释义</div>
                                <div class="text-body-2 grey--text">
                                    {{ previewResult.summary.vocabulary_item_count }} 个生词
                                </div>
                            </v-card>
                        </v-col>

                        <v-col cols="12" sm="6" class="pb-2">
                            <v-card
                                outlined
                                hover
                                :ripple="false"
                                class="pa-3"
                                style="cursor: pointer;"
                                @click="openDetail('phrases')"
                            >
                                <v-icon left color="warning">mdi-link-variant</v-icon>
                                <div class="text-h6 font-weight-medium mb-1">词组释义</div>
                                <div class="text-body-2 grey--text">
                                    {{ previewResult.summary.phrase_item_count }} 个词组
                                </div>
                            </v-card>
                        </v-col>

                        <v-col cols="12" sm="6" class="pb-2">
                            <v-card
                                outlined
                                hover
                                :ripple="false"
                                class="pa-3"
                                style="cursor: pointer;"
                                @click="openDetail('warnings')"
                            >
                                <v-icon left color="grey">mdi-alert-circle-outline</v-icon>
                                <div class="text-h6 font-weight-medium mb-1">警告信息</div>
                                <div class="text-body-2 grey--text">
                                    {{ previewResult.summary.warning_count }} 条警告
                                </div>
                            </v-card>
                        </v-col>
                    </v-row>
                </template>

                <!-- ─── DETAIL SEARCH BAR ──────────── -->
                <div v-if="previewStep === 'detail'" class="mb-3">
                    <v-text-field
                        v-model="detailSearchQuery"
                        label="搜索英文词、原型、词组或原句"
                        placeholder="例如：their / investigate / draw on"
                        outlined
                        dense
                        hide-details
                        clearable
                        prepend-inner-icon="mdi-magnify"
                    >
                        <template v-slot:append>
                            <span v-if="detailSearchQuery && hasActiveDetailData" class="caption grey--text">
                                已筛选出 {{ activeDetailFilteredCount }} / {{ activeDetailOriginalCount }} 条
                            </span>
                        </template>
                    </v-text-field>
                </div>

                <!-- ─── TRANSLATIONS DETAIL ─────────── -->
                <template v-if="previewStep === 'detail' && activeDetailType === 'translations'">
                    <div v-if="hasActiveDetailData && activeDetailItems.length">
                        <div
                            v-for="(st, i) in activeDetailItems"
                            :key="'st-d-' + i"
                            class="mb-3"
                        >
                            <v-sheet outlined rounded class="pa-3">
                                <div class="text-subtitle-2 font-weight-bold mb-1">{{ st.sentence_index }}</div>
                                <div class="body-2 mb-1">{{ st.source_text }}</div>
                                <div class="caption blue--text text--darken-1">{{ st.translation_zh }}</div>
                            </v-sheet>
                        </div>
                    </div>
                    <div v-else-if="!hasActiveDetailData" class="text-caption grey--text text-center py-4">
                        暂无句子译文。
                    </div>
                    <div v-else class="text-caption grey--text text-center py-4">
                        没有找到匹配内容。
                    </div>
                </template>

                <!-- ─── VOCABULARY DETAIL ───────────── -->
                <template v-if="previewStep === 'detail' && activeDetailType === 'vocabulary'">
                    <div v-if="hasActiveDetailData && activeDetailItems.length">
                        <div
                            v-for="(vi, i) in activeDetailItems"
                            :key="'vi-d-' + i"
                            class="mb-3"
                        >
                            <v-sheet outlined rounded class="pa-3">
                                <div class="d-flex align-center mb-1">
                                    <strong class="text-body-1">{{ vi.surface }}</strong>
                                    <v-chip
                                        v-if="vi.confidence"
                                        small
                                        class="ml-2"
                                        :color="confidenceColor(vi.confidence)"
                                        text-color="white"
                                        x-small
                                    >{{ vi.confidence }}</v-chip>
                                </div>
                                <div v-if="vi.suggested_lemma" class="caption grey--text mb-1">
                                    AI 建议原型：{{ vi.suggested_lemma }}
                                </div>
                                <div v-if="vi.pos" class="caption grey--text mb-1">
                                    词性：{{ vi.pos }}
                                </div>
                                <div class="body-2 mb-1">
                                    中文释义：{{ vi.meaning_zh }}
                                </div>
                                <div v-if="vi.sentence_index" class="caption grey--text mb-1">
                                    所在句：{{ vi.sentence_index }}
                                </div>
                                <div v-if="vi.source_sentence" class="caption grey--text mb-1">
                                    原句：{{ vi.source_sentence }}
                                </div>
                                <div v-if="vi.reason" class="caption grey--text">
                                    说明：{{ vi.reason }}
                                </div>
                            </v-sheet>
                        </div>
                    </div>
                    <div v-else-if="!hasActiveDetailData" class="text-caption grey--text text-center py-4">
                        暂无生词释义。
                    </div>
                    <div v-else class="text-caption grey--text text-center py-4">
                        没有找到匹配内容。
                    </div>
                </template>

                <!-- ─── PHRASES DETAIL ──────────────── -->
                <template v-if="previewStep === 'detail' && activeDetailType === 'phrases'">
                    <div v-if="hasActiveDetailData && activeDetailItems.length">
                        <div
                            v-for="(pi, i) in activeDetailItems"
                            :key="'pi-d-' + i"
                            class="mb-3"
                        >
                            <v-sheet outlined rounded class="pa-3">
                                <div class="d-flex align-center mb-1">
                                    <strong class="text-body-1">{{ pi.phrase }}</strong>
                                    <v-chip
                                        v-if="pi.confidence"
                                        small
                                        class="ml-2"
                                        :color="confidenceColor(pi.confidence)"
                                        text-color="white"
                                        x-small
                                    >{{ pi.confidence }}</v-chip>
                                </div>
                                <div class="body-2 mb-1">
                                    中文释义：{{ pi.meaning_zh }}
                                </div>
                                <div v-if="pi.trigger_words && pi.trigger_words.length" class="caption grey--text mb-1">
                                    触发词：{{ pi.trigger_words.join('、') }}
                                </div>
                                <div v-if="pi.sentence_index" class="caption grey--text mb-1">
                                    所在句：{{ pi.sentence_index }}
                                </div>
                                <div v-if="pi.source_sentence" class="caption grey--text mb-1">
                                    原句：{{ pi.source_sentence }}
                                </div>
                                <div v-if="pi.reason" class="caption grey--text">
                                    说明：{{ pi.reason }}
                                </div>
                            </v-sheet>
                        </div>
                    </div>
                    <div v-else-if="!hasActiveDetailData" class="text-caption grey--text text-center py-4">
                        暂无词组释义。
                    </div>
                    <div v-else class="text-caption grey--text text-center py-4">
                        没有找到匹配内容。
                    </div>
                </template>

                <!-- ─── WARNINGS DETAIL ─────────────── -->
                <template v-if="previewStep === 'detail' && activeDetailType === 'warnings'">
                    <div v-if="hasActiveDetailData && activeDetailItems.length">
                        <div
                            v-for="(w, i) in activeDetailItems"
                            :key="'w-d-' + i"
                            class="mb-2"
                        >
                            <v-sheet outlined rounded class="pa-3">
                                <div class="caption font-weight-medium mb-1">{{ w.type }}</div>
                                <div class="caption grey--text">{{ w.message }}</div>
                            </v-sheet>
                        </div>
                    </div>
                    <div v-else-if="!hasActiveDetailData" class="text-caption grey--text text-center py-4">
                        暂无警告。
                    </div>
                    <div v-else class="text-caption grey--text text-center py-4">
                        没有找到匹配内容。
                    </div>
                </template>
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

                previewStep: 'input', // 'input' | 'overview' | 'detail'
                activeDetailType: null, // 'translations' | 'vocabulary' | 'phrases' | 'warnings'
                previewResult: null,
                previewError: '',
                previewErrorList: [],
                error: '',
                detailSearchQuery: '',
            }
        },
        computed: {
            visible: {
                get() { return this.value; },
                set(v) {
                    if (!v) {
                        this.$emit('input', v);
                    } else {
                        this.$emit('input', v);
                    }
                },
            },
            items() {
                if (!this.previewResult || !this.previewResult.items) {
                    return {
                        sentence_translations: [],
                        vocabulary_items: [],
                        phrase_items: [],
                        warnings: [],
                    };
                }
                return this.previewResult.items;
            },
            detailTitle() {
                const map = {
                    translations: '句子译文详情',
                    vocabulary: '生词释义详情',
                    phrases: '词组释义详情',
                    warnings: '警告信息',
                };
                return map[this.activeDetailType] || '详情';
            },

            // ── Search filters ──

            filteredSentenceTranslations() {
                return this.filterList(this.items.sentence_translations, this.detailSearchQuery, ['source_text', 'translation_zh']);
            },
            filteredVocabularyItems() {
                return this.filterList(this.items.vocabulary_items, this.detailSearchQuery, ['surface', 'suggested_lemma', 'pos', 'meaning_zh', 'source_sentence', 'reason', 'confidence']);
            },
            filteredPhraseItems() {
                return this.filterList(this.items.phrase_items, this.detailSearchQuery, ['phrase', 'meaning_zh', 'trigger_words', 'source_sentence', 'reason', 'confidence']);
            },
            filteredWarnings() {
                return this.filterList(this.items.warnings, this.detailSearchQuery, ['type', 'message']);
            },
            activeDetailOriginalCount() {
                if (!this.activeDetailType) return 0;
                const map = {
                    translations: this.items.sentence_translations.length,
                    vocabulary: this.items.vocabulary_items.length,
                    phrases: this.items.phrase_items.length,
                    warnings: this.items.warnings.length,
                };
                return map[this.activeDetailType] || 0;
            },
            activeDetailFilteredCount() {
                if (!this.activeDetailType) return 0;
                const map = {
                    translations: this.filteredSentenceTranslations.length,
                    vocabulary: this.filteredVocabularyItems.length,
                    phrases: this.filteredPhraseItems.length,
                    warnings: this.filteredWarnings.length,
                };
                return map[this.activeDetailType] || 0;
            },
            activeDetailItems() {
                if (!this.activeDetailType) return [];
                const map = {
                    translations: this.filteredSentenceTranslations,
                    vocabulary: this.filteredVocabularyItems,
                    phrases: this.filteredPhraseItems,
                    warnings: this.filteredWarnings,
                };
                return map[this.activeDetailType] || [];
            },
            hasActiveDetailData() {
                return this.activeDetailOriginalCount > 0;
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
            confidenceColor(level) {
                const map = {
                    high: 'green',
                    medium: 'orange',
                    low: 'red',
                };
                return map[level] || 'grey';
            },
            filterList(list, query, fields) {
                if (!query || !query.trim()) return list;
                const q = query.trim().toLowerCase();
                return list.filter(item => {
                    return fields.some(field => {
                        const val = item[field];
                        if (Array.isArray(val)) {
                            return val.some(v => String(v).toLowerCase().includes(q));
                        }
                        return val != null && String(val).toLowerCase().includes(q);
                    });
                });
            },
            reset() {
                this.sourceLoading = false;
                this.sourceCopied = false;
                this.previewLoading = false;
                this.previewStep = 'input';
                this.activeDetailType = null;
                this.previewResult = null;
                this.previewError = '';
                this.previewErrorList = [];
                this.error = '';
                this.detailSearchQuery = '';
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
                        // Fallback
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
                    this.previewStep = 'overview';
                    this.activeDetailType = null;
                    this.detailSearchQuery = '';
                }).catch((error) => {
                    const data = error.response?.data;
                    if (data && data.parsed === false) {
                        this.previewError = data.message || '解析失败。';
                        this.previewErrorList = data.errors || [];
                        this.previewStep = 'input';
                    } else {
                        this.error = error.response?.data?.message || '请求失败。';
                    }
                }).finally(() => {
                    this.previewLoading = false;
                });
            },
            goBackToOverview() {
                this.previewStep = 'overview';
                this.activeDetailType = null;
                this.detailSearchQuery = '';
            },
            openDetail(type) {
                this.activeDetailType = type;
                this.previewStep = 'detail';
                this.detailSearchQuery = '';
            },
            close() {
                this.visible = false;
            },
        },
    }
</script>
