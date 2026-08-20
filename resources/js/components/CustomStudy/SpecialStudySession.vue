<template>
    <div class="special-study-session">
        <div class="d-flex flex-wrap align-center mb-4">
            <div>
                <div class="text-h5">{{ localSession.name || '专项学习' }}</div>
                <div class="text--secondary">{{ impactText }}</div>
            </div>
            <v-spacer></v-spacer>
            <v-btn text :disabled="busy" @click="$emit('exit')">返回会话列表</v-btn>
        </div>

        <v-alert v-if="error" type="error" dense outlined>{{ error }}</v-alert>
        <v-alert :type="isPreview ? 'info' : 'warning'" dense outlined>{{ impactText }}</v-alert>

        <v-card outlined class="rounded-lg pa-4 mb-4">
            <div class="d-flex flex-wrap align-center">
                <v-chip small outlined>{{ localSession.completed_count || 0 }} / {{ localSession.total_count || 0 }}</v-chip>
                <v-chip v-if="localSession.skipped_count" small outlined color="warning" class="ml-2">
                    跳过 {{ localSession.skipped_count }}
                </v-chip>
                <v-chip small outlined class="ml-2">{{ statusLabel }}</v-chip>
                <v-spacer></v-spacer>
                <v-btn small text :loading="rebuilding" :disabled="busy" @click="rebuild">重建</v-btn>
                <v-btn small text color="error" :loading="ending" :disabled="busy || localSession.status === 'ended'" @click="endSession">结束</v-btn>
            </div>
            <div class="d-flex flex-wrap align-center mt-3">
                <v-text-field
                    v-model="saveName"
                    dense
                    outlined
                    hide-details
                    maxlength="100"
                    label="会话名称"
                    class="session-name-field"
                ></v-text-field>
                <v-btn class="ml-2" small color="primary" :loading="saving" :disabled="busy || !saveName.trim()" @click="saveSession">
                    {{ localSession.saved ? '重命名' : '保存' }}
                </v-btn>
            </div>
        </v-card>

        <v-card v-if="localSession.status === 'completed'" outlined class="rounded-lg pa-6 text-center">
            <div class="text-h6">本次专项学习已完成</div>
            <div class="text--secondary mt-2">可以重建同一条件，或返回建立新的会话。</div>
            <v-btn class="mt-4" color="primary" :loading="rebuilding" :disabled="busy" @click="rebuild">重建会话</v-btn>
        </v-card>

        <v-card v-else-if="localSession.status === 'ended'" outlined class="rounded-lg pa-6 text-center">
            <div class="text-h6">会话已结束</div>
            <div class="text--secondary mt-2">{{ localSession.saved ? '这个已保存定义可以重新建立候选队列。' : '未保存的已结束会话不能重建。' }}</div>
            <v-btn v-if="localSession.saved" class="mt-4" color="primary" :loading="rebuilding" :disabled="busy" @click="rebuild">重新打开</v-btn>
        </v-card>

        <v-card v-else-if="localSession.current_card" outlined class="rounded-lg pa-5">
            <SenseStudyCard
                :card="localSession.current_card"
                :show-answer="showAnswer"
                :font-size="20"
                @reveal="showAnswer = true"
                @view-source="viewSource"
            >
                <template #reveal>
                    <v-btn depressed rounded color="primary" large :disabled="busy" @click="showAnswer = true">
                        显示答案
                    </v-btn>
                </template>
                <template #after-answer>
                    <div class="rating-actions mt-5">
                        <v-btn color="error" :loading="answering && pendingRating === 'again'" :disabled="busy" @click="answer('again')">再来一次</v-btn>
                        <v-btn color="warning" :loading="answering && pendingRating === 'hard'" :disabled="busy" @click="answer('hard')">困难</v-btn>
                        <v-btn color="primary" :loading="answering && pendingRating === 'good'" :disabled="busy" @click="answer('good')">良好</v-btn>
                        <v-btn color="success" :loading="answering && pendingRating === 'easy'" :disabled="busy" @click="answer('easy')">简单</v-btn>
                    </div>
                    <div class="caption text--secondary text-center mt-2">{{ ratingHelp }}</div>
                    <div class="caption text--secondary text-center mt-1">快捷键：Space 显示答案；答案面按 1 / 2 / 3 / 4 评分。</div>
                </template>
            </SenseStudyCard>
        </v-card>

        <v-alert v-else type="info" outlined>
            当前卡片不可用，可以跳过失效项并继续。
            <v-btn class="ml-2" small color="primary" :loading="answering" :disabled="busy" @click="answer('good', true)">跳过并继续</v-btn>
        </v-alert>

        <SenseExampleDialog
            v-model="sourceDialog"
            :payload="sourcePayload"
            :font-size="20"
            language="english"
        />
    </div>
