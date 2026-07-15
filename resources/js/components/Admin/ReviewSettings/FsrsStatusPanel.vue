<template>
    <v-card outlined class="rounded-lg mt-4" :loading="loading">
        <v-card-title>当前 FSRS 状态</v-card-title>
        <v-card-subtitle>仅统计当前语言下的词义复习卡，不包含旧单词卡。</v-card-subtitle>
        <v-card-text>
            <v-alert v-if="error" type="error" dense outlined>{{ error }}</v-alert>
            <div v-else-if="!loading && stats.total === 0" class="text--secondary py-4 text-center">当前没有词义复习卡。</div>
            <div v-else>
                <div class="text-caption text--secondary mb-2">概况</div>
                <v-row dense class="mb-4">
                    <v-col v-for="item in summaryItems" :key="item.label" cols="6" sm="3">
                        <v-sheet outlined rounded class="pa-3 text-center">
                            <div class="text-h6 font-weight-bold">{{ item.value }}</div>
                            <div class="text-caption text--secondary">{{ item.label }}</div>
                        </v-sheet>
                    </v-col>
                </v-row>
                <div class="text-caption text--secondary mb-2">状态分布</div>
                <v-row dense class="mb-4">
                    <v-col v-for="item in stateItems" :key="item.label" cols="6" sm="3">
                        <v-sheet outlined rounded class="pa-3 text-center">
                            <div class="text-h6 font-weight-bold">{{ item.value }}</div>
                            <div class="text-caption text--secondary">{{ item.label }}</div>
                        </v-sheet>
                    </v-col>
                </v-row>
                <div class="text-caption text--secondary mb-2">FSRS 熟练度</div>
                <v-row dense>
                    <v-col v-for="item in metricItems" :key="item.label" cols="6" sm="4" md="2">
                        <v-sheet outlined rounded class="pa-3 text-center">
                            <div class="text-h6 font-weight-bold">{{ item.value }}</div>
                            <div class="text-caption text--secondary">{{ item.label }}</div>
                        </v-sheet>
                    </v-col>
                </v-row>
                <div class="caption grey--text mt-2">稳定度越高，记忆越稳定；难度越高，这张卡越难。</div>
            </div>
        </v-card-text>
    </v-card>
</template>

<script>
import * as AdminReviewSettingsApi from '../../../services/AdminReviewSettingsApi';

const emptyStats = () => ({
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
});

export default {
    props: {
        refreshKey: {
            type: Number,
            default: 0,
        },
    },
    data() {
        return {
            loading: false,
            error: '',
            stats: emptyStats(),
        };
    },
    computed: {
        summaryItems() {
            return [
                { label: '总词义卡', value: this.stats.total },
                { label: '启用中', value: this.stats.enabled },
                { label: '已归档', value: this.stats.archived },
                { label: '当前到期', value: this.stats.due },
            ];
        },
        stateItems() {
            const states = this.stats.by_state || emptyStats().by_state;
            return [
                { label: '新卡', value: states.new },
                { label: '学习中', value: states.learning },
                { label: '复习中', value: states.review },
                { label: '重新学习', value: states.relearning },
            ];
        },
        metricItems() {
            return [
                { label: '平均稳定度', value: this.formatFloat(this.stats.average_stability) },
                { label: '平均难度', value: this.formatFloat(this.stats.average_difficulty) },
                { label: '总遗忘次数', value: this.stats.lapses_total },
                { label: '今日已复习', value: this.stats.reviewed_today },
                { label: '今日重置', value: this.stats.reset_count },
            ];
        },
    },
    watch: {
        refreshKey() {
            this.loadStats();
        },
    },
    mounted() {
        this.loadStats();
    },
    methods: {
        formatFloat(value) {
            return value === null || value === undefined ? '—' : Number(value).toFixed(2);
        },
        loadStats() {
            this.loading = true;
            this.error = '';
            AdminReviewSettingsApi.getReviewCardStats()
                .then(response => {
                    this.stats = response.data;
                    this.$emit('stats-loaded', response.data);
                })
                .catch(() => { this.error = 'FSRS 统计加载失败。'; })
                .finally(() => { this.loading = false; });
        },
    },
};
</script>
