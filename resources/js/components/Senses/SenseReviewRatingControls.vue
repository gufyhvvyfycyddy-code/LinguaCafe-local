<template>
    <!--
        SenseReviewRatingControls
        SenseReview-RatingControls-1000-1

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
    -->
    <div>
        <div class="text-center caption grey--text mb-2">
            {{ hotkeyHint }}
        </div>
        <div class="d-flex justify-center flex-wrap mt-6">
            <v-btn
                v-for="rating in ratings"
                :key="rating.value"
                depressed
                rounded
                :color="rating.color"
                class="ma-2"
                :disabled="disabled"
                @click="$emit('rating', rating.value)"
            >{{ rating.label }}</v-btn>
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
        },
        // Emits: 'rating' with value 'again' | 'hard' | 'good' | 'easy'.
        data() {
            return {
                ratings: RATING_PRESENTATION,
                hotkeyHint: hotkeyHintText(),
            };
        },
    }
</script>
