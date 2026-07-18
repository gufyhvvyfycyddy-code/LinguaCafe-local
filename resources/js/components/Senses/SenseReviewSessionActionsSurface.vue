<template>
    <div>
        <v-dialog v-model="drawerOpen" max-width="640" scrollable>
            <v-card>
                <v-card-title class="d-flex align-center">
                    本次操作（{{ activeCount }}）
                    <v-spacer />
                    <v-btn icon small @click="drawerOpen = false">
                        <v-icon small>mdi-close</v-icon>
                    </v-btn>
                </v-card-title>
                <v-divider />
                <v-card-text class="pa-0" style="max-height: 60vh;">
                    <v-progress-linear v-if="loading" indeterminate />
                    <v-alert v-if="error" type="warning" dense text class="ma-2">
                        {{ error }}
                    </v-alert>
                    <v-list v-if="actions.length" dense>
                        <v-list-item
                            v-for="action in actions"
                            :key="action.review_log_id"
                            two-line
                        >
                            <v-list-item-content>
                                <v-list-item-title class="d-flex align-center">
                                    <span class="font-weight-medium">{{ action.lemma || '未知' }}</span>
                                    <v-chip x-small outlined class="ml-2" :color="ratingColor(action.rating)">
                                        {{ action.rating_label || action.rating }}
                                    </v-chip>
                                    <v-chip v-if="action.undone" x-small color="grey" class="ml-2">已撤销</v-chip>
                                </v-list-item-title>
                                <v-list-item-subtitle class="text--secondary">
                                    {{ action.sense_zh || '暂无释义' }}
                                    · {{ formatTime(action.reviewed_at) }}
                                    <span v-if="action.new_due_at"> · 到期 {{ formatTime(action.new_due_at) }}</span>
                                    <span v-if="action.undone && action.undone_at" class="ml-2">
                                        · 撤销于 {{ formatTime(action.undone_at) }}
                                        <span v-if="action.undo_source">（{{ undoSourceLabel(action.undo_source) }}）</span>
                                    </span>
                                    <span v-if="!action.undoable && !action.undone && action.blocked_reason" class="ml-2">
                                        · {{ blockedReasonLabel(action.blocked_reason) }}
                                    </span>
                                </v-list-item-subtitle>
                            </v-list-item-content>
                            <v-list-item-action v-if="action.undoable">
                                <v-btn
                                    small
                                    text
                                    color="primary"
                                    :loading="undoLoadingReviewLogId === action.review_log_id"
                                    @click="requestUndo(action, 'sense_review_history')"
                                >撤销</v-btn>
                            </v-list-item-action>
                        </v-list-item>
                    </v-list>
                    <div v-else-if="!loading" class="text-center text--secondary pa-4">
                        本次复习还没有评分记录。
                    </div>
                </v-card-text>
            </v-card>
        </v-dialog>

        <v-snackbar v-if="conflict" :value="true" :timeout="5000" top color="error">
            {{ conflict }}
            <template #action="{ attrs }">
                <v-btn text v-bind="attrs" @click="conflict = ''">关闭</v-btn>
            </template>
        </v-snackbar>
    </div>
</template>

<script>
    import { createReviewApiClient } from '../Review/ReviewApiClient.js';

    const reviewApi = createReviewApiClient();

    export default {
        props: {
            value: Boolean,
            reviewSessionId: String,
        },
        data: () => ({
            actions: [],
            loading: false,
            error: '',
            conflict: '',
            undoLoadingReviewLogId: null,
            requestSequence: 0,
        }),
        computed: {
            drawerOpen: {
                get() { return this.value; },
                set(next) { this.$emit('input', next); },
            },
            latestUndoableAction() {
                return this.actions.find(action => action.undoable) || null;
            },
            activeCount() {
                return this.actions.filter(action => !action.undone).length;
            },
        },
        beforeDestroy() {
            this.requestSequence++;
        },
        methods: {
            emitState() {
                this.$emit('state-change', {
                    latestUndoableAction: this.latestUndoableAction,
                    activeCount: this.activeCount,
                    undoLoadingReviewLogId: this.undoLoadingReviewLogId,
                });
            },
            reload() {
                if (!this.reviewSessionId) {
                    this.actions = [];
                    this.emitState();
                    return;
                }
                const seq = ++this.requestSequence;
                this.loading = true;
                this.error = '';
                this.emitState();
                return reviewApi.loadSenseSessionActions(this.reviewSessionId).then((response) => {
                    if (seq !== this.requestSequence) return;
                    this.actions = response.data.actions || [];
                }).catch(() => {
                    if (seq !== this.requestSequence) return;
                    this.error = '本次操作历史加载失败。';
                }).finally(() => {
                    if (seq !== this.requestSequence) return;
                    this.loading = false;
                    this.emitState();
                });
            },
            requestUndo(action, source) {
                if (!action || !action.undoable || !action.review_log_id) return;
                if (this.undoLoadingReviewLogId !== null) return;

                this.undoLoadingReviewLogId = action.review_log_id;
                this.conflict = '';
                this.emitState();
                const undoRequestId = (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function')
                    ? crypto.randomUUID()
                    : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                        const r = Math.random() * 16 | 0;
                        const v = c === 'x' ? r : (r & 0x3 | 0x8);
                        return v.toString(16);
                    });
                const payload = {
                    review_session_id: this.reviewSessionId,
                    undo_request_id: undoRequestId,
                    source: source,
                };

                return reviewApi.undoSenseReviewAction(action.review_log_id, payload).then((response) => {
                    this.conflict = '';
                    return Promise.resolve(this.reload()).then(() => {
                        this.$emit('undone', response.data);
                    });
                }).catch((error) => {
                    const status = error.response?.status;
                    if (status === 409) {
                        this.conflict = '无法撤销：卡片状态已在其他页面发生变化。';
                    } else if (status === 404) {
                        this.conflict = '无法撤销：该操作不属于当前复习会话。';
                    } else {
                        this.conflict = '撤销失败，请检查网络后重试。';
                    }
                    this.reload();
                }).finally(() => {
                    this.undoLoadingReviewLogId = null;
                    this.emitState();
                });
            },
            ratingColor(rating) {
                return { again: 'error', hard: 'warning', good: 'primary', easy: 'success' }[rating] || 'foreground';
            },
            formatTime(iso) {
                if (!iso) return '';
                const date = new Date(iso);
                if (isNaN(date.getTime())) return '';
                return date.toLocaleString('zh-CN', {
                    month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit',
                });
            },
            undoSourceLabel(source) {
                return {
                    sense_review_snackbar: '评分提示',
                    sense_review_history: '操作历史',
                    sense_review_hotkey: '快捷键',
                }[source] || source || '';
            },
            blockedReasonLabel(reason) {
                return {
                    wrong_session: '不属于当前会话',
                    not_latest_action: '不是最新操作',
                    already_undone: '已撤销',
                    missing_snapshot: '缺少快照（旧日志）',
                    card_state_changed: '卡片状态已变化',
                    legacy_target: '旧版卡片不支持撤销',
                    sense_not_confirmed: '词义未确认',
                    card_archived: '卡片已归档',
                    unsupported_rating: '不支持的评分类型',
                    unsupported_source: '不支持的来源',
                }[reason] || reason || '';
            },
        },
    };
</script>
