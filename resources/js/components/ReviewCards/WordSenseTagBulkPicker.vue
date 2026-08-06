<template>
    <v-menu offset-y :close-on-content-click="false" min-width="300">
        <template #activator="{ on, attrs }">
            <v-btn small color="secondary" class="mr-2" v-bind="attrs" v-on="on" :disabled="reviewCardIds.length === 0 || tags.length === 0">
                <v-icon small left>mdi-tag-multiple-outline</v-icon>{{ buttonLabel }}
            </v-btn>
        </template>
        <v-card>
            <v-card-title class="text-subtitle-2">{{ resolvedTitle }}</v-card-title>
            <v-card-text class="pb-2">
                <v-alert v-if="error" type="error" dense text>{{ error }}</v-alert>
                <v-autocomplete
                    v-model="selectedTagIds"
                    :items="tags"
                    item-text="name"
                    item-value="id"
                    label="选择标签"
                    multiple
                    chips
                    small-chips
                    dense
                    hide-details
                />
            </v-card-text>
            <v-card-actions>
                <v-btn small color="primary" :loading="loading" :disabled="selectedTagIds.length === 0" @click="apply('add')">添加</v-btn>
                <v-btn small color="warning" text :loading="loading" :disabled="selectedTagIds.length === 0" @click="apply('remove')">移除</v-btn>
            </v-card-actions>
        </v-card>
    </v-menu>
</template>

<script>
import axios from 'axios';

export default {
    props: {
        reviewCardIds: { type: Array, default: () => [] },
        tags: { type: Array, default: () => [] },
        buttonLabel: { type: String, default: '批量标签' },
        title: { type: String, default: '' },
    },
    data() {
        return {
            selectedTagIds: [],
            loading: false,
            error: '',
        };
    },
    computed: {
        resolvedTitle() {
            return this.title || `为 ${this.reviewCardIds.length} 张卡修改内容标签`;
        },
    },
    methods: {
        async apply(action) {
            if (this.loading || this.selectedTagIds.length === 0) return;
            this.loading = true;
            this.error = '';
            try {
                await axios.post('/review-cards/manage/tags/bulk-assignments', {
                    review_card_ids: this.reviewCardIds,
                    tag_ids: this.selectedTagIds,
                    action,
                });
                const label = action === 'add' ? '已添加标签。' : '已移除标签。';
                this.selectedTagIds = [];
                this.$emit('updated');
                this.$emit('notify', label, 'success');
            } catch (error) {
                const errors = error.response?.data?.errors;
                this.error = Object.values(errors || {}).flat()[0]
                    || error.response?.data?.message
                    || '批量标签操作失败。';
            } finally {
                this.loading = false;
            }
        },
    },
};
</script>
