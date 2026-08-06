<template>
    <div class="sense-review-experience-controller">
        <SenseReviewExperienceBar
            :show-timer="normalizedExperience.show_timer"
            :card-time="cardTime"
            :session-time="sessionTime"
            :phase-label="phaseLabel"
            :auto-advance-available="autoAdvanceAvailable"
            :auto-advance-running="autoAdvanceRunning"
            :paused="snapshot.paused"
            :previous-available="previousAvailable"
            :busy="busy"
            :font-size="preferences.fontSize"
            :high-contrast="preferences.highContrast"
            :reduce-motion="preferences.reduceMotion"
            @toggle-auto-advance="toggleAutoAdvance"
            @toggle-pause="togglePause"
            @previous-card="$emit('previous-card')"
            @view-source="$emit('view-source')"
            @bury="$emit('bury')"
            @font-delta="changeFontSize"
            @toggle-high-contrast="toggleHighContrast"
            @toggle-reduce-motion="toggleReduceMotion"
        >
            <template #marker>
                <ReviewCardMarkerPicker
                    v-if="cardId"
                    dense
                    :card-id="cardId"
                    :marker="card.marker"
                    :disabled="busy"
                    @updated="$emit('marker-updated', $event)"
                    @notify="notify"
                />
            </template>
            <template #tag>
                <WordSenseTagBulkPicker
                    v-if="cardId"
                    :review-card-ids="[cardId]"
                    :tags="availableTags"
                    button-label="标签"
                    title="修改当前卡内容标签"
                    @notify="notify"
                />
            </template>
        </SenseReviewExperienceBar>
        <div class="sr-only" aria-live="polite" aria-atomic="true">{{ announcement }}</div>
    </div>
</template>

<script>
import SenseReviewExperienceBar from './SenseReviewExperienceBar.vue';
import ReviewCardMarkerPicker from '../ReviewCards/ReviewCardMarkerPicker.vue';
import WordSenseTagBulkPicker from '../ReviewCards/WordSenseTagBulkPicker.vue';
import {
    autoAdvanceAction,
    createExperienceSession,
    experienceSnapshot,
    formatExperienceDuration,
    normalizeExperienceConfig,
    pauseExperience,
    resumeExperience,
    setExperiencePhase,
    startExperienceCard,
} from '../Review/ReviewExperienceTimer.js';
import {
    loadReviewExperiencePreferences,
    saveReviewExperiencePreferences,
} from '../Review/ReviewExperiencePreferences.js';

