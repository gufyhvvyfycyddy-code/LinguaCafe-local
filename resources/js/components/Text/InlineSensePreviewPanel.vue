<template>
    <div v-if="shouldRender" class="inline-sense-preview-panel rounded pa-2 mb-3">
        <div class="d-flex align-center mb-1">
            <v-icon small color="primary" class="mr-1">mdi-eye-outline</v-icon>
            <strong class="inline-preview-title">候选预览</strong>
            <v-chip v-if="previewData && previewData.candidate_count !== undefined" x-small class="ml-2">
                {{ previewData.candidate_count }}
            </v-chip>
            <v-spacer />
            <span class="text-caption text--secondary">read-only preview</span>
        </div>

        <!-- Safety banner: this is NOT a review rating -->
        <div class="inline-preview-safety-banner text-caption text--secondary mb-2">
            这是候选预览。这不是复习评分，不会写入复习记录，不会改变复习进度（FSRS）。
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
                :class="{ 'is-selected': effectiveChoice(candidate) === 'match', 'is-rejected': effectiveChoice(candidate) === 'not_match' }"
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

                <!-- Usage surface: per-sense reading confirmation summary (GLM-ReadingInlineConfirmationUsageSurface-AndMorphology-1000-1) -->
                <div
                    v-if="hasUsageSummary(candidate)"
                    class="inline-preview-usage-summary text-caption mt-1 mb-1"
                >
                    <div v-if="candidate.usage_match_count > 0" class="inline-preview-usage-match">
                        这个词义在阅读中确认过 {{ candidate.usage_match_count }} 次
                    </div>
                    <div v-if="candidate.usage_not_match_count > 0" class="inline-preview-usage-not-match">
                        这个词义在阅读中排除过 {{ candidate.usage_not_match_count }} 次
                    </div>
                    <div v-if="candidate.usage_last_choice" class="inline-preview-usage-last text--secondary">
                        最近一次：<span v-if="candidate.usage_last_choice === 'match'">是这个意思</span><span v-else>不是这个意思</span>
                    </div>
                </div>

                <!-- Persisted confirmation buttons (GLM-ReadingInlineConfirmationPersistence-1000-1) -->
                <div class="d-flex mt-2 align-center flex-wrap">
                    <v-btn
                        x-small
                        depressed
                        :color="effectiveChoice(candidate) === 'match' ? 'success' : ''"
                        class="inline-preview-btn-yes mr-2 mb-1"
                        :loading="savingSenseId === candidate.sense_id && pendingChoice === 'match'"
                        :disabled="savingSenseId !== null"
                        @click="persistChoice(candidate, 'match')"
                    >
                        是这个意思
                    </v-btn>
                    <v-btn
                        x-small
                        depressed
                        :color="effectiveChoice(candidate) === 'not_match' ? 'error' : ''"
                        class="inline-preview-btn-no mb-1"
                        :loading="savingSenseId === candidate.sense_id && pendingChoice === 'not_match'"
                        :disabled="savingSenseId !== null"
                        @click="persistChoice(candidate, 'not_match')"
                    >
                        不是这个意思
                    </v-btn>
                </div>

                <!-- Persisted confirmation echo -->
                <div v-if="effectiveChoice(candidate)" class="text-caption mt-1 inline-preview-persisted-echo">
                    <v-icon x-small class="mr-1">mdi-check-circle-outline</v-icon>
                    <span v-if="effectiveChoice(candidate) === 'match'" class="inline-preview-saved-match">
                        已保存：是这个意思
                    </span>
                    <span v-else class="inline-preview-saved-not-match">
                        已保存：不是这个意思
                    </span>
                    <span class="text--secondary ml-1">（这不是复习评分，不会写入复习记录，不会改变复习进度）</span>
                </div>

                <!-- Per-candidate save error -->
                <v-alert
                    v-if="saveErrors[candidate.sense_id]"
                    dense
                    text
                    type="error"
                    class="mt-1 mb-0 inline-preview-save-error"
                >
                    保存失败：{{ saveErrors[candidate.sense_id] }}
                </v-alert>
            </div>

            <!-- Choice notice: persisted, not a review rating -->
            <v-alert
                v-if="hasAnyPersistedChoice"
                dense
                text
                type="info"
                icon="mdi-information-outline"
                class="mt-2 mb-0 inline-preview-choice-notice"
            >
                你的选择已保存为阅读位置级别的确认。这不是复习评分，不会写入复习记录，不会改变复习进度（FSRS），不会创建词义或复习卡。
            </v-alert>

            <!-- Management entry: view / filter / revoke all reading-inline confirmations (GLM-ReadingInlineConfirmationManagementSurface-1000-1) -->
            <div class="mt-2 text-caption">
                <a
                    href="/senses/inline-confirmations/manage"
                    class="inline-preview-manage-link text-decoration-none"
                >
                    <v-icon x-small class="mr-1">mdi-format-list-bulleted</v-icon>
                    查看全部阅读确认记录
                </a>
            </div>
        </div>

        <!-- Visible undo affordance: persistent button + hint shown while undo_token is valid
             (OpenCode-ReadingInlineConfirmationUndoAffordanceFix-1) -->
        <v-alert
            v-if="undoToken && !undoLoading"
            dense
            text
            type="info"
            icon="mdi-undo-variant"
            class="mt-2 mb-0 inline-preview-undo-affordance"
        >
            <div class="d-flex align-center flex-wrap">
                <span class="inline-preview-undo-affordance-hint mr-2">点错了？按 Ctrl+Z 或点击下方按钮撤回。</span>
                <v-btn
                    text
                    color="primary"
                    class="inline-preview-undo-button"
                    @click="triggerUndo"
                >
                    撤回刚才的阅读判断
                </v-btn>
            </div>
        </v-alert>

        <!-- Undo snackbar: shown after a store / choice-switch action
             (OpenCode-ReadingInlineConfirmationUndoHotkey-800-1) -->
        <v-snackbar
            v-model="undoSnackbar.show"
            :timeout="undoSnackbar.timeout"
            :color="undoSnackbar.color"
            class="inline-preview-undo-snackbar"
        >
            <span class="inline-preview-undo-hint">{{ undoSnackbar.text }}</span>
            <template v-slot:action="{ attrs }">
                <v-btn
                    text
                    v-bind="attrs"
                    @click="triggerUndo"
                    class="inline-preview-undo-btn"
                >
                    撤销
                </v-btn>
            </template>
        </v-snackbar>
    </div>
