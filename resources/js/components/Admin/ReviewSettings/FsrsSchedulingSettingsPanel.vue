<template>
    <v-card outlined class="rounded-lg mt-4">
        <v-card-title class="subtitle-1">学习步骤与复习体验</v-card-title>
        <v-card-text aria-live="polite">
            <v-progress-linear v-if="loading" indeterminate color="primary" class="mb-4" />
            <v-alert v-if="error" dense outlined type="error">
                {{ error }}
                <v-btn text small color="primary" @click="load">重试</v-btn>
            </v-alert>
            <template v-else-if="settings">
                <v-alert dense outlined type="info">
                    步骤和 Easy Days 只影响之后的评分，不会追溯改写已有到期日。
                </v-alert>
                <v-row>
                    <v-col cols="12" md="6">
                        <v-text-field
                            v-model="learningStepsText"
                            label="学习步骤（分钟，用逗号分隔）"
                            hint="推荐 10, 30；留空可跳过学习阶段"
                            persistent-hint
                            outlined dense
                        />
                    </v-col>
                    <v-col cols="12" md="6">
                        <v-text-field
                            v-model="relearningStepsText"
                            label="重学步骤（分钟，用逗号分隔）"
                            hint="推荐 10；每一步必须小于一天"
                            persistent-hint
                            outlined dense
                        />
                    </v-col>
                    <v-col cols="12" md="6">
                        <v-text-field v-model.number="settings.scheduling.maximum_interval_days" type="number" min="1" max="36500" label="最大间隔（天）" outlined dense />
                    </v-col>
                    <v-col cols="12" md="6">
                        <v-text-field v-model.number="settings.scheduling.minimum_relearning_interval_days" type="number" min="1" label="重学后的最小间隔（天）" outlined dense />
                    </v-col>
                </v-row>

                <div class="subtitle-2 mb-2">Easy Days</div>
                <div class="caption grey--text mb-3">可把未来负担轻微移开；星期日到星期六分别设置。</div>
                <v-row dense>
                    <v-col v-for="(label, index) in weekdayLabels" :key="label" cols="6" sm="4" md>
                        <v-select
                            v-model="settings.scheduling.easy_days[index]"
                            :items="easyDayModes"
                            :label="label"
                            item-text="text"
                            item-value="value"
                            outlined dense hide-details
                        />
                    </v-col>
                </v-row>

                <v-divider class="my-5" />
                <div class="subtitle-2 mb-2">计时、自动推进与音频偏好</div>
                <v-row>
                    <v-col cols="12" md="4"><v-text-field v-model.number="settings.experience.question_timer_seconds" type="number" min="0" max="3600" label="问题面秒数（0 为关闭）" outlined dense /></v-col>
                    <v-col cols="12" md="4"><v-text-field v-model.number="settings.experience.answer_timer_seconds" type="number" min="0" max="3600" label="答案面秒数（0 为关闭）" outlined dense /></v-col>
                    <v-col cols="12" md="4"><v-switch v-model="settings.experience.show_timer" label="显示计时器" color="primary" /></v-col>
                </v-row>
                <v-switch v-model="settings.experience.auto_advance_enabled" label="启用自动推进（绝不会自动选择评分）" color="primary" />
                <v-alert v-if="autoAdvanceNeedsTimer" dense outlined type="warning">启用自动推进前，至少设置一个非零计时。</v-alert>
                <v-switch v-model="settings.experience.audio_autoplay" label="自动播放发音/音频" color="primary" />
                <v-switch v-model="settings.experience.audio_replay_answer" label="显示答案后重复播放" color="primary" />

                <v-alert v-if="status" dense outlined :type="statusType" class="mt-3">{{ status }}</v-alert>
                <v-btn color="primary" :loading="saving" :disabled="saving || autoAdvanceNeedsTimer" @click="save">保存设置</v-btn>
            </template>
        </v-card-text>
    </v-card>
</template>

<script>
import * as AdminReviewSettingsApi from '../../../services/AdminReviewSettingsApi';

export default {
    data() {
        return {
            loading: true,
            saving: false,
            error: '',
            status: '',
            statusType: 'success',
            settings: null,
            learningStepsText: '',
            relearningStepsText: '',
            weekdayLabels: ['周日', '周一', '周二', '周三', '周四', '周五', '周六'],
            easyDayModes: [
                { text: '正常', value: 'normal' },
                { text: '减少', value: 'reduced' },
                { text: '最少', value: 'minimum' },
            ],
        };
    },
    computed: {
        autoAdvanceNeedsTimer() {
            if (!this.settings?.experience?.auto_advance_enabled) return false;
            return Number(this.settings.experience.question_timer_seconds) === 0
                && Number(this.settings.experience.answer_timer_seconds) === 0;
        },
    },
    mounted() {
        this.load();
    },
    methods: {
        load() {
            this.loading = true;
            this.error = '';
            AdminReviewSettingsApi.getAdvancedReviewSettings()
                .then(response => {
                    this.settings = {
                        scheduling: { ...response.data.scheduling, easy_days: [...response.data.scheduling.easy_days] },
                        experience: { ...response.data.experience },
                    };
                    this.learningStepsText = response.data.scheduling.learning_steps_minutes.join(', ');
                    this.relearningStepsText = response.data.scheduling.relearning_steps_minutes.join(', ');
                })
                .catch(() => { this.error = '高级复习设置加载失败。'; })
                .finally(() => { this.loading = false; });
        },
        parseSteps(value) {
            if (!value.trim()) return [];
            return value.split(',').map(item => Number(item.trim()));
        },
        save() {
            this.saving = true;
            this.status = '';
            const payload = {
                scheduling: {
                    ...this.settings.scheduling,
                    learning_steps_minutes: this.parseSteps(this.learningStepsText),
                    relearning_steps_minutes: this.parseSteps(this.relearningStepsText),
                },
                experience: { ...this.settings.experience },
            };
            AdminReviewSettingsApi.updateAdvancedReviewSettings(payload)
                .then(response => {
                    this.statusType = 'success';
                    this.status = response.data.message || '设置已保存。';
                    this.settings = {
                        scheduling: { ...response.data.scheduling, easy_days: [...response.data.scheduling.easy_days] },
                        experience: { ...response.data.experience },
                    };
                })
                .catch(error => {
                    this.statusType = 'error';
                    this.status = error.response?.data?.message || '设置保存失败，请检查输入。';
                })
                .finally(() => { this.saving = false; });
        },
    },
};
</script>