export default {
    name: 'SenseReviewExperienceController',
    components: { SenseReviewExperienceBar, ReviewCardMarkerPicker, WordSenseTagBulkPicker },
    props: {
        experience: { type: Object, default: null },
        card: { type: Object, default: null },
        showAnswer: { type: Boolean, default: false },
        busy: { type: Boolean, default: false },
        overlayOpen: { type: Boolean, default: false },
        previousAvailable: { type: Boolean, default: false },
    },
    data() {
        return {
            timer: createExperienceSession(undefined, document.visibilityState !== 'hidden'),
            snapshot: {
                sessionElapsedMs: 0, cardElapsedMs: 0, phaseElapsedMs: 0,
                phase: 'question', paused: false, pauseReasons: [],
            },
            tickId: null,
            autoAdvanceRunning: false,
            announcement: '',
            availableTags: [],
            preferences: loadReviewExperiencePreferences(
                window.localStorage,
                window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches,
            ),
        };
    },
    computed: {
        normalizedExperience() { return normalizeExperienceConfig(this.experience); },
        cardId() {
            const id = Number(this.card?.review_card_id);
            return Number.isInteger(id) && id > 0 ? id : null;
        },
        autoAdvanceAvailable() {
            return this.normalizedExperience.auto_advance_enabled
                && (this.normalizedExperience.question_timer_seconds > 0
                    || this.normalizedExperience.answer_timer_seconds > 0);
        },
        cardTime() { return formatExperienceDuration(this.snapshot.cardElapsedMs); },
        sessionTime() { return formatExperienceDuration(this.snapshot.sessionElapsedMs); },
        phaseLabel() { return this.snapshot.phase === 'answer' ? '答案面' : '问题面'; },
    },
    watch: {
        cardId: {
            immediate: true,
            handler(value, previous) {
                const id = Number(value);
                if (Number.isInteger(id) && id > 0 && id !== Number(previous)) {
                    startExperienceCard(this.timer, id);
                    resumeExperience(this.timer, 'no_card');
                    resumeExperience(this.timer, 'answer_elapsed');
                    this.announcement = '新卡片已显示。';
                    this.$emit('card-started');
                } else if (!Number.isInteger(id) || id <= 0) {
                    pauseExperience(this.timer, 'no_card');
                }
                this.refresh();
            },
        },
        showAnswer(value) {
            if (!value) return;
            setExperiencePhase(this.timer, 'answer');
            this.refresh();
            this.announcement = '答案已显示，请选择评分。';
        },
        busy: {
            immediate: true,
            handler(value) {
                if (value) pauseExperience(this.timer, 'busy');
                else resumeExperience(this.timer, 'busy');
                this.refresh();
            },
        },
        overlayOpen: {
            immediate: true,
            handler(value) {
                if (value) pauseExperience(this.timer, 'overlay');
                else resumeExperience(this.timer, 'overlay');
                this.refresh();
            },
        },
        autoAdvanceAvailable(value) {
            if (!value) this.autoAdvanceRunning = false;
        },
    },
    mounted() {
        this.$emit('preferences-change', { ...this.preferences });
        this.loadAvailableTags();
        document.addEventListener('visibilitychange', this.onVisibilityChange);
        this.tickId = window.setInterval(this.tick, 250);
    },
    beforeDestroy() {
        document.removeEventListener('visibilitychange', this.onVisibilityChange);
        if (this.tickId !== null) window.clearInterval(this.tickId);
    },
    methods: {
        notify(text, color) { this.$emit('notify', text, color); },
        loadAvailableTags() {
            axios.get('/review-cards/manage/tags').then((response) => {
                this.availableTags = Array.isArray(response.data?.items) ? response.data.items : [];
            }).catch(() => {
                this.availableTags = [];
            });
        },
        refresh() { this.snapshot = experienceSnapshot(this.timer); },
        tick() {
            this.refresh();
            if (!this.autoAdvanceRunning || this.snapshot.paused || !this.cardId) return;
            const action = autoAdvanceAction(
                this.normalizedExperience,
                this.snapshot.phase,
                this.snapshot.phaseElapsedMs,
            );
            if (action === 'reveal_answer' && !this.showAnswer) {
                this.announcement = '问题计时结束，已自动显示答案。请人工选择评分。';
                this.$emit('reveal-answer');
            } else if (action === 'wait_for_rating' && this.showAnswer) {
                this.autoAdvanceRunning = false;
                pauseExperience(this.timer, 'answer_elapsed');
                this.refresh();
                this.announcement = '答案计时结束。自动推进已暂停，请人工选择评分。';
                this.$emit('focus-rating');
            }
        },
        toggleAutoAdvance() {
            if (!this.autoAdvanceAvailable) return;
            this.autoAdvanceRunning = !this.autoAdvanceRunning;
            if (this.autoAdvanceRunning) {
                resumeExperience(this.timer, 'answer_elapsed');
                this.announcement = '自动推进已开始；它只显示答案，不会自动评分。';
                this.tick();
            } else {
                this.announcement = '自动推进已停止。';
            }
        },
        togglePause() {
            const manuallyPaused = this.timer.pauseReasons.includes('manual');
            const answerElapsed = this.timer.pauseReasons.includes('answer_elapsed');
            if (manuallyPaused) {
                resumeExperience(this.timer, 'manual');
            } else if (answerElapsed) {
                resumeExperience(this.timer, 'answer_elapsed');
            } else if (!this.snapshot.paused) {
                pauseExperience(this.timer, 'manual');
            }
            this.refresh();
            this.announcement = this.snapshot.paused ? '复习计时已暂停。' : '复习计时已继续。';
        },
        onVisibilityChange() {
            if (document.visibilityState === 'hidden') pauseExperience(this.timer, 'visibility');
            else resumeExperience(this.timer, 'visibility');
            this.refresh();
        },
        updatePreferences(next) {
            this.preferences = saveReviewExperiencePreferences(window.localStorage, next);
            this.$emit('preferences-change', { ...this.preferences });
        },
        changeFontSize(delta) {
            this.updatePreferences({
                ...this.preferences,
                fontSize: Math.min(32, Math.max(16, this.preferences.fontSize + delta)),
            });
        },
        toggleHighContrast() {
            this.updatePreferences({ ...this.preferences, highContrast: !this.preferences.highContrast });
        },
        toggleReduceMotion() {
            this.updatePreferences({ ...this.preferences, reduceMotion: !this.preferences.reduceMotion });
        },
    },
};
</script>

<style scoped>
.sr-only {
    position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
    overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0;
}
</style>

<style>
.sense-review-page.review-high-contrast .sense-review-card,
.sense-review-page.review-high-contrast .sense-review-summary,
.sense-review-page.review-high-contrast .sense-review-experience-bar {
    border-width: 2px !important;
    border-color: currentColor !important;
}
.sense-review-page.review-high-contrast button:focus-visible,
.sense-review-page.review-high-contrast [tabindex]:focus-visible {
    outline: 3px solid var(--v-primary-base) !important;
    outline-offset: 2px;
}
.sense-review-page.review-reduce-motion *,
.sense-review-page.review-reduce-motion *::before,
.sense-review-page.review-reduce-motion *::after {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
    scroll-behavior: auto !important;
}
</style>
