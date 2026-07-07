<template>
    <v-dialog v-model="dialogValue" max-width="1100" scrollable>
        <v-card class="sense-example-dialog">
            <v-card-title>
                原文与译文
                <v-spacer />
                <v-chip v-if="sourceCount > 1" small outlined class="mr-2">
                    来源 {{ activeSourceIndex + 1 }} / {{ sourceCount }}
                </v-chip>
                <v-btn icon @click="close"><v-icon>mdi-close</v-icon></v-btn>
            </v-card-title>

            <v-card-text class="source-dialog-body">
                <v-alert v-if="preferredHint" type="success" dense text class="mb-2" dismissible>
                    {{ preferredHint }}
                </v-alert>

                <v-alert v-if="message" type="info" dense text class="mb-3">
                    {{ message }}
                </v-alert>

                <div v-if="sourceCount > 1" class="d-flex align-center mb-3 source-carousel-bar">
                    <v-btn small text :disabled="activeSourceIndex === 0" @click="prevSource">
                        <v-icon small left>mdi-chevron-left</v-icon>上一来源
                    </v-btn>
                    <v-spacer />
                    <span class="text-caption text--secondary">
                        {{ activeSourceChapterTitle || '来源 ' + (activeSourceIndex + 1) }}
                    </span>
                    <v-spacer />
                    <v-btn small text :disabled="activeSourceIndex === sourceCount - 1" @click="nextSource">
                        下一来源<v-icon small right>mdi-chevron-right</v-icon>
                    </v-btn>
                </div>

                <div v-if="tokens.length" ref="scrollBox" class="source-scroll-box">
                    <div class="text-block-group source-context" :style="{ 'font-size': fontSize + 'px' }">
                        <span
                            v-for="(token, index) in tokens"
                            :key="index"
                            :ref="token.is_target ? 'targetToken' : null"
                            :class="[
                                'word',
                                'selected-font',
                                {
                                    'space-after': token.spaceAfter,
                                    'source-target-token': token.is_target,
                                    'source-sentence-token': token.is_source_sentence
                                }
                            ]"
                            :stage="token.stage === undefined || token.stage === null ? 2 : token.stage"
                        >{{ token.word }}</span>
                    </div>
                </div>

                <v-alert v-else type="info" dense text class="mt-3">
                    暂无可显示的原文或例句。
                </v-alert>

                <div v-if="translation" class="mt-4 pa-3 rounded example-translation">
                    <div class="text-caption text--secondary mb-1">译文 / 释义</div>
                    <div>{{ translation }}</div>
                </div>

                <div v-if="activeContext && activeContext.debug" class="mt-2 text-caption text--secondary">
                    匹配方式：{{ activeContext.source_kind }}，得分：{{ activeContext.debug.match_score }}
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
            payload: Object,
            fontSize: Number,
            language: String,
        },
        data() {
            return {
                autoScrolled: false,
                activeSourceIndex: 0,
            };
        },
        computed: {
            dialogValue: {
                get() {
                    return this.value;
                },
                set(value) {
                    this.$emit('input', value);
                },
            },
            card() {
                return this.payload && this.payload.card ? this.payload.card : null;
            },
            sources() {
                if (this.payload && Array.isArray(this.payload.sources) && this.payload.sources.length) {
                    return this.payload.sources;
                }
                // Backward compat: if only `context` is provided (single-source
                // shape), treat it as a one-element source list.
                if (this.payload && this.payload.context) {
                    return [this.payload.context];
                }
                return [];
            },
            sourceCount() {
                return this.sources.length;
            },
            activeContext() {
                return this.sources[this.activeSourceIndex] || null;
            },
            context() {
                // Kept for legacy template bindings; alias of activeContext.
                return this.activeContext;
            },
            activeSourceChapterTitle() {
                return this.activeContext && this.activeContext.chapter_title
                    ? this.activeContext.chapter_title
                    : '';
            },
            tokens() {
                if (this.activeContext && Array.isArray(this.activeContext.context_tokens) && this.activeContext.context_tokens.length) {
                    return this.activeContext.context_tokens;
                }

                if (this.card && Array.isArray(this.card.example_sentence_tokens)) {
                    return this.card.example_sentence_tokens;
                }

                return [];
            },
            translation() {
                if (this.card && this.card.example_sentence_zh) {
                    return this.card.example_sentence_zh;
                }

                if (this.card && this.card.sense_zh) {
                    return this.card.sense_zh;
                }

                if (this.card && this.card.sense_en) {
                    return this.card.sense_en;
                }

                return '';
            },
            preferredHint() {
                // SenseSourceContextFollowDisplayedOccurrence-1000-7:
                // Lightweight, non-blocking hint that the source dialog
                // successfully aligned with the example currently shown on
                // the review card. We only show the positive 'matched' hint
                // (and the neutral 'fallback' note when the dialog still
                // opened with other sources). We never expose the database
                // occurrence id here.
                const status = this.payload && this.payload.preferredOccurrenceStatus;
                if (status === 'matched') {
                    return '已定位到当前复习例句。';
                }
                if (status === 'fallback') {
                    return '未定位到当前例句，已显示其他可用来源。';
                }
                return '';
            },
            message() {
                if (this.payload && this.payload.error) {
                    return this.payload.error;
                }

                if (!this.activeContext) {
                    return '未定位到原章节，以下为复习卡保存的例句。';
                }

                if (!this.activeContext.source_available) {
                    return this.activeContext.fallback_message || '暂无可用原文位置。';
                }

                if (this.activeContext.source_kind === 'chapter') {
                    return this.sourceCount > 1
                        ? '已定位到原文位置（来源 ' + (this.activeSourceIndex + 1) + ' / ' + this.sourceCount + '）。'
                        : '已定位到原文位置。';
                }

                if (this.activeContext.source_kind === 'chapter_recovered') {
                    return '已根据复习卡例句定位到原章节。';
                }

                if (this.activeContext.source_kind === 'chapter_fuzzy') {
                    return '已根据复习卡例句模糊定位到原文位置。';
                }

                if (this.activeContext.source_kind === 'chapter_fuzzy_title') {
                    return '已根据复习卡例句模糊定位到章节标题。';
                }

                if (this.activeContext.source_kind === 'chapter_title') {
                    return '该例句来自章节标题。';
                }

                if (this.activeContext.source_kind === 'card_example') {
                    return this.activeContext.fallback_message || '未找到原章节位置，以下为复习卡保存的例句。';
                }

                return '';
            },
        },
        watch: {
            value(newValue) {
                if (newValue) {
                    this.activeSourceIndex = 0;
                    this.autoScrolled = false;
                    this.$nextTick(() => {
                        this.scrollTargetIntoView();
                    });
                }
            },
            activeSourceIndex() {
                this.autoScrolled = false;
                this.$nextTick(() => {
                    this.scrollTargetIntoView();
                });
            },
        },
        methods: {
            close() {
                this.$emit('input', false);
            },
            prevSource() {
                if (this.activeSourceIndex > 0) {
                    this.activeSourceIndex--;
                }
            },
            nextSource() {
                if (this.activeSourceIndex < this.sourceCount - 1) {
                    this.activeSourceIndex++;
                }
            },
            scrollTargetIntoView() {
                this.$nextTick(() => {
                    const refs = this.$refs.targetToken;
                    const target = Array.isArray(refs) ? refs[0] : refs;

                    if (target && target.scrollIntoView) {
                        target.scrollIntoView({
                            behavior: 'smooth',
                            block: 'center',
                            inline: 'center',
                        });
                    }
                });
            },
        },
    };
</script>

<style scoped>
    .source-carousel-bar {
        gap: 8px;
    }
</style>