</template>

<script>
import axios from 'axios';

/**
 * InlineSensePreviewPanel
 *
 * (GLM-ReadingInlinePreview-First-1 + GLM-ReadingInlineConfirmationPersistence-1000-1)
 *
 * A preview panel shown inside WordSensesList after the user clicks a token
 * in the reading page. It shows:
 *  - the current surface form (e.g. "geese");
 *  - the current lemma (e.g. "goose");
 *  - the sentence the token appears in;
 *  - confirmed WordSense candidates for this lemma;
 *  - each candidate's sense text + whether it has a sense ReviewCard;
 *  - a read-only FSRS status summary per candidate;
 *  - the persisted match / not_match choice per candidate, echoed from the
 *    `reading_inline_sense_confirmations` table.
 *
 * The "是这个意思 / 不是这个意思" buttons now call POST
 * `/senses/inline-confirmation` to persist the user's choice. The choice is
 * occurrence-level (chapter + sentence + surface + lemma + sense) and is
 * NOT a review rating. It does NOT write ReviewLog, does NOT change FSRS,
 * does NOT create WordSense / ReviewCard. See ADR-0003.
 *
 * Safety contract enforced by the backend:
 *  - `POST /senses/inline-confirmation` validates user / language / chapter /
 *    sense ownership and WordSense STATUS_CONFIRMED.
 *  - The backend returns `safety_flags` with `not_a_review_rating: true`.
 *
 * This component does NOT emit events to the parent for the choice buttons.
 * The choice is persisted in the backend and echoed via the GET preview
 * endpoint on reload.
 *
 * A future round that wants to turn the choice into an FSRS rating MUST
 * pass an Architecture Gate + new ADR first (ADR-0003 explicitly forbids
 * reusing `/senses/inline-confirmation` for rating).
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
        chapterId: {
            type: Number,
            default: null,
        },
        sentenceIndex: {
            type: Number,
            default: null,
        },
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
            // Optimistic local override of persisted_choice per sense_id.
            // { [sense_id]: 'match' | 'not_match' }
            localOverride: {},
            savingSenseId: null,
            pendingChoice: null,
            // { [sense_id]: string errorMessage }
            saveErrors: {},
            // Undo token (OpenCode-ReadingInlineConfirmationUndoHotkey-800-1):
            // opaque backend-signed string returned by POST /senses/inline-confirmation.
            // Only the most recent action is undoable. Cleared after use.
            undoToken: null,
            undoSnackbar: {
                show: false,
                text: '',
                color: 'info',
                timeout: 6000,
            },
            undoLoading: false,
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
        hasAnyPersistedChoice() {
            if (!this.previewData || !this.previewData.candidates) return false;
            return this.previewData.candidates.some(c => this.effectiveChoice(c));
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
        chapterId() {
            this.fetchInlinePreview();
        },
        sentenceIndex() {
            this.fetchInlinePreview();
        },
    },
    mounted() {
        window.addEventListener('keydown', this.handleKeyDown);
    },
    beforeDestroy() {
        window.removeEventListener('keydown', this.handleKeyDown);
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
                this.localOverride = {};
                this.saveErrors = {};
                return;
            }

            const lookupKey = language + '|' + lemma + '|' + this.surfaceWord + '|' + this.sentenceText
                + '|' + (this.chapterId ?? '') + '|' + (this.sentenceIndex ?? '');
            this.latestLookupKey = lookupKey;
            this.loading = true;
            this.error = false;
            this.localOverride = {};
            this.saveErrors = {};

            axios.get('/senses/inline-preview', {
                params: {
                    lemma: lemma,
                    language: language,
                    surface: this.surfaceWord,
                    sentence: this.sentenceText,
                    chapter_id: this.chapterId,
                    sentence_index: this.sentenceIndex,
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
        effectiveChoice(candidate) {
            const sid = candidate.sense_id;
            if (Object.prototype.hasOwnProperty.call(this.localOverride, sid)) {
                return this.localOverride[sid];
            }
            return candidate.persisted_choice || null;
        },
        /**
         * Returns true when the candidate carries any reading-inline confirmation
         * usage summary worth displaying. Used by the template to gate the
         * usage summary block so empty confirmations don't render an empty box.
         *
         * (GLM-ReadingInlineConfirmationUsageSurface-AndMorphology-1000-1)
         */
        hasUsageSummary(candidate) {
            if (!candidate) return false;
            const matchCount = candidate.usage_match_count;
            const notMatchCount = candidate.usage_not_match_count;
            const lastChoice = candidate.usage_last_choice;
            return (typeof matchCount === 'number' && matchCount > 0)
                || (typeof notMatchCount === 'number' && notMatchCount > 0)
                || (lastChoice !== null && lastChoice !== undefined && lastChoice !== '');
        },
        persistChoice(candidate, choice) {
            const senseId = candidate.sense_id;

            // If clicking the same choice that is already persisted, do not re-send.
            if (this.effectiveChoice(candidate) === choice) {
                return;
            }

            // Clear any previous per-candidate error.
            this.$delete(this.saveErrors, senseId);

            this.savingSenseId = senseId;
            this.pendingChoice = choice;

            // Optimistic local override so the UI updates immediately.
            this.$set(this.localOverride, senseId, choice);

            axios.post('/senses/inline-confirmation', {
                lemma: this.effectiveLemma,
                surface: this.surfaceWord,
                language: this.language,
                chapter_id: this.chapterId,
                sentence_index: this.sentenceIndex,
                sentence_text: this.sentenceText,
                word_sense_id: senseId,
                choice: choice,
            }).then((response) => {
                const data = response && response.data;
                // If the backend returned an updated preview, replace the whole payload.
                if (data && data.updated_preview) {
                    this.previewData = data.updated_preview;
                }
                // Clear the local override; the preview payload now carries persisted_choice.
                this.$delete(this.localOverride, senseId);

                // Save the undo token returned by the backend and show a
                // snackbar telling the user they can press Ctrl+Z
                // (OpenCode-ReadingInlineConfirmationUndoHotkey-800-1).
                if (data && typeof data.undo_token === 'string' && data.undo_token) {
                    this.undoToken = data.undo_token;
                    this.undoSnackbar = {
                        show: true,
                        text: data.undo_hint || '按 Ctrl+Z 可撤销刚才的阅读判断。',
                        color: 'info',
                        timeout: 6000,
                    };
                }
            }).catch((err) => {
                // Revert the optimistic override.
                this.$delete(this.localOverride, senseId);
                const msg = (err && err.response && err.response.data && err.response.data.message)
                    || '网络或校验错误';
                this.$set(this.saveErrors, senseId, msg);
            }).finally(() => {
                this.savingSenseId = null;
                this.pendingChoice = null;
            });
        },
        /**
         * Keydown handler for Ctrl+Z / Cmd+Z (Anki-style undo).
         * - If focus is inside an input / textarea / select / contenteditable,
         *   do NOT intercept — let the browser do native text undo.
         * - If no undo token is available, do nothing (no error).
         * - Otherwise, call the undo endpoint.
         *
         * (OpenCode-ReadingInlineConfirmationUndoHotkey-800-1, ADR-0003 Undo Hotkey Layer)
         */
        handleKeyDown(event) {
            const isCtrlOrCmd = event.ctrlKey || event.metaKey;
            if (!isCtrlOrCmd || event.key !== 'z' && event.key !== 'Z') {
                return;
            }
            // Do not intercept when the user is editing text.
            if (this.isFocusInsideEditableInput()) {
                return;
            }
            if (!this.undoToken || this.undoLoading) {
                // No token / already running — silently ignore (per ADR-0003 §10).
                return;
            }
            event.preventDefault();
            this.triggerUndo();
        },
        isFocusInsideEditableInput() {
            const el = document.activeElement;
            if (!el) return false;
            const tag = (el.tagName || '').toLowerCase();
            if (tag === 'input' || tag === 'textarea' || tag === 'select') {
                return true;
            }
            if (el.isContentEditable) {
                return true;
            }
            return false;
        },
        triggerUndo() {
            if (!this.undoToken || this.undoLoading) {
                return;
            }
            this.undoLoading = true;
            axios.post('/senses/inline-confirmations/undo', {
                undo_token: this.undoToken,
                lemma: this.effectiveLemma,
                surface: this.surfaceWord,
                sentence: this.sentenceText,
                chapter_id: this.chapterId,
                sentence_index: this.sentenceIndex,
            }).then((response) => {
                const data = response && response.data;
                // Refresh the preview payload if the backend returned one.
                if (data && data.updated_preview) {
                    this.previewData = data.updated_preview;
                }
                // Clear the token so it cannot be replayed.
                this.undoToken = null;
                this.undoSnackbar = {
                    show: true,
                    text: '已撤销刚才的阅读判断。',
                    color: 'success',
                    timeout: 4000,
                };
            }).catch(() => {
                // Token invalid / expired / cross-user / cross-language.
                // Backend rejected — clear the token and show a light notice.
                this.undoToken = null;
                this.undoSnackbar = {
                    show: true,
                    text: '撤销失败：撤销令牌已过期或无效。',
                    color: 'error',
                    timeout: 4000,
                };
            }).finally(() => {
                this.undoLoading = false;
            });
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

.inline-preview-persisted-echo {
    font-size: 0.75rem;
}

.inline-preview-saved-match {
    color: var(--v-success-base);
    font-weight: 600;
}

.inline-preview-saved-not-match {
    color: var(--v-error-base);
    font-weight: 600;
}

/* Usage surface: per-sense reading confirmation summary
   (GLM-ReadingInlineConfirmationUsageSurface-AndMorphology-1000-1) */
.inline-preview-usage-summary {
    line-height: 1.5;
    border-left: 2px solid rgba(var(--v-primary-base), 0.35);
    padding-left: 6px;
}

.inline-preview-usage-match {
    color: var(--v-success-base);
    font-weight: 500;
}

.inline-preview-usage-not-match {
    color: var(--v-error-base);
    font-weight: 500;
}

.inline-preview-usage-last {
    font-size: 0.7rem;
}
</style>
