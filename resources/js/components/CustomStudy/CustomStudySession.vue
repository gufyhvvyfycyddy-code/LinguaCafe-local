<template>
    <v-container class="custom-study-session" fluid>
        <div class="d-flex align-center mb-4">
            <div>
                <div class="text-h5">自定义学习</div>
                <div class="text--secondary">预览模式，不会影响正式复习。</div>
            </div>
            <v-spacer></v-spacer>
            <v-btn text @click="exitSession">退出本次学习</v-btn>
        </div>

        <v-alert v-if="error" type="error" dense outlined>{{ error }}</v-alert>

        <v-card v-if="loading" outlined class="pa-6 text-center">
            <v-progress-circular indeterminate color="primary"></v-progress-circular>
            <div class="mt-3">正在恢复预览会话…</div>
        </v-card>

        <v-card v-else-if="completed" outlined class="rounded-lg pa-5 custom-study-summary">
            <div class="text-h6 mb-3">本次预览学习已完成</div>
            <v-row dense>
                <v-col cols="6" sm="4"><div class="caption text--secondary">全部候选</div><div>{{ summary.total_candidates || 0 }}</div></v-col>
                <v-col cols="6" sm="4"><div class="caption text--secondary">计划卡片</div><div>{{ summary.total_count || 0 }}</div></v-col>
                <v-col cols="6" sm="4"><div class="caption text--secondary">已完成</div><div>{{ summary.completed_count || 0 }}</div></v-col>
                <v-col cols="6" sm="4"><div class="caption text--secondary">剩余</div><div>{{ summary.remaining_count || 0 }}</div></v-col>
                <v-col cols="6" sm="4"><div class="caption text--secondary">跳过</div><div>{{ summary.skipped_ineligible_count || 0 }}</div></v-col>
            </v-row>
            <v-btn class="mt-4" color="primary" @click="exitSession">返回自定义学习</v-btn>
            <v-btn class="mt-4 ml-2" text href="/reviews/senses">前往普通复习</v-btn>
        </v-card>

        <v-card v-else-if="isWaiting" outlined class="rounded-lg pa-6 text-center custom-study-waiting">
            <div class="text-h6">这张卡稍后再练习</div>
            <div class="text--secondary mt-2">{{ waitingMessage }}</div>
            <v-btn class="mt-4" color="primary" :loading="resumeLoading" :disabled="resumeLoading" @click="resumeSession">
                继续预览学习
            </v-btn>
        </v-card>

        <v-card v-else-if="currentCard" outlined class="rounded-lg pa-5">
            <div class="d-flex align-center mb-3">
                <v-chip small outlined>{{ summary.completed_count || 0 }} / {{ summary.total_count || 0 }}</v-chip>
                <v-chip v-if="summary.skipped_ineligible_count" small outlined color="warning" class="ml-2">
                    跳过 {{ summary.skipped_ineligible_count }}
                </v-chip>
            </div>

            <SenseStudyCard
                :card="currentCard"
                :show-answer="showAnswer"
                :font-size="20"
                @reveal="showAnswer = true"
                @view-source="viewSource"
            >
                <template #reveal>
                    <v-btn depressed rounded color="primary" large :disabled="answerLoading" @click="showAnswer = true">
                        显示答案
                    </v-btn>
                </template>

                <template #after-answer>
                    <div class="custom-study-rating-actions mt-5">
                        <v-btn color="error" :loading="answerLoading && pendingRating === 'again'" :disabled="answerLoading" @click="answer('again')">再来一次</v-btn>
                        <v-btn color="warning" :loading="answerLoading && pendingRating === 'hard'" :disabled="answerLoading" @click="answer('hard')">困难</v-btn>
                        <v-btn color="primary" :loading="answerLoading && pendingRating === 'good'" :disabled="answerLoading" @click="answer('good')">良好</v-btn>
                        <v-btn color="success" :loading="answerLoading && pendingRating === 'easy'" :disabled="answerLoading" @click="answer('easy')">简单</v-btn>
                    </div>
                    <div class="caption text--secondary text-center mt-2">这些按钮只推进本次预览会话，不会写入正式复习记录。</div>
                </template>
            </SenseStudyCard>
        </v-card>

        <v-card v-else outlined class="pa-5">
            <v-alert type="info" dense outlined class="mb-0">当前没有可显示的预览卡片。</v-alert>
        </v-card>

        <SenseExampleDialog
            v-model="sourceDialog"
            :payload="sourcePayload"
            :font-size="20"
            language="english"
        />
    </v-container>
