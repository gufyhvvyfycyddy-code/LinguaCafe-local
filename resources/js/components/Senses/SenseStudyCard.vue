<template>
    <div class="sense-study-card">
        <div class="d-flex align-center mb-3">
            <div>
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
            <v-sheet outlined rounded class="pa-3 mb-3">
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

        <div v-if="!showAnswer" class="d-flex justify-center mb-4">
            <slot name="reveal">
                <v-btn depressed rounded color="primary" large @click="$emit('reveal')">
                    显示答案
                </v-btn>
            </slot>
        </div>

        <div v-if="!showAnswer" class="text-center caption grey--text mt-2">
            快捷键：Space 显示答案
        </div>

        <template v-if="showAnswer">
            <div class="d-flex justify-end align-center mb-3" style="gap: 8px;">
                <v-btn small text @click="$emit('view-source')">
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

            <slot name="after-answer"></slot>
        </template>
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
    .sense-main {
        font-size: 24px;
        font-weight: 600;
    }
</style>
