<template>
    <div id="admin-review-settings">

        <div class="subheader mt-4">间隔重复系统</div>

        <!-- Zone 1: 复习目标 -->
        <v-card outlined class="rounded-lg mt-4">
            <v-card-title>复习目标</v-card-title>
            <v-card-subtitle>设置目标记忆保持率，控制复习频率。</v-card-subtitle>
            <v-card-text>
                <v-simple-table dense class="no-hover">
                    <tbody>
                        <tr>
                            <td class="font-weight-bold pr-4 py-2" style="vertical-align: middle;">Desired Retention</td>
                            <td class="py-2" style="min-width: 200px;">
                                <v-select
                                    v-model="fsrsDesiredRetention"
                                    :items="fsrsRetentionOptions"
                                    item-text="text"
                                    item-value="value"
                                    outlined
                                    dense
                                    hide-details
                                    style="max-width: 160px;"
                                />
                                <div class="mt-2 grey--text text--darken-1 caption">
                                    <span class="font-weight-bold">{{ fsrsDesiredRetentionText }}</span>
                                    <span v-if="retentionExplanation"> — {{ retentionExplanation }}</span>
                                    <v-chip v-if="isRecommended" x-small color="success" outlined class="ml-1">推荐默认值</v-chip>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td class="font-weight-bold pr-4 py-2">说明</td>
                            <td class="py-2 grey--text text--darken-1">
                                Desired retention 越高，复习负担越重。
                                本设置只影响之后评分产生的新到期时间，不会自动重排已有卡片。
                            </td>
                        </tr>
                    </tbody>
                </v-simple-table>

                <!-- 复习负担预估 -->
                <div class="mt-3 pa-3 rounded" style="background: #f5f7fa;">
                    <div class="font-weight-medium body-2 mb-1">每天大概要复习多少</div>
                    <div class="body-1 grey--text text--darken-1">{{ burdenEstimateMessage }}</div>
                    <div class="caption grey--text mt-1">粗略预估，仅帮助你感受负担，不会重排已有卡片。</div>
                </div>

                <v-card-actions class="px-0">
                    <v-spacer />
                    <v-btn
                        rounded
                        depressed
                        color="primary"
                        :disabled="fsrsSaving"
                        :loading="fsrsSaving"
                        @click="saveFsrsSettings"
                    >
                        保存 FSRS 设置
                    </v-btn>
                </v-card-actions>

                <div v-if="fsrsSaveStatus" class="mt-2 green--text text--darken-1 body-2">
                    {{ fsrsSaveStatus }}
                </div>
            </v-card-text>
        </v-card>

        <!-- Zone 2: 当前 FSRS 状态 -->
        <v-card outlined class="rounded-lg mt-4" :loading="statsLoading">
            <v-card-title>当前 FSRS 状态</v-card-title>
            <v-card-subtitle>仅统计当前语言下的词义复习卡，不包含旧单词卡。</v-card-subtitle>
            <v-card-text>
                <v-alert v-if="statsError" type="error" dense outlined class="mb-4">{{ statsError }}</v-alert>

                <div v-if="!statsError && fsrsStats.total === 0 && !statsLoading" class="text--secondary py-4 text-center">
                    当前没有词义复习卡。
                </div>

                <div v-if="!statsError && fsrsStats.total > 0">
                    <!-- Row 1: Summary -->
                    <div class="text-caption text--secondary mb-2">概况</div>
                    <v-row dense class="mb-4">
                        <v-col cols="3">
                            <v-sheet outlined rounded class="pa-3 text-center">
                                <div class="text-h6 font-weight-bold">{{ fsrsStats.total }}</div>
                                <div class="text-caption text--secondary">总词义卡</div>
                            </v-sheet>
                        </v-col>
                        <v-col cols="3">
                            <v-sheet outlined rounded class="pa-3 text-center">
                                <div class="text-h6 font-weight-bold">{{ fsrsStats.enabled }}</div>
                                <div class="text-caption text--secondary">启用中</div>
                            </v-sheet>
                        </v-col>
                        <v-col cols="3">
                            <v-sheet outlined rounded class="pa-3 text-center">
                                <div class="text-h6 font-weight-bold">{{ fsrsStats.archived }}</div>
                                <div class="text-caption text--secondary">已归档</div>
                            </v-sheet>
                        </v-col>
                        <v-col cols="3">
                            <v-sheet outlined rounded class="pa-3 text-center">
                                <div class="text-h6 font-weight-bold">{{ fsrsStats.due }}</div>
                                <div class="text-caption text--secondary">当前到期</div>
                            </v-sheet>
                        </v-col>
                    </v-row>

                    <!-- Row 2: State Distribution -->
                    <div class="text-caption text--secondary mb-2">状态分布</div>
                    <v-row dense class="mb-4">
                        <v-col cols="3">
                            <v-sheet outlined rounded class="pa-3 text-center">
                                <div class="text-h6 font-weight-bold">{{ fsrsStats.by_state.new }}</div>
                                <div class="text-caption text--secondary">新卡</div>
                            </v-sheet>
                        </v-col>
                        <v-col cols="3">
                            <v-sheet outlined rounded class="pa-3 text-center">
                                <div class="text-h6 font-weight-bold">{{ fsrsStats.by_state.learning }}</div>
                                <div class="text-caption text--secondary">学习中</div>
                            </v-sheet>
                        </v-col>
                        <v-col cols="3">
                            <v-sheet outlined rounded class="pa-3 text-center">
                                <div class="text-h6 font-weight-bold">{{ fsrsStats.by_state.review }}</div>
                                <div class="text-caption text--secondary">复习中</div>
                            </v-sheet>
                        </v-col>
                        <v-col cols="3">
                            <v-sheet outlined rounded class="pa-3 text-center">
                                <div class="text-h6 font-weight-bold">{{ fsrsStats.by_state.relearning }}</div>
                                <div class="text-caption text--secondary">重新学习</div>
                            </v-sheet>
                        </v-col>
                    </v-row>

                    <!-- Row 3: FSRS Proficiency -->
                    <div class="text-caption text--secondary mb-2">FSRS 熟练度</div>
                    <v-row dense class="mb-2">
                        <v-col cols="2">
                            <v-sheet outlined rounded class="pa-3 text-center">
                                <div class="text-h6 font-weight-bold">{{ formatFloat(fsrsStats.average_stability) }}</div>
                                <div class="text-caption text--secondary">平均稳定度</div>
                            </v-sheet>
                        </v-col>
                        <v-col cols="2">
                            <v-sheet outlined rounded class="pa-3 text-center">
                                <div class="text-h6 font-weight-bold">{{ formatFloat(fsrsStats.average_difficulty) }}</div>
                                <div class="text-caption text--secondary">平均难度</div>
                            </v-sheet>
                        </v-col>
                        <v-col cols="2">
                            <v-sheet outlined rounded class="pa-3 text-center">
                                <div class="text-h6 font-weight-bold">{{ fsrsStats.lapses_total }}</div>
                                <div class="text-caption text--secondary">总遗忘次数</div>
                            </v-sheet>
                        </v-col>
                        <v-col cols="3">
                            <v-sheet outlined rounded class="pa-3 text-center">
                                <div class="text-h6 font-weight-bold">{{ fsrsStats.reviewed_today }}</div>
                                <div class="text-caption text--secondary">今日已复习</div>
                            </v-sheet>
                        </v-col>
                        <v-col cols="3">
                            <v-sheet outlined rounded class="pa-3 text-center">
                                <div class="text-h6 font-weight-bold">{{ fsrsStats.reset_count }}</div>
                                <div class="text-caption text--secondary">今日重置</div>
                            </v-sheet>
                        </v-col>
                    </v-row>

                    <div class="mt-2 grey--text caption">
                        稳定度越高，表示记忆越稳定；难度越高，表示这张卡越难。
                    </div>
                </div>
            </v-card-text>
        </v-card>

        <!-- Zone 3: 高级工具 (collapsed by default) -->
        <v-expansion-panels flat class="mt-4">
            <v-expansion-panel>
                <v-expansion-panel-header>
                    高级工具
                </v-expansion-panel-header>
                <v-expansion-panel-content>
                    <div class="text-caption grey--text text--darken-1 mb-3">
                        参数优化、手动参数、卡片重置等低频操作，需要时再打开。
                    </div>

                    <v-simple-table dense class="no-hover">
                        <tbody>
                            <tr>
                                <td class="font-weight-bold pr-4 py-2" style="vertical-align: middle;">自动优化参数</td>
                                <td class="py-2">
                                    <div>暂未开放。</div>
                                    <div class="grey--text text--darken-1 caption">
                                        后续会根据你的真实复习记录，自动优化 FSRS 参数，让之后的复习安排更适合你的记忆情况。
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td class="font-weight-bold pr-4 py-2" style="vertical-align: middle;">参数来源</td>
                                <td class="py-2">
                                    <div>当前使用 fsrs-rs-php 默认参数。</div>
                                    <div class="grey--text text--darken-1 caption">
                                        后续会显示是否使用过个性化优化参数，以及最近一次优化时间。
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td class="font-weight-bold pr-4 py-2" style="vertical-align: middle;">手动编辑参数</td>
                                <td class="py-2">
                                    <div>暂未开放。</div>
                                    <div class="grey--text text--darken-1 caption">
                                        后续可以作为高级功能单独评估。手动编辑会影响复习安排，需要强提醒和单独确认。
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td class="font-weight-bold pr-4 py-2" style="vertical-align: middle;">卡片重置</td>
                                <td class="py-2">
                                    <div>已在复习卡管理页开放。</div>
                                    <div class="grey--text text--darken-1 caption">
                                        如果想让某张卡重新开始学习，请在管理页使用"重置"。
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </v-simple-table>

                    <v-card-actions class="px-0">
                        <v-btn
                            small
                            outlined
                            color="primary"
                            @click="goToManagePage"
                        >
                            前往复习卡管理页
                        </v-btn>
                    </v-card-actions>
                </v-expansion-panel-content>
            </v-expansion-panel>
        </v-expansion-panels>

        <!-- Legacy SRS Settings (collapsed by default) -->
        <v-expansion-panels flat class="mt-4">
            <v-expansion-panel>
                <v-expansion-panel-header>
                    旧版 SRS 设置（仅影响旧单词卡和短语）
                </v-expansion-panel-header>
                <v-expansion-panel-content>
                    <v-alert border="left" type="warning" color="warning" class="mb-4">
                        这些设置不会影响词义复习卡。<br>
                        当前词义复习卡使用 FSRS 调度。<br>
                        仅在仍使用旧单词复习或短语复习时才需要修改这里。
                    </v-alert>

                    <v-card outlined class="rounded-lg" :loading="!reviewIntervals.length">
                        <v-card-text>
                            <v-simple-table dense class="no-hover no-lines">
                                <tbody>
                                    <tr v-for="(interval, index) in reviewIntervals" :key="index">
                                        <td class="pt-4">
                                            等级 {{ interval.name }}：
                                        </td>
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
                            <v-btn
                                rounded
                                depressed
                                color="primary"
                                :disabled="!reviewIntervals.length || saving"
                                :loading="saving"
                                @click="saveSettings"
                            >
                                保存
                            </v-btn>
                        </v-card-actions>
                    </v-card>
                </v-expansion-panel-content>
            </v-expansion-panel>
        </v-expansion-panels>
    </div>
