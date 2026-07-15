<template>
    <v-card outlined class="rounded-lg mt-4">
        <v-card-title>复习目标</v-card-title>
        <v-card-subtitle>设置目标记忆保持率和每日学习上限。</v-card-subtitle>
        <v-card-text>
            <v-row dense>
                <v-col cols="12" md="5">
                    <div class="font-weight-bold mb-2">Desired Retention</div>
                    <v-select
                        v-model="desiredRetention"
                        :items="retentionOptions"
                        item-text="text"
                        item-value="value"
                        outlined
                        dense
                        hide-details
                        style="max-width: 180px;"
                    />
                    <div class="caption grey--text text--darken-1 mt-2">
                        <strong>{{ desiredRetentionText }}</strong> — {{ retentionExplanation }}
                        <v-chip v-if="desiredRetention === 0.90" x-small outlined color="success">推荐默认值</v-chip>
                    </div>
                    <div class="caption grey--text mt-2">
                        保持率越高，复习负担越重。保存后只影响新的评分，不会自动重排已有卡片。
                    </div>
                    <v-btn
                        class="mt-3"
                        small
                        rounded
                        depressed
                        color="primary"
                        :loading="retentionSaving"
                        :disabled="retentionSaving"
                        @click="saveRetention"
                    >保存 FSRS 设置</v-btn>
                    <v-alert v-if="retentionSaveStatus" dense outlined :type="retentionSaveError ? 'error' : 'success'" class="mt-3 mb-0">
                        {{ retentionSaveStatus }}
                    </v-alert>
                </v-col>

                <v-col cols="12" md="7">
                    <div class="pa-3 rounded" style="background: #f5f7fa;">
                        <div class="font-weight-medium body-2">每天大概要复习多少</div>
                        <div class="body-1 grey--text text--darken-1 mt-1">{{ burdenEstimateMessage }}</div>
                        <div class="caption grey--text mt-1">粗略预估，仅帮助感受负担，不会重排已有卡片。</div>
                    </div>
                    <v-btn
                        class="mt-3"
                        small
                        outlined
                        color="primary"
                        :loading="simulationLoading"
                        :disabled="simulationLoading"
                        @click="loadSimulation"
                    >查看不同保持率的复习量</v-btn>
                </v-col>
            </v-row>

            <div v-if="simulation" class="mt-4">
                <v-alert v-if="!simulation.simulation_available" dense outlined type="warning" class="mb-0">
                    {{ simulation.warnings ? simulation.warnings[0] : 'FSRS 扩展未加载，暂时无法生成工作量模拟。' }}
                </v-alert>
                <div v-else>
                    <div class="font-weight-medium body-2 mb-2">保持率工作量模拟</div>
                    <div class="caption grey--text mb-2">基于 {{ simulation.total_candidates }} 张复习中卡片估算，不会修改卡片。</div>
                    <v-simple-table dense class="no-hover">
                        <thead>
                            <tr>
                                <th>保持率</th>
                                <th class="text-right">今天到期</th>
                                <th class="text-right">未来 7 天</th>
                                <th class="text-right">变化</th>
                                <th>建议</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(option, index) in simulation.options" :key="index" :class="option.is_current ? 'primary lighten-5' : ''">
                                <td>{{ option.label }}</td>
                                <td class="text-right">{{ option.today_due }}</td>
                                <td class="text-right">{{ option.next7_due }}</td>
                                <td class="text-right">{{ option.next7_delta_vs_current > 0 ? '+' : '' }}{{ option.next7_delta_vs_current }}</td>
                                <td>{{ option.recommendation }}<div class="caption grey--text">{{ option.message }}</div></td>
                            </tr>
                        </tbody>
                    </v-simple-table>
                </div>
            </div>

            <v-divider class="my-5" />
            <div class="font-weight-medium body-2 mb-1">每日学习上限</div>
            <div class="caption grey--text mb-3">控制每天引入新卡和显示复习卡的数量。</div>
            <v-row dense align="center">
                <v-col cols="12" md="4">
                    <v-switch v-model="dailyNewLimitEnabled" label="启用每日新学上限" dense hide-details class="mt-0" />
                    <v-text-field v-model.number="dailyNewLimit" :disabled="!dailyNewLimitEnabled" type="number" min="0" max="999" outlined dense hide-details class="mt-2" />
                </v-col>
                <v-col cols="12" md="4">
                    <v-switch v-model="dailyReviewLimitEnabled" label="启用每日复习上限" dense hide-details class="mt-0" />
                    <v-text-field v-model.number="dailyReviewLimit" :disabled="!dailyReviewLimitEnabled" type="number" min="0" max="9999" outlined dense hide-details class="mt-2" />
                </v-col>
                <v-col cols="12" md="4">
                    <v-switch v-model="newCardsIgnoreReviewLimit" label="新卡无视复习上限" dense hide-details class="mt-0" />
                    <div class="caption grey--text mt-2">关闭时优先处理旧卡，避免积压时继续增加新卡压力。</div>
                </v-col>
            </v-row>
            <v-btn class="mt-3" small outlined color="primary" :loading="limitsSaving" :disabled="limitsSaving" @click="saveLimits">
                保存每日上限设置
            </v-btn>
            <v-alert v-if="limitsStatus" dense outlined :type="limitsError ? 'error' : 'success'" class="mt-3 mb-0">
                {{ limitsStatus }}
            </v-alert>
        </v-card-text>
    </v-card>
</template>

<script>
import * as AdminReviewSettingsApi from '../../../services/AdminReviewSettingsApi';