</template>

<script>
    import SenseExampleDialog from '../Review/SenseExampleDialog.vue';
    import SenseStudyCard from '../Senses/SenseStudyCard.vue';

    const SESSION_TOKEN_KEY = 'linguacafe.custom-study.preview-token';

    export default {
        components: {
            SenseExampleDialog,
            SenseStudyCard,
        },
        props: {
            initialToken: {
                type: String,
                required: true,
            },
            initialPayload: {
                type: Object,
                default: null,
            },
        },
        data() {
            return {
                token: this.initialToken,
                currentCard: null,
                summary: {},
                showAnswer: false,
                completed: false,
                waitUntil: null,
                loading: false,
                answerLoading: false,
                resumeLoading: false,
                pendingRating: '',
                error: '',
                answerRequestSequence: 0,
                resumeRequestSequence: 0,
                waitTimer: null,
                waitingSeconds: 0,
                waitAutoResumeStarted: false,
                sourceDialog: false,
                sourcePayload: {},
            };
        },
        computed: {
            isWaiting() {
                return Boolean(this.waitUntil) && !this.currentCard;
            },
            waitingMessage() {
                return this.waitingSeconds > 0
                    ? `将在 ${this.waitingSeconds} 秒后可继续，或现在手动继续。`
                    : '现在可以继续预览学习。';
            },
        },
        mounted() {
            if (this.initialPayload) {
                this.applySessionPayload(this.initialPayload, true);
                return;
            }
            this.resumeSession(true);
        },
        beforeDestroy() {
            this.clearWaitTimer();
        },
        methods: {
            answer(rating) {
                if (this.answerLoading || this.resumeLoading || !this.token || !this.currentCard) {
                    return;
                }
                const requestSequence = ++this.answerRequestSequence;
                this.answerLoading = true;
                this.pendingRating = rating;
                this.error = '';

                axios.post('/custom-study/sessions/answer', { token: this.token, rating: rating })
                    .then((response) => {
                        if (requestSequence !== this.answerRequestSequence) {
                            return;
                        }
                        this.applySessionPayload(response.data || {}, false);
                    })
                    .catch((requestError) => {
                        if (requestSequence === this.answerRequestSequence) {
                            this.handleSessionError(requestError);
                        }
                    })
                    .finally(() => {
                        if (requestSequence === this.answerRequestSequence) {
                            this.answerLoading = false;
                            this.pendingRating = '';
                        }
                    });
            },
            resumeSession(isInitialRestore = false) {
                if (this.resumeLoading || this.answerLoading || !this.token) {
                    return;
                }
                const requestSequence = ++this.resumeRequestSequence;
                this.resumeLoading = true;
                this.loading = isInitialRestore;
                this.error = '';

                axios.post('/custom-study/sessions/resume', { token: this.token })
                    .then((response) => {
                        if (requestSequence !== this.resumeRequestSequence) {
                            return;
                        }
                        this.applySessionPayload(response.data || {}, false);
                    })
                    .catch((requestError) => {
                        if (requestSequence === this.resumeRequestSequence) {
                            this.handleSessionError(requestError);
                        }
                    })
                    .finally(() => {
                        if (requestSequence === this.resumeRequestSequence) {
                            this.resumeLoading = false;
                            this.loading = false;
                        }
                    });
            },
            applySessionPayload(payload, isOpenPayload) {
                const refreshedToken = payload.refreshed_token || payload.token;
                if (refreshedToken) {
                    this.token = refreshedToken;
                    window.sessionStorage.setItem(SESSION_TOKEN_KEY, refreshedToken);
                    this.$emit('token-updated', refreshedToken);
                }
                this.summary = payload.summary || {};
                this.currentCard = payload.current_card || null;
                this.completed = Boolean(payload.completed);
                this.showAnswer = false;
                this.waitUntil = payload.wait_until || null;
                this.waitAutoResumeStarted = false;
                this.refreshWaitingClock();

                if (isOpenPayload && !this.currentCard && !this.waitUntil) {
                    this.completed = true;
                }

                if (this.completed) {
                    this.waitUntil = null;
                    this.clearWaitTimer();
                    this.clearStoredToken();
                }
            },
            refreshWaitingClock() {
                this.clearWaitTimer();
                if (!this.waitUntil) {
                    this.waitingSeconds = 0;
                    return;
                }

                const tick = () => {
                    const milliseconds = new Date(this.waitUntil).getTime() - Date.now();
                    this.waitingSeconds = Math.max(0, Math.ceil(milliseconds / 1000));
                    if (this.waitingSeconds === 0) {
                        this.clearWaitTimer();
                        if (!this.waitAutoResumeStarted) {
                            this.waitAutoResumeStarted = true;
                            this.resumeSession();
                        }
                    }
                };
                tick();
                if (this.waitingSeconds > 0) {
                    this.waitTimer = window.setInterval(tick, 1000);
                }
            },
            clearWaitTimer() {
                if (this.waitTimer) {
                    window.clearInterval(this.waitTimer);
                    this.waitTimer = null;
                }
            },
            clearStoredToken() {
                window.sessionStorage.removeItem(SESSION_TOKEN_KEY);
            },
            viewSource() {
                if (!this.currentCard) {
                    return;
                }
                const card = {
                    lemma: this.currentCard.lemma,
                    surface_form: this.currentCard.surface_form,
                    sense_zh: this.currentCard.sense_zh,
                    sense_en: this.currentCard.sense_en,
                    example_sentence_en: this.currentCard.example_sentence_en,
                    example_sentence_zh: this.currentCard.example_sentence_zh,
                };
                const params = {};
                if (this.currentCard.displayed_occurrence_id) {
                    params.preferred_occurrence_id = this.currentCard.displayed_occurrence_id;
                }

                axios.get(`/senses/${this.currentCard.word_sense_id}/source-context-list`, { params: params })
                    .then((response) => {
                        const data = response.data || {};
                        const sources = Array.isArray(data.sources) ? data.sources : [];
                        this.sourcePayload = {
                            card: card,
                            context: sources[0] || null,
                            sources: sources,
                            sourceCount: data.count || sources.length,
                            preferredOccurrenceStatus: data.preferred_occurrence_status || null,
                        };
                        this.sourceDialog = true;
                    })
                    .catch(() => {
                        this.sourcePayload = { card: card, context: null, sources: [], sourceCount: 0, error: '获取原文失败。' };
                        this.sourceDialog = true;
                    });
            },
            handleSessionError(requestError) {
                const response = requestError.response;
                const payload = response && response.data ? response.data : {};
                if (response && response.status === 404) {
                    window.sessionStorage.removeItem(SESSION_TOKEN_KEY);
                    this.$emit('expired', payload.message || '预览会话已过期或不存在。');
                    return;
                }
                this.error = payload.message || '请求失败，请稍后重试。';
            },
            exitSession() {
                this.clearWaitTimer();
                this.clearStoredToken();
                this.$emit('exit');
            },
        },
    };
</script>

<style scoped>
    .custom-study-summary,
    .custom-study-waiting {
        max-width: 760px;
        margin: 0 auto;
    }

    .custom-study-rating-actions {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 8px;
    }
</style>
