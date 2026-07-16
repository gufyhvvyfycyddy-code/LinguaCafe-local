<template>
    <div class="review-card-lifecycle-mutation-surface">
        <v-dialog v-model="lifecycleDialog" max-width="480">
            <v-card>
                <v-card-title>{{ lifecycleDialogTitle }}</v-card-title>
                <v-card-text>
                    <p>{{ lifecycleDialogHint }}</p>
                    <v-alert v-if="lifecycleConflict" type="error" dense text class="mt-2 mb-0">
                        {{ lifecycleConflict }}
                    </v-alert>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text :disabled="lifecycleLoading" @click="closeLifecycleDialog">取消</v-btn>
                    <v-btn
                        :color="lifecycleDialogColor"
                        :loading="lifecycleLoading"
                        class="review-card-manage-lifecycle-confirm"
                        @click="performLifecycleAction"
                    >确认</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <v-dialog v-model="bulkLifecycleDialog" max-width="520">
            <v-card>
                <v-card-title>{{ bulkLifecycleDialogTitle }}</v-card-title>
                <v-card-text>
                    <p class="review-card-manage-bulk-lifecycle-scope">
                        只会处理你当前勾选的 {{ bulkSelectionIds.length }} 张复习卡，不会按筛选条件全量操作。
                    </p>
                    <p class="review-card-manage-bulk-lifecycle-hint">{{ bulkLifecycleDialogHint }}</p>
                    <p class="review-card-manage-bulk-lifecycle-note text--secondary">
                        跳过当前状态不允许该操作的卡片；不删除词义或复习历史。
                    </p>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text :disabled="bulkLifecycleLoading" @click="closeBulkLifecycleDialog">取消</v-btn>
                    <v-btn
                        :color="bulkLifecycleDialogColor"
                        :loading="bulkLifecycleLoading"
                        class="review-card-manage-bulk-lifecycle-confirm"
                        @click="performBulkLifecycleAction"
                    >确认</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <v-dialog v-model="stateHelpDialog" max-width="560">
            <v-card>
                <v-card-title>复习卡生命周期状态说明</v-card-title>
                <v-card-text>
                    <v-list dense>
                        <v-list-item v-for="state in lifecycleStateHelpEntries" :key="state.key">
                            <v-list-item-icon>
                                <v-chip x-small :color="state.color">{{ state.label }}</v-chip>
                            </v-list-item-icon>
                            <v-list-item-content>
                                <v-list-item-subtitle>{{ state.hint }}</v-list-item-subtitle>
                            </v-list-item-content>
                        </v-list-item>
                    </v-list>
                    <v-divider class="my-3" />
                    <p class="text--secondary text-body-2 mb-1">
                        <strong>重置</strong>：清空 FSRS 调度进度，不影响生命周期状态。
                    </p>
                    <p class="text--secondary text-body-2 mb-0">
                        <strong>删除</strong>：永久移除复习卡，独立于生命周期状态。
                    </p>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="stateHelpDialog = false">关闭</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script>
import axios from 'axios';
import {
    actionColor,
    actionDangerLevel,
    actionHint,
    actionLabel,
    LIFECYCLE_ACTIONS,
    LIFECYCLE_PRESENTATION,
    LIFECYCLE_STATES,
} from '../../services/ReviewCardLifecyclePresentation.js';

