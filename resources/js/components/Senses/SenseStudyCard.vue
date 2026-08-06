<template>
    <div
        ref="cardRoot"
        class="sense-study-card"
        data-testid="sense-study-card"
        role="group"
        :aria-label="`${card.lemma} 复习卡，${showAnswer ? '答案面' : '问题面'}`"
        tabindex="-1"
    >
        <div class="sense-study-card-header d-flex align-center flex-wrap mb-3">
            <div class="sense-study-card-title">
                <div class="text-h5 default-font">{{ card.lemma }}</div>
                <div class="text--secondary">
                    {{ card.surface_form || card.lemma }}
                    <span v-if="card.pos"> / {{ card.pos }}</span>
                </div>
            </div>
            <v-spacer></v-spacer>
            <slot name="header-meta"></slot>
        </div>

        <div class="mb-4">
            <div class="caption text--secondary d-flex align-center">
                <span>例句</span>
                <v-chip
                    v-if="card.occurrence_count > 1"
                    x-small
                    outlined
                    color="info"
                    class="ml-2"
                >本词义已有 {{ card.occurrence_count }} 条来源例句</v-chip>
            </div>
            <v-sheet outlined rounded class="sense-study-question pa-3 mb-3">
                <SenseSentencePreview
                    :tokens="card.example_sentence_tokens"
                    :sentence-text="card.example_sentence_en"
                    :target-surface="card.surface_form"
                    :target-lemma="card.lemma"
                    :language="card.language || 'english'"
                    :font-size="fontSize"
                    fallback-text="暂无例句。"
                />
            </v-sheet>
            <div class="body-1 primary--text font-weight-medium">
                这个句子里的 “{{ card.lemma }}” 是什么意思？
            </div>
        </div>

        <div v-if="!showAnswer" class="sense-study-reveal d-flex justify-center mb-4">
            <slot name="reveal">
                <v-btn
                    depressed
                    rounded
                    color="primary"
                    large
                    class="mobile-reveal-button"
                    data-testid="show-sense-answer"
                    @click="$emit('reveal')"
                >
                    显示答案
                </v-btn>
            </slot>
        </div>

        <div v-if="!showAnswer" class="sense-study-hotkey-hint text-center caption grey--text mt-2">
            快捷键：Space 显示答案
        </div>

        <div v-if="showAnswer" role="region" aria-label="词义卡答案">
            <div class="sense-study-answer-toolbar d-flex justify-end align-center mb-3">
                <v-btn
                    small
                    text
                    class="sense-study-source-button"
                    data-testid="view-sense-source"
                    @click="$emit('view-source')"
                >
                    <v-icon small left>mdi-book-open-page-variant</v-icon>查看原文
                </v-btn>
                <slot name="answer-toolbar"></slot>
            </div>

            <v-row dense>
                <v-col cols="12" md="6">
                    <div class="caption text--secondary">中文释义</div>
                    <div class="sense-main mb-4">{{ card.sense_zh }}</div>

                    <template v-if="hasSenseEn">
                        <div class="caption text--secondary">英文释义</div>
                        <div class="mb-4">{{ card.sense_en }}</div>
                    </template>

                    <template v-if="hasAliases">
                        <div class="caption text--secondary">近义释法</div>
                        <div class="mb-4">
                            <v-chip small class="mr-1 mb-1" v-for="alias in normalizedAliases" :key="alias">{{ alias }}</v-chip>
                        </div>
                    </template>

                    <template v-if="hasCollocations">
                        <div class="caption text--secondary">搭配</div>
                        <div>
                            <v-chip small class="mr-1 mb-1" v-for="collocation in normalizedCollocations" :key="collocation">{{ collocation }}</v-chip>
                        </div>
                    </template>

                    <slot name="answer-left-extra"></slot>
                </v-col>
                <v-col cols="12" md="6">
                    <div class="caption text--secondary">例句</div>
                    <v-sheet outlined rounded class="pa-3 mb-4">
                        <SenseSentencePreview
                            :tokens="card.example_sentence_tokens"
                            :sentence-text="card.example_sentence_en"
                            :target-surface="card.surface_form"
                            :target-lemma="card.lemma"
                            :language="card.language || 'english'"
                            :font-size="fontSize"
                            fallback-text="暂无例句。"
                        />
                        <div v-if="card.example_sentence_zh" class="text--secondary mt-2">{{ card.example_sentence_zh }}</div>
                    </v-sheet>

                    <template v-if="supplementaryExample">
                        <div class="caption text--secondary">补充例句</div>
                        <v-sheet outlined rounded class="pa-3 mb-4 supplementary-example">
                            <div class="default-font">{{ supplementaryExample.sentence_en }}</div>
                            <div v-if="supplementaryExample.sentence_zh" class="text--secondary mt-2">{{ supplementaryExample.sentence_zh }}</div>
                            <div v-if="supplementaryExample.chapter_title" class="text-caption text--secondary mt-2">
                                来源：{{ supplementaryExample.chapter_title }}
                            </div>
                        </v-sheet>
                    </template>

                    <slot name="answer-right-extra"></slot>
                </v-col>
            </v-row>

            <div class="sense-study-card-rating-dock">
                <slot name="after-answer"></slot>
            </div>
        </div>
    </div>
