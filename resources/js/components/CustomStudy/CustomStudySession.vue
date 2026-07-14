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
            <v-btn class="mt-4" color="primary" :loading="resumeLoading" :disabled="answerLoading || resumeLoading || completed || expired" @click="resumeSession">
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
                    <v-btn depressed rounded color="primary" large :disabled="sessionInputBlocked" @click="showAnswer = true">
                        显示答案
                    </v-btn>
                </template>

                <template #after-answer>
                    <div class="custom-study-rating-actions mt-5">
                        <v-btn color="error" :loading="answerLoading && pendingRating === 'again'" :disabled="sessionInputBlocked" @click="answer('again')">再来一次</v-btn>
                        <v-btn color="warning" :loading="answerLoading && pendingRating === 'hard'" :disabled="sessionInputBlocked" @click="answer('hard')">困难</v-btn>
                        <v-btn color="primary" :loading="answerLoading && pendingRating === 'good'" :disabled="sessionInputBlocked" @click="answer('good')">良好</v-btn>
                        <v-btn color="success" :loading="answerLoading && pendingRating === 'easy'" :disabled="sessionInputBlocked" @click="answer('easy')">简单</v-btn>
                    </div>
                    <div class="caption text--secondary text-center mt-2">这些按钮只推进本次预览会话，不会写入正式复习记录。</div>
                    <div class="caption text--secondary text-center mt-1">快捷键：Space 显示答案；答案面按 1 / 2 / 3 / 4 选择 Again / Hard / Good / Easy。</div>
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
    import {
        CustomStudySessionCoordinator,
        customStudyKeyboardAction,
        customStudySessionStorageKey,
        isCustomStudySessionMutationLocked,
    } from './CustomStudySessionCoordinator.js';

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
                expired: false,
                expiredNotified: false,
                coordinator: null,
                waitTimer: null,
                waitingSeconds: 0,
                sourceDialog: false,
                sourcePayload: {},
            };
        },
        computed: {
            isWaiting() {
                return Boolean(this.waitUntil) && !this.currentCard;
            },
            sessionInputBlocked() {
                return isCustomStudySessionMutationLocked({
                    mutationLocked: this.answerLoading || this.resumeLoading,
                    waitUntil: this.waitUntil,
                    completed: this.completed,
                    expired: this.expired,
                    currentCard: this.currentCard,
                });
            },
            waitingMessage() {
                return this.waitingSeconds > 0
                    ? `将在 ${this.waitingSeconds} 秒后可继续，或现在手动继续。`
                    : '现在可以继续预览学习。';
            },
        },
        mounted() {
            this.coordinator = new CustomStudySessionCoordinator({
                transport: {
                    answer: (token, rating) => axios
                        .post('/custom-study/sessions/answer', { token: token, rating: rating })
                        .then(response => response.data || {}),
                    resume: token => axios
                        .post('/custom-study/sessions/resume', { token: token })
                        .then(response => response.data || {}),
                },
                storage: window.sessionStorage,
                storageKey: customStudySessionStorageKey(),
                onState: this.applyCoordinatorState,
            });
            window.addEventListener('keydown', this.handleKeydown);
            if (this.initialPayload) {
                this.coordinator.open(this.initialToken, this.initialPayload);
                return;
            }
            this.coordinator.restore();
        },
        beforeDestroy() {
            this.clearWaitTimer();
            window.removeEventListener('keydown', this.handleKeydown);
            if (this.coordinator) {
                this.coordinator.dispose();
            }
        },
        methods: {
            answer(rating) {
                return this.coordinator ? this.coordinator.answer(rating) : Promise.resolve(false);
            },
            resumeSession() {
                return this.coordinator ? this.coordinator.resume() : Promise.resolve(false);
            },
            applyCoordinatorState(state) {
                const previousCardId = this.currentCard ? this.currentCard.review_card_id : null;
                const previousWaitUntil = this.waitUntil;
                const previousToken = this.token;
                this.token = state.token;
                this.currentCard = state.currentCard;
                this.summary = state.summary;
                this.completed = state.completed;
                this.expired = state.expired;
                this.waitUntil = state.waitUntil;
                this.loading = state.loading;
                this.answerLoading = state.mutationLocked && state.mutationType === 'answer';
                this.resumeLoading = state.mutationLocked && state.mutationType === 'resume';
                this.pendingRating = state.pendingRating;
                this.error = state.error;

                const currentCardId = this.currentCard ? this.currentCard.review_card_id : null;
                if (currentCardId !== previousCardId) {
                    this.showAnswer = false;
                }
                if (this.waitUntil !== previousWaitUntil) {
                    this.refreshWaitingClock();
                }
                if (this.token && this.token !== previousToken) {
                    this.$emit('token-updated', this.token);
                }
                if (this.expired && !this.expiredNotified) {
                    this.expiredNotified = true;
                    this.clearWaitTimer();
                    this.$emit('expired', this.error);
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
                        if (this.coordinator) {
                            this.coordinator.autoResume();
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
            handleKeydown(event) {
                const action = customStudyKeyboardAction(event, {
                    showAnswer: this.showAnswer,
                    blocked: this.sessionInputBlocked,
                    sourceDialogOpen: this.sourceDialog,
                });
                if (!action) {
                    return;
                }
                event.preventDefault();
                if (action === 'reveal') {
                    this.showAnswer = true;
                    return;
                }
                this.answer(action);
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
                const params = { read_only: 1 };
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
            exitSession() {
                this.clearWaitTimer();
                if (this.coordinator) {
                    this.coordinator.exit();
                }
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
