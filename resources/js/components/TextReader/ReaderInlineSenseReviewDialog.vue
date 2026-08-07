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
                    这是一次明确评分。只有你显示答案、选择 Again / Hard / Good / Easy，并选定具体词义后，才会提交正式复习。
                </v-alert>
                <v-alert v-if="error" dense outlined type="error">{{ error }}</v-alert>

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
                        <sense-review-rating-controls
                            :disabled="busy"
                            @rating="chooseRating"
                        />
                        <div v-if="state.pendingRating" class="caption text--secondary text-center mt-1">
                            已选择 {{ ratingLabel(state.pendingRating) }}，还需要选择具体词义才能提交。
                        </div>
                    </div>

                    <div>
                        <div class="text-subtitle-1 font-weight-medium mb-2">2. 这次复习的是哪个具体词义？</div>
                        <v-radio-group v-model="selectedSenseId" :disabled="busy" class="mt-0">
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
                            当前没有可通过正式 Sense Review 入口评分的词义卡。
                        </v-alert>
                    </div>
                </template>
            </v-card-text>
            <v-card-actions>
                <v-btn text :disabled="busy" @click="close">取消</v-btn>
                <v-spacer />
                <v-btn
                    v-if="state.showAnswer"
                    color="primary"
                    depressed
                    :loading="busy"
                    :disabled="!canSubmit"
                    @click="submit"
                >提交正式评分</v-btn>
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
            busy: { type: Boolean, default: false },
            error: { type: String, default: '' },
        },
        data() {
            return {
                state: createReaderInlineSenseReviewState(this.occurrence),
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
        },
        watch: {
            occurrence(next) {
                this.state = replaceReaderInlineOccurrence(this.state, next);
            },
            value(open) {
                if (!open) this.state = clearReaderInlinePendingRating(this.state);
                else this.state = replaceReaderInlineOccurrence(this.state, this.occurrence);
            },
        },
        methods: {
            reveal() {
                this.state = revealReaderInlineSenseAnswer(this.state);
            },
            chooseRating(rating) {
                this.state = chooseReaderInlineRating(this.state, rating);
            },
            ratingLabel(rating) {
                return { again: 'Again', hard: 'Hard', good: 'Good', easy: 'Easy' }[rating] || rating;
            },
            submit() {
                if (this.command) this.$emit('submit', this.command);
            },
            close() {
                this.state = clearReaderInlinePendingRating(this.state);
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
