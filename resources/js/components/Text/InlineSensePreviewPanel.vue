<template>
    <div v-if="shouldRender" class="inline-sense-preview-panel rounded pa-2 mb-3">
        <div class="d-flex align-center mb-1">
            <v-icon small color="primary" class="mr-1">mdi-eye-outline</v-icon>
            <strong class="inline-preview-title">候选预览</strong>
            <v-chip v-if="previewData && previewData.candidate_count !== undefined" x-small class="ml-2">
                {{ previewData.candidate_count }}
            </v-chip>
            <v-spacer />
            <span class="text-caption text--secondary">read-only</span>
        </div>

        <!-- Safety banner: this round does not write anything -->
        <div class="inline-preview-safety-banner text-caption text--secondary mb-2">
            这是候选预览。本轮不会写入复习记录，不会改变 FSRS。
        </div>

        <!-- Surface / lemma / sentence context -->
        <div class="inline-preview-context rounded pa-2 mb-2">
            <div class="text-caption text--secondary">当前词形</div>
            <div class="d-flex align-center mb-1">
                <strong class="default-font mr-2">{{ surfaceWord || '未选择' }}</strong>
                <span class="text--secondary mr-1">词元：</span>
                <strong class="default-font">{{ effectiveLemma || '未识别' }}</strong>
            </div>
            <div v-if="sentenceText" class="text-caption text--secondary mt-1">
                句子：<span class="default-font">{{ sentenceText }}</span>
            </div>
        </div>

        <!-- Loading state -->
        <div v-if="loading" class="text-caption text--secondary pa-2">
            正在加载候选预览… <v-progress-circular indeterminate size="14" width="2" color="primary" class="ml-1" />
        </div>

        <!-- Error state -->
        <v-alert v-else-if="error" dense text type="error" class="mb-2">
            候选预览加载失败。
        </v-alert>

        <!-- Empty state -->
        <div v-else-if="!previewData || previewData.candidate_count === 0" class="text-caption text--secondary pa-2">
            当前词元暂无已确认词义候选。
        </div>

        <!-- Candidate list -->
        <div v-else>
            <div
                v-for="candidate in previewData.candidates"
                :key="candidate.sense_id"
                class="inline-preview-candidate rounded pa-2 mb-2"
                :class="{ 'is-selected': userChoice[candidate.sense_id] === 'yes', 'is-rejected': userChoice[candidate.sense_id] === 'no' }"
            >
                <div class="d-flex align-center mb-1">
                    <v-chip v-if="candidate.pos" x-small outlined class="mr-1">{{ candidate.pos }}</v-chip>
                    <v-chip v-if="candidate.has_review_card" x-small outlined color="primary" class="mr-1">FSRS</v-chip>
                    <v-spacer />
                    <span class="text-caption text--secondary" v-if="candidate.fsrs_state">
                        状态：{{ candidate.fsrs_state }}
                    </span>
                </div>
                <div v-if="candidate.sense_zh" class="sense-zh mb-1"><strong>{{ candidate.sense_zh }}</strong></div>
                <div v-if="candidate.sense_en" class="sense-en mb-1 text--secondary">{{ candidate.sense_en }}</div>
                <div class="text-caption text--secondary" v-if="candidate.fsrs_reps !== null && candidate.fsrs_reps !== undefined">
                    已复习 {{ candidate.fsrs_reps }} 次
                </div>

                <!-- Front-end only choice buttons (this round) -->
                <div class="d-flex mt-2 align-center">
                    <v-btn
                        x-small
                        depressed
                        :color="userChoice[candidate.sense_id] === 'yes' ? 'success' : ''"
                        class="inline-preview-btn-yes mr-2"
                        @click="setChoice(candidate.sense_id, 'yes')"
                    >
                        是这个意思
                    </v-btn>
                    <v-btn
                        x-small
                        depressed
                        :color="userChoice[candidate.sense_id] === 'no' ? 'error' : ''"
                        class="inline-preview-btn-no"
                        @click="setChoice(candidate.sense_id, 'no')"
                    >
                        不是这个意思
                    </v-btn>
                </div>
            </div>

            <!-- Choice notice: explicitly tells user this is front-end only -->
            <v-alert
                v-if="hasAnyChoice"
                dense
                text
                type="info"
                icon="mdi-information-outline"
                class="mt-2 mb-0 inline-preview-choice-notice"
            >
                你的选择仅在本面板内记录，不会写入复习记录，不会改变 FSRS，不会创建词义或复习卡。
            </v-alert>
        </div>
    </div>
</template>

<script>
import axios from 'axios';