</template>

<script>
    import SenseExampleDialog from '../Review/SenseExampleDialog.vue';
    import SenseStudyCard from '../Senses/SenseStudyCard.vue';

    export default {
        components: { SenseExampleDialog, SenseStudyCard },
        props: {
            session: { type: Object, required: true },
        },
        data() {
            return {
                localSession: Object.assign({}, this.session),
                saveName: this.session.name || '',
                showAnswer: false,
                answering: false,
                pendingRating: '',
                saving: false,
                rebuilding: false,
                ending: false,
                error: '',
                sourceDialog: false,
                sourcePayload: {},
                answerStartedAt: null,
                pendingAction: null,
            };
        },
        computed: {
            isPreview() {
                return this.localSession.execution_mode === 'preview';
            },
            busy() {
                return this.answering || this.saving || this.rebuilding || this.ending;
            },
            impactText() {
                if (this.isPreview) return '预览模式：回答只推进本次会话，不写 ReviewLog 或 FSRS。';
                if (this.localSession.execution_mode === 'early_review') return '提前正式复习：评分会写入 ReviewLog，并重新计算正常队列到期时间。';
                return '正式评分：回答会写入 ReviewLog、FSRS 与撤销账本，并计入今天的复习数量。';
            },
            ratingHelp() {
                return this.isPreview
                    ? '四个按钮只记录本次预览进度。'
                    : '四个按钮是正式评分；提交后可在 Card Info 的操作记录中查看。';
            },
            statusLabel() {
                return { active: '进行中', completed: '已完成', ended: '已结束' }[this.localSession.status] || this.localSession.status;
            },
        },
        watch: {
            session(value) {
                this.localSession = Object.assign({}, value);
                this.saveName = value.name || '';
            },
            'localSession.current_card.review_card_id'() {
                this.showAnswer = false;
                this.answerStartedAt = Date.now();
            },
        },
        mounted() {
            this.answerStartedAt = Date.now();
            window.addEventListener('keydown', this.handleKeydown);
        },
        beforeDestroy() {
            window.removeEventListener('keydown', this.handleKeydown);
        },
        methods: {
            actionId() {
                if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                    return window.crypto.randomUUID();
                }
                return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, character => {
                    const random = Math.random() * 16 | 0;
                    const value = character === 'x' ? random : (random & 0x3 | 0x8);
                    return value.toString(16);
                });
            },
            async answer(rating, allowMissing = false) {
                if (this.busy || (!this.localSession.current_card && !allowMissing)) return;
                this.answering = true;
                this.pendingRating = rating;
                this.error = '';
                const revision = this.localSession.revision;
                if (!this.pendingAction
                    || this.pendingAction.rating !== rating
                    || this.pendingAction.revision !== revision) {
                    this.pendingAction = {
                        id: this.actionId(),
                        rating,
                        revision,
                        duration: Math.max(0, Date.now() - (this.answerStartedAt || Date.now())),
                        questionExampleKey: this.localSession.current_card
                            ? (this.localSession.current_card.question_example_key || null)
                            : null,
                    };
                }
                try {
                    const response = await axios.post(
                        `/special-study/sessions/${this.localSession.id}/answer`,
                        {
                            rating,
                            client_action_id: this.pendingAction.id,
                            expected_revision: this.pendingAction.revision,
                            review_duration_ms: this.pendingAction.duration,
                            question_example_key: this.pendingAction.questionExampleKey,
                        },
                    );
                    this.pendingAction = null;
                    this.apply(response.data);
                } catch (error) {
                    this.applyError(error, '评分失败，请重试。');
                    await this.reconcileAfterAnswerError();
                } finally {
                    this.answering = false;
                    this.pendingRating = '';
                }
            },
            async saveSession() {
                this.saving = true;
                this.error = '';
                try {
                    const response = await axios.put(
                        `/special-study/sessions/${this.localSession.id}/save`,
                        { name: this.saveName.trim(), expected_revision: this.localSession.revision },
                    );
                    this.apply(response.data);
                } catch (error) {
                    this.applyError(error, '保存会话失败。');
                } finally {
                    this.saving = false;
                }
            },
            async rebuild() {
                this.rebuilding = true;
                this.error = '';
                try {
                    const response = await axios.post(
                        `/special-study/sessions/${this.localSession.id}/rebuild`,
                        { expected_revision: this.localSession.revision },
                    );
                    this.apply(response.data);
                } catch (error) {
                    this.applyError(error, '重建会话失败。');
                } finally {
                    this.rebuilding = false;
                }
            },
            async endSession() {
                this.ending = true;
                this.error = '';
                try {
                    const response = await axios.post(
                        `/special-study/sessions/${this.localSession.id}/end`,
                        { expected_revision: this.localSession.revision },
                    );
                    this.apply(response.data);
                } catch (error) {
                    this.applyError(error, '结束会话失败。');
                } finally {
                    this.ending = false;
                }
            },
            apply(session) {
                this.localSession = session;
                this.saveName = session.name || this.saveName;
                this.showAnswer = false;
                this.answerStartedAt = Date.now();
                this.$emit('updated', session);
            },
            async reconcileAfterAnswerError() {
                try {
                    const response = await axios.get(`/special-study/sessions/${this.localSession.id}`);
                    if (response.data.revision !== this.localSession.revision) {
                        this.pendingAction = null;
                        this.apply(response.data);
                        this.error = '';
                    }
                } catch (ignored) {
                    // Keep the original action id so an exact retry remains safe.
                }
            },
            applyError(error, fallback) {
                const payload = error.response && error.response.data;
                this.error = payload && payload.message ? payload.message : fallback;
            },
            handleKeydown(event) {
                if (this.busy || this.sourceDialog || !this.localSession.current_card) return;
                if (!this.showAnswer && (event.code === 'Space' || event.key === ' ')) {
                    event.preventDefault();
                    this.showAnswer = true;
                    return;
                }
                if (!this.showAnswer) return;
                const rating = { '1': 'again', '2': 'hard', '3': 'good', '4': 'easy' }[event.key];
                if (rating) {
                    event.preventDefault();
                    this.answer(rating);
                }
            },
            async viewSource() {
                const current = this.localSession.current_card;
                if (!current) return;
                const card = {
                    lemma: current.lemma,
                    surface_form: current.surface_form,
                    sense_zh: current.sense_zh,
                    sense_en: current.sense_en,
                    example_sentence_en: current.example_sentence_en,
                    example_sentence_zh: current.example_sentence_zh,
                };
                const params = { read_only: 1 };
                if (current.displayed_occurrence_id) params.preferred_occurrence_id = current.displayed_occurrence_id;
                try {
                    const response = await axios.get(`/senses/${current.word_sense_id}/source-context-list`, { params });
                    const data = response.data || {};
                    const sources = Array.isArray(data.sources) ? data.sources : [];
                    this.sourcePayload = {
                        card,
                        context: sources[0] || null,
                        sources,
                        sourceCount: data.count || sources.length,
                        preferredOccurrenceStatus: data.preferred_occurrence_status || null,
                    };
                } catch (error) {
                    this.sourcePayload = { card, context: null, sources: [], sourceCount: 0, error: '获取原文失败。' };
                }
                this.sourceDialog = true;
            },
        },
    };
</script>

<style scoped>
    .special-study-session {
        max-width: 960px;
        margin: 0 auto;
    }
    .session-name-field {
        max-width: 360px;
    }
    .rating-actions {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 8px;
    }
</style>
