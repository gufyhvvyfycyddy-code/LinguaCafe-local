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
    -->
    <div>
        <div class="text-center caption grey--text mb-2">
            快捷键：1 忘了 / 2 勉强 / 3 记得 / 4 很熟
        </div>
        <div class="d-flex justify-center flex-wrap mt-6">
            <v-btn depressed rounded color="error" class="ma-2" :disabled="disabled" @click="$emit('rating', 'again')">忘了</v-btn>
            <v-btn depressed rounded color="warning" class="ma-2" :disabled="disabled" @click="$emit('rating', 'hard')">勉强记得</v-btn>
            <v-btn depressed rounded color="primary" class="ma-2" :disabled="disabled" @click="$emit('rating', 'good')">记得</v-btn>
            <v-btn depressed rounded color="success" class="ma-2" :disabled="disabled" @click="$emit('rating', 'easy')">很熟</v-btn>
        </div>
    </div>
</template>

<script>
    /**
     * SenseReviewRatingControls
     *
     * SenseReview-RatingControls-1000-1
     *
     * Pure presentational component extracted from SenseReview.vue.
     * Renders the four rating buttons and a hotkey hint. Emits a single
     * 'rating' event; the parent handles the actual API call.
     *
     * Why a separate component:
     *  - Keeps the button styling and hotkey hint in one place.
     *  - Makes it explicit that the buttons never call the backend
     *    directly (no axios import, no FSRS logic).
     *  - The parent's rate() method stays the single source of truth
     *    for rating semantics and session tracking.
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
        // Declared so IDE/tooling can detect the event contract.
    }
</script>
