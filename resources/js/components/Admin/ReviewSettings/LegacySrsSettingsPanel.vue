<template>
    <v-expansion-panels flat class="mt-4">
        <v-expansion-panel>
            <v-expansion-panel-header>旧版 SRS 设置（仅影响旧单词卡和短语）</v-expansion-panel-header>
            <v-expansion-panel-content>
                <v-alert border="left" type="warning" color="warning" class="mb-4">
                    这些设置不会影响词义复习卡。当前词义复习卡使用 FSRS 调度。
                </v-alert>
                <v-card outlined class="rounded-lg" :loading="loading">
                    <v-card-text>
                        <v-simple-table dense class="no-hover no-lines">
                            <tbody>
                                <tr v-for="(interval, index) in reviewIntervals" :key="index">
                                    <td class="pt-4">等级 {{ interval.name }}：</td>
                                    <td class="pt-4">
                                        <v-text-field
                                            v-model="interval.values"
                                            filled
                                            rounded
                                            dense
                                            hide-details
                                            :disabled="!index"
                                            @change="reviewIntervalChanged($event, index)"
                                        />
                                    </td>
                                </tr>
                            </tbody>
                        </v-simple-table>
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer />
                        <v-btn rounded depressed color="primary" :disabled="loading || saving || !reviewIntervals.length" :loading="saving" @click="saveSettings">
                            保存
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-expansion-panel-content>
        </v-expansion-panel>
    </v-expansion-panels>
</template>

<script>
import * as AdminReviewSettingsApi from '../../../services/AdminReviewSettingsApi';

export default {
    data() {
        return {
            loading: false,
            saving: false,
            reviewIntervals: [],
        };
    },
    mounted() {
        this.loadSettings();
    },
    methods: {
        loadSettings() {
            this.loading = true;
            this.reviewIntervals = [];
            AdminReviewSettingsApi.getGlobalSettings(['reviewIntervals'])
                .then(response => {
                    Object.keys(response.data.reviewIntervals).forEach(key => {
                        this.reviewIntervals.push({
                            name: String(key * -1),
                            values: response.data.reviewIntervals[key].join(','),
                        });
                    });
                })
                .finally(() => { this.loading = false; this.saving = false; });
        },
        reviewIntervalChanged(value, index) {
            let intervals = value.length ? value.split(',') : [1];
            intervals = intervals.map(item => {
                const parsed = parseInt(item);
                return Math.max(1, Math.min(3650, Number.isNaN(parsed) ? 1 : parsed));
            });
            this.reviewIntervals[index].name = String(7 - index);
            this.reviewIntervals[index].values = intervals.join(',');
            this.$forceUpdate();
        },
        saveSettings() {
            this.saving = true;
            const reviewIntervals = {};
            this.reviewIntervals.forEach(interval => {
                reviewIntervals[parseInt(interval.name) * -1] = interval.values.split(',').map(Number);
            });
            AdminReviewSettingsApi.updateGlobalSettings({ reviewIntervals })
                .then(() => this.loadSettings())
                .catch(() => { this.saving = false; });
        },
    },
};
</script>
