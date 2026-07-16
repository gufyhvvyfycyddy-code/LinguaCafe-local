<template>
    <div class="review-card-scheduling-mutation-surface">
        <v-dialog v-model="dueNowDialog" max-width="480">
            <v-card>
                <v-card-title class="review-card-manage-due-now-title">让这张卡立即到期？</v-card-title>
                <v-card-text>
                    <p class="review-card-manage-due-now-body">确认后，这张卡会尽快出现在复习队列中。</p>
                    <p class="review-card-manage-due-now-note text--secondary">这不是一次复习评分，不会写入复习历史，也不会改变 FSRS 记忆。</p>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text :disabled="dueNowLoading" @click="closeDueNow">取消</v-btn>
                    <v-btn color="primary" :loading="dueNowLoading" class="review-card-manage-due-now-confirm" @click="doDueNow">确认立即到期</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <v-dialog v-model="resetDialog" max-width="500">
            <v-card>
                <v-card-title class="review-card-manage-reset-title">重置这张复习卡的进度？</v-card-title>
                <v-card-text>
                    <p class="review-card-manage-reset-body">这会把这张词义卡恢复为新卡状态，并清空当前 FSRS 记忆。</p>
                    <p class="review-card-manage-reset-note text--secondary">旧复习历史会保留，并会新增一条 "reset" 记录。不会删除词义，也不会删除阅读来源。</p>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="closeReset" :disabled="resetLoading">取消</v-btn>
                    <v-btn color="primary" :loading="resetLoading" class="review-card-manage-reset-confirm" @click="doReset">确认重置</v-btn>
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
            dueNowDialog: false,
            dueNowTarget: null,
            dueNowLoading: false,
            resetDialog: false,
            resetTarget: null,
            resetLoading: false,
        };
    },
    methods: {
        confirmDueNow(item) {
            if (!item || !Number.isInteger(Number(item.review_card_id)) || Number(item.review_card_id) <= 0) {
                return;
            }
            this.dueNowTarget = item;
            this.dueNowDialog = true;
        },
        closeDueNow() {
            if (this.dueNowLoading) return;
            this.dueNowDialog = false;
            this.dueNowTarget = null;
        },
        doDueNow() {
            if (!this.dueNowTarget || this.dueNowLoading) return;
            const item = this.dueNowTarget;
            this.dueNowLoading = true;
            this.dueNowDialog = false;
            this.dueNowTarget = null;

            axios.post('/review-cards/manage/' + item.review_card_id + '/due-now')
                .then((response) => {
                    this.$emit('card-updated', response.data);
                    this.$emit('notify', '已设为立即到期。该卡会进入复习队列。', 'success');
                    this.$emit('refresh-stats');
                })
                .catch((error) => {
                    this.$emit('error', '操作失败：' + (error.response?.data?.message || error.message));
                })
                .finally(() => {
                    this.dueNowLoading = false;
                });
        },
        confirmReset(item) {
            if (!item || !Number.isInteger(Number(item.review_card_id)) || Number(item.review_card_id) <= 0) {
                return;
            }
            this.resetTarget = item;
            this.resetDialog = true;
        },
        closeReset() {
            if (this.resetLoading) return;
            this.resetDialog = false;
            this.resetTarget = null;
        },
        doReset() {
            if (!this.resetTarget || this.resetLoading) return;
            const item = this.resetTarget;
            this.resetLoading = true;

            axios.post('/review-cards/manage/' + item.review_card_id + '/reset')
                .then((response) => {
                    this.resetDialog = false;
                    this.resetTarget = null;
                    this.$emit('notify', response.data.message || '已重置复习进度。该卡会重新进入复习队列。', 'success');
                    this.$emit('refresh-list');
                    this.$emit('refresh-stats');
                })
                .catch((error) => {
                    this.$emit('notify', error.response?.data?.message || '重置失败。', 'error');
                })
                .finally(() => {
                    this.resetLoading = false;
                });
        },
    },
};
</script>
