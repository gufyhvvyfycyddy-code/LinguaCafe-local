<template>
    <!--
        SenseReviewRatingControls
        SenseReview-RatingControls-1000-1 / IntervalPreview-1000-5

        The four rating buttons (忘了 / 勉强记得 / 记得 / 很熟) plus the
        hotkey hint line. Pure presentational component: emits a single
        'rating' event with one of 'again' | 'hard' | 'good' | 'easy'.

        Contract:
          - Does NOT call any API directly.
          - Does NOT implement any FSRS logic.
          - Does NOT write ReviewLog.
          - The parent owns the actual rate() method and API call; this
            component only emits the chosen rating.
          - The 'disabled' prop covers all loading/locked states (rating
            in progress, archive/reset/delete loading) so the buttons
            cannot be double-clicked during an async operation.
          - Labels, colors, hotkeys and scores come from
            SenseReviewRatingPresentation.js (single source of truth).
          - Interval preview text/tooltips come from the parent via
            intervalPreviews prop (normalized by
            SenseReviewIntervalPresentation.js). This component does
            NOT fetch or compute intervals.
          - Preview loading NEVER disables the rating buttons: the user
            can still rate before the preview arrives.
          - Preview error shows a single shared hint above the buttons;
            the four buttons remain fully usable.
    -->
    <div>
        <div class="text-center caption grey--text mb-2">
            {{ hotkeyHint }}
        </div>
        <v-alert
            v-if="previewError"
            type="warning"
            dense
            text
            class="text-center mb-2"
        >{{ previewError }}</v-alert>
        <div class="d-flex justify-center flex-wrap mt-6">
            <v-btn
                v-for="rating in ratings"
                :key="rating.value"
                depressed
                rounded
                :color="rating.color"
                class="ma-2 rating-btn"
                :disabled="disabled"
                :title="intervalTooltip(rating.value)"
                @click="$emit('rating', rating.value)"
            >
                <div class="d-flex flex-column align-center">
                    <div>{{ rating.label }}</div>
                    <div
                        v-if="intervalText(rating.value)"
                        class="interval-text"
                    >{{ intervalText(rating.value) }}</div>
                    <div
                        v-else-if="previewLoading"
                        class="interval-text"
                    >计算中…</div>
                </div>
            </v-btn>
        </div>
    </div>
</template>

<script>
    import { RATING_PRESENTATION, hotkeyHintText } from './SenseReviewRatingPresentation.js';

    /**
     * SenseReviewRatingControls
     *
     * Pure presentational component. Renders the four rating buttons and
     * a hotkey hint driven by SenseReviewRatingPresentation. Emits a
     * single 'rating' event; the parent handles the actual API call.
     *
     * Interval preview display (1000-5):
     *   - intervalPreviews: normalized map { again: {text, tooltip}, ... }
     *     or null when no preview has been loaded yet.
     *   - previewLoading: true while the preview GET is in flight.
     *   - previewError: non-empty string when the preview request failed.
     *   The buttons are NEVER disabled by preview state; the user can
     *   always rate immediately.
     */
    export default {
        name: 'SenseReviewRatingControls',
        props: {
            // True when a rating or any card operation is in progress.
            // Disables all four buttons to prevent double-submit.
            disabled: {
                type: Boolean,
                default: false,
            },
            // Normalized interval preview map from
            // SenseReviewIntervalPresentation.normalizeIntervalPreview,
            // or null when no preview is available.
            intervalPreviews: {
                type: Object,
                default: null,
            },
            // True while the interval-preview GET is in flight. Shows
            // "计算中…" on buttons that don't yet have preview text.
            previewLoading: {
                type: Boolean,
                default: false,
            },
            // Non-empty string when the preview request failed. Shows a
            // single shared warning above the buttons.
            previewError: {
                type: String,
                default: '',
            },
        },
        // Emits: 'rating' with value 'again' | 'hard' | 'good' | 'easy'.
        data() {
            return {
                ratings: RATING_PRESENTATION,
                hotkeyHint: hotkeyHintText(),
            };
        },
        methods: {
            intervalText(value) {
                if (!this.intervalPreviews || !this.intervalPreviews[value]) {
                    return '';
                }
                return this.intervalPreviews[value].text || '';
            },
            intervalTooltip(value) {
                if (!this.intervalPreviews || !this.intervalPreviews[value]) {
                    return '';
                }
                return this.intervalPreviews[value].tooltip || '';
            },
        },
    }
</script>

<style scoped>
    .interval-text {
        font-size: 0.75rem;
        opacity: 0.85;
        line-height: 1.2;
        margin-top: 2px;
    }
    .rating-btn {
        height: auto !important;
        min-height: 48px;
        padding-top: 8px;
        padding-bottom: 8px;
    }
</style>
