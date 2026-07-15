<template>
    <div id="admin-review-settings">
        <div class="subheader mt-4">间隔重复系统</div>

        <current-review-settings-preset :language="language" />
        <fsrs-goal-settings-panel :fsrs-stats="fsrsStats" />
        <fsrs-queue-order-settings-panel />
        <fsrs-status-panel
            :refresh-key="statsRefreshKey"
            @stats-loaded="handleStatsLoaded"
        />
        <fsrs-advanced-tools-panel @stats-changed="refreshStats" />
        <legacy-srs-settings-panel />
    </div>
</template>

<script>
import FsrsGoalSettingsPanel from './ReviewSettings/FsrsGoalSettingsPanel.vue';
import CurrentReviewSettingsPreset from './ReviewSettings/CurrentReviewSettingsPreset.vue';
import FsrsQueueOrderSettingsPanel from './ReviewSettings/FsrsQueueOrderSettingsPanel.vue';
import FsrsStatusPanel from './ReviewSettings/FsrsStatusPanel.vue';
import FsrsAdvancedToolsPanel from './ReviewSettings/FsrsAdvancedToolsPanel.vue';
import LegacySrsSettingsPanel from './ReviewSettings/LegacySrsSettingsPanel.vue';

export default {
    components: {
        CurrentReviewSettingsPreset,
        FsrsGoalSettingsPanel,
        FsrsQueueOrderSettingsPanel,
        FsrsStatusPanel,
        FsrsAdvancedToolsPanel,
        LegacySrsSettingsPanel,
    },
    props: {
        language: String,
    },
    data() {
        return {
            statsRefreshKey: 0,
            fsrsStats: {
                total: 0,
                enabled: 0,
                archived: 0,
                due: 0,
                by_state: { new: 0, learning: 0, review: 0, relearning: 0 },
                average_stability: null,
                average_difficulty: null,
                lapses_total: 0,
                reviewed_today: 0,
                reset_count: 0,
            },
        };
    },
    methods: {
        handleStatsLoaded(stats) {
            this.fsrsStats = stats;
        },
        refreshStats() {
            this.statsRefreshKey += 1;
        },
    },
};
</script>
