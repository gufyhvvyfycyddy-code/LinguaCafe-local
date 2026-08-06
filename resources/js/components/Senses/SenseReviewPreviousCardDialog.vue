<template>
    <v-dialog :value="value" max-width="560" @input="$emit('input', $event)">
        <v-card v-if="snapshot" data-testid="sense-review-previous-card-dialog">
            <v-card-title class="d-flex align-center">
                <span>上一张卡信息</span><v-spacer />
                <v-btn icon aria-label="关闭上一张卡信息" @click="$emit('input', false)"><v-icon>mdi-close</v-icon></v-btn>
            </v-card-title>
            <v-card-subtitle>{{ snapshot.card.lemma }} · {{ snapshot.card.sense_zh }}</v-card-subtitle>
            <v-card-text>
                <v-simple-table dense>
                    <tbody>
                        <tr><th scope="row">评分</th><td>{{ snapshot.action.rating_label || snapshot.action.rating }}</td></tr>
                        <tr><th scope="row">答题用时</th><td>{{ formatDuration(snapshot.durationMs) }}</td></tr>
                        <tr><th scope="row">下一到期</th><td>{{ snapshot.card.fsrs_due_at || '—' }}</td></tr>
                        <tr><th scope="row">状态</th><td>{{ snapshot.card.fsrs_state || '—' }}</td></tr>
                        <tr><th scope="row">稳定度 / 难度</th><td>{{ valueOrDash(snapshot.card.fsrs_stability) }} / {{ valueOrDash(snapshot.card.fsrs_difficulty) }}</td></tr>
                        <tr><th scope="row">复习 / 遗忘次数</th><td>{{ valueOrDash(snapshot.card.fsrs_reps) }} / {{ valueOrDash(snapshot.card.fsrs_lapses) }}</td></tr>
                    </tbody>
                </v-simple-table>
                <v-alert dense text type="info" class="mt-3 mb-0">
                    此处只显示最近一次成功评分的响应快照；计时不会改变调度。
                </v-alert>
            </v-card-text>
            <v-card-actions>
                <v-btn
                    v-if="snapshot.action.undoable"
                    text
                    color="primary"
                    @click="$emit('undo', snapshot.action)"
                ><v-icon small left>mdi-undo</v-icon>撤销这次评分</v-btn>
                <v-spacer />
                <v-btn text @click="$emit('input', false)">关闭</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
import { formatExperienceDuration } from '../Review/ReviewExperienceTimer.js';

export default {
    name: 'SenseReviewPreviousCardDialog',
    props: {
        value: { type: Boolean, default: false },
        snapshot: { type: Object, default: null },
    },
    methods: {
        formatDuration: formatExperienceDuration,
        valueOrDash(value) { return value === null || value === undefined ? '—' : value; },
    },
};
</script>
