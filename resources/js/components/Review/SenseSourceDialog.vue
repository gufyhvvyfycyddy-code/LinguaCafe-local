<template>
    <v-dialog v-model="dialogValue" max-width="900">
        <v-card class="sense-source-dialog">
            <v-card-title>
                原文位置
                <v-spacer />
                <v-btn icon @click="close"><v-icon>mdi-close</v-icon></v-btn>
            </v-card-title>

            <v-card-text>
                <v-progress-linear v-if="loading" indeterminate />

                <v-alert v-if="error" type="error" dense text>{{ error }}</v-alert>

                <template v-if="context && !loading">
                    <v-alert v-if="!context.source_available" type="info" dense text>
                        {{ context.fallback_message || '暂无可用原文位置' }}
                    </v-alert>

                    <template v-else>
                        <v-alert v-if="context.source_kind === 'card_example'" type="info" dense text class="mb-3">
                            {{ context.fallback_message }}
                        </v-alert>
                        <v-alert v-if="context.source_kind === 'chapter_recovered'" type="info" dense text class="mb-3">
                            {{ context.fallback_message }}
                        </v-alert>
                        <v-alert v-if="context.source_kind === 'chapter_title'" type="info" dense text class="mb-3">
                            {{ context.fallback_message }}
                        </v-alert>

                        <div class="text-subtitle-2 mb-3">
                            {{ context.source_kind === 'card_example' ? '复习卡例句' : (context.chapter_title || '未命名章节') }}
                        </div>

                        <div class="text-block-group source-context" :style="{ 'font-size': fontSize + 'px' }">
                            <span
                                v-for="(token, index) in context.context_tokens"
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
                    </template>
                </template>
            </v-card-text>

            <v-card-actions>
                <v-spacer />
                <v-btn text @click="close">关闭</v-btn>
                <v-btn
                    color="primary"
                    depressed
                    :disabled="!canOpenChapter()"
                    @click="openChapter"
                >
                    打开原章节
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
    export default {
        props: {
            value: Boolean,
            senseId: Number,
            language: String,
            fontSize: Number,
        },
        data: function() {
            return {
                loading: false,
                error: '',
                context: null,
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
            canOpenChapter() {
                return this.context
                    && this.context.source_available
                    && this.context.chapter_id
                    && ['chapter', 'chapter_recovered', 'chapter_title'].includes(this.context.source_kind);
            },
        },
        watch: {
            value(newValue) {
                if (newValue) {
                    this.loadSourceContext();
                }
            },
            senseId() {
                if (this.value) {
                    this.loadSourceContext();
                }
            },
        },
        methods: {
            loadSourceContext() {
                if (!this.senseId) {
                    return;
                }

                this.loading = true;
                this.error = '';
                this.context = null;

                axios.get('/senses/' + this.senseId + '/source-context')
                    .then((response) => {
                        this.context = response.data;
                    })
                    .catch(() => {
                        this.error = '原文位置加载失败。';
                    })
                    .finally(() => {
                        this.loading = false;
                    });
            },
            close() {
                this.$emit('input', false);
            },
            openChapter() {
                if (!this.context || !this.context.chapter_id) {
                    return;
                }

                this.$emit('input', false);
                this.$router.push('/chapters/read/' + this.context.chapter_id);
            },
        },
    };
</script>