/**
 * InlineSensePreviewPanel (GLM-ReadingInlinePreview-First-1)
 *
 * A READ-ONLY preview panel shown inside WordSensesList after the user
 * clicks a token in the reading page. It shows:
 *  - the current surface form (e.g. "geese");
 *  - the current lemma (e.g. "goose");
 *  - the sentence the token appears in;
 *  - confirmed WordSense candidates for this lemma;
 *  - each candidate's sense text + whether it has a sense ReviewCard;
 *  - a read-only FSRS status summary per candidate.
 *
 * The "是这个意思 / 不是这个意思" buttons are FRONT-END ONLY this round.
 * They store the user's choice in `userChoice` (a local data object) and
 * DO NOT call any POST endpoint. They do not write ReviewLog, FSRS,
 * WordSense, or ReviewCard. The only backend call is the GET
 * `/senses/inline-preview` endpoint, which is itself read-only.
 *
 * This component does NOT emit events to the parent for the choice
 * buttons — the choice stays inside this component. A future round that
 * wants to turn the choice into a real write MUST pass an Architecture
 * Gate + ADR first and remove the corresponding safety_flag from the
 * backend payload.
 */
export default {
    name: 'InlineSensePreviewPanel',
    props: {
        lemma: {
            type: String,
            required: true,
        },
        surface: {
            type: String,
            default: '',
        },
        sentence: {
            type: String,
            default: '',
        },
        language: {
            type: String,
            default: 'english',
        },
        /**
         * When true, the panel is hidden entirely. Used by parent to
         * disable the preview in contexts where it should not appear.
         */
        disabled: {
            type: Boolean,
            default: false,
        },
    },
    data() {
        return {
            loading: false,
            error: false,
            previewData: null,
            latestLookupKey: '',
            // { [sense_id]: 'yes' | 'no' } — front-end only, never sent to backend
            userChoice: {},
        };
    },
    computed: {
        effectiveLemma() {
            return (this.lemma || '').trim().toLowerCase();
        },
        surfaceWord() {
            return (this.surface || this.lemma || '').trim();
        },
        sentenceText() {
            return (this.sentence || '').trim();
        },
        shouldRender() {
            if (this.disabled) return false;
            if (!this.effectiveLemma) return false;
            return true;
        },
        hasAnyChoice() {
            return Object.keys(this.userChoice).length > 0;
        },
    },
    watch: {
        effectiveLemma: {
            immediate: true,
            handler() {
                this.fetchInlinePreview();
            },
        },
        language() {
            this.fetchInlinePreview();
        },
    },
    methods: {
        fetchInlinePreview() {
            const lemma = this.effectiveLemma;
            const language = this.language;
            if (!lemma) {
                this.latestLookupKey = '';
                this.previewData = null;
                this.loading = false;
                this.error = false;
                this.userChoice = {};
                return;
            }

            const lookupKey = language + '|' + lemma + '|' + this.surfaceWord + '|' + this.sentenceText;
            this.latestLookupKey = lookupKey;
            this.loading = true;
            this.error = false;
            this.userChoice = {};

            axios.get('/senses/inline-preview', {
                params: {
                    lemma: lemma,
                    language: language,
                    surface: this.surfaceWord,
                    sentence: this.sentenceText,
                },
            }).then((response) => {
                if (this.latestLookupKey !== lookupKey) return;
                const data = response && response.data;
                this.previewData = data || null;
            }).catch(() => {
                if (this.latestLookupKey !== lookupKey) return;
                this.error = true;
                this.previewData = null;
            }).finally(() => {
                if (this.latestLookupKey !== lookupKey) return;
                this.loading = false;
            });
        },
        setChoice(senseId, choice) {
            // Front-end only. Do NOT call any backend endpoint here.
            // Toggle off if clicking the same choice again.
            if (this.userChoice[senseId] === choice) {
                this.$delete(this.userChoice, senseId);
            } else {
                this.$set(this.userChoice, senseId, choice);
            }
        },
    },
};
</script>

<style scoped>
.inline-sense-preview-panel {
    background: rgba(var(--v-primary-lighten-base), 0.06);
    border: 1px solid rgba(var(--v-primary-base), 0.18);
}

.inline-preview-safety-banner {
    line-height: 1.5;
}

.inline-preview-context {
    background: rgba(0, 0, 0, 0.04);
}

.inline-preview-candidate {
    background: rgba(0, 0, 0, 0.03);
    transition: background 0.15s ease;
}

.inline-preview-candidate.is-selected {
    background: rgba(var(--v-success-base), 0.10);
    border-left: 3px solid var(--v-success-base);
}

.inline-preview-candidate.is-rejected {
    background: rgba(var(--v-error-base), 0.08);
    border-left: 3px solid var(--v-error-base);
}

.inline-preview-choice-notice {
    font-size: 0.75rem;
}
</style>
