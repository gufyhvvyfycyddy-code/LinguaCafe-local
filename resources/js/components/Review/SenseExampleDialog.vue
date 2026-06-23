<template>
    <v-dialog v-model="dialogValue" max-width="800">
        <v-card class="sense-example-dialog">
            <v-card-title>
                复习卡例句
                <v-spacer />
                <v-btn icon @click="close"><v-icon>mdi-close</v-icon></v-btn>
            </v-card-title>

            <v-card-text>
                <v-alert v-if="message" type="info" dense text class="mb-3">
                    {{ message }}
                </v-alert>

                <div class="text-block-group source-context" :style="{ 'font-size': fontSize + 'px' }">
                    <span
                        v-for="(token, index) in tokens"
                        :key="index"
                        :class="[
                            'word',
                            'selected-font',
                            {
                                'space-after': token.spaceAfter,
                                'source-target-token': token.is_target
                            }
                        ]"
                        :stage="token.stage === undefined || token.stage === null ? 2 : token.stage"
                    >{{ token.word }}</span>
                </div>

                <div v-if="translation" class="mt-4 pa-3 rounded example-translation">
                    <div class="text-caption text--secondary mb-1">译文</div>
                    <div>{{ translation }}</div>
                </div>

                <v-alert v-if="!tokens.length" type="info" dense text class="mt-3">
                    暂无可显示的例句。
                </v-alert>
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
                if (this.context && this.context.fallback_message) {
                    return this.context.fallback_message;
                }

                return '未定位到原章节，以下为复习卡保存的例句。';
            },
        },
        methods: {
            close() {
                this.$emit('input', false);
            },
        },
    };
</script>
