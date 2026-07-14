<template>
    <v-dialog v-model="open" max-width="620" persistent>
        <v-card>
            <v-card-title class="d-flex align-center">
                <v-icon left color="primary">mdi-calendar-today</v-icon>
                今日学习设置
                <v-spacer />
                <v-btn icon aria-label="关闭今日学习设置" :disabled="saving" @click="open = false"><v-icon>mdi-close</v-icon></v-btn>
            </v-card-title>
            <v-card-subtitle v-if="limits" class="pb-2">{{ limits.study_date }} · {{ limits.timezone }}</v-card-subtitle>
            <v-card-text>
                <v-alert type="info" dense text class="mb-5">这是今天的临时调整，不会修改永久设置。</v-alert>
                <v-alert v-if="error" type="error" dense text class="mb-4">{{ error }}</v-alert>
                <v-skeleton-loader v-if="loading" type="list-item-three-line@2" />
                <template v-else-if="limits">
                    <v-row>
                        <v-col cols="12" sm="6">
                            <v-text-field v-model.number="form.new_limit_delta" type="number" min="0" max="999" outlined dense label="今天额外增加的新卡" hint="可输入 0 至 999（仅可增加，不能减少）" :error-messages="errors.new_limit_delta" persistent-hint />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <v-text-field v-model.number="form.review_limit_delta" type="number" min="0" max="9999" outlined dense label="今天额外增加的复习" hint="可输入 0 至 9999（仅可增加，不能减少）" :error-messages="errors.review_limit_delta" persistent-hint />
                        </v-col>
                    </v-row>
                    <v-switch v-model="form.pause_new_cards" inset color="warning" label="暂停今天的新卡" class="mt-0" />
                    <v-simple-table dense class="rounded-lg limits-table">
                        <thead><tr><th>项目</th><th>永久值</th><th>今日增量</th><th>有效值</th><th>已开始/完成</th><th>剩余</th></tr></thead>
                        <tbody>
                            <tr>
                                <td>新卡</td><td>{{ limits.permanent_new_limit }}</td><td>{{ form.new_limit_delta }}</td>
                                <td>{{ previewEffectiveNew }}</td>
                                <td>{{ limits.introduced_today_count }}</td><td>{{ previewRemainingNew }}</td>
                            </tr>
                            <tr>
                                <td>复习</td><td>{{ limits.permanent_review_limit }}</td><td>{{ form.review_limit_delta }}</td>
                                <td>{{ previewEffectiveReview }}</td>
                                <td>{{ limits.reviewed_today_count }}</td><td>{{ previewRemainingReview }}</td>
                            </tr>
                        </tbody>
                    </v-simple-table>
                </template>
            </v-card-text>
            <v-card-actions>
                <v-btn text :disabled="saving || loading || !limits" @click="resetToday">重置临时设置</v-btn>
                <v-spacer />
                <v-btn text :disabled="saving" @click="open = false">取消</v-btn>
                <v-btn color="primary" :loading="saving" :disabled="loading || !limits || !canSave" @click="save">保存今天</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
export default {
    props: { value: { type: Boolean, default: false } },
    data: () => ({ loading: false, saving: false, limits: null, error: '', form: { new_limit_delta: 0, review_limit_delta: 0, pause_new_cards: false }, requestSequence: 0 }),
    computed: {
        open: { get() { return this.value; }, set(value) { this.$emit('input', value); } },
        newDeltaError() {
            const v = this.form.new_limit_delta;
            if (v === '' || v === null || v === undefined || Number.isNaN(v)) return '请输入整数。';
            if (!Number.isInteger(v)) return '必须为整数。';
            if (v < 0) return '不能小于 0。';
            if (v > 999) return '不能大于 999。';
            return '';
        },
        reviewDeltaError() {
            const v = this.form.review_limit_delta;
            if (v === '' || v === null || v === undefined || Number.isNaN(v)) return '请输入整数。';
            if (!Number.isInteger(v)) return '必须为整数。';
            if (v < 0) return '不能小于 0。';
            if (v > 9999) return '不能大于 9999。';
            return '';
        },
        errors() {
            return { new_limit_delta: this.newDeltaError, review_limit_delta: this.reviewDeltaError };
        },
        canSave() { return !this.newDeltaError && !this.reviewDeltaError; },
        previewEffectiveNew() { return this.form.pause_new_cards ? 0 : Math.max(0, this.limits.permanent_new_limit + Number(this.form.new_limit_delta || 0)); },
        previewEffectiveReview() { return Math.max(0, this.limits.permanent_review_limit + Number(this.form.review_limit_delta || 0)); },
        previewRemainingNew() { return Math.max(0, this.previewEffectiveNew - this.limits.introduced_today_count); },
        previewRemainingReview() { return Math.max(0, this.previewEffectiveReview - this.limits.reviewed_today_count); },
    },
    watch: { value(isOpen) { if (isOpen) this.load(); } },
    methods: {
        applyPayload(payload) {
            this.limits = payload;
            const override = payload.override || {};
            this.form = { new_limit_delta: Number(override.new_limit_delta || 0), review_limit_delta: Number(override.review_limit_delta || 0), pause_new_cards: Boolean(override.pause_new_cards) };
        },
        load() {
            const seq = ++this.requestSequence;
            this.loading = true;
            this.error = '';
            axios.get('/reviews/senses/today-limits')
                .then(({ data }) => { if (seq === this.requestSequence) this.applyPayload(data); })
                .catch((error) => { if (seq === this.requestSequence) this.error = error.response?.data?.message || '今日学习设置加载失败。'; })
                .finally(() => { if (seq === this.requestSequence) this.loading = false; });
        },
        save() {
            if (!this.canSave) return;
            this.saving = true;
            this.error = '';
            axios.put('/reviews/senses/today-limits', this.form)
                .then(({ data }) => { this.applyPayload(data); this.$emit('changed', data); this.open = false; })
                .catch((error) => { this.error = error.response?.data?.message || '今日学习设置保存失败。'; })
                .finally(() => { this.saving = false; });
        },
        resetToday() {
            this.saving = true;
            this.error = '';
            axios.delete('/reviews/senses/today-limits')
                .then(({ data }) => { this.applyPayload(data); this.$emit('changed', data); this.open = false; })
                .catch((error) => { this.error = error.response?.data?.message || '临时设置重置失败。'; })
                .finally(() => { this.saving = false; });
        },
    },
};
</script>

<style scoped>
.limits-table { border: 1px solid rgba(127, 127, 127, 0.22); overflow-x: auto; }
.limits-table th, .limits-table td { white-space: nowrap; }
</style>
