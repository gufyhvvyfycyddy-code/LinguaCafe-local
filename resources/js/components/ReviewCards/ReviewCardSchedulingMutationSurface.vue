<template>
    <div class="review-card-scheduling-mutation-surface">
        <v-dialog v-model="dialog" max-width="560">
            <v-card>
                <v-card-title>{{ dialogTitle }}</v-card-title>
                <v-card-text>
                    <v-text-field
                        v-if="action === 'set_due'"
                        v-model="dueDate"
                        type="date"
                        label="到期日期"
                        :disabled="submitting"
                        @change="loadPreview"
                    />
                    <v-checkbox
                        v-if="action === 'reset_new'"
                        v-model="resetCounts"
                        label="同时把复习次数和遗忘次数归零"
                        :disabled="submitting"
                        @change="loadPreview"
                    />
                    <v-alert type="info" dense text border="left">
                        {{ actionHint }}
                    </v-alert>
                    <div v-if="previewLoading" class="text-caption text--secondary py-2">
                        正在生成服务器预览...
                    </div>
                    <v-alert v-else-if="previewError" type="error" dense text>
                        {{ previewError }}
                    </v-alert>
                    <div v-else-if="preview" class="manual-operation-preview text-caption">
                        <div>当前：{{ stateSummary(preview.before_state) }}</div>
                        <div>确认后：{{ stateSummary(preview.projected_after_state) }}</div>
                        <div class="text--secondary mt-2">
                            这不是复习评分。操作会写入 Manual operation 记录，可按线性顺序撤销。
                        </div>
                    </div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text :disabled="submitting" @click="close">取消</v-btn>
                    <v-btn
                        color="primary"
                        :loading="submitting"
                        :disabled="!preview || previewLoading || !!previewError"
                        class="review-card-manual-operation-confirm"
                        @click="apply"
                    >
                        确认
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script>
import axios from 'axios';

export default {
    name: 'ReviewCardSchedulingMutationSurface',
    data() {
        return {
            dialog: false,
            target: null,
            action: null,
            dueDate: '',
            resetCounts: false,
            preview: null,
            previewLoading: false,
            previewError: '',
            submitting: false,
            previewRequestSeq: 0,
        };
    },
    computed: {
        dialogTitle() {
            return {
                due_now: '让这张卡立即到期？',
                set_due: '设置这张卡的到期日期',
                reset_new: '重置为新卡？',
            }[this.action] || '确认手动调度操作';
        },
        actionHint() {
            return {
                due_now: '这不是一次复习评分；不会写入复习历史。只改变到期时间，不改变稳定度、难度或复习次数。',
                set_due: '这不是一次复习评分；不会写入复习历史。按服务器学习日设置到期时间，不重算 FSRS 记忆。',
                reset_new: this.resetCounts
                    ? '恢复为新卡状态并把 reps/lapses 归零；旧复习历史会保留，并新增一条 reset 记录。'
                    : '恢复为新卡状态并保留 reps/lapses；旧复习历史会保留，并新增一条 reset 记录。',
            }[this.action] || '';
        },
    },
    methods: {
        confirmDueNow(item) {
            this.open('due_now', item);
        },
        confirmSetDue(item) {
            const tomorrow = new Date(Date.now() + 86400000);
            this.dueDate = new Date(
                tomorrow.getTime() - tomorrow.getTimezoneOffset() * 60000,
            ).toISOString().slice(0, 10);
            this.open('set_due', item, true);
        },
        confirmReset(item) {
            this.resetCounts = false;
            this.open('reset_new', item, true);
        },
        open(action, item, preserveOptions = false) {
            const reviewCardId = Number(item?.review_card_id);
            if (!Number.isInteger(reviewCardId) || reviewCardId <= 0) return;
            this.target = item;
            this.action = action;
            if (!preserveOptions) {
                this.dueDate = '';
                this.resetCounts = false;
            }
            this.preview = null;
            this.previewError = '';
            this.dialog = true;
            this.publishState();
            this.loadPreview();
        },
        close() {
            if (this.submitting) return;
            this.dialog = false;
            this.target = null;
            this.preview = null;
            this.previewError = '';
            this.publishState();
        },
        options() {
            if (this.action === 'set_due') return { due_date: this.dueDate };
            if (this.action === 'reset_new') return { reset_counts: this.resetCounts };
            return {};
        },
        loadPreview() {
            if (!this.target || !this.action) return;
            if (this.action === 'set_due' && !this.dueDate) {
                this.preview = null;
                return;
            }
            const seq = ++this.previewRequestSeq;
            this.previewLoading = true;
            this.previewError = '';
            this.publishState();
            axios.post(
                '/review-cards/' + this.target.review_card_id + '/manual-operations/preview',
                { action: this.action, options: this.options() },
            ).then((response) => {
                if (seq !== this.previewRequestSeq) return;
                this.preview = response.data;
            }).catch((error) => {
                if (seq !== this.previewRequestSeq) return;
                this.preview = null;
                this.previewError = error.response?.data?.message || '无法生成操作预览。';
            }).finally(() => {
                if (seq === this.previewRequestSeq) {
                    this.previewLoading = false;
                    this.publishState();
                }
            });
        },
        apply() {
            if (!this.target || !this.preview || this.submitting) return;
            const target = this.target;
            const operationId = this.createRequestId();
            this.submitting = true;
            this.previewError = '';
            this.publishState();
            axios.post(
                '/review-cards/' + target.review_card_id + '/manual-operations/apply',
                {
                    operation_id: operationId,
                    action: this.action,
                    options: this.options(),
                    expected_state_fingerprint: this.preview.expected_state_fingerprint,
                },
            ).then((response) => {
                this.dialog = false;
                this.target = null;
                this.preview = null;
                this.$emit('card-updated', response.data.card);
                this.$emit('notify', '手动调度操作已应用，可在卡片详情中撤销。', 'success');
                this.$emit('refresh-list');
                this.$emit('refresh-stats');
            }).catch((error) => {
                this.previewError = error.response?.data?.message || '操作失败。';
                if (error.response?.status === 409) this.loadPreview();
            }).finally(() => {
                this.submitting = false;
                this.publishState();
            });
        },
        createRequestId() {
            if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                return window.crypto.randomUUID();
            }
            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
                const r = Math.random() * 16 | 0;
                const v = c === 'x' ? r : (r & 0x3 | 0x8);
                return v.toString(16);
            });
        },
        stateSummary(snapshot) {
            if (!snapshot) return '—';
            const fsrs = snapshot.fsrs || {};
            const lifecycle = snapshot.lifecycle || {};
            const due = fsrs.fsrs_due_at
                ? new Date(fsrs.fsrs_due_at).toLocaleString()
                : '无';
            return [
                '状态 ' + (fsrs.fsrs_state || '—'),
                '到期 ' + due,
                'reps ' + (fsrs.fsrs_reps ?? 0),
                'lapses ' + (fsrs.fsrs_lapses ?? 0),
                '生命周期 ' + (lifecycle.lifecycle_state || '—'),
            ].join(' · ');
        },
        publishState() {
            this.$emit('state-change', {
                open: this.dialog,
                busy: this.previewLoading || this.submitting,
            });
        },
    },
};
</script>
