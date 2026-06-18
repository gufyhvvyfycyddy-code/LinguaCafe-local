<template>
    <div id="admin-review-settings">
        
        <!-- SRS info -->
        <div class="subheader mt-4">间隔重复系统</div>
        <v-alert dark border="left" type="info" color="primary" class="mt-2 mb-4">
            数字表示复习或手动调整等级后，单词会在多少天后再次复习。<br><br>

            同一个等级可以填写多个数字，用英文逗号分隔。
            这种情况下，系统会在等级变化时选择复习任务较少的日期作为下次复习日。
        </v-alert>

        <!-- SRS settings -->
        <v-card outlined class="rounded-lg" :loading="!reviewIntervals.length">
            <v-card-text>
                <label class="font-weight-bold mt-4">SRS 设置</label>

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
    </div>
</template>

<script>
    export default {
        data: function() {
            return {
                saving: false,
                saveStatus: '',
                reviewIntervals: [],
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
            loadSettings() {
                axios.post('/settings/global/get', {
                    'settingNames': ['reviewIntervals']
                }).then((result) => {
                    Object.keys(result.data.reviewIntervals).forEach((key, index) => {
                        this.reviewIntervals.push({
                            name: (key * -1) + '',
                            values: result.data.reviewIntervals[key].join(',')
                        });
                    });
                    
                    this.saving = false;
                    this.$forceUpdate();
                });
            }
        }
    }
</script>
 