</template>

<script>
    import SenseSentencePreview from '../Review/SenseSentencePreview.vue';

    export default {
        components: {
            SenseSentencePreview,
        },
        props: {
            card: {
                type: Object,
                required: true,
            },
            showAnswer: {
                type: Boolean,
                default: false,
            },
            fontSize: {
                type: Number,
                default: 20,
            },
        },
        computed: {
            hasSenseEn() {
                return this.hasText(this.card.sense_en);
            },
            normalizedAliases() {
                return this.normalizedValues(this.card.aliases_zh);
            },
            hasAliases() {
                return this.normalizedAliases.length > 0;
            },
            normalizedCollocations() {
                return this.normalizedValues(this.card.collocations);
            },
            hasCollocations() {
                return this.normalizedCollocations.length > 0;
            },
            supplementaryExample() {
                const example = this.card.supplementary_example || null;
                if (!example || !this.hasText(example.sentence_en) || example.sentence_en === this.card.example_sentence_en) {
                    return null;
                }
                return example;
            },
        },
        methods: {
            focusCard() {
                this.$refs.cardRoot?.focus();
            },
            hasText(value) {
                return typeof value === 'string' && value.trim() !== '';
            },
            normalizedValues(values) {
                return Array.isArray(values)
                    ? values.filter((value) => this.hasText(value))
                    : [];
            },
        },
    };
</script>

<style scoped>
    .sense-study-card-header {
        gap: 8px;
    }
    .sense-study-card-title {
        min-width: 0;
    }
    .sense-study-answer-toolbar {
        gap: 8px;
    }
    .sense-main {
        font-size: 24px;
        font-weight: 600;
    }

    @media (max-width: 600px) {
        .sense-study-card-header {
            align-items: flex-start !important;
        }
        .sense-study-card-title {
            flex: 1 1 100%;
        }
        .sense-study-question {
            padding: 12px !important;
        }
        .sense-study-reveal,
        .sense-study-reveal .mobile-reveal-button {
            width: 100%;
        }
        .sense-study-reveal .mobile-reveal-button {
            min-height: 52px;
        }
        .sense-study-hotkey-hint {
            display: none;
        }
        .sense-study-answer-toolbar {
            justify-content: space-between !important;
            min-height: 48px;
        }
        .sense-study-source-button {
            min-height: 44px;
            margin-left: -8px;
        }
        .sense-main {
            font-size: 1.35rem;
        }
        .sense-study-card-rating-dock {
            margin-right: -8px;
            margin-left: -8px;
        }
    }
</style>