</template>

<script>
    export default {
        data: function() {
            return {
                saving: false,
                saveStatus: '',
                reviewIntervals: [],
                fsrsDesiredRetention: 0.90,
                fsrsSaving: false,
                fsrsSaveStatus: '',
                fsrsRetentionOptions: [
                    { text: '70%', value: 0.70 },
                    { text: '75%', value: 0.75 },
                    { text: '80%', value: 0.80 },
                    { text: '85%', value: 0.85 },
                    { text: '90%', value: 0.90 },
                    { text: '92%', value: 0.92 },
                    { text: '95%', value: 0.95 },
                    { text: '97%', value: 0.97 },
                ],
                // FSRS stats
                statsLoading: false,
                statsError: '',
                fsrsStats: {
                    total: 0,
                    enabled: 0,
                    archived: 0,
                    due: 0,
                    by_state: {
                        new: 0,
                        learning: 0,
                        review: 0,
                        relearning: 0,
                    },
                    average_stability: null,
                    average_difficulty: null,
                    lapses_total: 0,
                    reviewed_today: 0,
                    reset_count: 0,
                },
            }
        },
        props: {
            language: String
        },
        mounted() {
            this.loadSettings();
            this.loadFsrsStats();
        },
        computed: {
            fsrsDesiredRetentionText() {
                const option = this.fsrsRetentionOptions.find(o => o.value === this.fsrsDesiredRetention);
                return option ? option.text : '';
            },
            retentionExplanation() {
                const explanations = {
                    0.70: '复习压力最低，但会更容易忘，适合临时减负。',
                    0.75: '复习量较少，适合想保持轻量学习的人。',
                    0.80: '偏轻松，能减少复习次数，但记忆保持会下降。',
                    0.85: '负担适中偏轻，适合不想被复习压住的人。',
                    0.90: '记忆效果和复习负担比较平衡。',
                    0.92: '记得更稳一些，但每天复习会变多。',
                    0.95: '追求更牢固记忆，复习负担会明显增加。',
                    0.97: '非常高的保持率，复习压力可能很大，请谨慎选择。',
                };
                return explanations[this.fsrsDesiredRetention] || '';
            },
            isRecommended() {
                return this.fsrsDesiredRetention === 0.90;
            },
            retentionBurdenEstimate() {
                const multiplierMap = {
                    0.70: 0.55,
                    0.75: 0.65,
                    0.80: 0.78,
                    0.85: 0.90,
                    0.90: 1.00,
                    0.92: 1.15,
                    0.95: 1.45,
                    0.97: 1.90,
                };
                const multiplier = multiplierMap[this.fsrsDesiredRetention] || 1.00;
                const enabled = Number(this.fsrsStats.enabled || 0);
                const due = Number(this.fsrsStats.due || 0);
                const reviewedToday = Number(this.fsrsStats.reviewed_today || 0);
                const baseline = Math.max(due, reviewedToday, Math.ceil(enabled * 0.03));
                const estimate = Math.ceil(baseline * multiplier);
                const low = Math.max(0, Math.floor(estimate * 0.8));
                const high = Math.max(low, Math.ceil(estimate * 1.25));
                return { low, high };
            },
            burdenEstimateMessage() {
                if (Number(this.fsrsStats.enabled || 0) === 0) {
                    return '现在还没有启用中的词义卡，先不用担心复习负担。';
                }
                const est = this.retentionBurdenEstimate;
                const range = `按当前数据粗略看，每天大约复习 ${est.low}-${est.high} 张。`;
                if (this.fsrsDesiredRetention === 0.90) {
                    return `${range}90% 是比较平衡的默认选择。`;
                } else if (this.fsrsDesiredRetention < 0.90) {
                    return `${range}会轻松一些，但也更容易忘。`;
                }
                return `${range}记得更稳，但复习会更密。`;
            },
        },
        methods: {
            goToManagePage() {
                window.location.href = '/review-cards/manage';
            },
            formatFloat(value) {
                if (value === null || value === undefined) {
                    return '—';
                }
                return Number(value).toFixed(2);
            },
            loadFsrsStats() {
                this.statsLoading = true;
                this.statsError = '';
                axios.get('/review-cards/stats')
                    .then((response) => {
                        this.fsrsStats = response.data;
                    })
                    .catch(() => {
                        this.statsError = 'FSRS 统计加载失败。';
                    })
                    .finally(() => {
                        this.statsLoading = false;
                    });
            },
            reviewIntervalChanged(value, index) {
                // split value
                let intervals = [1];
                if (value.length) {
                    intervals = value.split(',');
                }

                // parse numbers and restrict undesired values
                for (let intervalIndex = 0; intervalIndex < intervals.length; intervalIndex++) {
                    let parsedInterval = parseInt(intervals[intervalIndex]);
                    intervals[intervalIndex] = isNaN(parsedInterval) ? 1 : parsedInterval;

                    if (intervals[intervalIndex] > 3650) {
                        intervals[intervalIndex] = 3650;
                    }

                    if (intervals[intervalIndex] < 1) {
                        intervals[intervalIndex] = 1;
                    }
                }

                this.reviewIntervals[index].name = (7 - index) + '';
                this.reviewIntervals[index].values = intervals.join(',');

                this.$nextTick(() => {
                    this.$forceUpdate();
                });
            },
            saveSettings() {
                this.saving = true;

                let reviewIntervalsArray = {};
                for (let intervalIndex = 0; intervalIndex < this.reviewIntervals.length; intervalIndex++) {
                    let key = (parseInt(this.reviewIntervals[intervalIndex].name) * -1);
                    reviewIntervalsArray[key] = this.reviewIntervals[intervalIndex].values.split(',');
                    reviewIntervalsArray[key] = reviewIntervalsArray[key].map(Number);
                }

                axios.post('/settings/global/update', {
                    'settings': {
                        'reviewIntervals': reviewIntervalsArray,
                    }
                }).then(() => {
                    this.reviewIntervals = [];
                    this.loadSettings();
                });
            },
            saveFsrsSettings() {
                this.fsrsSaving = true;

                axios.post('/settings/global/update', {
                    'settings': {
                        'fsrsDesiredRetention': this.fsrsDesiredRetention,
                    }
                }).then(() => {
                    this.fsrsSaving = false;
                    this.fsrsSaveStatus = 'FSRS 设置已保存。新的复习评分会使用该目标保持率；已排程卡片不会自动重排。';
                    setTimeout(() => { this.fsrsSaveStatus = ''; }, 5000);
                }).catch(() => {
                    this.fsrsSaving = false;
                    this.fsrsSaveStatus = '保存失败，请重试。';
                });
            },
            loadSettings() {
                axios.post('/settings/global/get', {
                    'settingNames': ['reviewIntervals', 'fsrsDesiredRetention']
                }).then((result) => {
                    Object.keys(result.data.reviewIntervals).forEach((key, index) => {
                        this.reviewIntervals.push({
                            name: (key * -1) + '',
                            values: result.data.reviewIntervals[key].join(',')
                        });
                    });

                    if (result.data.fsrsDesiredRetention !== undefined && result.data.fsrsDesiredRetention !== null) {
                        this.fsrsDesiredRetention = result.data.fsrsDesiredRetention;
                    }

                    this.saving = false;
                    this.$forceUpdate();
                });
            }
        }
    }
</script>
