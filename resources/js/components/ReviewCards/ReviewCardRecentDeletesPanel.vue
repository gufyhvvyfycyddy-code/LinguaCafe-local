<template>
    <v-card outlined class="rounded-lg mt-4">
        <v-card-title class="text-subtitle-1 font-weight-bold">
            <v-icon color="primary" class="mr-2">mdi-delete-restore</v-icon>
            最近删除
        </v-card-title>
        <v-card-text>
            <p class="text--secondary mb-3">词义复习卡移入最近删除后，可在 30 天内恢复。</p>
            <v-alert v-if="error" type="error" dense text>{{ error }}</v-alert>
            <v-btn small text color="primary" :loading="loading" @click="load">刷新</v-btn>
            <v-alert v-if="!loading && items.length === 0" type="info" dense text class="mt-2 mb-0">
                最近 30 天没有可恢复的删除。
            </v-alert>
            <v-list v-else dense class="pa-0">
                <v-list-item v-for="item in items" :key="item.operation_id">
                    <v-list-item-content>
                        <v-list-item-title>{{ item.lemma || '词义卡' }}</v-list-item-title>
                        <v-list-item-subtitle class="recent-delete-description">
                            {{ item.deleted_at || item.created_at }} · 操作 {{ item.operation_id }}
                        </v-list-item-subtitle>
                    </v-list-item-content>
                    <v-list-item-action>
                        <v-btn
                            small
                            text
                            color="primary"
                            :disabled="!item.can_restore || recoveringId !== null"
                            :loading="recoveringId === item.operation_id"
                            @click="restore(item.operation_id)"
                        >{{ item.can_restore ? '恢复' : '已恢复' }}</v-btn>
                    </v-list-item-action>
                </v-list-item>
            </v-list>
        </v-card-text>
    </v-card>
</template>

<script>
import axios from 'axios';

export default {
    name: 'ReviewCardRecentDeletesPanel',
    data() {
        return {
            items: [],
            loading: false,
            recoveringId: null,
            error: '',
        };
    },
    mounted() {
        this.load();
    },
    methods: {
        load() {
            this.loading = true;
            this.error = '';
            return axios.get('/review-cards/knowledge-hygiene/recent-deletes')
                .then((response) => {
                    this.items = response.data.items || [];
                })
                .catch((error) => {
                    this.error = error.response?.data?.message || '最近删除加载失败。';
                })
                .finally(() => {
                    this.loading = false;
                });
        },
        restore(operationId) {
            if (this.recoveringId !== null) return;
            this.recoveringId = operationId;
            this.error = '';
            axios.post(`/review-cards/knowledge-hygiene/operations/${operationId}/undo`)
                .then(() => this.load())
                .catch((error) => {
                    this.error = error.response?.data?.message || '恢复失败。';
                })
                .finally(() => {
                    this.recoveringId = null;
                });
        },
    },
};
</script>

<style scoped>
.recent-delete-description {
    white-space: normal;
    overflow-wrap: anywhere;
}
</style>
