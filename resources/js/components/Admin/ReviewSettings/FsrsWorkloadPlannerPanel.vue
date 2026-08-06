<template>
    <v-card outlined class="rounded-lg mt-4">
        <v-card-title class="subtitle-1">30 / 90 / 365 天工作量规划</v-card-title>
        <v-card-text aria-live="polite">
            <div class="body-2 mb-3">使用当前卡片的 stability、difficulty、retrievability、保持率、最大间隔和每日上限做只读估算。</div>
            <v-btn small outlined color="primary" :loading="loading" @click="load">生成工作量规划</v-btn>
            <v-alert v-if="error" dense outlined type="error" class="mt-3">{{ error }}</v-alert>
            <template v-if="planner">
                <v-alert v-for="warning in planner.warnings" :key="warning" dense outlined type="info" class="mt-3 mb-0">{{ warning }}</v-alert>
                <v-row v-if="planner.available" class="mt-2">
                    <v-col v-for="horizon in planner.horizons" :key="horizon.days" cols="12" md="4">
                        <v-sheet outlined rounded class="pa-4">
                            <div class="text-h6">{{ horizon.days }} 天</div>
                            <div>预计复习：<strong>{{ horizon.projected_reviews }}</strong></div>
                            <div>预计用时：<strong>{{ formatMinutes(horizon.projected_minutes) }}</strong></div>
                            <div>期末积压：<strong>{{ horizon.ending_backlog }}</strong></div>
                            <div>单日峰值：<strong>{{ horizon.peak_daily_load }}</strong></div>
                        </v-sheet>
                    </v-col>
                </v-row>
                <v-expansion-panels v-if="planner.assumptions" flat class="mt-3">
                    <v-expansion-panel>
                        <v-expansion-panel-header>查看估算假设</v-expansion-panel-header>
                        <v-expansion-panel-content>
                            <div>候选卡片：{{ planner.assumptions.candidate_cards }}</div>
                            <div>目标保持率：{{ percent(planner.assumptions.desired_retention) }}</div>
                            <div>最大间隔：{{ planner.assumptions.maximum_interval_days }} 天</div>
                            <div>每日复习上限：{{ planner.assumptions.daily_review_limit === null ? '未限制' : planner.assumptions.daily_review_limit }}</div>
                            <div>平均每题：{{ planner.assumptions.average_review_seconds }} 秒</div>
                            <div>平均难度：{{ planner.assumptions.average_difficulty === null ? '—' : planner.assumptions.average_difficulty }}</div>
                            <div>到期时平均可提取率：{{ percent(planner.assumptions.average_retrievability_at_due) }}</div>
                        </v-expansion-panel-content>
                    </v-expansion-panel>
                </v-expansion-panels>
            </template>
        </v-card-text>
    </v-card>
</template>

<script>
import * as AdminReviewSettingsApi from '../../../services/AdminReviewSettingsApi';

export default {
    data: () => ({ loading: false, error: '', planner: null }),
    methods: {
        load() {
            this.loading = true;
            this.error = '';
            this.planner = null;
            AdminReviewSettingsApi.simulateRetentionWorkload()
                .then(response => { this.planner = response.data.planner; })
                .catch(() => { this.error = '工作量规划加载失败，请稍后重试。'; })
                .finally(() => { this.loading = false; });
        },
        formatMinutes(value) {
            if (value < 60) return `${value} 分钟`;
            return `${(value / 60).toFixed(1)} 小时`;
        },
        percent(value) {
            return value === null || value === undefined ? '—' : `${(Number(value) * 100).toFixed(1)}%`;
        },
    },
};
</script>
