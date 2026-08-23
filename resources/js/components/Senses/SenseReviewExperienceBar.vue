<template>
    <v-sheet
        outlined
        rounded
        class="sense-review-experience-bar pa-2 mb-3 d-flex align-center flex-wrap"
        role="region"
        aria-label="复习体验工具条"
        data-testid="sense-review-experience-bar"
    >
        <template v-if="showTimer">
            <v-chip small outlined class="ma-1" aria-label="当前卡片用时">
                <v-icon small left>mdi-timer-outline</v-icon>本卡 {{ cardTime }}
            </v-chip>
            <v-chip small outlined class="ma-1" aria-label="本次会话用时">
                <v-icon small left>mdi-clock-outline</v-icon>会话 {{ sessionTime }}
            </v-chip>
            <v-chip small outlined class="ma-1">{{ phaseLabel }}</v-chip>
        </template>

        <v-btn
            v-if="autoAdvanceAvailable"
            small
            text
            class="experience-action ma-1"
            :color="autoAdvanceRunning ? 'warning' : 'primary'"
            :disabled="busy"
            :aria-pressed="String(autoAdvanceRunning)"
            @click="$emit('toggle-auto-advance')"
        >
            <v-icon small left>{{ autoAdvanceRunning ? 'mdi-stop-circle-outline' : 'mdi-play-circle-outline' }}</v-icon>
            {{ autoAdvanceRunning ? '停止自动推进' : '开始自动推进' }}
        </v-btn>
        <v-btn small text class="experience-action ma-1" :disabled="busy" @click="$emit('toggle-pause')">
            <v-icon small left>{{ paused ? 'mdi-play' : 'mdi-pause' }}</v-icon>{{ paused ? '继续计时' : '暂停' }}
        </v-btn>

        <v-spacer />
        <v-btn small text class="experience-action ma-1" :disabled="!previousAvailable || busy" @click="$emit('previous-card')">
            <v-icon small left>mdi-arrow-left</v-icon>返回
        </v-btn>
        <v-btn small text class="experience-action ma-1" :disabled="!forwardAvailable || busy" @click="$emit('next-card')">
            前进<v-icon small right>mdi-arrow-right</v-icon>
        </v-btn>
        <v-btn small text class="experience-action ma-1" :disabled="busy" @click="$emit('view-source')">
            <v-icon small left>mdi-book-open-page-variant</v-icon>原文
        </v-btn>
        <v-divider vertical class="mx-1 d-none d-sm-block" />
        <v-btn icon small class="experience-icon ma-1" :disabled="fontSize <= 16" aria-label="减小复习字号" @click="$emit('font-delta', -2)">
            <v-icon small>mdi-format-font-size-decrease</v-icon>
        </v-btn>
        <span class="text-caption mx-1" aria-label="当前复习字号">{{ fontSize }}px</span>
        <v-btn icon small class="experience-icon ma-1" :disabled="fontSize >= 32" aria-label="增大复习字号" @click="$emit('font-delta', 2)">
            <v-icon small>mdi-format-font-size-increase</v-icon>
        </v-btn>
        <v-btn icon small class="experience-icon ma-1" :color="highContrast ? 'primary' : undefined" :aria-pressed="String(highContrast)" aria-label="切换高对比度" @click="$emit('toggle-high-contrast')">
            <v-icon small>mdi-contrast-circle</v-icon>
        </v-btn>
        <v-btn icon small class="experience-icon ma-1" :color="reduceMotion ? 'primary' : undefined" :aria-pressed="String(reduceMotion)" aria-label="切换减少动画" @click="$emit('toggle-reduce-motion')">
            <v-icon small>mdi-motion-pause-outline</v-icon>
        </v-btn>
    </v-sheet>
</template>

<script>
export default {
    name: 'SenseReviewExperienceBar',
    props: {
        showTimer: { type: Boolean, default: false },
        cardTime: { type: String, default: '00:00' },
        sessionTime: { type: String, default: '00:00' },
        phaseLabel: { type: String, default: '问题面' },
        autoAdvanceAvailable: { type: Boolean, default: false },
        autoAdvanceRunning: { type: Boolean, default: false },
        paused: { type: Boolean, default: false },
        previousAvailable: { type: Boolean, default: false },
        forwardAvailable: { type: Boolean, default: false },
        busy: { type: Boolean, default: false },
        fontSize: { type: Number, default: 20 },
        highContrast: { type: Boolean, default: false },
        reduceMotion: { type: Boolean, default: false },
    },
};
</script>

<style>
.sense-review-experience-bar { gap: 2px; }
.sense-review-experience-bar .v-btn { min-width: 44px; min-height: 44px; }
@media (max-width: 600px) {
    .sense-review-experience-bar { align-items: stretch !important; }
    .sense-review-experience-bar > .spacer { display: none; }
    .experience-action { flex: 1 1 calc(50% - 8px); }
}
</style>
