<template>
    <div class="review-card-delete-mutation-surface">
        <v-dialog v-model="deleteDialog" max-width="500">
            <v-card>
                <v-card-title class="error--text review-card-manage-delete-title">彻底删除这张词义复习卡？</v-card-title>
                <v-card-text>
                    <p class="review-card-manage-delete-body">这会移除这张词义复习卡，并让该释义不再作为已确认词义出现在阅读页候选中。</p>
                    <p class="review-card-manage-delete-note text--secondary">复习历史会保留，阅读来源记录会保留。不会删除其他词义。</p>
                    <p class="review-card-manage-delete-last-sense text--secondary">如果这是该单词最后一个已确认词义，该单词会回到"新词"状态。</p>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text :disabled="deleteLoading" @click="closeDelete">取消</v-btn>
                    <v-btn
                        color="error"
                        :loading="deleteLoading"
                        class="review-card-manage-delete-confirm"
                        @click="doDelete"
                    >确认彻底删除</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <v-dialog v-model="bulkDeleteDialog" max-width="560">
            <v-card>
                <v-card-title class="error--text review-card-manage-bulk-delete-title">批量彻底删除选中的词义复习卡？</v-card-title>
                <v-card-text>
                    <p class="review-card-manage-bulk-delete-scope">只会处理你当前勾选的复习卡，不会按筛选条件全量删除。</p>
                    <div v-if="visibleBulkDeleteItems.length > 0" class="bulk-delete-list mb-3">
                        <div v-for="item in visibleBulkDeleteItems" :key="item.review_card_id" class="bulk-delete-item">
                            <span class="lemma">{{ item.lemma }}</span>
                            <span class="sense-zh">— {{ item.sense_zh || '无中文释义' }}</span>
                        </div>
                        <div v-if="hiddenBulkDeleteCount > 0" class="bulk-delete-more">
                            还有 {{ hiddenBulkDeleteCount }} 张未显示。
                        </div>
                    </div>
                    <p class="review-card-manage-bulk-delete-note text--secondary">对应释义会退出已确认词义候选，复习历史会保留，阅读来源记录会保留。</p>
                    <p class="review-card-manage-bulk-delete-last-sense text--secondary">如果某个单词没有其他已确认词义，它会回到"新词"状态。</p>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text :disabled="bulkDeleteLoading" @click="closeBulkDelete">取消</v-btn>
                    <v-btn
                        color="error"
                        :loading="bulkDeleteLoading"
                        class="review-card-manage-bulk-delete-confirm"
                        @click="doBulkDelete"
                    >确认批量彻底删除</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script>
import axios from 'axios';

export default {
    name: 'ReviewCardDeleteMutationSurface',
    data() {
        return {
            deleteDialog: false,
            deleteTarget: null,
            deleteLoading: false,
            bulkDeleteDialog: false,
            bulkDeleteLoading: false,
            bulkSelectionIds: [],
            bulkSelectionItems: [],
        };
    },
    computed: {
        visibleBulkDeleteItems() {
            return this.bulkSelectionItems.slice(0, 20);
        },
        hiddenBulkDeleteCount() {
            return Math.max(this.bulkSelectionItems.length - 20, 0);
        },
    },
    methods: {
        confirmDelete(item) {
            const reviewCardId = Number(item?.review_card_id);
            if (!Number.isInteger(reviewCardId) || reviewCardId <= 0 || this.deleteLoading) return;
            this.deleteTarget = item;
            this.deleteDialog = true;
        },
        closeDelete() {
            if (this.deleteLoading) return;
            this.deleteDialog = false;
            this.deleteTarget = null;
        },
        doDelete() {
            if (!this.deleteTarget || this.deleteLoading) return;
            const reviewCardId = Number(this.deleteTarget.review_card_id);
            if (!Number.isInteger(reviewCardId) || reviewCardId <= 0) return;

            this.deleteLoading = true;
            this.deleteDialog = false;
            this.deleteTarget = null;

            axios.delete('/review-cards/manage/' + reviewCardId)
                .then((response) => {
                    this.$emit(
                        'notify',
                        response.data.message || '已彻底删除词义复习卡。该释义不会再出现在阅读页，复习历史已保留。',
                        'success'
                    );
                    this.$emit('clear-selection');
                    this.$emit('refresh-list');
                    this.$emit('refresh-stats');
                })
                .catch((error) => {
                    this.$emit('error', '删除失败：' + (error.response?.data?.message || error.message));
                })
                .finally(() => {
                    this.deleteLoading = false;
                });
        },
        confirmBulk(selection) {
            const ids = Array.isArray(selection?.ids)
                ? selection.ids.map(Number).filter(id => Number.isInteger(id) && id > 0)
                : [];
            if (ids.length === 0 || this.bulkDeleteLoading) return;

            this.bulkSelectionIds = [...ids];
            this.bulkSelectionItems = Array.isArray(selection.items) ? [...selection.items] : [];
            this.bulkDeleteDialog = true;
        },
        closeBulkDelete() {
            if (this.bulkDeleteLoading) return;
            this.bulkDeleteDialog = false;
            this.bulkSelectionIds = [];
            this.bulkSelectionItems = [];
        },
        doBulkDelete() {
            if (this.bulkDeleteLoading || this.bulkSelectionIds.length === 0) return;
            const ids = [...this.bulkSelectionIds];
            this.bulkDeleteLoading = true;
            this.bulkDeleteDialog = false;

            axios.post('/review-cards/manage/bulk-delete', { ids })
                .then((response) => {
                    const data = response.data || {};
                    let message = data.message || '已彻底删除词义复习卡，复习历史已保留。';
                    if (data.skipped > 0) {
                        message += ' 其中有 ' + data.skipped + ' 张跳过处理。';
                    }
                    this.$emit('clear-selection');
                    this.$emit('notify', message, data.skipped > 0 ? 'warning' : 'success');
                    this.$emit('refresh-list');
                    this.$emit('refresh-stats');
                    this.bulkSelectionIds = [];
                    this.bulkSelectionItems = [];
                })
                .catch((error) => {
                    this.$emit('error', '批量删除失败：' + (error.response?.data?.message || error.message));
                })
                .finally(() => {
                    this.bulkDeleteLoading = false;
                });
        },
    },
};
</script>

<style scoped>
.bulk-delete-list {
    max-height: 240px;
    overflow-y: auto;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    padding: 8px 12px;
    background: #fafafa;
}

.bulk-delete-item {
    padding: 4px 0;
    font-size: 0.9rem;
    line-height: 1.5;
}

.bulk-delete-item .lemma {
    font-weight: 600;
}

.bulk-delete-item .sense-zh {
    color: rgba(0, 0, 0, 0.66);
}

.bulk-delete-more {
    padding: 6px 0 2px;
    font-size: 0.85rem;
    color: rgba(0, 0, 0, 0.54);
    font-style: italic;
}
</style>