export default {
    name: 'ReviewCardLifecycleMutationSurface',
    data() {
        return {
            lifecycleMenuId: null,
            lifecycleDescriptor: null,
            lifecycleLoading: false,
            lifecycleDialog: false,
            lifecycleDialogContext: null,
            lifecycleConflict: '',
            descriptorRequestSeq: 0,
            bulkLifecycleLoading: false,
            bulkLifecycleDialog: false,
            bulkLifecycleAction: null,
            bulkSelectionIds: [],
            bulkSelectionItems: [],
            stateHelpDialog: false,
        };
    },
    computed: {
        availableLifecycleActions() {
            return this.lifecycleDescriptor?.available_actions || [];
        },
        lifecycleDialogTitle() {
            return this.lifecycleDialogContext
                ? '确认' + actionLabel(this.lifecycleDialogContext.action)
                : '';
        },
        lifecycleDialogHint() {
            return this.lifecycleDialogContext
                ? actionHint(this.lifecycleDialogContext.action)
                : '';
        },
        lifecycleDialogColor() {
            return this.lifecycleDialogContext
                ? actionColor(this.lifecycleDialogContext.action)
                : 'primary';
        },
        bulkLifecycleDialogTitle() {
            return this.bulkLifecycleAction
                ? '批量' + actionLabel(this.bulkLifecycleAction) + '选中的复习卡？'
                : '';
        },
        bulkLifecycleDialogHint() {
            return this.bulkLifecycleAction ? actionHint(this.bulkLifecycleAction) : '';
        },
        bulkLifecycleDialogColor() {
            return this.bulkLifecycleAction ? actionColor(this.bulkLifecycleAction) : 'primary';
        },
        lifecycleStateHelpEntries() {
            return LIFECYCLE_STATES.map((key) => ({
                key,
                label: LIFECYCLE_PRESENTATION[key].label,
                color: LIFECYCLE_PRESENTATION[key].color,
                hint: LIFECYCLE_PRESENTATION[key].hint,
            }));
        },
    },
    mounted() {
        this.publishState();
    },
    beforeDestroy() {
        this.descriptorRequestSeq++;
    },
    methods: {
        publishState() {
            this.$emit('state-change', {
                menuId: this.lifecycleMenuId,
                descriptor: this.lifecycleDescriptor
                    ? { ...this.lifecycleDescriptor, available_actions: [...this.availableLifecycleActions] }
                    : null,
                availableActions: [...this.availableLifecycleActions],
                loading: this.lifecycleLoading,
                bulkLoading: this.bulkLifecycleLoading,
            });
        },
        openStateHelp() {
            this.stateHelpDialog = true;
        },
        handleMenuToggle(isOpen, item) {
            const reviewCardId = Number(item?.review_card_id);
            if (!Number.isInteger(reviewCardId) || reviewCardId <= 0) return;

            if (isOpen) {
                this.lifecycleMenuId = reviewCardId;
                this.lifecycleDescriptor = null;
                this.publishState();
                this.fetchLifecycleDescriptor(reviewCardId);
                return;
            }

            const closingMenuId = reviewCardId;
            setTimeout(() => {
                if (this.lifecycleMenuId !== closingMenuId || this.lifecycleLoading) return;
                this.descriptorRequestSeq++;
                this.lifecycleMenuId = null;
                this.lifecycleDescriptor = null;
                this.publishState();
            }, 200);
        },
        fetchLifecycleDescriptor(cardId, updateDialogContext = false) {
            const normalizedId = Number(cardId);
            if (!Number.isInteger(normalizedId) || normalizedId <= 0) return Promise.resolve(null);

            const seq = ++this.descriptorRequestSeq;
            return axios.get('/review-cards/' + normalizedId + '/lifecycle')
                .then((response) => {
                    if (seq !== this.descriptorRequestSeq) return null;
                    const descriptor = response.data?.lifecycle || null;
                    if (this.lifecycleMenuId === normalizedId) {
                        this.lifecycleDescriptor = descriptor;
                    }
                    if (
                        updateDialogContext
                        && this.lifecycleDialogContext?.item?.review_card_id === normalizedId
                    ) {
                        this.lifecycleDialogContext = {
                            ...this.lifecycleDialogContext,
                            expectedVersion: descriptor?.version ?? null,
                        };
                    }
                    this.publishState();
                    return descriptor;
                })
                .catch(() => {
                    if (seq !== this.descriptorRequestSeq) return null;
                    if (this.lifecycleMenuId === normalizedId) {
                        this.lifecycleDescriptor = null;
                        this.publishState();
                    }
                    return null;
                });
        },
        handleAction(action, item) {
            const reviewCardId = Number(item?.review_card_id);
            if (!LIFECYCLE_ACTIONS.includes(action) || !Number.isInteger(reviewCardId) || reviewCardId <= 0) {
                return;
            }
            const expectedVersion = this.lifecycleMenuId === reviewCardId
                ? (this.lifecycleDescriptor?.version ?? null)
                : null;

            if (actionDangerLevel(action) === 'safe') {
                this.executeLifecycleAction(action, item, expectedVersion);
                return;
            }

            this.lifecycleDialogContext = {
                action,
                item,
                expectedVersion,
            };
            this.lifecycleConflict = '';
            this.lifecycleDialog = true;
        },
        closeLifecycleDialog() {
            if (this.lifecycleLoading) return;
            this.lifecycleDialog = false;
            this.lifecycleDialogContext = null;
            this.lifecycleConflict = '';
        },
        performLifecycleAction() {
            if (!this.lifecycleDialogContext || this.lifecycleLoading) return;
            const { action, item, expectedVersion } = this.lifecycleDialogContext;
            this.executeLifecycleAction(action, item, expectedVersion);
        },
        createRequestId() {
            if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                return window.crypto.randomUUID();
            }
            return 'lc-' + Date.now() + '-' + Math.random().toString(36).slice(2);
        },
        requestLifecycleAction({ action, item, expectedVersion = null, source = 'review_card_manage' }) {
            if (this.lifecycleLoading) {
                return Promise.reject(new Error('lifecycle_request_in_flight'));
            }
            const reviewCardId = Number(item?.review_card_id);
            if (!LIFECYCLE_ACTIONS.includes(action) || !Number.isInteger(reviewCardId) || reviewCardId <= 0) {
                return Promise.reject(new Error('invalid_lifecycle_request'));
            }

            this.lifecycleLoading = true;
            this.publishState();
            return axios.post('/review-cards/' + reviewCardId + '/lifecycle-actions', {
                action,
                request_id: this.createRequestId(),
                expected_version: expectedVersion ?? null,
                source,
            }).finally(() => {
                this.lifecycleLoading = false;
                this.publishState();
            });
        },
        runLifecycleAction(options) {
            return this.requestLifecycleAction(options);
        },
        executeLifecycleAction(action, item, expectedVersion) {
            const reviewCardId = Number(item?.review_card_id);
            this.requestLifecycleAction({ action, item, expectedVersion })
                .then((response) => {
                    const label = actionLabel(action);
                    const alreadyApplied = response.data?.already_applied;
                    this.lifecycleDialog = false;
                    this.lifecycleDialogContext = null;
                    this.lifecycleConflict = '';
                    this.lifecycleMenuId = null;
                    this.lifecycleDescriptor = null;
                    this.$emit(
                        'notify',
                        alreadyApplied ? label + '：该操作已应用过。' : '已' + label + '。',
                        'success'
                    );
                    this.$emit('refresh-list');
                    this.$emit('refresh-stats');
                }).catch((err) => {
                    if (err.message === 'lifecycle_request_in_flight' || err.message === 'invalid_lifecycle_request') {
                        return;
                    }
                    const status = err.response?.status;
                    const hasOpenDialog = this.lifecycleDialogContext?.item?.review_card_id === reviewCardId;
                    if (status === 409 || status === 422) {
                        const message = status === 409
                            ? '卡片状态已在其他页面发生变化，已刷新最新状态。'
                            : (err.response?.data?.message || '该操作在当前状态下不可用。');
                        if (hasOpenDialog) {
                            this.lifecycleConflict = message;
                        } else {
                            this.$emit('notify', message, 'error');
                        }
                        this.fetchLifecycleDescriptor(reviewCardId, hasOpenDialog);
                    } else if (!err.response) {
                        this.$emit('notify', '网络错误，请检查连接后重试。', 'error');
                    } else {
                        this.$emit('notify', err.response?.data?.message || '操作失败。', 'error');
                        this.lifecycleDialog = false;
                        this.lifecycleDialogContext = null;
                    }
                });
        },
        confirmBulk(selection) {
            const action = selection?.action;
            const ids = Array.isArray(selection?.ids)
                ? selection.ids.map(Number).filter(id => Number.isInteger(id) && id > 0)
                : [];
            if (!LIFECYCLE_ACTIONS.includes(action) || ids.length === 0) return;

            this.bulkLifecycleAction = action;
            this.bulkSelectionIds = [...ids];
            this.bulkSelectionItems = Array.isArray(selection.items) ? [...selection.items] : [];
            this.bulkLifecycleDialog = true;
        },
        closeBulkLifecycleDialog() {
            if (this.bulkLifecycleLoading) return;
            this.bulkLifecycleDialog = false;
            this.bulkLifecycleAction = null;
            this.bulkSelectionIds = [];
            this.bulkSelectionItems = [];
        },
        requestBulkLifecycle({ ids, action, source = 'review_card_manage_bulk' }) {
            const normalizedIds = Array.isArray(ids)
                ? ids.map(Number).filter(id => Number.isInteger(id) && id > 0)
                : [];
            if (this.bulkLifecycleLoading) {
                return Promise.reject(new Error('bulk_lifecycle_request_in_flight'));
            }
            if (!LIFECYCLE_ACTIONS.includes(action) || normalizedIds.length === 0) {
                return Promise.reject(new Error('invalid_bulk_lifecycle_request'));
            }

            this.bulkLifecycleLoading = true;
            this.publishState();
            return axios.post('/review-cards/manage/bulk-lifecycle', {
                ids: normalizedIds,
                action,
                source,
            }).finally(() => {
                this.bulkLifecycleLoading = false;
                this.publishState();
            });
        },
        runBulkLifecycle(options) {
            return this.requestBulkLifecycle(options);
        },
        performBulkLifecycleAction() {
            if (!this.bulkLifecycleAction || this.bulkSelectionIds.length === 0) return;
            const ids = [...this.bulkSelectionIds];
            const action = this.bulkLifecycleAction;
            this.bulkLifecycleDialog = false;

            this.requestBulkLifecycle({ ids, action })
                .then((response) => {
                    const data = response.data || {};
                    const label = actionLabel(action);
                    let message = `已批量${label}：应用 ${data.applied ?? ids.length} 张`;
                    if (data.skipped > 0) {
                        message += `，跳过 ${data.skipped} 张（当前状态不允许）`;
                    }
                    message += '。';
                    this.$emit('clear-selection');
                    this.$emit('notify', message, data.skipped > 0 ? 'warning' : 'success');
                    this.$emit('refresh-list');
                    this.$emit('refresh-stats');
                    this.bulkLifecycleAction = null;
                    this.bulkSelectionIds = [];
                    this.bulkSelectionItems = [];
                }).catch((err) => {
                    if (err.message === 'bulk_lifecycle_request_in_flight' || err.message === 'invalid_bulk_lifecycle_request') {
                        return;
                    }
                    const status = err.response?.status;
                    if (status === 422) {
                        this.$emit('notify', err.response?.data?.message || '请求参数有误。', 'error');
                    } else if (!err.response) {
                        this.$emit('notify', '网络错误，请检查连接后重试。', 'error');
                    } else {
                        this.$emit('error', '批量操作失败：' + (err.response?.data?.message || err.message));
                    }
                });
        },
    },
};
</script>
