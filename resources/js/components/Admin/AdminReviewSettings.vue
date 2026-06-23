<template>
    <div id="admin-review-settings">

        <div class="subheader mt-4">间隔重复系统</div>

        <!-- FSRS Status Card -->
        <v-card outlined class="rounded-lg mt-4">
            <v-card-title>FSRS 复习系统</v-card-title>
            <v-card-subtitle>当前词义复习卡使用 FSRS（Free Spaced Repetition Scheduler）自动计算下次复习时间。</v-card-subtitle>
            <v-card-text>
                <v-alert type="info" text class="mb-4">
                    FSRS 会根据你的评分、稳定度、难度和复习历史自动计算下一次复习时间。
                    这些熟练度参数由算法维护，不建议也不允许手动编辑。
                    如需重新开始，应使用"重置卡片"为新学状态，而不是手动修改参数。
                </v-alert>

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
                            </td>
                        </tr>
                        <tr>
                            <td class="font-weight-bold pr-4 py-2">目标记忆保持率说明</td>
                            <td class="py-2 grey--text text--darken-1">
                                Desired retention 越高，复习负担越重。
                                本设置只影响之后评分产生的新到期时间，本轮不自动重排已有卡片。
                            </td>
                        </tr>
                        <tr>
                            <td class="font-weight-bold pr-4 py-2">参数来源</td>
                            <td class="py-2">fsrs-rs-php 默认参数</td>
                        </tr>
                        <tr>
                            <td class="font-weight-bold pr-4 py-2">参数编辑</td>
                            <td class="py-2">暂未开放</td>
                        </tr>
                        <tr>
                            <td class="font-weight-bold pr-4 py-2">卡片重置</td>
                            <td class="py-2">后续开放</td>
                        </tr>
                        <tr>
                            <td class="font-weight-bold pr-4 py-2">参数优化</td>
                            <td class="py-2">后续开放</td>
                        </tr>
                    </tbody>
                </v-simple-table>

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

                <div class="mt-2 grey--text caption">
                    FSRS 参数优化和卡片重置功能会在后续版本加入。
                </div>
            </v-card-text>
        </v-card>

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
            }
        },
        props: {
            language: String
        },
        mounted() {
            this.loadSettings();
        },
        methods: {
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
