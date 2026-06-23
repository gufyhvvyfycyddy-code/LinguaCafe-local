<template>
    <v-dialog v-model="dialogValue" max-width="1100" scrollable>
        <v-card class="sense-example-dialog">
            <v-card-title>
                原文与译文
                <v-spacer />
                <v-btn icon @click="close"><v-icon>mdi-close</v-icon></v-btn>
            </v-card-title>

            <v-card-text class="source-dialog-body">
                <v-alert v-if="message" type="info" dense text class="mb-3">
                    {{ message }}
                </v-alert>

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

                <div v-if="context && context.debug" class="mt-2 text-caption text--secondary">
                    匹配方式：{{ context.source_kind }}，得分：{{ context.debug.match_score }}
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
            context() {
                return this.payload && this.payload.context ? this.payload.context : null;
            },
            tokens() {
                if (this.context && Array.isArray(this.context.context_tokens) && this.context.context_tokens.length) {
                    return this.context.context_tokens;
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
            message() {
                if (this.payload && this.payload.error) {
                    return this.payload.error;
                }

                if (!this.context) {
                    return '未定位到原章节，以下为复习卡保存的例句。';
                }

                if (!this.context.source_available) {
                    return this.context.fallback_message || '暂无可用原文位置。';
                }

                if (this.context.source_kind === 'chapter') {
                    return '已定位到原文位置。';
                }

                if (this.context.source_kind === 'chapter_recovered') {
                    return '已根据复习卡例句定位到原章节。';
                }

                if (this.context.source_kind === 'chapter_fuzzy') {
                    return '已根据复习卡例句模糊定位到原文位置。';
                }

                if (this.context.source_kind === 'chapter_fuzzy_title') {
                    return '已根据复习卡例句模糊定位到章节标题。';
                }

                if (this.context.source_kind === 'chapter_title') {
                    return '该例句来自章节标题。';
                }

                if (this.context.source_kind === 'card_example') {
                    return this.context.fallback_message || '未找到原章节位置，以下为复习卡保存的例句。';
                }

                return '';
            },
        },
        watch: {
            value(newValue) {
                if (newValue) {
                    this.autoScrolled = false;
                    this.$nextTick(() => {
                        this.scrollTargetIntoView();
                    });
                }
            },
        },
        methods: {
            close() {
                this.$emit('input', false);
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
