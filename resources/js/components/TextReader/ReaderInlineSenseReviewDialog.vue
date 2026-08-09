<template>
    <v-dialog v-model="visible" max-width="720" :fullscreen="$vuetify.breakpoint.xsOnly" persistent>
        <v-card>
            <v-card-title>
                阅读中词义复习
                <v-spacer />
                <v-btn icon aria-label="关闭阅读中词义复习" :disabled="busy" @click="close"><v-icon>mdi-close</v-icon></v-btn>
            </v-card-title>
            <v-card-text>
                <v-alert dense text type="info">
                    这是一次明确评分。显示答案、选择 Again / Hard / Good / Easy，再选定具体词义后才会提交正式复习。
                </v-alert>
                <v-alert v-if="error" dense outlined type="error">{{ error }}</v-alert>
                <v-alert v-if="outcomeUnknown" dense outlined type="warning">服务器结果未知期间，评分与词义选择已锁定。只能安全重试刚才那一笔正式评分。</v-alert>
                <v-alert v-if="manualCreateBlocked" dense outlined type="warning">上一次新增词义结果未知。原评分已保留；请从服务器当前候选中明确选择词义继续，本窗口不会再次创建词义。</v-alert>

                <div v-if="occurrence" class="mb-4">
                    <div class="text-h5 font-weight-medium">{{ occurrence.surface || occurrence.lemma }}</div>
                    <div v-if="occurrence.source_sentence" class="body-2 text--secondary mt-2">{{ occurrence.source_sentence }}</div>
                </div>

                <div v-if="!state.showAnswer" class="text-center py-6">
                    <div class="body-1 mb-4">先回想：这个词在这里是什么意思？</div>
                    <v-btn color="primary" depressed large :disabled="busy || !occurrence" @click="reveal">显示答案</v-btn>
                </div>

                <template v-else>
                    <div class="mb-5">
                        <div class="text-subtitle-1 font-weight-medium mb-2">1. 你刚才回想得怎么样？</div>
                        <sense-review-rating-controls :disabled="busy || outcomeUnknown || Boolean(frozenRating)" @rating="chooseRating" />
                        <div v-if="state.pendingRating" class="caption text--secondary text-center mt-1">
                            已选择 {{ ratingLabel(state.pendingRating) }}，还需要确定具体词义。
                        </div>
                    </div>

                    <div v-if="!manualMode">
                        <div class="text-subtitle-1 font-weight-medium mb-2">2. 这次复习的是哪个具体词义？</div>
                        <v-radio-group v-model="selectedSenseId" :disabled="busy || outcomeUnknown" class="mt-0">
                            <v-radio
                                v-for="candidate in ratableCandidates"
                                :key="candidate.word_sense_id || candidate.sense_id"
                                :value="Number(candidate.word_sense_id || candidate.sense_id)"
                            >
                                <template #label>
                                    <div>
                                        <strong>{{ candidate.sense_zh || '（无中文释义）' }}</strong>
                                        <span v-if="candidate.sense_en" class="text--secondary"> · {{ candidate.sense_en }}</span>
                                        <span v-if="candidate.pos" class="caption text--secondary"> · {{ candidate.pos }}</span>
                                    </div>
                                </template>
                            </v-radio>
                        </v-radio-group>
                        <v-alert v-if="!ratableCandidates.length" type="warning" dense text>
                            当前候选里没有可评分的词义卡。可以手动新增这个词义后，继续提交刚才选择的评分。
                        </v-alert>
                        <v-btn text small color="primary" :disabled="busy || outcomeUnknown || manualCreateBlocked || !state.pendingRating" @click="openManualMode">
                            都不是 / 新增词义
                        </v-btn>
                    </div>

                    <div v-else>
                        <div class="text-subtitle-1 font-weight-medium mb-2">2. 新增这个词义</div>
                        <v-select
                            v-model="manualSense.pos"
                            :items="posOptions"
                            item-text="text"
                            item-value="value"
                            label="词性"
                            outlined
                            dense
                            :disabled="busy || outcomeUnknown || manualCreateBlocked"
                        />
                        <v-text-field
                            v-model.trim="manualSense.sense_zh"
                            label="中文释义"
                            outlined
                            dense
                            :disabled="busy || outcomeUnknown || manualCreateBlocked"
                        />
                        <v-text-field
                            v-model.trim="manualSense.sense_en"
                            label="English（可选）"
                            outlined
                            dense
                            :disabled="busy || outcomeUnknown || manualCreateBlocked"
                        />
                        <v-alert dense text type="info">
                            保存成功后会把本次出现位置绑定到新词义，并沿用刚才的 {{ ratingLabel(state.pendingRating) }} 评分；不会再让你选第二次评分。
                        </v-alert>
                        <v-btn text small :disabled="busy || outcomeUnknown || manualCreateBlocked" @click="manualMode = false">返回已有词义</v-btn>
                    </div>
                </template>
            </v-card-text>
            <v-card-actions>
                <v-btn text :disabled="busy" @click="close">取消</v-btn>
                <v-spacer />
                <v-btn
                    v-if="state.showAnswer && !manualMode && !outcomeUnknown"
                    color="primary"
                    depressed
                    :loading="busy"
                    :disabled="!canSubmit"
                    @click="submit"
                >提交正式评分</v-btn>
                <v-btn
                    v-if="state.showAnswer && manualMode && !outcomeUnknown && !manualCreateBlocked"
                    color="primary"
                    depressed
                    :loading="busy"
                    :disabled="!canCreateSense"
                    @click="createSenseAndSubmit"
                >新增并提交评分</v-btn>
                <v-btn
                    v-if="outcomeUnknown"
                    color="warning"
                    depressed
                    :loading="busy"
                    :disabled="busy"
                    @click="$emit('retry-outcome-unknown')"
                >安全重试刚才评分</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
    import SenseReviewRatingControls from '../Senses/SenseReviewRatingControls.vue';
    import {
        buildReaderInlineOfficialRatingCommand,
        chooseReaderInlineRating,
        chooseReaderInlineSense,
        clearReaderInlinePendingRating,
        createReaderInlineSenseReviewState,
        normalizeReaderManualSensePos,
        revealReaderInlineSenseAnswer,
        replaceReaderInlineOccurrence,
    } from '../../services/ReaderInlineSenseReviewPolicy.js';

    export default {
        name: 'ReaderInlineSenseReviewDialog',
        components: { SenseReviewRatingControls },
        props: {
            value: { type: Boolean, default: false },
            occurrence: { type: Object, default: null },
            candidates: { type: Array, default: () => [] },
            readingSessionId: { type: String, default: '' },
            frozenRating: { type: String, default: '' },
            manualCreateBlocked: { type: Boolean, default: false },
            busy: { type: Boolean, default: false },
            error: { type: String, default: '' },
            outcomeUnknown: { type: Boolean, default: false },
        },
        data() {
            return {
                state: createReaderInlineSenseReviewState(this.occurrence),
                manualMode: false,
                manualSense: { pos: 'other', sense_zh: '', sense_en: '' },
                posOptions: [
                    { value: 'noun', text: '名词' },
                    { value: 'verb', text: '动词' },
                    { value: 'adjective', text: '形容词' },
                    { value: 'adverb', text: '副词' },
                    { value: 'preposition', text: '介词' },
                    { value: 'conjunction', text: '连词' },
                    { value: 'phrase', text: '短语' },
                    { value: 'other', text: '其他' },
                ],
            };
        },
        computed: {
            visible: {
                get() { return this.value; },
                set(value) { this.$emit('input', value); },
            },
            ratableCandidates() {
                return (this.candidates || []).filter(candidate => {
                    const cardId = Number(candidate.review_card_id);
                    return Number.isInteger(cardId) && cardId > 0 && candidate.fsrs_enabled !== false;
                });
            },
            selectedSenseId: {
                get() { return this.state.selectedWordSenseId; },
                set(value) { this.state = chooseReaderInlineSense(this.state, value); },
            },
            command() {
                return buildReaderInlineOfficialRatingCommand(this.state, this.ratableCandidates, this.readingSessionId);
            },
            canSubmit() {
                return Boolean(this.command) && !this.busy;
            },
            canCreateSense() {
                return Boolean(
                    this.state.pendingRating
                    && this.occurrence
                    && this.manualSense.pos
                    && this.manualSense.sense_zh.trim()
                    && !this.busy
                    && !this.outcomeUnknown
                    && !this.manualCreateBlocked
                );
            },
        },
        watch: {
            occurrence(next) {
                this.state = replaceReaderInlineOccurrence(this.state, next);
                this.resetManualSense(next);
                this.applyFrozenRating();
            },
            value(open) {
                if (!open) {
                    this.state = clearReaderInlinePendingRating(this.state);
                    this.manualMode = false;
                } else {
                    this.state = replaceReaderInlineOccurrence(this.state, this.occurrence);
                    this.resetManualSense(this.occurrence);
                    this.applyFrozenRating();
                }
            },
            frozenRating() {
                this.applyFrozenRating();
            },
        },
        methods: {
            reveal() {
                this.state = revealReaderInlineSenseAnswer(this.state);
                this.$emit('reveal', this.occurrence);
            },
            chooseRating(rating) {
                if (this.frozenRating) return;
                this.state = chooseReaderInlineRating(this.state, rating);
                if (this.state.pendingRating) this.$emit('rating-intent', this.state.pendingRating);
            },
            applyFrozenRating() {
                if (!this.value || !this.frozenRating || !this.occurrence) return;
                this.state = chooseReaderInlineRating(revealReaderInlineSenseAnswer(this.state), this.frozenRating);
            },
            ratingLabel(rating) {
                return { again: 'Again', hard: 'Hard', good: 'Good', easy: 'Easy' }[rating] || rating;
            },
            submit() {
                if (this.command) this.$emit('submit', this.command);
            },
            openManualMode() {
                if (!this.state.pendingRating) return;
                this.manualMode = true;
            },
            createSenseAndSubmit() {
                if (!this.canCreateSense) return;
                this.$emit('create-sense-and-submit', {
                    occurrence: this.occurrence,
                    rating: this.state.pendingRating,
                    form: { ...this.manualSense },
                });
            },
            resetManualSense(occurrence) {
                this.manualSense = {
                    pos: normalizeReaderManualSensePos(occurrence && occurrence.pos),
                    sense_zh: '',
                    sense_en: '',
                };
            },
            close() {
                this.state = clearReaderInlinePendingRating(this.state);
                this.manualMode = false;
                this.$emit('input', false);
                this.$emit('cancel');
            },
        },
    };
</script>

<style scoped>
    @media (max-width: 600px) {
        .v-card { padding-bottom: env(safe-area-inset-bottom, 0px); }
    }
</style>