export default {
    props: {
        fsrsStats: {
            type: Object,
            required: true,
        },
    },
    data() {
        return {
            desiredRetention: 0.90,
            retentionSaving: false,
            retentionSaveStatus: '',
            retentionSaveError: false,
            simulationLoading: false,
            simulation: null,
            dailyNewLimitEnabled: true,
            dailyNewLimit: 20,
            dailyReviewLimitEnabled: true,
            dailyReviewLimit: 200,
            newCardsIgnoreReviewLimit: false,
            limitsSaving: false,
            limitsStatus: '',
            limitsError: false,
            retentionOptions: [
                { text: '70%', value: 0.70 }, { text: '75%', value: 0.75 },
                { text: '80%', value: 0.80 }, { text: '85%', value: 0.85 },
                { text: '90%', value: 0.90 }, { text: '92%', value: 0.92 },
                { text: '95%', value: 0.95 }, { text: '97%', value: 0.97 },
            ],
        };
    },
    computed: {
        desiredRetentionText() {
            const option = this.retentionOptions.find(item => item.value === this.desiredRetention);
            return option ? option.text : '';
        },
        retentionExplanation() {
            const messages = {
                0.70: '复习压力最低，但会更容易忘。', 0.75: '复习量较少，适合轻量学习。',
                0.80: '偏轻松，复习次数较少。', 0.85: '负担适中偏轻。',
                0.90: '记忆效果和复习负担比较平衡。', 0.92: '记得更稳，复习会增加。',
                0.95: '追求更牢固记忆，负担明显增加。', 0.97: '保持率很高，复习压力可能很大。',
            };
            return messages[this.desiredRetention] || '';
        },
        burdenEstimateMessage() {
            const enabled = Number(this.fsrsStats.enabled || 0);
            if (enabled === 0) return '现在还没有启用中的词义卡，先不用担心复习负担。';
            const multipliers = { 0.70: 0.55, 0.75: 0.65, 0.80: 0.78, 0.85: 0.90, 0.90: 1, 0.92: 1.15, 0.95: 1.45, 0.97: 1.90 };
            const baseline = Math.max(Number(this.fsrsStats.due || 0), Number(this.fsrsStats.reviewed_today || 0), Math.ceil(enabled * 0.03));
            const estimate = Math.ceil(baseline * (multipliers[this.desiredRetention] || 1));
            const low = Math.max(0, Math.floor(estimate * 0.8));
            const high = Math.max(low, Math.ceil(estimate * 1.25));
            return `按当前数据粗略看，每天大约复习 ${low}-${high} 张。`;
        },
    },
    mounted() {
        this.loadSettings();
        this.loadLimits();
    },
    methods: {
        loadSettings() {
            AdminReviewSettingsApi.getGlobalSettings(['fsrsDesiredRetention']).then(response => {
                if (response.data.fsrsDesiredRetention !== undefined && response.data.fsrsDesiredRetention !== null) {
                    this.desiredRetention = response.data.fsrsDesiredRetention;
                }
            });
        },
        saveRetention() {
            this.retentionSaving = true;
            this.retentionSaveStatus = '';
            AdminReviewSettingsApi.updateGlobalSettings({ fsrsDesiredRetention: this.desiredRetention })
                .then(() => {
                    this.retentionSaveError = false;
                    this.retentionSaveStatus = 'FSRS 设置已保存。新的复习评分会使用该目标保持率；已排程卡片不会自动重排。';
                })
                .catch(() => {
                    this.retentionSaveError = true;
                    this.retentionSaveStatus = '保存失败，请重试。';
                })
                .finally(() => { this.retentionSaving = false; });
        },
        loadSimulation() {
            this.simulationLoading = true;
            this.simulation = null;
            AdminReviewSettingsApi.simulateRetentionWorkload()
                .then(response => { this.simulation = response.data; })
                .catch(() => { this.simulation = { simulation_available: false, warnings: ['加载失败，请稍后再试。'], options: [] }; })
                .finally(() => { this.simulationLoading = false; });
        },
        loadLimits() {
            AdminReviewSettingsApi.getDailyLimits().then(response => {
                const data = response.data;
                this.dailyNewLimitEnabled = data.daily_new_limit_enabled;
                this.dailyNewLimit = data.daily_new_limit;
                this.dailyReviewLimitEnabled = data.daily_review_limit_enabled;
                this.dailyReviewLimit = data.daily_review_limit;
                this.newCardsIgnoreReviewLimit = data.new_cards_ignore_review_limit;
            }).catch(() => {});
        },
        saveLimits() {
            this.limitsSaving = true;
            this.limitsStatus = '';
            this.limitsError = false;
            AdminReviewSettingsApi.updateDailyLimits({
                daily_new_limit_enabled: this.dailyNewLimitEnabled,
                daily_new_limit: this.dailyNewLimit,
                daily_review_limit_enabled: this.dailyReviewLimitEnabled,
                daily_review_limit: this.dailyReviewLimit,
                new_cards_ignore_review_limit: this.newCardsIgnoreReviewLimit,
            }).then(response => {
                const data = response.data;
                this.dailyNewLimitEnabled = data.daily_new_limit_enabled;
                this.dailyNewLimit = data.daily_new_limit;
                this.dailyReviewLimitEnabled = data.daily_review_limit_enabled;
                this.dailyReviewLimit = data.daily_review_limit;
                this.newCardsIgnoreReviewLimit = data.new_cards_ignore_review_limit;
                this.limitsStatus = data.message || '每日上限设置已保存。';
            }).catch(error => {
                this.limitsError = true;
                const message = error.response?.data?.message || '保存失败，请稍后再试。';
                const errors = error.response?.data?.errors;
                this.limitsStatus = errors ? `${message} ${Object.values(errors).join(' ')}` : message;
            }).finally(() => { this.limitsSaving = false; });
        },
    },
};
</script>
